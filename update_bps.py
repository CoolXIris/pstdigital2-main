"""Enrich the BPS CSV with original table IDs and verified page URL patterns."""

from __future__ import annotations

import argparse
import base64
import csv
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


def _lookup(tables: list[dict[str, str]]) -> dict[tuple[str, str], list[dict[str, str]]]:
    index: dict[tuple[str, str], list[dict[str, str]]] = defaultdict(list)
    for table in tables:
        source = str(table.get("tablesource") or "")
        raw_id = str(table.get("id") or "").strip()
        title = str(table.get("title") or "").strip()
        if source in {"1", "2", "3"} and raw_id and title:
            index[(source, _title_key(title))].append({
                "id_raw": raw_id,
                "judul_api": title,
            })
    return index


def enrich_csv(path: Path, tables: list[dict[str, str]]) -> tuple[int, int, int]:
    with path.open(encoding="utf-8-sig", newline="") as source:
        reader = csv.DictReader(source)
        fieldnames = list(reader.fieldnames or [])
        rows = list(reader)
    if not {"judul", "id_tabel", "jenis_tabel"}.issubset(fieldnames):
        raise RuntimeError("CSV harus memiliki kolom judul, id_tabel, dan jenis_tabel.")

    for field in ("id_raw", "url_halaman"):
        if field not in fieldnames:
            fieldnames.append(field)

    index = _lookup(tables)
    changed = 0
    matched = 0
    unmatched = 0
    for row in rows:
        source_code = SOURCE_BY_TYPE.get(str(row.get("jenis_tabel") or "").strip())
        title = str(row.get("judul") or "").strip()
        matches = index.get((source_code or "", _title_key(title)), [])
        if len(matches) > 1:
            table_id = str(row.get("id_tabel") or "").strip()
            matches = [
                item for item in matches
                if _decoded_prefix(item["id_raw"]) == table_id
                or item["id_raw"] == table_id
            ]

        id_raw = matches[0]["id_raw"] if len(matches) == 1 else ""
        api_title = matches[0]["judul_api"] if len(matches) == 1 else title
        url = (
            f"{BASE}/id/statistics-table/{source_code}/"
            f"{quote(id_raw, safe='=')}/{slug_judul(api_title)}.html"
            if id_raw and source_code
            else ""
        )
        if id_raw:
            matched += 1
        else:
            unmatched += 1
        if row.get("id_raw", "") != id_raw or row.get("url_halaman", "") != url:
            changed += 1
        row["id_raw"] = id_raw
        row["url_halaman"] = url

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
    return matched, unmatched, changed


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
        matched, unmatched, changed = enrich_csv(args.csv, tables)
    except (OSError, RuntimeError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        return 1

    print(
        f"CSV diperbarui: {args.csv.name}; tabel API={len(tables)}, "
        f"baris dipetakan={matched}, belum cocok={unmatched}, baris berubah={changed}."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
