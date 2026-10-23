"""Validate a deterministic random sample of BPS page URLs and prior failures."""

from __future__ import annotations

import argparse
import csv
import random
import sys
import time
from collections import Counter
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

import httpx

ROOT = Path(__file__).resolve().parent
DEFAULT_CSV = ROOT / "tabel_bps_sumsel.csv"
DEFAULT_AUDIT = ROOT / "tabel_bps_sumsel_audit.csv"
AUDIT_FIELDS = (
    "kategori_subjek",
    "subjek",
    "id_subjek",
    "judul",
    "id_tabel",
    "jenis_tabel",
    "tahun",
    "link_json",
    "url_halaman",
    "status_tarik",
    "status_detail",
    "dicek_pada",
    "status_link",
    "status_link_detail",
    "dicek_link_pada",
)


def _read(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open(encoding="utf-8-sig", newline="") as source:
        reader = csv.DictReader(source)
        return list(reader.fieldnames or []), list(reader)


def _link_identity(row: dict[str, str]) -> tuple[str, str, str]:
    return (
        str(row.get("id_tabel") or ""),
        str(row.get("tahun") or ""),
        str(row.get("link_json") or ""),
    )


def _sample_rows(
    catalog: list[dict[str, str]],
    previous_audit: list[dict[str, str]],
    sample_size: int,
    seed: int,
) -> list[dict[str, str]]:
    by_url: dict[str, dict[str, str]] = {}
    for row in catalog:
        url = str(row.get("url_halaman") or "").strip()
        if url.startswith("https://sumsel.bps.go.id/"):
            by_url.setdefault(url, row)
    failed_urls = {
        str(row.get("url_halaman") or "").strip()
        for row in previous_audit
        if str(row.get("status_link") or "").casefold() in {"gagal", "tidak_valid"}
    }
    failed = [row for url, row in by_url.items() if url in failed_urls]
    remaining = [row for url, row in by_url.items() if url not in failed_urls]
    rng = random.Random(seed)
    selected = failed + rng.sample(remaining, min(sample_size, len(remaining)))
    return list({str(row["url_halaman"]): row for row in selected}.values())


def _check(client: httpx.Client, url: str) -> tuple[str, str]:
    try:
        response = client.get(url)
    except httpx.HTTPError as exc:
        return "gagal", f"Kesalahan jaringan: {type(exc).__name__}"
    final_host = (response.url.host or "").lower()
    if response.status_code == 200 and final_host == "sumsel.bps.go.id":
        return "ok", "HTTP 200; halaman BPS Sumatera Selatan"
    if response.status_code in {401, 403} or response.status_code >= 500:
        return "gagal", f"HTTP {response.status_code}; akses/layanan tidak dapat diverifikasi"
    return "tidak_valid", f"HTTP {response.status_code}; host akhir {final_host or 'tidak diketahui'}"


def run(
    csv_path: Path,
    audit_path: Path,
    *,
    sample_size: int = 50,
    delay: float = 0.5,
    seed: int = 20261023,
) -> dict[str, Counter[str]]:
    _, catalog = _read(csv_path)
    old_fields, old_audit = _read(audit_path) if audit_path.is_file() else ([], [])
    selected = _sample_rows(catalog, old_audit, sample_size, seed)
    checked_at = datetime.now(ZoneInfo("Asia/Jakarta")).isoformat(timespec="seconds")

    statuses: dict[str, tuple[str, str]] = {}
    headers = {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
        ),
        "Accept": "text/html,application/xhtml+xml",
    }
    with httpx.Client(timeout=20, follow_redirects=True, headers=headers) as client:
        for index, row in enumerate(selected):
            url = str(row["url_halaman"])
            statuses[url] = _check(client, url)
            if index + 1 < len(selected):
                time.sleep(max(0.0, delay))

    prior_by_identity = {_link_identity(row): row for row in old_audit}
    output: list[dict[str, str]] = []
    for row in catalog:
        identity = _link_identity(row)
        audited = {**prior_by_identity.get(identity, {}), **row}
        status = statuses.get(str(row.get("url_halaman") or ""))
        if status:
            audited["status_link"], audited["status_link_detail"] = status
            audited["dicek_link_pada"] = checked_at
        output.append({field: str(audited.get(field) or "") for field in AUDIT_FIELDS})

    audit_path.parent.mkdir(parents=True, exist_ok=True)
    with audit_path.open("w", encoding="utf-8-sig", newline="") as target:
        writer = csv.DictWriter(target, fieldnames=AUDIT_FIELDS)
        writer.writeheader()
        writer.writerows(output)

    summary: dict[str, Counter[str]] = {}
    status_by_url = {url: status for url, (status, _) in statuses.items()}
    for row in selected:
        kind = str(row.get("jenis_tabel") or "lainnya")
        summary.setdefault(kind, Counter())[status_by_url[str(row["url_halaman"])]] += 1
    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--csv", type=Path, default=DEFAULT_CSV)
    parser.add_argument("--audit", type=Path, default=DEFAULT_AUDIT)
    parser.add_argument("--sample", type=int, default=50)
    parser.add_argument("--delay", type=float, default=0.5)
    parser.add_argument("--seed", type=int, default=20261023)
    args = parser.parse_args()
    if args.sample < 0 or args.delay < 0:
        parser.error("--sample dan --delay tidak boleh negatif.")
    try:
        summary = run(args.csv, args.audit, sample_size=args.sample, delay=args.delay, seed=args.seed)
    except (OSError, csv.Error, httpx.HTTPError) as exc:
        print(f"Gagal memeriksa tautan: {type(exc).__name__}", file=sys.stderr)
        return 1
    print("Hasil pemeriksaan URL:")
    for kind, counts in sorted(summary.items()):
        print(
            f"- {kind}: ok={counts.get('ok', 0)}, "
            f"tidak_valid={counts.get('tidak_valid', 0)}, gagal={counts.get('gagal', 0)}"
        )
    print(
        f"Audit disimpan ke {args.audit.name}; "
        f"baris diperiksa={sum(sum(counts.values()) for counts in summary.values())}."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
