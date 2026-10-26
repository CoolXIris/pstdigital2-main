"""Synchronize the local BPS table catalog with WebAPI BPS without dropping old rows."""

from __future__ import annotations

import argparse
import base64
import csv
import json
import os
import re
import sys
import tempfile
import time
import unicodedata
from collections import defaultdict
from pathlib import Path
from urllib.parse import quote

import httpx
from dotenv import load_dotenv

from sumber_link import BASE, slug_judul

API_BASE = "https://webapi.bps.go.id/v1/api"
SOURCE_BY_TYPE = {"Tabel Statis": "1", "Tabel Dinamis": "2", "SIMDASI": "3"}
TYPE_BY_SOURCE = {source: table_type for table_type, source in SOURCE_BY_TYPE.items()}
CSV_PATH = Path(__file__).resolve().parent / "tabel_bps_sumsel.csv"


def _title_key(value: object) -> str:
    normalized = unicodedata.normalize("NFKC", str(value or "")).casefold()
    return " ".join(normalized.split())


def _decoded_prefix(raw_id: str) -> str:
    try:
        decoded = base64.b64decode(raw_id, validate=True).decode("utf-8", "strict")
    except (ValueError, UnicodeDecodeError):
        return ""
    return decoded.split("#", 1)[0].strip()


def _fetch_tables(client: httpx.Client, api_key: str, domain: str) -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    page = 1
    total_pages = 1
    while page <= total_pages:
        path = f"list/model/tablestatistic/domain/{domain}"
        if page > 1:
            path += f"/page/{page}"
        url = f"{API_BASE}/{path}/key/{quote(api_key, safe='')}/"
        payload = None
        for attempt in range(3):
            try:
                response = client.get(url)
                response.raise_for_status()
                candidate = response.json()
                if candidate.get("status") == "OK":
                    payload = candidate
                    break
            except (httpx.HTTPError, ValueError):
                pass
            if attempt < 2:
                time.sleep(0.5 * (attempt + 1))
        if payload is None:
            raise RuntimeError(f"WebAPI BPS gagal memuat katalog tabel pada halaman {page}.")

        data = payload.get("data")
        if not (
            isinstance(data, list)
            and len(data) >= 2
            and isinstance(data[0], dict)
            and isinstance(data[1], list)
        ):
            raise RuntimeError("Format daftar tabel WebAPI BPS tidak dikenali.")
        total_pages = max(1, int(data[0].get("pages") or 1))
        rows.extend(row for row in data[1] if isinstance(row, dict))
        page += 1
    return rows


def _fetch_periods(
    client: httpx.Client,
    api_key: str,
    domain: str,
    variable_id: str,
) -> list[dict[str, str]]:
    periods: dict[str, dict[str, str]] = {}
    page = 1
    total_pages = 1
    while page <= total_pages:
        path = f"list/model/th/domain/{domain}/var/{variable_id}"
        if page > 1:
            path += f"/page/{page}"
        url = f"{API_BASE}/{path}/key/{quote(api_key, safe='')}/"
        payload = None
        for attempt in range(3):
            try:
                response = client.get(url)
                response.raise_for_status()
                candidate = response.json()
                if candidate.get("status") == "OK":
                    payload = candidate
                    break
            except (httpx.HTTPError, ValueError):
                pass
            if attempt < 2:
                time.sleep(0.5 * (attempt + 1))
        if payload is None:
            raise RuntimeError(f"WebAPI BPS gagal memuat periode tabel dinamis {variable_id}.")

        data = payload.get("data")
        if not (
            isinstance(data, list)
            and len(data) >= 2
            and isinstance(data[0], dict)
            and isinstance(data[1], list)
        ):
            raise RuntimeError(f"Format daftar periode tabel dinamis {variable_id} tidak dikenali.")
        total_pages = max(1, int(data[0].get("pages") or 1))
        if total_pages > 100:
            raise RuntimeError(f"Jumlah periode tabel dinamis {variable_id} di luar batas yang diizinkan.")
        for period in data[1]:
            if not isinstance(period, dict):
                continue
            year = str(period.get("th") or "").strip()
            period_id = str(period.get("th_id") or "").strip()
            if year.isdecimal() and period_id.isdecimal():
                periods[year] = {"year": year, "period_id": period_id}
        page += 1

    return sorted(periods.values(), key=lambda period: int(period["year"]))


def _new_row(
    table: dict[str, str],
    source: str,
    table_id: str,
    year: str,
    link_json: str,
) -> dict[str, str]:
    raw_id = str(table.get("id") or "").strip()
    title = str(table.get("title") or "").strip()
    url = (
        f"{BASE}/id/statistics-table/{source}/"
        f"{quote(raw_id, safe='=')}/{slug_judul(title)}.html"
    )
    return {
        "kategori_subjek": str(table.get("subcat_name") or "").strip(),
        "subjek": str(table.get("subject") or "").strip(),
        "id_subjek": str(table.get("id_subject") or "").strip(),
        "judul": title,
        "id_tabel": table_id,
        "jenis_tabel": TYPE_BY_SOURCE[source],
        "tahun": year,
        "link_json": link_json,
        "id_raw": raw_id,
        "url_halaman": url,
    }


