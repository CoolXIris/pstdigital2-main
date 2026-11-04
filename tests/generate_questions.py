"""Generate a deterministic, table-stratified chatbot question bank."""

from __future__ import annotations

import csv
import difflib
import hashlib
import os
import random
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any

from test_harness import ROOT, TESTS_DIR, load_local_environment, stable_split, write_jsonl

sys.path.insert(0, str(ROOT))

SEED = 20261022
QUESTIONS_PER_SUBJECT = 8


def read_catalog() -> list[dict[str, str]]:
    path = ROOT / "tabel_bps_sumsel.csv"
    with path.open(encoding="utf-8-sig", newline="") as source:
        reader = csv.DictReader(source)
        required = {
            "subjek",
            "judul",
            "id_tabel",
            "jenis_tabel",
            "tahun",
            "link_json",
        }
        if not required.issubset(set(reader.fieldnames or [])):
            raise RuntimeError("Kolom wajib katalog CSV tidak lengkap.")
        return [
            {key: str(value or "").strip() for key, value in row.items() if key}
            for row in reader
            if row.get("judul") and row.get("id_tabel")
        ]


def connect_for_read_only_keys():
    """Open the existing MySQL source for SELECT-only independent answer keys."""
    import pymysql
    from pymysql.cursors import DictCursor

    import bps_data_store

    settings = bps_data_store._mysql_settings()
    return pymysql.connect(
        **settings,
        connect_timeout=10,
        read_timeout=30,
        cursorclass=DictCursor,
        autocommit=True,
    )


def read_cached_values() -> tuple[dict[str, list[dict[str, Any]]], str | None]:
    load_local_environment()
    connection = connect_for_read_only_keys()
    try:
        with connection.cursor() as cursor:
            cursor.execute("SHOW TABLES")
            table_names = {str(next(iter(row.values()))) for row in cursor.fetchall()}
            required = {"tabel_meta", "nilai_data"}
            if not required.issubset(table_names):
                raise RuntimeError("Tabel sumber independen tabel_meta/nilai_data tidak tersedia.")

            cursor.execute(
                """
                SELECT n.id_tabel, n.wilayah, n.turvar, n.tahun, n.nilai,
                       m.satuan, m.subjek
                FROM nilai_data AS n
                LEFT JOIN tabel_meta AS m ON m.id_tabel = n.id_tabel
                WHERE n.nilai IS NOT NULL
                """
            )
            grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
            for row in cursor.fetchall():
                grouped[str(row["id_tabel"])].append(dict(row))

            for values in grouped.values():
                values.sort(
                    key=lambda item: (
                        str(item.get("tahun") or ""),
                        str(item.get("wilayah") or ""),
                        str(item.get("turvar") or ""),
                    ),
                    reverse=True,
                )

            issue = None
            if "log_gagal" not in table_names:
                issue = "Migrasi log_gagal belum diterapkan; aplikasi BPS Python dapat gagal mengakses penyimpanan."
            return grouped, issue
    finally:
        connection.close()


def _key(row: dict[str, Any], table_id: str) -> dict[str, Any]:
    return {
        "id_tabel": table_id,
        "wilayah": str(row.get("wilayah") or ""),
        "kategori": str(row.get("turvar") or ""),
        "tahun": str(row.get("tahun") or ""),
        "nilai": row.get("nilai"),
        "satuan": str(row.get("satuan") or ""),
    }


