"""Audit catalog WebAPI links without modifying the source CSV."""

import argparse
import csv
import os
import re
import sys
import time
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import quote, urlsplit, urlunsplit

import httpx
from dotenv import load_dotenv

import bps_csv_catalog


load_dotenv()
WIB = timezone(timedelta(hours=7))
_ALLOWED_HOST = "webapi.bps.go.id"


def _request_url(link: str, api_key: str) -> str:
    parsed = urlsplit(link)
    if (
        parsed.scheme != "https"
        or parsed.hostname != _ALLOWED_HOST
        or not parsed.path.startswith("/v1/api/")
        or not parsed.path.rstrip("/").endswith("/key")
        or parsed.query
        or parsed.fragment
    ):
        raise ValueError("Tautan katalog bukan endpoint WebAPI BPS yang valid.")
    path = parsed.path.rstrip("/") + "/"
    return urlunsplit((
        parsed.scheme,
        parsed.netloc,
        path + quote(api_key, safe="") + "/",
        "",
        "",
    ))


def _has_payload_data(payload: dict) -> bool:
    def has_content(value: object) -> bool:
        if isinstance(value, dict):
            if "table" in value:
                table_html = value.get("table")
                return isinstance(table_html, str) and (
                    len(re.findall(r"<tr\b", table_html, re.IGNORECASE)) > 1
                )
            if "datacontent" in value:
                return bool(value.get("datacontent"))
            if "data" in value:
                return has_content(value.get("data"))
            return bool(value)
        if isinstance(value, (list, tuple)):
            return bool(value)
        return value is not None and bool(str(value).strip())

    data = payload.get("data")
    if isinstance(data, list):
        if len(data) < 2:
            return False
        return has_content(data[1])
    if isinstance(data, dict):
        return has_content(data)
    return bool(data)


def _check_link(
    client: httpx.Client,
    link: str,
    api_key: str,
    retries: int,
    retry_delay: float,
) -> tuple[str, str]:
    try:
        url = _request_url(link, api_key)
    except ValueError as exc:
        return "gagal", str(exc)

    for attempt in range(retries + 1):
        try:
            response = client.get(url)
        except httpx.RequestError as exc:
            detail = f"gagal koneksi ({type(exc).__name__})"
            if attempt < retries:
                time.sleep(retry_delay)
                continue
            return "gagal", detail

        if response.status_code >= 400:
            detail = f"HTTP {response.status_code}"
            if attempt < retries and (response.status_code == 429 or response.status_code >= 500):
                time.sleep(retry_delay)
                continue
            return "gagal", detail

        if response.headers.get("data-availability", "").casefold() == "list-not-available":
            return "kosong", f"HTTP {response.status_code}; tidak tersedia"
        try:
            payload = response.json()
        except ValueError:
            return "gagal", f"HTTP {response.status_code}; JSON tidak valid"
        if not isinstance(payload, dict) or payload.get("status") != "OK":
            return "gagal", f"HTTP {response.status_code}; respons API tidak valid"
        if not _has_payload_data(payload):
            return "kosong", f"HTTP {response.status_code}; data kosong"
        return "ok", f"HTTP {response.status_code}"
    return "gagal", "percobaan ulang habis"


def _target_indices(rows: list[dict[str, str]], args: argparse.Namespace) -> list[int]:
    if args.sample_per_type is not None:
        counts: Counter[str] = Counter()
        selected = []
        for index, row in enumerate(rows):
            table_type = row.get("jenis_tabel", "")
            if counts[table_type] < args.sample_per_type:
                selected.append(index)
                counts[table_type] += 1
        return selected
    if args.limit is not None:
        return list(range(min(args.limit, len(rows))))
    return list(range(len(rows)))


def run_audit(args: argparse.Namespace) -> int:
    api_key = os.getenv("BPS_API_KEY", "").strip()
    if not api_key:
        print("BPS_API_KEY belum dikonfigurasi di environment.", file=sys.stderr)
        return 2

    source_path = bps_csv_catalog._catalog_path()
    with source_path.open(encoding="utf-8-sig", newline="") as source:
        reader = csv.DictReader(source)
        fieldnames = list(reader.fieldnames or [])
        rows = list(reader)
    if not fieldnames or not {"jenis_tabel", "link_json"}.issubset(fieldnames):
        print("CSV katalog tidak memiliki kolom wajib untuk audit.", file=sys.stderr)
        return 2

    for field in ("status_tarik", "status_detail", "dicek_pada"):
        if field not in fieldnames:
            fieldnames.append(field)
    indices = _target_indices(rows, args)
    checked_by_type: dict[str, Counter[str]] = defaultdict(Counter)
    checked_at = datetime.now(WIB).isoformat(timespec="seconds")

    with httpx.Client(timeout=args.timeout, follow_redirects=False) as client:
        for position, index in enumerate(indices):
            row = rows[index]
            status, detail = _check_link(
                client,
                str(row.get("link_json") or ""),
                api_key,
                args.retries,
                args.retry_delay,
            )
            row["status_tarik"] = status
            row["status_detail"] = detail[:160]
            row["dicek_pada"] = checked_at
            checked_by_type[row.get("jenis_tabel", "")][status] += 1
            if args.delay and position + 1 < len(indices):
                time.sleep(args.delay)

    output_path = Path(args.output).expanduser() if args.output else (
        bps_csv_catalog._audit_path(source_path)
    )
    with output_path.open("w", encoding="utf-8-sig", newline="") as target:
        writer = csv.DictWriter(target, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)

    selected_count = len(indices)
    print(f"Audit selesai: {selected_count} tautan diperiksa dari {len(rows)} baris.")
    print(f"Hasil: {output_path}")
    for table_type in sorted({row.get("jenis_tabel", "") for row in rows}):
        counts = checked_by_type[table_type]
        remaining = sum(row.get("jenis_tabel", "") == table_type for row in rows) - sum(counts.values())
        print(
            f"{table_type or '(tanpa jenis)'}: "
            f"ok={counts['ok']}, kosong={counts['kosong']}, gagal={counts['gagal']}, "
            f"belum_diperiksa={remaining}"
        )
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", help="Output CSV terpisah; default: *_audit.csv.")
    parser.add_argument("--delay", type=float, default=1.0, help="Jeda antar tautan (default 1 detik).")
    parser.add_argument("--retry-delay", type=float, default=2.0, help="Jeda antar retry.")
    parser.add_argument("--retries", type=int, default=2, help="Jumlah retry setelah percobaan awal.")
    parser.add_argument("--timeout", type=float, default=15.0, help="Timeout HTTP dalam detik.")
    scope = parser.add_mutually_exclusive_group()
    scope.add_argument("--limit", type=int, help="Batasi ke N baris pertama (untuk uji sampel).")
    scope.add_argument(
        "--sample-per-type",
        type=int,
        help="Periksa maksimal N tautan pertama untuk setiap jenis tabel.",
    )
    args = parser.parse_args()
    if args.delay < 0 or args.retry_delay < 0 or args.retries < 0 or args.timeout <= 0:
        parser.error("Jeda dan retry tidak boleh negatif; timeout harus lebih dari nol.")
    if args.limit is not None and args.limit < 0:
        parser.error("--limit tidak boleh negatif.")
    if args.sample_per_type is not None and args.sample_per_type < 0:
        parser.error("--sample-per-type tidak boleh negatif.")
    return run_audit(args)


if __name__ == "__main__":
    raise SystemExit(main())