def _simdasi_link(source_id: str, domain: str, year: str, table_id: str) -> str:
    return (
        f"{API_BASE}/interoperabilitas/datasource/simdasi/id/{source_id}"
        f"/wilayah/{domain}000/tahun/{year}/id_tabel/{table_id}/key/"
    )


def sync_catalog(
    path: Path,
    tables: list[dict[str, str]],
    client: httpx.Client,
    api_key: str,
    domain: str,
) -> dict[str, int]:
    with path.open(encoding="utf-8-sig", newline="") as source:
        reader = csv.DictReader(source)
        fieldnames = list(reader.fieldnames or [])
        rows = list(reader)
    if not {"judul", "id_tabel", "jenis_tabel"}.issubset(fieldnames):
        raise RuntimeError("CSV harus memiliki kolom judul, id_tabel, dan jenis_tabel.")

    for field in ("id_raw", "url_halaman"):
        if field not in fieldnames:
            fieldnames.append(field)

    catalog_fields = {
        "kategori_subjek", "subjek", "id_subjek", "judul",
        "id_tabel", "jenis_tabel", "tahun", "link_json",
    }
    if not catalog_fields.issubset(fieldnames):
        raise RuntimeError("CSV tidak memiliki kolom katalog BPS yang dibutuhkan.")

    matched = 0
    added_tables = 0
    added_rows = 0
    added_periods = 0
    skipped = 0
    updated_row_ids: set[int] = set()
    simdasi_source_ids = {
        match.group(1)
        for row in rows
        if row.get("jenis_tabel") == "SIMDASI"
        and (match := re.search(r"/datasource/simdasi/id/(\d+)/", str(row.get("link_json") or "")))
    }
    if len(simdasi_source_ids) > 1:
        raise RuntimeError("CSV memuat beberapa ID sumber SIMDASI; sumber tabel baru tidak dapat ditentukan.")
    simdasi_source_id = next(iter(simdasi_source_ids), "")

    existing_by_table: dict[tuple[str, str], list[dict[str, str]]] = defaultdict(list)
    existing_by_title: dict[tuple[str, str], list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        source_code = SOURCE_BY_TYPE.get(str(row.get("jenis_tabel") or "").strip())
        table_id = str(row.get("id_tabel") or "").strip()
        title = str(row.get("judul") or "").strip()
        if source_code and table_id:
            existing_by_table[(source_code, table_id)].append(row)
        if source_code and title:
            existing_by_title[(source_code, _title_key(title))].append(row)

    period_cache: dict[str, list[dict[str, str]]] = {}
    processed_tables: set[tuple[str, str]] = set()
    for table in tables:
        source_code = str(table.get("tablesource") or "").strip()
        raw_id = str(table.get("id") or "").strip()
        title = str(table.get("title") or "").strip()
        if source_code not in TYPE_BY_SOURCE or not raw_id or not title:
            skipped += 1
            continue

        table_id = _decoded_prefix(raw_id)
        if not table_id:
            skipped += 1
            continue
        table_key = (source_code, table_id)
        if table_key in processed_tables:
            continue
        processed_tables.add(table_key)

        matching_rows = existing_by_table.get(table_key, [])
        if not matching_rows:
            matching_rows = existing_by_title.get((source_code, _title_key(title)), [])
        if matching_rows:
            matched += 1
            for row in matching_rows:
                url = (
                    f"{BASE}/id/statistics-table/{source_code}/"
                    f"{quote(raw_id, safe='=')}/{slug_judul(title)}.html"
                )
                updated = False
                for field, value in (
                    ("judul", title),
                    ("kategori_subjek", str(table.get("subcat_name") or "").strip()),
                    ("subjek", str(table.get("subject") or "").strip()),
                    ("id_subjek", str(table.get("id_subject") or "").strip()),
                    ("id_raw", raw_id),
                    ("url_halaman", url),
                ):
                    if value and row.get(field, "") != value:
                        row[field] = value
                        updated = True
                if source_code == "1":
                    static_link = (
                        f"{API_BASE}/view/model/statictable/domain/{domain}/id/{table_id}/lang/ind/key/"
                    )
                    if row.get("link_json") != static_link:
                        row["link_json"] = static_link
                        updated = True
                elif source_code == "3" and simdasi_source_id:
                    row_year = str(row.get("tahun") or "").strip()
                    if row_year.isdecimal():
                        link = _simdasi_link(simdasi_source_id, domain, row_year, table_id)
                        if row.get("link_json") != link:
                            row["link_json"] = link
                            updated = True
                if updated:
                    updated_row_ids.add(id(row))
            existing_years = {
                str(row.get("tahun") or "").strip()
                for row in matching_rows
                if str(row.get("tahun") or "").strip().isdecimal()
            }
        else:
            existing_years = set()
            added_tables += 1

        pending_rows: list[dict[str, str]] = []
        if source_code == "1":
            if not matching_rows:
                table_link = (
                    f"{API_BASE}/view/model/statictable/domain/{domain}/id/{table_id}/lang/ind/key/"
                )
                pending_rows.append(_new_row(table, source_code, table_id, "", table_link))
        elif source_code == "2":
            latest_year = str(table.get("latest_period") or "").strip()
            needs_period_refresh = not matching_rows or (
                latest_year.isdecimal()
                and (not existing_years or int(latest_year) > max(map(int, existing_years)))
            )
            if needs_period_refresh:
                periods = period_cache.get(table_id)
                if periods is None:
                    periods = _fetch_periods(client, api_key, domain, table_id)
                    period_cache[table_id] = periods
                period_ids = {period["year"]: period["period_id"] for period in periods}
                if not periods and not matching_rows:
                    raise RuntimeError(f"WebAPI BPS tidak mengembalikan periode tabel dinamis {table_id}.")
                for row in matching_rows:
                    row_year = str(row.get("tahun") or "").strip()
                    period_id = period_ids.get(row_year)
                    if period_id is None:
                        continue
                    link = (
                        f"{API_BASE}/list/model/data/domain/{domain}/var/{table_id}"
                        f"/th/{period_id}/key/"
                    )
                    if row.get("link_json") != link:
                        row["link_json"] = link
                        updated_row_ids.add(id(row))
                for period in periods:
                    year = period["year"]
                    if year in existing_years:
                        continue
                    table_link = (
                        f"{API_BASE}/list/model/data/domain/{domain}/var/{table_id}"
                        f"/th/{period['period_id']}/key/"
                    )
                    pending_rows.append(_new_row(table, source_code, table_id, year, table_link))
                if latest_year.isdecimal() and latest_year not in period_ids:
                    raise RuntimeError(f"Periode terbaru tabel dinamis {table_id} tidak ditemukan pada WebAPI BPS.")
        elif source_code == "3":
            latest_year = str(table.get("latest_period") or "").strip()
            if not simdasi_source_id:
                simdasi_source_id = str(table.get("id_datasource") or table.get("datasource_id") or "").strip()
            if not simdasi_source_id:
                raise RuntimeError("ID sumber SIMDASI tidak tersedia dari katalog maupun CSV.")
            if not latest_year.isdecimal():
                skipped += 1
                continue
            if latest_year not in existing_years:
                table_link = _simdasi_link(simdasi_source_id, domain, latest_year, table_id)
                pending_rows.append(_new_row(table, source_code, table_id, latest_year, table_link))

        if pending_rows:
            rows.extend(pending_rows)
            added_rows += len(pending_rows)
            added_periods += sum(bool(row["tahun"]) for row in pending_rows)
            for row in pending_rows:
                row_source = SOURCE_BY_TYPE[row["jenis_tabel"]]
                existing_by_table[(row_source, row["id_tabel"])].append(row)
                existing_by_title[(row_source, _title_key(row["judul"]))].append(row)

    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(
        "w",
        encoding="utf-8-sig",
        newline="",
        dir=path.parent,
        suffix=".tmp",
        delete=False,
    ) as target:
        temp_path = Path(target.name)
        writer = csv.DictWriter(target, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)
    temp_path.replace(path)
    return {
        "matched_tables": matched,
        "skipped_tables": skipped,
        "added_tables": added_tables,
        "added_rows": added_rows,
        "added_periods": added_periods,
        "updated_rows": len(updated_row_ids),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--csv", type=Path, default=CSV_PATH)
    parser.add_argument("--domain", default="1600")
    args = parser.parse_args()

    load_dotenv(Path(__file__).resolve().parent / ".env")
    api_key = os.getenv("BPS_API_KEY", "").strip()
    if not api_key:
        print("BPS_API_KEY belum dikonfigurasi.", file=sys.stderr)
        return 2
    if not args.domain.isdecimal():
        print("Domain BPS harus berupa angka.", file=sys.stderr)
        return 2

    try:
        with httpx.Client(timeout=20, follow_redirects=True) as client:
            tables = _fetch_tables(client, api_key, args.domain)
            if not tables:
                raise RuntimeError("WebAPI BPS mengembalikan katalog tabel kosong; CSV tidak diubah.")
            summary = sync_catalog(
                args.csv,
                tables,
                client,
                api_key,
                args.domain,
            )
    except (OSError, RuntimeError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        return 1

    print(json.dumps({
        "status": "ok",
        "csv": args.csv.name,
        "api_tables": len(tables),
        **summary,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
