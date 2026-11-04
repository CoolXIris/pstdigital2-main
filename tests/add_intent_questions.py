"""Menambahkan variasi pertanyaan niat (definisi, sapaan, saran) ke tests/questions.jsonl.

Idempoten: hanya menambah id yang belum ada. Tidak mengubah baris yang sudah ada.
"""
from __future__ import annotations

import json
from pathlib import Path

TESTS_DIR = Path(__file__).resolve().parent
QUESTIONS = TESTS_DIR / "questions.jsonl"

DEFINISI = [
    "Apa itu inflasi?", "apa sih IPM?", "Apa yang dimaksud dengan kemiskinan?", "pengertian TPT itu apa?",
    "Jelaskan arti Gini Ratio", "definisi PDRB harga konstan", "artinya NTP apa ya?", "apaan sih garis kemiskinan",
    "Tolong terangkan konsep angkatan kerja", "kepanjangan BRS itu apa", "makna indeks pembangunan manusia",
    "apa perbedaan PDRB harga berlaku dan konstan?", "cara menghitung laju pertumbuhan ekonomi", "rumus inflasi itu gimana",
    "apa itu pengangguran terbuka", "Apa maksud dari rata-rata lama sekolah?", "pengertian umur harapan hidup",
    "apa itu SIMDASI", "jelasin dong apa itu NTP", "apa itu stunting menurut statistik",
    "Apa itu sensus penduduk?", "definisi tingkat partisipasi angkatan kerja", "apa yang dimaksud upah minimum",
    "bagaimana cara membaca angka inflasi", "apa itu angka kemiskinan", "arti persentase penduduk miskin",
    "apa artinya ASN", "apa itu indeks harga konsumen", "pngertian pdrb itu apa", "aoa itu inflasi",
    "istilah rasio ketergantungan maksudnya apa", "Apa itu produk domestik regional bruto?",
]

SAPAAN = [
    "Halo", "hai", "selamat pagi", "Selamat siang!", "selamat sore min", "selamat malam", "Assalamualaikum",
    "terima kasih", "makasih ya", "makasih banyak", "terimakasih atas bantuannya", "trims", "thanks", "thx",
    "oke sip", "siap, makasih", "mantap, terima kasih", "ok makasih", "sampai jumpa", "dadah", "bye",
    "cukup itu saja, terima kasih", "halo apa kabar?", "hi, selamat pagi", "terima kasihhh", "makasihhh banyak",
    "baik, terima kasih ya", "permisi", "halo min", "hello", "hey", "tq", "makasih banyak bantuannya",
]

SARAN = [
    "Beri saran cara memilih data statistik", "tolong berikan rekomendasi menyusun laporan", "kasih saran dong buat riset",
    "minta saran memahami tabel BPS", "bisa minta panduan menggunakan data BPS?", "saran untuk membuat presentasi data",
    "Tolong beri masukan menulis analisis statistik", "rekomendasi cara mencari data kemiskinan", "bagaimana cara minta data ke BPS",
    "di mana mencari data statistik", "cara mendapatkan data BPS", "ada tips memilih indikator untuk skripsi?",
    "mohon nasihat merancang survei", "minta rekomendasi memahami grafik statistik", "beri panduan mengakses publikasi",
    "saran memilih tabel yang tepat", "kasih tips membaca data BPS", "tolong anjuran menggunakan data untuk penelitian",
    "berikan saran menyusun proposal dengan data statistik", "butuh saran cara menggunakan layanan statistik",
    "gimana cara minta data permintaan khusus", "minta petunjuk memperoleh data", "saran dong cara menganalisis tren",
    "tolong kasih masukan merancang kuesioner", "bisakah beri rekomendasi memilih data", "beri nasihat menulis laporan data",
    "cara mengunduh publikasi BPS", "bagaimana prosedur layanan permintaan data", "minta sarannya memahami angka inflasi",
    "rekomendasi buat belajar statistik", "Kasih saran cara memakai data BPS untuk bisnis",
]


def build() -> list[dict]:
    rows = []
    for prefix, questions in (("def", DEFINISI), ("sapa", SAPAAN), ("saran", SARAN)):
        for index, question in enumerate(questions, 1):
            rows.append({
                "ekspektasi": {"tabel": [], "tahun": [], "tanpa_data": False, "tipe": "general", "wilayah": []},
                "id": f"intent-{prefix}-{index:02d}",
                "kategori": "pertanyaan_umum",
                "kunci_jawaban": [],
                "pertanyaan": question,
                "split": "train",
                "status_kunci": "ready",
                "tingkat_kesulitan": "mudah",
            })
    return rows


def main() -> None:
    existing = QUESTIONS.read_text(encoding="utf-8")
    known = {json.loads(line)["id"] for line in existing.splitlines() if line.strip()}
    additions = [row for row in build() if row["id"] not in known]
    if not additions:
        print("Tidak ada pertanyaan baru.")
        return
    with QUESTIONS.open("a", encoding="utf-8", newline="\n") as handle:
        if not existing.endswith("\n"):
            handle.write("\n")
        for row in additions:
            handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")
    print(f"Ditambahkan {len(additions)} pertanyaan.")


if __name__ == "__main__":
    main()