def _question(
    *,
    question_id: str,
    text: str,
    category: str,
    row: dict[str, str],
    split: str,
    expected_keys: list[dict[str, Any]] | None = None,
    regions: list[str] | None = None,
    years: list[str] | None = None,
    key_status: str = "manual_review",
    difficulty: str = "sedang",
    no_data: bool = False,
) -> dict[str, Any]:
    table_ids = sorted({str(item["id_tabel"]) for item in expected_keys or []})
    if not table_ids and row.get("id_tabel"):
        table_ids = [row["id_tabel"]]
    return {
        "id": question_id,
        "pertanyaan": text,
        "kategori": category,
        "ekspektasi": {
            "tabel": table_ids if len(table_ids) != 1 else (table_ids[0] if table_ids else ""),
            "wilayah": regions or [],
            "tahun": years or [],
            "tipe": row.get("jenis_tabel", ""),
            "tanpa_data": no_data,
        },
        "tingkat_kesulitan": difficulty,
        "split": split,
        "status_kunci": key_status,
        "kunci_jawaban": expected_keys or [],
    }


def _training_ids(table_ids: set[str]) -> set[str]:
    ordered = sorted(table_ids, key=lambda item: hashlib.sha256(item.encode("utf-8")).digest())
    training_count = round(len(ordered) * 0.7)
    return set(ordered[:training_count])


def _same_dimension_rows(
    rows: list[dict[str, Any]],
    *,
    region: str | None = None,
    year: str | None = None,
    category: str | None = None,
) -> list[dict[str, Any]]:
    return [
        row for row in rows
        if (region is None or str(row.get("wilayah") or "") == region)
        and (year is None or str(row.get("tahun") or "") == year)
        and (category is None or str(row.get("turvar") or "") == category)
    ]


