"""Mengambil SELURUH katalog data WebAPI BPS untuk satu domain (bawaan: 1600, Sumatera Selatan).

Hasil:
  katalog_subjek_<domain>.csv    kategori -> subjek (dengan jumlah tabel)
  katalog_variabel_<domain>.csv  semua variabel dinamis: subjek, var_id, judul, satuan, tahun_terbaru
  katalog_ringkasan_<domain>.txt jumlah entri tiap jenis data (tabel statis, publikasi, BRS, berita, dst.)

Pemakaian (butuh BPS_API_KEY di .env atau variabel lingkungan):
  python dump_bps_catalog.py                 # cepat: tanpa tahun terbaru
  python dump_bps_catalog.py --tahun         # lengkap: ikut mengambil tahun terbaru tiap variabel
  python dump_bps_catalog.py --domain 0000   # katalog nasional
  python dump_bps_catalog.py --kerja 4       # kurangi paralelisme jika API menolak (429)
"""
import argparse
import csv
import os
import sys
import time
from concurrent.futures import ThreadPoolExecutor

import httpx
from dotenv import load_dotenv

BASE = "https://webapi.bps.go.id/v1/api"
OTHER_MODELS = {
    "statictable": "Tabel statis",
    "publication": "Publikasi",
    "pressrelease": "Berita Resmi Statistik",
    "news": "Berita",
    "infographic": "Infografis",
    "indicators": "Indikator strategis (hanya domain pusat/provinsi)",
}


class Catalog:
    def __init__(self, client: httpx.Client, api_key: str, domain: str):
        self.client = client
        self.key = api_key
        self.domain = domain

    def get(self, path: str):
        url = f"{BASE}/{path.strip('/')}/key/{self.key}/"
        for attempt in range(3):
            try:
                response = self.client.get(url)
                response.raise_for_status()
                payload = response.json()
                return payload if payload.get("status") == "OK" else None
            except Exception:
                # Sengaja tanpa pesan asli: URL pada exception memuat API key.
                time.sleep(1.5 * (attempt + 1))
        return None

    def pages(self, path: str) -> list[dict]:
        rows: list[dict] = []
        page = 1
        while True:
            payload = self.get(path if page == 1 else f"{path}/page/{page}")
            data = payload.get("data") if payload else None
            if not (isinstance(data, list) and len(data) >= 2 and isinstance(data[1], list)):
                break
            rows += data[1]
            if page >= int((data[0] or {}).get("pages", 1) or 1):
                break
            page += 1
        return rows

    def total(self, model: str) -> str:
        payload = self.get(f"list/model/{model}/domain/{self.domain}")
        data = payload.get("data") if payload else None
        if isinstance(data, list) and data and isinstance(data[0], dict):
            return str(data[0].get("total", "?"))
        return "tidak tersedia"

    def latest_year(self, var_id) -> tuple[str, int]:
        payload = self.get(f"list/model/th/domain/{self.domain}/var/{var_id}")
        data = payload.get("data") if payload else None
        years = [str(r["th"]) for r in (data[1] if isinstance(data, list) and len(data) > 1 else []) if str(r.get("th", "")).isdigit()]
        return (max(years, key=int), len(years)) if years else ("", 0)


def run(catalog: Catalog, with_years: bool, workers: int, out_dir: str = ".") -> None:
    d = catalog.domain
    print(f"[1/4] Kategori dan subjek domain {d} ...")
    subjects = catalog.pages(f"list/model/subject/domain/{d}")
    with open(os.path.join(out_dir, f"katalog_subjek_{d}.csv"), "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["subcat_id", "kategori", "sub_id", "subjek", "jumlah_tabel"])
        for s in subjects:
            w.writerow([s.get("subcat_id"), s.get("subcat"), s.get("sub_id"), s.get("title"), s.get("ntabel") or s.get("ntable")])
    print(f"      {len(subjects)} subjek")

    print("[2/4] Variabel per subjek ...")

    def vars_for(subject: dict) -> list[dict]:
        rows = catalog.pages(f"list/model/var/domain/{d}/subject/{subject['sub_id']}")
        return [{"kategori": subject.get("subcat"), "sub_id": subject.get("sub_id"), "subjek": subject.get("title"), **r} for r in rows]

    with ThreadPoolExecutor(max_workers=workers) as pool:
        grouped = list(pool.map(vars_for, subjects))
    variables = [v for group in grouped for v in group]

    seen = {int(v["var_id"]) for v in variables if str(v.get("var_id", "")).isdigit()}
    extra = [r for r in catalog.pages(f"list/model/var/domain/{d}") if str(r.get("var_id", "")).isdigit() and int(r["var_id"]) not in seen]
    for r in extra:
        variables.append({"kategori": "(tanpa subjek)", "sub_id": "", "subjek": "", **r})
    print(f"      {len(variables)} variabel ({len(extra)} di luar subjek)")

    latest: dict[int, tuple[str, int]] = {}
    if with_years:
        print("[3/4] Tahun terbaru tiap variabel (bisa lama) ...")
        ids = sorted({int(v["var_id"]) for v in variables if str(v.get("var_id", "")).isdigit()})
        with ThreadPoolExecutor(max_workers=workers) as pool:
            for var_id, result in zip(ids, pool.map(catalog.latest_year, ids)):
                latest[var_id] = result
    else:
        print("[3/4] Dilewati (tambahkan --tahun untuk tahun terbaru)")

    with open(os.path.join(out_dir, f"katalog_variabel_{d}.csv"), "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["kategori", "sub_id", "subjek", "var_id", "judul", "satuan", "tahun_terbaru", "jumlah_tahun"])
        for v in variables:
            vid = int(v["var_id"]) if str(v.get("var_id", "")).isdigit() else None
            year, count = latest.get(vid, ("", ""))
            w.writerow([v.get("kategori"), v.get("sub_id"), v.get("subjek"), v.get("var_id"), v.get("title"), v.get("unit"), year, count])

    print("[4/4] Jumlah entri jenis data lain ...")
    lines = [f"Domain {d}", f"Subjek: {len(subjects)}", f"Variabel dinamis: {len(variables)}"]
    for model, label in OTHER_MODELS.items():
        lines.append(f"{label} ({model}): {catalog.total(model)}")
    with open(os.path.join(out_dir, f"katalog_ringkasan_{d}.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")
    print("\n".join(lines))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--domain", default="1600")
    parser.add_argument("--tahun", action="store_true", help="ambil tahun terbaru tiap variabel")
    parser.add_argument("--kerja", type=int, default=6, help="jumlah permintaan paralel")
    args = parser.parse_args()

    load_dotenv()
    api_key = os.getenv("BPS_API_KEY")
    if not api_key:
        sys.exit("BPS_API_KEY belum diisi (.env atau variabel lingkungan).")
    with httpx.Client(timeout=15.0) as client:
        run(Catalog(client, api_key, args.domain), args.tahun, args.kerja)


if __name__ == "__main__":
    main()