def build_questions(
    catalog: list[dict[str, str]],
    values: dict[str, list[dict[str, Any]]],
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    rng = random.Random(SEED)
    table_ids = {row["id_tabel"] for row in catalog}
    training_ids = _training_ids(table_ids)
    by_subject: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in catalog:
        if row.get("subjek"):
            by_subject[row["subjek"]].append(row)
    subjects = sorted(by_subject)

    selected: dict[str, dict[str, str]] = {}
    for subject in subjects:
        options = sorted(
            by_subject[subject],
            key=lambda row: (
                0 if row["id_tabel"] in values else 1,
                row["judul"].casefold(),
                row["id_tabel"],
            ),
        )
        selected[subject] = rng.choice(options[: min(20, len(options))])

    questions: list[dict[str, Any]] = []
    categories = (
        "dasar_satu_topik_satu_wilayah",
        "multi_wilayah_nama_mirip",
        "multi_topik",
        "rentang_tahun_tahun_tanpa_data",
        "variasi_bahasa",
        "sinonim_parafrase",
        "kata_generik_dominan",
        "tabel_statis_simdasi",
    )

    for subject_index, subject in enumerate(subjects):
        table = selected[subject]
        table_id = table["id_tabel"]
        title = table["judul"]
        table_rows = values.get(table_id, [])
        split = stable_split(table_id, training_ids)
        latest = table_rows[0] if table_rows else None
        latest_region = str(latest.get("wilayah") or "") if latest else ""
        latest_year = str(latest.get("tahun") or table.get("tahun") or "") if latest else str(table.get("tahun") or "")
        latest_category = str(latest.get("turvar") or "") if latest else ""
        default_key = [_key(latest, table_id)] if latest else []
        default_status = "ready" if default_key else "manual_review"

        variants: list[tuple[str, str, list[dict[str, Any]], list[str], list[str], str, bool]] = []
        variants.append((
            f"Berapa {title} di {latest_region or 'Sumatera Selatan'}"
            f"{f' pada tahun {latest_year}' if latest_year else ''}?",
            categories[0], default_key, [latest_region] if latest_region else [],
            [latest_year] if latest_year else [], default_status, False,
        ))

        same_year = _same_dimension_rows(table_rows, year=latest_year or None, category=latest_category or None)
        unique_regions = sorted({str(item.get("wilayah") or "") for item in same_year if item.get("wilayah")})
        similar_pairs = [
            (difflib.SequenceMatcher(None, first.casefold(), second.casefold()).ratio(), first, second)
            for index, first in enumerate(unique_regions)
            for second in unique_regions[index + 1:]
        ]
        similar_pairs = [pair for pair in similar_pairs if pair[0] >= 0.55]
        region_pair = (
            [max(similar_pairs, key=lambda pair: pair[0])[1], max(similar_pairs, key=lambda pair: pair[0])[2]]
            if similar_pairs else unique_regions[:2]
        )
        regional_keys = []
        if len(region_pair) == 2:
            for region in region_pair:
                match = next(item for item in same_year if str(item.get("wilayah") or "") == region)
                regional_keys.append(_key(match, table_id))
        variants.append((
            f"Bandingkan {title} di {' dan '.join(region_pair) if region_pair else latest_region or 'wilayah Sumatera Selatan'}"
            f"{f' tahun {latest_year}' if latest_year else ''}.",
            categories[1], regional_keys, region_pair,
            [latest_year] if latest_year else [], "ready" if regional_keys else "manual_review", False,
        ))

        other_subject = subjects[(subject_index + 1) % len(subjects)] if subjects else subject
        other_table = selected.get(other_subject, table)
        other_id = other_table["id_tabel"]
        other_rows = values.get(other_id, [])
        other_match = next(
            (
                item for item in other_rows
                if str(item.get("wilayah") or "") == latest_region
                and str(item.get("tahun") or "") == latest_year
            ),
            None,
        ) if latest_region and latest_year else None
        multi_keys = default_key + ([_key(other_match, other_id)] if other_match else [])
        multi_text = (
            f"Berapa {title} dan {other_table['judul']} di {latest_region or 'Sumatera Selatan'}"
            f"{f' tahun {latest_year}' if latest_year else ''}?"
        )
        variants.append((
            multi_text, categories[2], multi_keys if other_match else [],
            [latest_region] if other_match else [],
            [latest_year] if other_match else [],
            "ready" if other_match and default_key else "manual_review", False,
        ))

        years_for_dimension = sorted({
            str(item.get("tahun") or "")
            for item in table_rows
            if str(item.get("wilayah") or "") == latest_region
            and str(item.get("turvar") or "") == latest_category
            and str(item.get("tahun") or "")
        })
        range_keys: list[dict[str, Any]] = []
        year_range: list[str] = []
        if len(years_for_dimension) >= 2:
            year_range = [years_for_dimension[0], years_for_dimension[-1]]
            range_keys = [
                _key(item, table_id)
                for item in table_rows
                if str(item.get("wilayah") or "") == latest_region
                and str(item.get("turvar") or "") == latest_category
                and str(item.get("tahun") or "") in year_range
            ]
        variants.append((
            f"Tampilkan {title} di {latest_region or 'Sumatera Selatan'}"
            f"{f' dari tahun {year_range[0]} sampai {year_range[-1]}' if year_range else ' pada rentang tahun yang tersedia'}.",
            categories[3], range_keys, [latest_region] if range_keys else [],
            year_range, "ready" if range_keys else "manual_review", False,
        ))

        variants.append((
            f"Ada berapa {title} di {latest_region or 'Sumatera Selatan'}"
            f"{f' tahun {latest_year}' if latest_year else ''}?",
            categories[4], default_key, [latest_region] if latest_region else [],
            [latest_year] if latest_year else [], default_status, False,
        ))
        variants.append((
            f"Tolong cek data terbaru untuk {title} di {latest_region or 'Sumatera Selatan'}.",
            categories[5], default_key, [latest_region] if latest_region else [],
            [latest_year] if latest_year else [], default_status, False,
        ))
        variants.append((
            f"Berapa total jumlah {title} di {latest_region or 'Sumatera Selatan'}"
            f"{f' pada {latest_year}' if latest_year else ''}?",
            categories[6], default_key, [latest_region] if latest_region else [],
            [latest_year] if latest_year else [], default_status, False,
        ))
        variants.append((
            f"Bagaimana data {title} yang tersedia di {latest_region or 'Sumatera Selatan'}"
            f"{f' pada tahun {latest_year}' if latest_year else ''}?",
            categories[7], default_key, [latest_region] if latest_region else [],
            [latest_year] if latest_year else [], default_status, False,
        ))

        for variant_index, (text, category, keys, regions, years, status, no_data) in enumerate(variants):
            questions.append(_question(
                question_id=f"csv-{subject_index + 1:02d}-{variant_index + 1:02d}",
                text=text,
                category=category,
                row=table,
                split=split,
                expected_keys=keys,
                regions=regions,
                years=years,
                key_status=status,
                difficulty=("mudah" if variant_index in {0, 4} else "sulit" if variant_index in {2, 3} else "sedang"),
                no_data=no_data,
            ))

        questions.append(_question(
            question_id=f"csv-{subject_index + 1:02d}-09",
            text=f"Brp {title} di {latest_region or 'Sumatera Selatan'}"
            f"{f' tahun {latest_year}' if latest_year else ''}?",
            category="variasi_bahasa",
            row=table,
            split=split,
            expected_keys=default_key,
            regions=[latest_region] if latest_region else [],
            years=[latest_year] if latest_year else [],
            key_status=default_status,
            difficulty="sedang",
        ))

        future_question = _question(
            question_id=f"csv-{subject_index + 1:02d}-10",
            text=f"Berapa {title} di {latest_region or 'Sumatera Selatan'} tahun 2035?",
            category="rentang_tahun_tahun_tanpa_data",
            row=table,
            split=split,
            regions=[latest_region] if latest_region else [],
            years=["2035"],
            key_status="manual_review",
            difficulty="sulit",
        )
        future_question["ekspektasi"]["tahun_diminta"] = "2035"
        questions.append(future_question)

    for kind in ("Tabel Statis", "Tabel SIMDASI"):
        typed = sorted(
            (row for row in catalog if row.get("jenis_tabel") == kind),
            key=lambda row: hashlib.sha256(row["id_tabel"].encode("utf-8")).digest(),
        )[:10]
        for index, table in enumerate(typed):
            table_id = table["id_tabel"]
            candidates = values.get(table_id, [])
            row_value = candidates[0] if candidates else None
            region = str(row_value.get("wilayah") or "") if row_value else ""
            year = str(row_value.get("tahun") or table.get("tahun") or "") if row_value else str(table.get("tahun") or "")
            keys = [_key(row_value, table_id)] if row_value else []
            questions.append(_question(
                question_id=f"type-{kind.casefold().replace(' ', '-')}-{index + 1:02d}",
                text=f"Tolong tampilkan {table['judul']}"
                f"{f' untuk {region}' if region else ''}{f' tahun {year}' if year else ''}.",
                category="tabel_statis_simdasi",
                row=table,
                split=stable_split(table_id, training_ids),
                expected_keys=keys,
                regions=[region] if region else [],
                years=[year] if year else [],
                key_status="ready" if keys else "manual_review",
                difficulty="sedang",
            ))

    extras = [
        ("BRS terbaru tentang inflasi Sumatera Selatan", "brs_publikasi_berita", "editorial"),
        ("Tampilkan publikasi BPS Sumatera Selatan terbaru", "brs_publikasi_berita", "editorial"),
        ("Berita statistik terbaru di Sumatera Selatan", "brs_publikasi_berita", "editorial"),
        ("Harga mobil listrik di Lahat berapa?", "di_luar_data", "no_data"),
        ("Berapa harga bitcoin di Palembang?", "di_luar_data", "no_data"),
        ("Data penduduk Provinsi Lampung tahun 2025 berapa?", "di_luar_data", "no_data"),
        ("Apa itu PDRB?", "pertanyaan_umum", "general"),
        ("Halo, apa kabar?", "pertanyaan_umum", "general"),
    ]
    editorial_templates = (
        ("BRS", "Ada BRS terbaru BPS tentang {subject}?"),
        ("publikasi", "Tolong carikan publikasi BPS mengenai {subject}."),
        ("berita statistik", "Tampilkan berita statistik terbaru terkait {subject}."),
    )
    for index in range(15):
        subject = subjects[index % len(subjects)]
        _, template = editorial_templates[index % len(editorial_templates)]
        extras.append((
            template.format(subject=subject),
            "brs_publikasi_berita",
            "editorial",
        ))
    extras.extend(
        (text, "di_luar_data", "no_data")
        for text in (
            "Berapa harga emas di Kabupaten Musi Banyuasin?",
            "Bagaimana cuaca besok di Kota Palembang?",
            "Berapa gaji pemain sepak bola di Lahat?",
            "Berapa harga saham perusahaan teknologi di Sumatera Selatan?",
            "Tampilkan harga rumah di Palembang tahun ini.",
            "Berapa harga mata uang kripto di Musi Rawas?",
            "Berapa jumlah restoran tertentu di luar Provinsi Sumatera Selatan?",
            "Tampilkan data penduduk DKI Jakarta tahun 2025.",
            "Berapa harga tiket pesawat dari Palembang ke Jakarta?",
            "Berapa jumlah paus di Sungai Musi?",
            "Tampilkan harga kendaraan listrik di Prabumulih.",
            "Berapa harga bitcoin di Muara Enim?",
        )
    )
    extras.extend(
        (text, "pertanyaan_umum", "general")
        for text in (
            "Apa yang dimaksud statistik resmi?",
            "Bagaimana cara membaca tabel statistik?",
            "Apa perbedaan data, indikator, dan satuan?",
            "Bagaimana cara mengunduh publikasi BPS?",
            "Terima kasih atas bantuannya.",
            "Halo, selamat pagi.",
            "Bisa jelaskan cara memilih indikator statistik?",
            "Apa perbedaan data primer dan data sekunder?",
            "Bagaimana cara mengutip sumber statistik dengan benar?",
            "Tolong beri saran menyusun pertanyaan survei.",
            "Apa arti istilah metodologi?",
            "Bagaimana cara memahami grafik?",
            "Apa yang dimaksud sampel dalam survei?",
            "Jelaskan arti sensus secara umum.",
            "Bisa bantu saya memahami istilah statistik?",
        )
    )
    for index, (text, category, intent) in enumerate(extras, 1):
        table = {"id_tabel": "", "jenis_tabel": "di luar katalog"}
        questions.append({
            "id": f"special-{index:02d}",
            "pertanyaan": text,
            "kategori": category,
            "ekspektasi": {
                "tabel": [],
                "wilayah": [],
                "tahun": [],
                "tipe": intent,
                "tanpa_data": intent == "no_data",
            },
            "tingkat_kesulitan": "mudah",
            "split": stable_split("", training_ids, f"special-{index}"),
            "status_kunci": "ready" if intent in {"no_data", "general"} else "manual_review",
            "kunci_jawaban": [],
        })

    train = [item for item in questions if item["split"] == "train"]
    holdout = [item for item in questions if item["split"] == "holdout"]
    return train, holdout


def main() -> int:
    load_local_environment()
    catalog = read_catalog()
    values, issue = read_cached_values()
    train, holdout = build_questions(catalog, values)
    if len(train) + len(holdout) < 200:
        raise RuntimeError("Bank pertanyaan kurang dari 200; generator berhenti tanpa menulis berkas.")
    write_jsonl(TESTS_DIR / "questions.jsonl", train)
    write_jsonl(TESTS_DIR / "holdout.jsonl", holdout)
    print(f"CSV tables: {len({row['id_tabel'] for row in catalog})}")
    print(f"Subjects: {len({row['subjek'] for row in catalog if row.get('subjek')})}")
    print(f"Questions: {len(train) + len(holdout)} total ({len(train)} train, {len(holdout)} holdout)")
    print(f"Independent cached table keys: {len(values)}")
    if issue:
        print(f"data_issue: {issue}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
