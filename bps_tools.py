"""Tools WebAPI BPS untuk Gemini (function calling)."""
import functools
import html
import json
import logging
import os
import re
import time
from concurrent.futures import ThreadPoolExecutor
from difflib import SequenceMatcher
from datetime import datetime, timedelta, timezone
from io import StringIO
from pathlib import Path
from typing import Any, Optional

from dotenv import load_dotenv
from lxml.etree import LxmlError
import pandas as pd

import bps_csv_catalog
import bps_csv_search
import bps_data_store
import sinonim

load_dotenv()
logger = logging.getLogger(__name__)

DOMAIN = os.getenv("BPS_DOMAIN", "1600")
WIB = timezone(timedelta(hours=7))
_YEAR_PATTERN = re.compile(r"20\d{2}")
_REGIONS = (
    ("Provinsi Sumatera Selatan", ("sumatera selatan", "sumsel")),
    ("Kabupaten Ogan Komering Ulu", ("ogan komering ulu", "oku")),
    ("Kabupaten Ogan Komering Ilir", ("ogan komering ilir", "oki")),
    ("Kabupaten Muara Enim", ("muara enim",)),
    ("Kabupaten Lahat", ("lahat",)),
    ("Kabupaten Musi Rawas", ("musi rawas",)),
    ("Kabupaten Musi Banyuasin", ("musi banyuasin", "muba")),
    ("Kabupaten Banyuasin", ("banyuasin", "banyu asin")),
    ("Kabupaten Ogan Komering Ulu Selatan", ("ogan komering ulu selatan", "oku selatan")),
    ("Kabupaten Ogan Komering Ulu Timur", ("ogan komering ulu timur", "oku timur")),
    ("Kabupaten Ogan Ilir", ("ogan ilir",)),
    ("Kabupaten Empat Lawang", ("empat lawang",)),
    ("Kabupaten Penukal Abab Lematang Ilir", ("penukal abab lematang ilir", "pali")),
    ("Kabupaten Musi Rawas Utara", ("musi rawas utara", "muratara")),
    ("Kota Palembang", ("palembang",)),
    ("Kota Prabumulih", ("prabumulih",)),
    ("Kota Pagar Alam", ("pagar alam",)),
    ("Kota Lubuklinggau", ("lubuklinggau", "lubuk linggau")),
)
_SHORT_REGION_ALIASES = {"oku", "oki", "pali", "muba"}
_VARIABLE_SYNONYMS = {
    "kemiskinan": "miskin",
    "miskin": "kemiskinan",
    "pengangguran": "penganggur",
    "penganggur": "pengangguran",
    "tpt": "penganggur",
    "nganggur": "pengangguran",
    "pertumbuhan": "tumbuh",
    "tumbuh": "pertumbuhan",
    "penduduk": "populasi",
    "populasi": "penduduk",
    "warga": "penduduk",
    "upah": "gaji",
    "gaji": "upah",
    "penghasilan": "pendapatan",
    "pendapatan": "penghasilan",
    "jiwa": "penduduk",
}
_GENDER_MALE_SYNONYMS = ("laki", "pria", "lelaki", "cowok")
_GENDER_FEMALE_SYNONYMS = ("perempuan", "wanita", "cewek")
_VARIABLE_SYNONYM_GROUPS = (
    ("miskin", "kemiskinan"),
    ("penganggur", "pengangguran", "pengangguran terbuka"),
    ("penduduk", "warga", "jiwa", "populasi", "masyarakat", "rakyat"),
    ("upah", "gaji", "penghasilan", "pendapatan"),
    _GENDER_MALE_SYNONYMS,
    _GENDER_FEMALE_SYNONYMS,
)
_VARIABLE_SYNONYM_PHRASES = (
    (
        "fasilitas kesehatan",
        (
            "rumah sakit khusus",
            "rumah sakit umum",
            "balai kesehatan",
            "klinik kesehatan",
            "rumah sakit",
            "puskesmas",
            "posyandu",
            "klinik",
            "rsud",
            "faskes",
        ),
    ),
    ("jenis kelamin", ("gender", "sex")),
    ("nilai tukar petani", ("ntp",)),
    ("penjara", ("masuk penjara", "dipenjara", "masuk tahanan")),
)
_RELATED_VARIABLE_SYNONYMS = {
    "penjara": ("tindak pidana", "narapidana", "tahanan", "dipenjara"),
    "narapidana": ("penjara", "tindak pidana", "tahanan"),
    "tahanan": ("penjara", "tindak pidana", "narapidana"),
    "kejahatan": ("tindak pidana", "kriminalitas"),
    "kriminalitas": ("tindak pidana", "kejahatan"),
}
_SEARCH_FILLER_WORDS = {
    "apa", "apakah", "adakah", "berapa", "berapakah", "bagaimana", "tolong", "mohon", "bisa",
    "carikan", "cari", "menampilkan", "tampilkan", "menunjukkan", "tunjukkan",
    "perbedaan", "beda", "pengertian", "definisi", "arti", "maksud", "konsep",
    "cara", "membaca", "menafsirkan", "menghitung", "rumus", "metodologi",
    "data", "terbaru", "terkini", "terakhir", "tahun", "pada", "di", "ke", "dari",
    "untuk", "tentang", "mengenai", "yang", "dan", "atau", "dengan", "saya",
    "ingin", "dong", "kah", "angka", "nilai", "jumlah", "adalah", "tersebut",
    "jelaskan", "sebutkan", "besaran", "menurut", "per", "tiap", "seluruh", "orang",
    "info", "informasi",
    "kab", "kabupaten", "kota", "provinsi", "sumsel", "sumatera", "selatan",
}
_MONTH_WORDS = {
    "januari", "februari", "maret", "april", "mei", "juni",
    "juli", "agustus", "september", "oktober", "november", "desember", "bulan",
}


def _load_canonical_indicators() -> dict[str, dict[str, Any]]:
    path = Path(__file__).resolve().parent / "config" / "bps_indicators.json"
    with path.open(encoding="utf-8") as source:
        configuration = json.load(source)

    indicators: dict[str, dict[str, Any]] = {}
    for group in configuration["groups"]:
        for variable in group["variables"]:
            name = variable["name"]
            indicators[name] = {
                key: variable[key]
                for key in (
                    "var_id",
                    "title",
                    "sifat_data",
                    "catatan_sumber",
                    "vervar_label",
                    "province_only",
                )
                if key in variable
            }
    if not indicators:
        raise ValueError("Shared BPS indicator map is empty.")
    return indicators


_INDIKATOR_UTAMA = _load_canonical_indicators()


class BpsError(Exception):
    pass


def strip_notes(value: Any) -> Any:
    if isinstance(value, dict):
        return {
            key: strip_notes(item)
            for key, item in value.items()
            if str(key).casefold() != "notes"
        }
    if isinstance(value, list):
        return [strip_notes(item) for item in value]
    return value


def _fetch_catalog_row(row: dict[str, str]) -> dict:
    try:
        return bps_csv_catalog.fetch_json(row)
    except bps_csv_catalog.CatalogError as exc:
        raise BpsError(str(exc)) from None


def _catalog_rows() -> list[dict[str, str]]:
    try:
        return bps_csv_catalog.load_catalog()
    except bps_csv_catalog.CatalogError as exc:
        raise BpsError(str(exc)) from None


def _periods(var_id: int) -> list[dict]:
    """Daftar tahun tersedia untuk satu variabel, terbaru dulu."""
    periods = []
    for row in _catalog_rows():
        if row.get("jenis_tabel") != "Tabel Dinamis" or row.get("id_tabel") != str(var_id):
            continue
        year = row.get("tahun", "")
        th_id = re.search(r"/th/([^/]+)/", row.get("link_json", ""))
        if year.isdigit() and th_id and th_id.group(1).isdigit():
            periods.append({"th": year, "th_id": th_id.group(1)})
    return sorted(periods, key=lambda row: int(row["th"]), reverse=True)


def _dynamic_table_row(var_id: int, year: str) -> dict[str, str]:
    for row in _catalog_rows():
        if (
            row.get("jenis_tabel") == "Tabel Dinamis"
            and row.get("id_tabel") == str(var_id)
            and row.get("tahun") == str(year)
        ):
            return row
    raise BpsError(f"Baris katalog untuk tahun {year} tidak tersedia.")


def _latest_year(var_id: int) -> tuple[Optional[str], int, bool]:
    periods = _periods(var_id)
    this_year = datetime.now(WIB).year
    past = [p for p in periods if int(p["th"]) <= this_year]
    latest = past or periods
    has_future = any(int(p["th"]) > this_year for p in periods)
    return (str(latest[0]["th"]) if latest else None), len(periods), has_future


def _remove_region_names(keyword: str) -> str:
    result = keyword.lower()
    regions = sorted(_REGIONS, key=lambda item: max(len(alias) for alias in item[1]), reverse=True)
    for canonical, aliases in regions:
        for name in (canonical, *aliases):
            result = re.sub(rf"(?<!\w){re.escape(name.lower())}(?!\w)", " ", result)
    result = re.sub(r"\b(kabupaten|kab|kota|provinsi)\b", " ", result)
    return " ".join(result.split())


def _clean_search_keyword(keyword: str) -> str:
    raw_keyword = str(keyword or "")
    raw_keyword = re.sub(
        r"\b([A-Z][A-Z0-9-]{1,9})\s*\([^()]{4,80}\)",
        r"\1",
        raw_keyword,
        flags=re.IGNORECASE,
    )
    raw_keyword = re.sub(
        r"\b[^()]{4,80}\s*\(([A-Z][A-Z0-9-]{1,9})\)",
        r"\1",
        raw_keyword,
        flags=re.IGNORECASE,
    )
    result = raw_keyword.casefold()
    for canonical, aliases in _VARIABLE_SYNONYM_PHRASES:
        for alias in sorted(aliases, key=len, reverse=True):
            result = re.sub(
                rf"(?<!\w){re.escape(alias)}(?!\w)",
                canonical,
                result,
            )
    result = _remove_region_names(result)
    result = re.sub(r"\b20\d{2}\b", " ", result)
    words = [
        word for word in re.findall(r"[a-z0-9]+", result.casefold())
        if word not in _SEARCH_FILLER_WORDS
        and word not in _MONTH_WORDS
        and word not in sinonim.STOP
        and word not in sinonim.GENERIC
    ]
    canonical_synonyms = {
        synonym: group[0]
        for group in _VARIABLE_SYNONYM_GROUPS
        for synonym in group
    }
    words = [canonical_synonyms.get(word, word) for word in words]
    return " ".join(words)[:80].strip()


def _token_match_score(term: str, tokens: set[str]) -> float:
    normalized = str(term or "").casefold().strip()
    if not normalized:
        return 0.0
    if normalized in tokens:
        return 1.0
    if len(normalized) < 4:
        return 0.0
    score = max(
        (
            SequenceMatcher(None, normalized, token).ratio()
            for token in tokens
        ),
        default=0.0,
    )
    return score if score >= 0.8 else 0.0


def _stem_keyword(word: str) -> str:
    return bps_csv_search.stem(word)


def _search_keyword_variants(keyword: str) -> list[str]:
    normalized = _clean_search_keyword(keyword)
    words = normalized.split()
    if not words:
        return []

    candidates = [normalized]
    if (
        any(word in _GENDER_MALE_SYNONYMS for word in words)
        and any(word in _GENDER_FEMALE_SYNONYMS for word in words)
    ):
        candidates.insert(0, "jenis kelamin")
    synonym_phrase = " ".join(_VARIABLE_SYNONYMS.get(word, word) for word in words)
    if synonym_phrase != normalized:
        candidates.append(synonym_phrase)

    stemmed_phrase = " ".join(_stem_keyword(word) for word in words)
    if stemmed_phrase != normalized:
        candidates.append(stemmed_phrase)

    for word in dict.fromkeys(words):
        if len(word) < 3:
            continue
        candidates.append(word)
        stem = _stem_keyword(word)
        if stem != word:
            candidates.append(stem)
        candidates.extend(
            synonym
            for group in _VARIABLE_SYNONYM_GROUPS
            if word in group
            for synonym in group
            if synonym != word
        )
        mapped = _VARIABLE_SYNONYMS.get(word)
        if mapped and mapped != word:
            candidates.append(mapped)

    return list(dict.fromkeys(query for query in candidates if query))[:24]


def _load_variable_index() -> dict:
    variables_by_id: dict[str, dict] = {}
    subjects_by_id: dict[str, dict] = {}
    for row in _catalog_rows():
        subject_id = row.get("id_subjek", "")
        subject = subjects_by_id.setdefault(subject_id, {
            "sub_id": subject_id,
            "subject": row.get("subjek", ""),
            "category": row.get("kategori_subjek", ""),
            "category_id": "",
            "jumlah_tabel": None,
        })
        if row.get("jenis_tabel") != "Tabel Dinamis":
            continue
        variable_id = row.get("id_tabel", "")
        title = row.get("judul", "")
        if not variable_id.isdigit() or not title:
            continue
        variable = variables_by_id.setdefault(variable_id, {
            "var_id": variable_id,
            "title": title,
            "unit": None,
            "subject_id": subject_id,
            "subject": subject.get("subject", ""),
            "category": subject.get("category", ""),
            "category_id": "",
            "years": [],
            "_status_by_year": {},
            "aliases": _variable_aliases(title),
            "context_aliases": list(dict.fromkeys([
                row.get("subjek", ""),
                row.get("kategori_subjek", ""),
            ])),
        })
        year = row.get("tahun", "")
        if year.isdigit() and year not in variable["years"]:
            variable["years"].append(year)
        if year.isdigit() and row.get("status_tarik"):
            variable["_status_by_year"][year] = row.get("status_tarik")
    variables = list(variables_by_id.values())
    for variable in variables:
        variable["years"].sort(key=int, reverse=True)
        variable["latest_year"] = variable["years"][0] if variable["years"] else None
        variable["year_count"] = len(variable["years"])
        variable["status_tarik"] = variable["_status_by_year"].get(
            variable["latest_year"],
            "belum_diaudit",
        )
        variable.pop("_status_by_year", None)
    return {
        "variables": sorted(
            variables,
            key=lambda variable: (variable["title"].casefold(), variable["var_id"]),
        ),
        "subjects": sorted(
            subjects_by_id.values(),
            key=lambda subject: subject["subject"].casefold(),
        ),
        "refreshed_at": datetime.now(WIB).isoformat(timespec="seconds"),
    }


def _load_catalog_index() -> dict:
    return _load_variable_index()


def _variable_aliases(title: str) -> list[str]:
    aliases = re.findall(r"\(([A-Z][A-Z0-9]{1,7})\)", title)
    words = re.findall(r"[a-z0-9]+", title.casefold())
    stop_words = {"dan", "di", "dari", "ke", "yang", "menurut", "per", "serta"}
    acronym_words = []
    for word in words:
        if word in stop_words:
            continue
        if not acronym_words or acronym_words[-1] != word:
            acronym_words.append(word)
    if 2 <= len(acronym_words) <= 8:
        aliases.append("".join(word[0] for word in acronym_words))
    return list(dict.fromkeys(alias.casefold() for alias in aliases))


def _combine_variable_indexes(remote: dict, catalog: dict) -> dict:
    variables_by_id = {
        str(variable["var_id"]): dict(variable)
        for variable in remote.get("variables", [])
        if isinstance(variable, dict) and variable.get("var_id") is not None
    }
    for catalog_variable in catalog.get("variables", []):
        variable_id = str(catalog_variable["var_id"])
        variable = variables_by_id.setdefault(variable_id, dict(catalog_variable))
        for key, value in catalog_variable.items():
            if value not in (None, "") and variable.get(key) in (None, ""):
                variable[key] = value
        variable["aliases"] = list(dict.fromkeys(
            list(variable.get("aliases") or []) + list(catalog_variable.get("aliases") or [])
        ))
        variable["context_aliases"] = list(dict.fromkeys(
            list(variable.get("context_aliases") or [])
            + list(catalog_variable.get("context_aliases") or [])
        ))

    subjects_by_id = {
        str(subject["sub_id"]): dict(subject)
        for subject in remote.get("subjects", [])
        if isinstance(subject, dict) and subject.get("sub_id") is not None
    }
    for catalog_subject in catalog.get("subjects", []):
        subject_id = str(catalog_subject["sub_id"])
        subject = subjects_by_id.setdefault(subject_id, dict(catalog_subject))
        for key, value in catalog_subject.items():
            if value not in (None, ""):
                subject[key] = value

    return {
        **remote,
        "variables": sorted(
            variables_by_id.values(),
            key=lambda variable: (str(variable.get("title") or "").casefold(), str(variable["var_id"])),
        ),
        "subjects": sorted(subjects_by_id.values(), key=lambda subject: str(subject.get("subject") or "").casefold()),
    }


def _load_searchable_variable_index() -> dict:
    return _load_variable_index()


def refresh_variable_index() -> dict:
    return _load_searchable_variable_index()


def _variable_index() -> dict:
    return _load_searchable_variable_index()


def _rank_local_variables(
    keyword: str,
    variables: list[dict],
    tahun_diminta: Optional[str] = None,
) -> list[dict]:
    search = bps_csv_search.Pencari(
        variables,
        corpus_rows=_catalog_rows(),
    ).cari(
        keyword,
        tahun_diminta=tahun_diminta,
        top=len(variables),
    )
    matches: list[dict] = []
    for match in search["hasil"]:
        source_rows = match["rows"]
        if not source_rows:
            continue
        variable = dict(source_rows[0])
        variable["similarity_score"] = match["skor_kemiripan"]
        variable["match_type"] = match["jenis_kecocokan"]
        variable["core_terms"] = match["kata_inti_cocok"]
        variable["synonyms_used"] = match["sinonim_digunakan"]
        variable["search_score"] = match["skor"]
        matches.append(variable)
    return matches


def catalog_variable_matches(question: str) -> bool:
    keyword = _clean_search_keyword(question or "")
    catalog = _load_catalog_index()
    variables = [
        {
            **variable,
            "var_id": variable.get("var_id") or f"catalog-{index}",
        }
        for index, variable in enumerate(catalog["variables"])
    ]
    return bool(_rank_local_variables(keyword, variables))


def cari_tabel_katalog(
    kata_kunci: str,
    tahun_diminta: Optional[str] = None,
    jumlah: int = 10,
) -> dict:
    """Search all catalog table types without fetching values."""
    keyword = _clean_search_keyword(kata_kunci or "")
    if not keyword:
        return {"hasil": [], "kata_inti": [], "status_hasil": "kosong"}
    try:
        rows = _catalog_rows()
        search = bps_csv_search.Pencari(rows, corpus_rows=rows).cari(
            keyword,
            tahun_diminta=tahun_diminta,
            top=max(1, min(int(jumlah or 10), 25)),
        )
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}
    except (KeyError, TypeError, ValueError):
        logger.exception("Pencarian katalog BPS gagal memproses struktur katalog.")
        return {"error": "Format katalog BPS tidak dikenali.", "status_hasil": "gagal_teknis"}

    results = []
    current_year = datetime.now(WIB).year
    for match in search["hasil"]:
        source_rows = match.get("rows") or []
        if not source_rows:
            continue
        years = sorted(
            {str(row.get("tahun") or "") for row in source_rows if str(row.get("tahun") or "").isdigit()},
            key=int,
        )
        selected_year = str(tahun_diminta or "")
        if selected_year not in years:
            available_years = [year for year in years if int(year) <= current_year] or years
            selected_year = available_years[-1] if available_years else ""
        selected_row = next(
            (row for row in source_rows if str(row.get("tahun") or "") == selected_year),
            source_rows[-1],
        )
        results.append({
            "id_tabel": match["id"],
            "judul": match["judul"],
            "jenis_tabel": match.get("jenis", ""),
            "subjek": match.get("subjek", ""),
            "tahun_tersedia": years,
            "tahun_terpilih": selected_year or None,
            "status_tarik": selected_row.get("status_tarik") or "belum_diaudit",
            "status_detail": selected_row.get("status_detail") or "",
            "link_json": selected_row.get("link_json") or "",
            "link_tabel": next(
                (
                    selected_row.get(field)
                    for field in ("link_tabel", "url_tabel", "url", "tautan")
                    if selected_row.get(field)
                ),
                "",
            ),
            "skor": match["skor"],
            "skor_kemiripan": match["skor_kemiripan"],
            "kata_inti_cocok": match["kata_inti_cocok"],
            "sinonim_digunakan": match["sinonim_digunakan"],
            "jenis_kecocokan": match["jenis_kecocokan"],
        })
    return {
        "kata_inti": search["kata_inti"],
        "hasil": results,
        "kandidat_terdekat": search["saran"],
        "status_hasil": "ditemukan" if results else "kosong",
    }


def catalog_table_matches(question: str) -> bool:
    return bool(cari_tabel_katalog(question, jumlah=1).get("hasil"))


def cari_variabel(
    kata_kunci: str,
    tahun_diminta: Optional[str] = None,
) -> dict:
    """Mencari variabel (indikator) data tahunan BPS Sumatera Selatan berdasarkan kata kunci.

    Gunakan 1-2 kata inti, mis. "jumlah penduduk", "penduduk miskin", "IPM". Jangan
    menyertakan nama wilayah; wilayah diisi pada ambil_data. Hasil berisi var_id, judul,
    satuan, tahun_terbaru, skor_kemiripan (0-100), jenis_kecocokan, dan seri_lama.
    Skor hanya untuk pemeringkatan internal dan pencatatan log; jangan tampilkan kepada pengguna.
    Pilih variabel yang judulnya paling sesuai;
    hindari seri_lama=True kecuali tidak ada alternatif, dan sebutkan periode terakhirnya
    jika terpaksa menggunakannya. memuat_tahun_mendatang=True berarti variabel itu proyeksi.
    jenis_kecocokan='related' berarti indikator hanya berkaitan, bukan sinonim atau ukuran
    yang setara; jelaskan perbedaannya dan minta konfirmasi sebelum menyajikannya sebagai jawaban.

    Args:
        kata_kunci: kata inti indikator yang dicari.

    Pencarian juga menormalkan sinonim umum seperti NTP/nilai tukar petani,
    pria/laki-laki, wanita/perempuan, dan rumah sakit/fasilitas kesehatan.
    Pastikan judul hasil tetap sesuai karena sebagian sinonim dapat menunjuk
    kategori yang lebih luas.
    """
    keyword = _clean_search_keyword(kata_kunci or "")
    if not keyword:
        return {
            "jumlah_ditemukan": 0,
            "hasil": [],
            "status_hasil": "kosong",
            "petunjuk": "Masukkan kata inti indikator tanpa nama wilayah.",
        }
    search_keyword = (
        "ntp"
        if re.search(r"\bntp\b", kata_kunci or "", re.IGNORECASE)
        else keyword
    )

    try:
        index = _variable_index()
        catalog_rows = _catalog_rows()
        search = bps_csv_search.Pencari(
            index["variables"],
            corpus_rows=catalog_rows,
        ).cari(
            search_keyword,
            tahun_diminta=tahun_diminta,
            top=3,
        )
        variables = _rank_local_variables(
            search_keyword,
            index["variables"],
            tahun_diminta=tahun_diminta,
        )[:3]
        candidates = variables[:3]
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}
    except (KeyError, ValueError, TypeError):
        return {"error": "Format respons BPS tidak dikenali.", "status_hasil": "gagal_teknis"}

    hasil = []
    for variable in candidates:
        year = variable.get("latest_year")
        count = variable.get("year_count") or 0
        future = bool(
            variable.get("years")
            and any(int(value) > datetime.now(WIB).year for value in variable["years"])
        )
        hasil.append({
            "var_id": int(variable["var_id"]),
            "judul": variable.get("title"),
            "satuan": variable.get("unit"),
            "tahun_terbaru": year or None,
            "jumlah_tahun": count,
            "memuat_tahun_mendatang": future,
            "kategori": variable.get("category"),
            "subjek": variable.get("subject"),
            "sub_id": variable.get("subject_id"),
            "category_id": variable.get("category_id"),
            "alias": variable.get("aliases") or [],
            "skor_kemiripan": variable.get("similarity_score"),
            "jenis_kecocokan": variable.get("match_type"),
            "kata_inti_cocok": variable.get("core_terms") or [],
            "sinonim_digunakan": variable.get("synonyms_used") or {},
            "status_tarik": variable.get("status_tarik") or "belum_diaudit",
        })
    newest = max((int(item["tahun_terbaru"]) for item in hasil if item["tahun_terbaru"]), default=0)
    for item in hasil:
        item["seri_lama"] = bool(item["tahun_terbaru"]) and int(item["tahun_terbaru"]) < newest - 2
    hasil.sort(key=lambda item: item["seri_lama"])

    result = {
        "jumlah_ditemukan": len(variables),
        "hasil": hasil,
        "kata_inti": bps_csv_search.Pencari(
            index["variables"],
            corpus_rows=catalog_rows,
        ).kata_inti(search_keyword),
        "kandidat_terdekat": search["saran"],
        "status_hasil": (
            "ditemukan"
            if variables and int(variables[0].get("similarity_score") or 0) >= 80
            else "kandidat_mirip" if variables else "kosong"
        ),
    }
    if not variables:
        result["petunjuk"] = "Tidak ada hasil. Coba satu kata inti tanpa nama wilayah."
    return result


def cari_subjek(kata_kunci: str = "") -> dict:
    """Mencari subjek dan kategori yang tercantum dalam katalog BPS Sumatera Selatan."""
    try:
        index = _variable_index()
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}

    keyword = _clean_search_keyword(kata_kunci or "")
    subjects = index.get("subjects", [])
    if keyword:
        terms = keyword.split()
        subjects = [
            subject for subject in subjects
            if all(
                _token_match_score(term, set(re.findall(
                    r"[a-z0-9]+",
                    " ".join(str(subject.get(field) or "") for field in ("subject", "category")).casefold(),
                ))) > 0
                for term in terms
            )
        ]

    variable_counts: dict[str, int] = {}
    for variable in index.get("variables", []):
        subject_id = str(variable.get("subject_id") or "")
        if subject_id:
            variable_counts[subject_id] = variable_counts.get(subject_id, 0) + 1

    results = [
        {
            "kategori": subject.get("category"),
            "subcat_id": subject.get("category_id"),
            "subjek": subject.get("subject"),
            "sub_id": subject.get("sub_id"),
            "jumlah_tabel": subject.get("jumlah_tabel"),
            "jumlah_variabel_katalog": variable_counts.get(str(subject.get("sub_id")), 0),
        }
        for subject in subjects
    ]
    return {
        "jumlah_ditemukan": len(results),
        "hasil": results,
        "status_hasil": "ditemukan" if results else "kosong",
    }


def _simdasi_tables() -> list[dict]:
    tables: dict[str, dict] = {}
    for row in _catalog_rows():
        table_id = row.get("id_tabel", "")
        title = row.get("judul", "")
        if row.get("jenis_tabel") != "SIMDASI" or not table_id or not title:
            continue
        table = tables.setdefault(table_id, {
            "id_tabel": table_id,
            "judul": title,
            "subject": row.get("subjek", ""),
            "bab": row.get("kategori_subjek", ""),
            "ketersediaan_tahun": [],
            "baris_tahun": {},
            "status_tarik": row.get("status_tarik", ""),
        })
        year = row.get("tahun", "")
        if year.isdigit():
            table["ketersediaan_tahun"].append(year)
            table["baris_tahun"][year] = row
    for table in tables.values():
        table["ketersediaan_tahun"] = sorted(
            set(table["ketersediaan_tahun"]), key=int
        )
    return list(tables.values())


def simdasi_daftar_tabel(kata_kunci: str = "", jumlah: int = 250) -> dict:
    """Mencari judul tabel SIMDASI Sumatera Selatan beserta ID dan tahun yang tersedia.

    Kosongkan kata_kunci untuk seluruh daftar. Gunakan kata inti seperti "TPT",
    "pendidikan", atau "pertanian" untuk memfilter judul.

    Args:
        kata_kunci: kata inti judul/subjek tabel (opsional).
        jumlah: maksimal judul yang dikembalikan, dibatasi 1 sampai 250.
    """
    try:
        tables = _simdasi_tables()
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}

    terms = [
        term for term in _clean_search_keyword(kata_kunci or "").split()
        if term not in {"simdasi", "tabel", "daftar", "list", "katalog", "judul"}
    ]
    ranked = []
    for table in tables:
        searchable = " ".join(
            str(table.get(field) or "")
            for field in ("judul", "subject", "bab", "mms_subject")
        )
        tokens = set(re.findall(r"[a-z0-9]+", searchable.casefold()))
        score = sum(_token_match_score(term, tokens) for term in terms)
        if not terms or score > 0:
            ranked.append((score, table))
    ranked.sort(key=lambda item: (item[0], str(item[1].get("judul", "")).casefold()), reverse=True)
    count = max(1, min(int(jumlah or 250), 250))
    results = [
        {
            "judul": table["judul"],
            "id_tabel": table["id_tabel"],
            "subjek": table.get("subject"),
            "bab": table.get("bab"),
            "tahun_tersedia": [
                int(year)
                for year in table.get("ketersediaan_tahun", [])
                if str(year).isdigit()
            ],
        }
        for _, table in ranked[:count]
    ]
    return {
        "jumlah_ditemukan": len(ranked),
        "terpotong": len(ranked) > count,
        "hasil": results,
        "status_hasil": "ditemukan" if results else "kosong",
    }


def simdasi_cari_data(
    kata_kunci: str,
    tahun: Optional[str] = None,
    wilayah: Optional[str] = None,
    id_tabel: Optional[str] = None,
) -> dict:
    """Mencari judul dan mengambil data tabel SIMDASI Sumatera Selatan.

    Gunakan sebagai sumber pelengkap bila data tidak ada pada variabel WebAPI biasa,
    terutama tabel per kabupaten/kota. Isi kata_kunci dengan indikator inti seperti
    "TPT" atau "pendidikan"; isi tahun dan wilayah bila disebut pengguna.

    Args:
        kata_kunci: kata inti indikator/topik tabel.
        tahun: tahun yang diminta; jika kosong, gunakan tahun terakhir yang tersedia.
        wilayah: kabupaten/kota yang ingin diambil; kosongkan untuk cakupan provinsi.
    """
    keyword = _clean_search_keyword(kata_kunci or "")
    terms = keyword.split()
    if not terms:
        return {"error": "Masukkan kata inti indikator untuk mencari tabel SIMDASI.", "status_hasil": "kosong"}

    resolved_region = None
    if wilayah:
        resolved_region, candidates = _resolve_region(wilayah)
        if resolved_region is None:
            return {
                "error": f"Nama wilayah '{wilayah}' tidak dikenali dengan yakin.",
                "wilayah_kandidat": candidates,
                "status_hasil": "kosong",
            }

    try:
        tables = _simdasi_tables()
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}

    if id_tabel:
        ranked = [
            (100.0, table)
            for table in tables
            if str(table.get("id_tabel")) == str(id_tabel)
        ]
    else:
        search_rows = [
            {
                "id_tabel": table["id_tabel"],
                "judul": table["judul"],
                "subjek": " ".join((table.get("subject", ""), table.get("bab", ""))),
                "jenis_tabel": "SIMDASI",
                "tahun": table.get("ketersediaan_tahun", []),
                "status_tarik": table.get("status_tarik", ""),
            }
            for table in tables
        ]
        search = bps_csv_search.Pencari(
            search_rows,
            corpus_rows=_catalog_rows(),
        ).cari(keyword, tahun_diminta=tahun, top=5)
        table_by_id = {str(table["id_tabel"]): table for table in tables}
        ranked = [
            (match["skor"], table_by_id[match["id"]])
            for match in search["hasil"]
            if match["id"] in table_by_id
        ]
    if not ranked:
        return {
            "judul": "Tabel SIMDASI",
            "hasil": [],
            "status_hasil": "kosong",
        }
    if (
        not id_tabel
        and len(ranked) > 1
        and abs(float(ranked[0][0]) - float(ranked[1][0])) <= 4
    ):
        return {
            "judul": "Kandidat tabel SIMDASI",
            "kandidat": [
                {
                    "id_tabel": table.get("id_tabel"),
                    "judul": table.get("judul"),
                    "tahun_tersedia": table.get("ketersediaan_tahun") or [],
                }
                for _, table in ranked[:3]
            ],
            "status_hasil": "kandidat_mirip",
        }

    table = ranked[0][1]
    current_year = datetime.now(WIB).year
    all_available_years = [
        int(value)
        for value in table.get("ketersediaan_tahun", [])
        if str(value).isdigit()
    ]
    available_years = [
        year for year in all_available_years if year <= current_year
    ] or all_available_years
    if not available_years:
        return {
            "judul": table.get("judul"),
            "error": "Tabel SIMDASI belum memiliki tahun data yang dapat digunakan.",
            "status_hasil": "kosong",
        }

    requested_year = None
    if tahun is not None:
        try:
            requested_year = int(str(tahun).strip())
        except ValueError:
            return {"error": "Tahun harus berupa angka.", "status_hasil": "kosong"}
    if requested_year is not None and requested_year not in all_available_years:
        return {
            "judul": table.get("judul"),
            "tahun_tersedia": all_available_years,
            "tahun_diminta_tidak_tersedia": requested_year,
            "status_hasil": "kosong",
        }
    if requested_year is not None:
        selected_year = requested_year
    else:
        status_by_year = {
            year: str(table["baris_tahun"].get(str(year), {}).get("status_tarik") or "").casefold()
            for year in available_years
        }
        usable_years = [
            year for year in available_years
            if status_by_year[year] not in {"gagal", "kosong"}
        ]
        if not usable_years:
            return {
                "judul": table.get("judul"),
                "status_tarik": "gagal",
                "status_hasil": "gagal_teknis",
                "error": "Semua tahun yang tersedia ditandai gagal atau kosong pada audit WebAPI.",
            }
        selected_year = max(
            usable_years,
            key=lambda year: (
                status_by_year[year] == "ok",
                year,
            ),
        )

    try:
        selected_row = table["baris_tahun"].get(str(selected_year))
        if selected_row is None:
            return {
                "judul": table.get("judul"),
                "tahun_tersedia": available_years,
                "tahun_diminta_tidak_tersedia": requested_year,
                "status_hasil": "kosong",
            }
        audit_status = str(selected_row.get("status_tarik") or "").casefold()
        if audit_status in {"gagal", "kosong"}:
            return {
                "judul": table.get("judul"),
                "tahun": selected_year,
                "status_tarik": audit_status,
                "status_hasil": "gagal_teknis" if audit_status == "gagal" else "kosong",
                "error": str(selected_row.get("status_detail") or "Tautan ini gagal atau kosong pada audit terakhir."),
            }
        detail = _fetch_catalog_row(selected_row)
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}

    data = detail.get("data")
    table_data = data[1] if isinstance(data, list) and len(data) > 1 else None
    if not isinstance(table_data, dict):
        logger.warning(
            "SIMDASI response structure is not recognized for table %s year %s.",
            table.get("id_tabel"),
            selected_year,
        )
        return {
            "judul": table.get("judul"),
            "error": "Struktur respons SIMDASI tidak dikenali; nilai tidak dapat dipastikan.",
            "status_hasil": "gagal_teknis",
        }

    columns = table_data.get("kolom")
    rows = table_data.get("data")
    if not isinstance(columns, dict) or not isinstance(rows, list):
        logger.warning(
            "SIMDASI response fields are not recognized for table %s year %s.",
            table.get("id_tabel"),
            selected_year,
        )
        return {
            "judul": table.get("judul"),
            "error": "Kolom atau baris pada respons SIMDASI tidak dikenali.",
            "status_hasil": "gagal_teknis",
        }

    generic_terms = _SEARCH_FILLER_WORDS | {
        "tingkat", "rasio", "persentase", "persen", "indikator", "variabel", "statistik",
    }
    months = _MONTH_WORDS - {"bulan"}
    month_terms = {
        month for month in months
        if re.search(rf"\b{re.escape(month)}\b", str(kata_kunci), re.IGNORECASE)
    }
    indicator_terms = [term for term in terms if term not in generic_terms and term not in months]
    matching_columns = {}
    for column_id, column in columns.items():
        if not isinstance(column, dict):
            continue
        name = str(column.get("nama_variabel") or column.get("nama_variabel_en") or column_id)
        column_tokens = set(re.findall(r"[a-z0-9]+", name.casefold()))
        if month_terms and not any(_token_match_score(month, column_tokens) > 0 for month in month_terms):
            continue
        if not indicator_terms or any(_token_match_score(term, column_tokens) > 0 for term in indicator_terms):
            matching_columns[str(column_id)] = {
                "name": name,
                "unit": str(
                    column.get("satuan")
                    or column.get("satuan_data")
                    or column.get("unit")
                    or ""
                ).strip(),
            }
    if not matching_columns:
        return {
            "judul": table_data.get("judul_tabel") or table.get("judul"),
            "error": "Kolom pada tabel tidak cocok dengan indikator yang diminta.",
            "status_hasil": "kosong",
        }

    if resolved_region:
        target_names = {_norm_region(alias) for alias in _region_aliases(resolved_region)}
        rows = [
            row for row in rows
            if isinstance(row, dict) and _norm_region(str(row.get("label") or "")) in target_names
        ]
        if not rows:
            return {
                "judul": table_data.get("judul_tabel") or table.get("judul"),
                "wilayah_ditafsirkan": resolved_region,
                "error": f"Data untuk {resolved_region} tidak tercantum pada tabel SIMDASI ini.",
                "status_hasil": "kosong",
            }
    elif not re.search(r"\b(kabupaten|kab\s*/?\s*kota|per\s+kota)\b", kata_kunci, re.IGNORECASE):
        province_rows = [
            row for row in rows
            if isinstance(row, dict)
            and (
                str(row.get("kode_wilayah") or "") == "1600000"
                or _norm_region(str(row.get("label") or "")) == "sumatera selatan"
            )
        ]
        if province_rows:
            rows = province_rows

    results = []
    for row in rows[:150]:
        if not isinstance(row, dict):
            continue
        for column_id, column_info in matching_columns.items():
            cell = row.get("variables", {}).get(column_id)
            value = cell.get("value") if isinstance(cell, dict) else cell
            if value is None or str(value).strip().casefold() in {
                "", "...", "..", "na", "n/a", "n.a.", "-", "—", "�",
            }:
                continue
            results.append({
                "wilayah": row.get("label"),
                "variabel": column_info["name"],
                "nilai": value,
                **({"satuan": column_info["unit"]} if column_info["unit"] else {}),
            })

    if not results:
        if requested_year is None:
            older_years = [
                year for year in available_years
                if year < selected_year
                and str(table["baris_tahun"].get(str(year), {}).get("status_tarik") or "").casefold()
                not in {"gagal", "kosong"}
            ]
            if older_years:
                fallback = simdasi_cari_data(
                    kata_kunci,
                    tahun=str(max(older_years)),
                    wilayah=wilayah,
                    id_tabel=str(table["id_tabel"]),
                )
                if fallback.get("status_hasil") == "ditemukan":
                    fallback["tahun_terbaru_kosong"] = selected_year
                    return fallback
        return {
            "judul": table_data.get("judul_tabel") or table.get("judul"),
            "wilayah_ditafsirkan": resolved_region,
            "tahun": selected_year,
            "hasil": [],
            "status_hasil": "kosong",
        }

    result = {
        "judul": table_data.get("judul_tabel") or table.get("judul"),
        "sumber": "BPS Provinsi Sumatera Selatan",
        "tahun": table_data.get("tahun_data") or selected_year,
        "subjek": table_data.get("subject") or table.get("subject"),
        "wilayah_ditafsirkan": resolved_region or "Provinsi Sumatera Selatan",
        "jumlah_baris": len(results),
        "satuan": next(
            (
                row["satuan"] for row in results
                if row.get("satuan")
            ),
            None,
        ),
        "terpotong": len(rows) > 150,
        "data": results,
        "status_hasil": "ditemukan",
    }
    if requested_year is not None and selected_year != requested_year:
        result["tahun_diminta_tidak_tersedia"] = requested_year
        result["tahun_tersedia"] = available_years

    return result


def warm_cache() -> None:
    try:
        _variable_index()
    except BpsError as exc:
        logger.warning("Indeks variabel BPS belum dapat diperbarui: %s", exc)
        return
    for keyword in (
        "jumlah penduduk",
        "penduduk miskin",
        "indeks pembangunan manusia",
        "gini",
        "harapan hidup",
        "pertumbuhan",
    ):
        cari_variabel(keyword)


def _labels(items: Any) -> dict[str, str]:
    out: dict[str, str] = {}
    for item in items or []:
        if isinstance(item, dict) and "val" in item:
            label = str(item.get("label", "")).strip()
            out[str(item["val"])] = "" if label.lower() in {"tidak ada", "-"} else label
    return out


def _norm_region(label: str) -> str:
    words = re.sub(r"[^\w\s]", " ", label.lower(), flags=re.UNICODE).split()
    return " ".join(word for word in words if word not in {"kab", "kabupaten", "kota", "provinsi"})


def _region_aliases(label: str) -> tuple[str, ...]:
    for canonical, aliases in _REGIONS:
        if canonical == label:
            return tuple(dict.fromkeys((_norm_region(canonical), *(_norm_region(alias) for alias in aliases))))
    return (_norm_region(label),)


def _resolve_region(wilayah: str) -> tuple[Optional[str], list[str]]:
    target = _norm_region(wilayah)
    if not target:
        return None, []

    normalized_regions = [
        (label, tuple(_norm_region(alias) for alias in aliases))
        for label, aliases in _REGIONS
    ]
    exact = [label for label, aliases in normalized_regions if target in aliases]
    if len(exact) == 1:
        return exact[0], []

    scores: list[tuple[float, int, str]] = []
    for label, aliases in normalized_regions:
        fuzzy_aliases = [alias for alias in aliases if alias not in _SHORT_REGION_ALIASES]
        if not fuzzy_aliases:
            continue
        score = max(SequenceMatcher(None, target, alias).ratio() for alias in fuzzy_aliases)
        scores.append((score, len(_norm_region(label)), label))

    scores.sort(key=lambda item: (item[0], item[1]), reverse=True)
    candidates = [label for _, _, label in scores[:3]]
    if scores and scores[0][0] >= 0.85:
        next_score = scores[1][0] if len(scores) > 1 else 0.0
        if scores[0][0] - next_score >= 0.05:
            return scores[0][2], []

    return None, candidates


def resolve_region_from_text(text: str) -> tuple[Optional[str], list[str]]:
    normalized = _norm_region(text)
    for canonical, aliases in sorted(_REGIONS, key=lambda item: max(map(len, item[1])), reverse=True):
        if any(re.search(rf"(?<!\w){re.escape(alias)}(?!\w)", normalized) for alias in aliases):
            return canonical, []

    tokens = normalized.split()
    candidates: set[str] = set()
    for size in range(min(4, len(tokens)), 0, -1):
        for start in range(len(tokens) - size + 1):
            target = " ".join(tokens[start:start + size])
            resolved, suggestions = _resolve_region(target)
            if resolved:
                return resolved, []
            candidates.update(suggestions)
    return None, sorted(candidates)[:3]


def resolve_regions_from_text(text: str) -> list[str]:
    normalized = _norm_region(text)
    matches: list[tuple[int, int, str]] = []
    for canonical, aliases in _REGIONS:
        for alias in aliases:
            normalized_alias = _norm_region(alias)
            if not normalized_alias:
                continue
            matches.extend(
                (match.start(), -len(normalized_alias), canonical)
                for match in re.finditer(
                    rf"(?<!\w){re.escape(normalized_alias)}(?!\w)",
                    normalized,
                )
            )

    regions: list[str] = []
    occupied: list[tuple[int, int]] = []
    for start, negative_length, canonical in sorted(matches):
        end = start - negative_length
        if any(start < occupied_end and end > occupied_start for occupied_start, occupied_end in occupied):
            continue
        occupied.append((start, end))
        if canonical not in regions:
            regions.append(canonical)

    if regions:
        return regions
    resolved, _ = resolve_region_from_text(text)
    return [resolved] if resolved else []


def _format_data(
    payload: dict,
    var_id: int,
    period: dict,
    wilayah: Optional[str],
    available: list[str],
    kategori: Optional[str] = None,
) -> dict:
    var_info = (payload.get("var") or [{}])[0]
    judul = var_info.get("label") or var_info.get("title")
    resolved_region = None
    if wilayah:
        resolved_region, candidates = _resolve_region(wilayah)
        if resolved_region is None:
            return {
                "judul": judul,
                "error": f"Nama wilayah '{wilayah}' tidak dikenali dengan yakin.",
                "wilayah_kandidat": candidates,
                "status_hasil": "kosong",
            }
    content = payload.get("datacontent") or {}
    connection = None
    try:
        connection = bps_data_store.init_db()
        table_id = str(var_id)
        stored = bps_data_store.cari_nilai(
            connection,
            table_id,
            tahun=[str(period["th"])],
        )
        if stored["meta"] is None or (content and not stored["baris"]):
            bps_data_store.simpan_nilai(
                connection,
                payload,
                table_id=table_id,
            )
            connection.commit()
            stored = bps_data_store.cari_nilai(
                connection,
                table_id,
                tahun=[str(period["th"])],
            )

        available_regions = [
            str(row["wilayah"])
            for row in connection.execute(
                "SELECT DISTINCT wilayah FROM nilai_data WHERE id_tabel=? AND tahun=?",
                (table_id, str(period["th"])),
            )
        ]
        matched_regions = (
            bps_data_store.deteksi_wilayah(connection, table_id, wilayah)
            if wilayah
            else None
        )
        if wilayah and not matched_regions:
            return {
                "judul": stored["meta"]["judul"] if stored["meta"] else judul,
                "error": f"Data untuk {resolved_region} tidak tersedia pada variabel ini.",
                "wilayah_ditafsirkan": resolved_region,
                "wilayah_tersedia": available_regions[:30],
                "status_hasil": "kosong",
            }

        requested_categories = (
            bps_data_store.deteksi_kategori(connection, table_id, kategori)
            if kategori
            else None
        )
        if kategori and not requested_categories:
            available_categories = [
                str(row["turvar"])
                for row in connection.execute(
                    "SELECT DISTINCT turvar FROM nilai_data WHERE id_tabel=? AND tahun=? AND turvar<>''",
                    (table_id, str(period["th"])),
                )
            ]
            return {
                "judul": stored["meta"]["judul"] if stored["meta"] else judul,
                "error": f"Kategori '{kategori}' tidak tersedia pada tahun {period['th']}.",
                "kategori_tersedia": available_categories[:30],
                "status_hasil": "kosong",
            }

        result = bps_data_store.cari_nilai(
            connection,
            table_id,
            wilayah=matched_regions,
            tahun=[str(period["th"])],
            turvar=requested_categories,
        )
    except bps_data_store.BpsDatabaseError:
        logger.exception("MySQL query failed for BPS table %s.", var_id)
        raise BpsError("Penyimpanan data BPS belum dapat dibaca saat ini.") from None
    finally:
        if connection is not None:
            connection.close()

    metadata = result["meta"] or {}
    rows = [
        {
            key: value
            for key, value in {
                "wilayah": row["wilayah"],
                "kategori": row["turvar"],
                "periode": row["turtahun"],
                "nilai": (
                    int(row["nilai"])
                    if isinstance(row["nilai"], float) and row["nilai"].is_integer()
                    else row["nilai"]
                ),
            }.items()
            if value not in ("", None, "0")
        }
        for row in result["baris"]
        if row["nilai"] is not None
    ]
    if content and not rows:
        return {
            "catatan": "Nilai dari respons tersimpan tidak dapat didekode ke tabel terstruktur.",
            "judul": metadata.get("judul") or judul,
            "satuan": metadata.get("satuan") or var_info.get("unit"),
            "tahun": period["th"],
            "status_hasil": "kosong",
        }
    if resolved_region and not rows:
        return {
            "judul": metadata.get("judul") or judul,
            "error": f"Data untuk {resolved_region} tidak tersedia pada variabel ini.",
            "wilayah_ditafsirkan": resolved_region,
            "wilayah_tersedia": available_regions[:30],
            "status_hasil": "kosong",
        }

    formatted = {
        "judul": metadata.get("judul") or judul,
        "sumber": "BPS Provinsi Sumatera Selatan",
        "sifat_data": "proyeksi, bukan hasil pencacahan" if "proyeksi" in str(judul).lower() else "data resmi",
        "satuan": metadata.get("satuan") or var_info.get("unit"),
        "definisi": str(var_info.get("def") or "")[:300],
        "tahun": period["th"],
        "tahun_mendatang": int(period["th"]) > datetime.now(WIB).year,
        "rentang_tahun_tersedia": f"{available[-1]} sampai {available[0]}",
        **({"wilayah_ditafsirkan": resolved_region} if resolved_region else {}),
        "jumlah_baris": len(rows),
        "terpotong": len(rows) > 150,
        "data": rows[:150],
    }
    if metadata.get("last_update_bps"):
        formatted["last_update_bps"] = metadata["last_update_bps"]
    if metadata.get("catatan"):
        formatted["catatan_tabel"] = metadata["catatan"]
    if payload.get("_catatan"):
        formatted["catatan_data"] = payload["_catatan"]
    return formatted


def ambil_data(
    var_id: int,
    tahun: Optional[str] = None,
    wilayah: Optional[str] = None,
    tahun_mulai: Optional[str] = None,
    tahun_akhir: Optional[str] = None,
    kategori: Optional[str] = None,
) -> dict:
    """Mengambil nilai satu tahun atau rentang tahun untuk satu variabel BPS Sumatera Selatan.

    Tanpa tahun, yang diambil adalah tahun terbaru yang sudah berjalan (bukan tahun proyeksi
    mendatang). Isi tahun untuk tahun lain, termasuk proyeksi masa depan jika tersedia. Isi
    parameter wilayah (mis. "Palembang", "Muara Enim") jika pengguna menanyakan satu
    kabupaten/kota; kosongkan untuk data tingkat provinsi atau semua wilayah. Untuk beberapa
    tahun berurutan, isi tahun_mulai dan tahun_akhir; rentang dibatasi maksimal 10 tahun.

    Args:
        var_id: ID variabel dari hasil cari_variabel.
        tahun: tahun yang diminta, mis. "2025". Kosongkan untuk tahun terbaru.
        wilayah: nama kabupaten/kota untuk memfilter baris data.
        tahun_mulai: tahun awal rentang (opsional).
        tahun_akhir: tahun akhir rentang (opsional).
        kategori: label kategori (turvar) yang diminta, mis. "SD". Kosongkan untuk semua kategori.
    """
    try:
        vid = int(var_id)
        resolved_region = None
        if wilayah:
            resolved_region, candidates = _resolve_region(wilayah)
            if resolved_region is None:
                return {
                    "error": f"Nama wilayah '{wilayah}' tidak dikenali dengan yakin.",
                    "wilayah_kandidat": candidates,
                    "status_hasil": "kosong",
                }
        periods = _periods(vid)
        if not periods:
            return {
                "error": "Variabel ini tidak memiliki data tahunan.",
                "status_hasil": "kosong",
            }
        available = [str(p["th"]) for p in periods]
        if tahun_mulai is not None or tahun_akhir is not None:
            if tahun is not None:
                return {
                    "error": "Gunakan tahun atau rentang tahun, bukan keduanya sekaligus.",
                    "input_tidak_valid": True,
                    "status_hasil": "kosong",
                }
            if tahun_mulai is None:
                tahun_mulai = tahun_akhir
            if tahun_akhir is None:
                tahun_akhir = tahun_mulai
            try:
                start_year = int(str(tahun_mulai).strip())
                end_year = int(str(tahun_akhir).strip())
            except (TypeError, ValueError):
                return {
                    "error": "Tahun awal dan akhir harus berupa angka tahun.",
                    "input_tidak_valid": True,
                    "status_hasil": "kosong",
                }
            if not (1000 <= start_year <= 9999 and 1000 <= end_year <= 9999):
                return {
                    "error": "Tahun awal dan akhir tidak valid.",
                    "input_tidak_valid": True,
                    "status_hasil": "kosong",
                }
            if start_year > end_year:
                return {
                    "error": "Tahun awal rentang tidak boleh melebihi tahun akhir.",
                    "input_tidak_valid": True,
                    "status_hasil": "kosong",
                }
            requested_years = list(range(start_year, end_year + 1))
            if len(requested_years) > 10:
                return {
                    "error": "Rentang tahun dibatasi maksimal 10 tahun.",
                    "input_tidak_valid": True,
                    "status_hasil": "kosong",
                }
            period_by_year = {str(period["th"]): period for period in periods}
            selected_periods = [
                period_by_year[str(year)]
                for year in requested_years
                if str(year) in period_by_year
            ]
            missing_years = [
                str(year) for year in requested_years
                if str(year) not in period_by_year
            ]
            if not selected_periods:
                return {
                    "error": f"Tidak ada tahun dalam rentang {start_year}-{end_year} yang tersedia.",
                    "input_tidak_valid": False,
                    "tahun_tersedia": available[:10],
                    "tahun_tidak_tersedia": [str(year) for year in requested_years],
                    "status_hasil": "kosong",
                }

            def load_year(period: dict) -> dict:
                payload = _fetch_catalog_row(
                    _dynamic_table_row(vid, str(period["th"]))
                )
                return _format_data(payload, vid, period, resolved_region, available, kategori)

            with ThreadPoolExecutor(max_workers=6) as pool:
                yearly_results = list(pool.map(load_year, selected_periods))
            data_by_year = []
            for period, result in zip(selected_periods, yearly_results):
                result_year = str(result.get("tahun") or period["th"])
                rows = result.get("data")
                if result.get("error") or not isinstance(rows, list) or not rows:
                    if result_year:
                        missing_years.append(result_year)
                    continue
                data_by_year.append({
                    "tahun": result_year,
                    "judul": result.get("judul"),
                    "satuan": result.get("satuan"),
                    "data": rows,
                })
            data_by_year.sort(key=lambda item: int(item["tahun"]))
            return {
                "judul": next(
                    (result.get("judul") for result in yearly_results if result.get("judul")),
                    None,
                ),
                "sumber": "BPS Provinsi Sumatera Selatan",
                "satuan": next(
                    (result.get("satuan") for result in yearly_results if result.get("satuan")),
                    None,
                ),
                "tahun_mulai": start_year,
                "tahun_akhir": end_year,
                "data_per_tahun": data_by_year,
                "tahun_tersedia": [item["tahun"] for item in data_by_year],
                "tahun_tidak_tersedia": sorted(set(missing_years), key=int),
                "jumlah_tahun": len(data_by_year),
                **({"wilayah_ditafsirkan": resolved_region} if resolved_region else {}),
                "status_hasil": "ditemukan" if data_by_year else "kosong",
            }

        if tahun:
            chosen = next((p for p in periods if str(p["th"]) == str(tahun).strip()), None)
            if chosen is None:
                return {
                    "error": f"Tahun {tahun} tidak tersedia.",
                    "status_hasil": "kosong",
                    "rentang_tahun_tersedia": f"{available[-1]} sampai {available[0]}",
                    "tahun_tersedia": available[:10],
                }
        else:
            this_year = datetime.now(WIB).year
            chosen = next((p for p in periods if int(p["th"]) <= this_year), periods[0])
        payload = _fetch_catalog_row(
            _dynamic_table_row(vid, str(chosen["th"]))
        )
        return _format_data(payload, vid, chosen, resolved_region, available, kategori)
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}
    except (KeyError, ValueError, TypeError):
        return {
            "error": "Format respons BPS tidak dikenali.",
            "status_hasil": "gagal_teknis",
        }


def indikator_utama(
    nama: str,
    tahun: Optional[str] = None,
    wilayah: Optional[str] = None,
    tahun_mulai: Optional[str] = None,
    tahun_akhir: Optional[str] = None,
    kategori: Optional[str] = None,
) -> dict:
    """Mengambil data indikator kanonik untuk tahun dan wilayah yang diminta.

    nama wajib persis salah satu nama di peta kanonik bersama config/bps_indicators.json.
    Tool ini tidak menerima var_id bebas. Tanpa tahun, mengambil tahun berjalan terbaru
    yang tersedia; tanpa wilayah, mengembalikan semua wilayah. Jumlah penduduk memakai
    estimasi var_id 262; proyeksi memakai seri berbeda, var_id 51.

    Args:
        nama: nama indikator kanonik dari daftar tetap.
        tahun: tahun yang diminta (opsional).
        wilayah: nama wilayah (opsional); kosongkan untuk semua wilayah.
        tahun_mulai: tahun awal rentang opsional, sampai dengan tahun_akhir.
        tahun_akhir: tahun akhir rentang opsional; rentang maksimal 10 tahun.
        kategori: label kategori turvar yang diminta; kosongkan untuk semua kategori.
    """
    if not isinstance(nama, str) or nama not in _INDIKATOR_UTAMA:
        return {
            "error": "Nama indikator tidak didukung.",
            "nama_didukung": list(_INDIKATOR_UTAMA),
            "status_hasil": "kosong",
        }

    indicator = _INDIKATOR_UTAMA[nama]
    category_filter = {"kategori": kategori} if kategori is not None else {}
    if tahun_mulai is not None or tahun_akhir is not None:
        result = ambil_data(
            int(indicator["var_id"]),
            wilayah=wilayah,
            tahun_mulai=tahun_mulai,
            tahun_akhir=tahun_akhir,
            **category_filter,
        )
    else:
        result = ambil_data(
            int(indicator["var_id"]),
            tahun=tahun,
            wilayah=wilayah,
            **category_filter,
        )
    if "error" in result:
        return {"nama_indikator": nama, "var_id": int(indicator["var_id"]), **result}
    result["nama_indikator"] = nama
    result["var_id"] = int(indicator["var_id"])
    if indicator.get("province_only"):
        result["wilayah_cakupan"] = "Provinsi Sumatera Selatan"
    if indicator.get("vervar_label") and isinstance(result.get("data"), list):
        expected_label = str(indicator["vervar_label"]).casefold().strip()
        matching_rows = [
            row for row in result["data"]
            if str(row.get("wilayah", "")).casefold().strip().lstrip("0123456789. )-") == expected_label
        ]
        if not matching_rows:
            return {
                "nama_indikator": nama,
                "var_id": int(indicator["var_id"]),
                "error": f"Kategori '{indicator['vervar_label']}' tidak tersedia pada periode ini.",
                "status_hasil": "kosong",
            }
        result["data"] = matching_rows
        result["jumlah_baris"] = len(matching_rows)
    elif indicator.get("vervar_label") and isinstance(result.get("data_per_tahun"), list):
        expected_label = str(indicator["vervar_label"]).casefold().strip()
        missing_category_years = []
        for yearly_result in result["data_per_tahun"]:
            yearly_result["data"] = [
                row for row in yearly_result["data"]
                if str(row.get("wilayah", "")).casefold().strip().lstrip("0123456789. )-")
                == expected_label
            ]
            if not yearly_result["data"]:
                missing_category_years.append(str(yearly_result["tahun"]))
        result["data_per_tahun"] = [
            item for item in result["data_per_tahun"] if item["data"]
        ]
        result["tahun_tersedia"] = [item["tahun"] for item in result["data_per_tahun"]]
        result["tahun_tidak_tersedia"] = sorted(
            set(result.get("tahun_tidak_tersedia", [])) | set(missing_category_years),
            key=int,
        )
        result["jumlah_tahun"] = len(result["data_per_tahun"])
        result["status_hasil"] = "ditemukan" if result["data_per_tahun"] else "kosong"
    if "sifat_data" in indicator:
        result["sifat_data"] = indicator["sifat_data"]
    if "catatan_sumber" in indicator:
        result["catatan_sumber"] = indicator["catatan_sumber"]
    return result


def nama_indikator_utama() -> list[str]:
    """Nama-nama indikator kanonik yang dimuat dari peta bersama."""
    return list(_INDIKATOR_UTAMA)


_EDITORIAL_ID_FIELDS = {
    "pressrelease": ("br_id", "pressrelease_id", "id"),
    "publication": ("pub_id", "publication_id", "id"),
    "news": ("news_id", "id"),
}
_EDITORIAL_MONTHS = {
    "januari": 1, "februari": 2, "maret": 3, "april": 4,
    "mei": 5, "juni": 6, "juli": 7, "agustus": 8,
    "september": 9, "oktober": 10, "november": 11, "desember": 12,
}


def _editorial_records(payload: dict) -> list[dict]:
    data = payload.get("data")
    if isinstance(data, dict):
        for key in ("items", "results", "list"):
            records = data.get(key)
            if isinstance(records, list):
                return [record for record in records if isinstance(record, dict)]
        if any(
            key in data
            for key in ("title", "judul", "abstract", "br_id", "pub_id", "news_id", "id")
        ):
            return [data]
        if not data:
            return []
        raise bps_csv_catalog.CatalogError("Format detail arsip dari WebAPI BPS tidak dikenali.")
    if not isinstance(data, list):
        raise bps_csv_catalog.CatalogError("Format arsip dari WebAPI BPS tidak dikenali.")
    if not data:
        return []
    for part in reversed(data):
        if isinstance(part, list):
            return [record for record in part if isinstance(record, dict)]
    records = [
        record for record in data
        if isinstance(record, dict)
        and any(
            key in record
            for key in ("title", "judul", "abstract", "br_id", "pub_id", "news_id", "id")
        )
    ]
    if records:
        return records
    if all(
        isinstance(record, dict)
        and set(record).issubset({"pages", "total", "page", "limit"})
        for record in data
    ):
        return []
    raise bps_csv_catalog.CatalogError("Format daftar arsip dari WebAPI BPS tidak dikenali.")


def _editorial_value(record: dict, *fields: str) -> str:
    for field in fields:
        value = record.get(field)
        if isinstance(value, (str, int, float)) and str(value).strip():
            return str(value).strip()
    return ""


def _editorial_date_sort_key(value: str) -> datetime:
    if not value:
        return datetime.min
    normalized = value.strip().replace("Z", "+00:00")
    try:
        return datetime.fromisoformat(normalized).replace(tzinfo=None)
    except ValueError:
        pass
    for date_format in ("%d-%m-%Y", "%d/%m/%Y", "%Y/%m/%d", "%Y-%m"):
        try:
            return datetime.strptime(value.strip(), date_format)
        except ValueError:
            continue
    return datetime.min


def _editorial_content_search(
    model: str,
    kata_kunci: Optional[str],
    jumlah: int,
    tahun: Optional[int],
    bulan: Optional[str],
    halaman: int,
) -> dict:
    requested_period = {"tahun": tahun, "bulan": bulan}
    try:
        normalized_month = None
        if bulan is not None and str(bulan).strip():
            month_text = str(bulan).strip().casefold()
            normalized_month = (
                int(month_text)
                if month_text.isdecimal()
                else _EDITORIAL_MONTHS.get(month_text, month_text)
            )
        payload = bps_csv_catalog.fetch_editorial_json(
            model,
            domain=DOMAIN,
            keyword=kata_kunci,
            year=tahun,
            month=normalized_month,
            page=halaman,
        )
        rows = _editorial_records(payload)
        rows.sort(
            key=lambda row: _editorial_date_sort_key(
                _editorial_value(
                    row, "rl_date", "release_date", "pub_date", "date", "updt_date"
                )
            ),
            reverse=True,
        )
        limit = min(3, max(1, int(jumlah)))
        selected = rows[:limit]
        results = []
        detail_failures = 0
        for row in selected:
            item_id = _editorial_value(row, *_EDITORIAL_ID_FIELDS[model])
            detail = {}
            detail_unavailable = not item_id
            if item_id:
                try:
                    detail_payload = bps_csv_catalog.fetch_editorial_json(
                        model,
                        domain=DOMAIN,
                        identifier=item_id,
                    )
                    detail_records = _editorial_records(detail_payload)
                    detail = next(iter(detail_records), {})
                    detail_unavailable = not detail_records
                except bps_csv_catalog.CatalogError:
                    detail_unavailable = True

            merged = {**row, **detail}
            abstract = _editorial_value(
                merged, "abstract", "abstrak", "description", "isi", "content"
            )
            summary = re.sub(r"<[^>]*>", " ", html.unescape(abstract))
            summary = re.sub(r"\s+", " ", summary).strip()[:900]
            if detail_unavailable or not summary:
                detail_failures += 1
            source_link = _editorial_value(
                merged, "pdf", "pdf_url", "url_pdf", "link", "url", "permalink"
            )
            if source_link and not source_link.startswith(("https://", "http://")):
                source_link = ""
            results.append({
                "id": item_id or None,
                "judul": _editorial_value(merged, "title", "judul", "name"),
                "tanggal_rilis": _editorial_value(
                    merged, "rl_date", "release_date", "pub_date", "date", "updt_date"
                ),
                "ringkasan": summary,
                "tautan": source_link,
            })

        result = {
            "jenis_konten": model,
            "kata_kunci": str(kata_kunci).strip() if kata_kunci else None,
            "periode_diminta": requested_period,
            "halaman": int(halaman),
            "hasil": results,
            "status_hasil": "ditemukan" if results else "kosong",
        }
        if detail_failures:
            result["abstrak_tidak_tersedia"] = detail_failures
        return result
    except (bps_csv_catalog.CatalogError, TypeError, ValueError) as exc:
        return {
            "jenis_konten": model,
            "periode_diminta": requested_period,
            "error": str(exc),
            "status_hasil": "gagal_teknis",
        }


def berita_resmi_statistik(
    kata_kunci: Optional[str] = None,
    jumlah: int = 3,
    tahun: Optional[int] = None,
    bulan: Optional[str] = None,
    halaman: int = 1,
) -> dict:
    """Search BPS press releases and retrieve the abstract for up to three results."""
    return _editorial_content_search(
        "pressrelease", kata_kunci, jumlah, tahun, bulan, halaman
    )


def publikasi_terbaru(
    kata_kunci: Optional[str] = None,
    jumlah: int = 3,
    tahun: Optional[int] = None,
    bulan: Optional[str] = None,
    halaman: int = 1,
) -> dict:
    """Search BPS publications and retrieve the abstract for up to three results."""
    return _editorial_content_search(
        "publication", kata_kunci, jumlah, tahun, bulan, halaman
    )


def berita_statistik(
    kata_kunci: Optional[str] = None,
    jumlah: int = 3,
    tahun: Optional[int] = None,
    bulan: Optional[str] = None,
    halaman: int = 1,
) -> dict:
    """Search BPS news and retrieve the returned article summary for up to three results."""
    return _editorial_content_search("news", kata_kunci, jumlah, tahun, bulan, halaman)


def _static_table_rows(keyword: str, domain: str) -> list[dict]:
    if domain != DOMAIN:
        return []
    tables: dict[str, dict] = {}
    for row in _catalog_rows():
        table_id = row.get("id_tabel", "")
        title = row.get("judul", "")
        if row.get("jenis_tabel") != "Tabel Statis" or not table_id or not title:
            continue
        table = tables.setdefault(table_id, {
            "table_id": table_id,
            "title": title,
            "domain": domain,
            "jenis_tabel": "Tabel Statis",
            "years": set(),
            "status_by_year": {},
            "aliases": _variable_aliases(title),
            "context_aliases": list(dict.fromkeys([
                row.get("subjek", ""),
                row.get("kategori_subjek", ""),
            ])),
            "catalog_row": row,
        })
        year = str(row.get("tahun") or "").strip()
        if year:
            table["years"].add(year[:4])
            if row.get("status_tarik"):
                table["status_by_year"][year[:4]] = row["status_tarik"]
            if not table.get("catalog_row") or row.get("status_tarik") == "ok":
                table["catalog_row"] = row
    current_year = datetime.now(WIB).year
    for table in tables.values():
        available_years = sorted(table["years"], key=int)
        relevant_years = [year for year in available_years if int(year) <= current_year] or available_years
        table["status_tarik"] = table["status_by_year"].get(
            relevant_years[-1] if relevant_years else "",
            "belum_diaudit",
        )
        table.pop("status_by_year", None)
    return list(tables.values())


def _static_table_dataframe(
    table_html: str,
    wilayah: Optional[str | list[str]] = None,
    tahun_diminta: Optional[str] = None,
) -> tuple[Optional[pd.DataFrame], dict[str, Any]]:
    try:
        frames = pd.read_html(
            StringIO(html.unescape(table_html)),
            thousands=".",
            decimal=",",
        )
    except (ImportError, LxmlError, TypeError, ValueError):
        return None, {}
    if not frames:
        return None, {}

    frame = frames[0].dropna(how="all").dropna(axis=1, how="all")
    frame = frame.loc[:, ~frame.T.duplicated()].copy()
    if isinstance(frame.columns, pd.MultiIndex):
        frame.columns = [
            " ".join(str(part) for part in column if str(part) != "nan").strip()
            for column in frame.columns
        ]

    info: dict[str, Any] = {"jumlah_baris_total": len(frame)}
    year_columns = [
        column for column in frame.columns
        if _YEAR_PATTERN.findall(str(column))
    ]
    year_column = next(
        (
            column for column in frame.columns
            if frame[column].astype(str).str.fullmatch(r"20\d{2}").any()
        ),
        None,
    )
    years = {
        year
        for column in year_columns
        for year in _YEAR_PATTERN.findall(str(column))
    }
    if year_column is not None:
        years.update(
            frame[year_column].astype(str).loc[
                frame[year_column].astype(str).str.fullmatch(r"20\d{2}")
            ].tolist()
        )
    info["tahun_tersedia"] = sorted(years, key=int)

    if wilayah and not frame.empty:
        requested_regions = wilayah if isinstance(wilayah, list) else [wilayah]
        requested_names = set()
        for requested in requested_regions:
            canonical, _ = resolve_region_from_text(str(requested))
            requested_names.add(_static_region_key(canonical or str(requested)))
            requested_names.add(_static_region_key(str(requested)))
        first_column = frame.columns[0]
        region_keys = frame[first_column].astype(str).map(_static_region_key)
        matched = region_keys.isin(requested_names)
        frame = frame.loc[matched].copy()
        if frame.empty:
            info["wilayah_tidak_ditemukan"] = True

    if not years:
        return frame, info

    ordered_years = sorted(years, key=int)
    requested_year = str(tahun_diminta or "").strip()
    all_year_columns = [
        column for column in frame.columns
        if _YEAR_PATTERN.findall(str(column))
    ]
    selected_year = requested_year if requested_year in years else ordered_years[-1]
    matching_year_columns = [
        column for column in all_year_columns
        if selected_year in _YEAR_PATTERN.findall(str(column))
    ]
    if matching_year_columns:
        non_year_columns = [
            column for column in frame.columns if column not in all_year_columns
        ]
        frame = frame.loc[:, [*non_year_columns, *matching_year_columns]]
        if requested_year and requested_year not in years:
            info["tahun_diminta_tidak_ditemukan"] = requested_year
            info["tahun_terbaru_tersedia"] = selected_year
        return frame, info
    if year_column is not None:
        frame = frame.loc[frame[year_column].astype(str) == selected_year]
        if requested_year and requested_year not in years:
            info["tahun_diminta_tidak_ditemukan"] = requested_year
            info["tahun_terbaru_tersedia"] = selected_year
        return frame, info
    if requested_year and requested_year in years:
        return frame, info

    if requested_year:
        info["tahun_diminta_tidak_ditemukan"] = requested_year
        info["tahun_terbaru_tersedia"] = selected_year
    return frame, info


def _static_region_key(value: str) -> str:
    normalized = re.sub(r"^\d+[\s.)-]+", "", str(value).casefold().strip())
    normalized = re.sub(
        r"^(?:kabupaten|kab\.?|kota|provinsi)\s*",
        "",
        normalized,
    )
    return " ".join(re.findall(r"[a-z]+", normalized))


def _static_table_fallback_text(table_html: str) -> str:
    del table_html
    return "Isi tabel tidak dapat dibaca dengan aman dari format respons yang diterima."


def _static_table_plain_text(frame: pd.DataFrame) -> str:
    display = frame.copy()
    for column in display.columns:
        if _YEAR_PATTERN.fullmatch(str(column).strip()):
            continue
        values = display[column].astype(str)
        if values.str.fullmatch(r"20\d{2}").all():
            continue
        if not pd.api.types.is_numeric_dtype(display[column]):
            continue

        def format_number(value: Any) -> str:
            if pd.isna(value):
                return ""
            number = float(value)
            formatted = f"{number:,.3f}".rstrip("0").rstrip(".")
            return formatted.replace(",", "_").replace(".", ",").replace("_", ".")

        display[column] = display[column].map(format_number)
    headers = [str(column).strip() or "Nilai" for column in display.columns]
    lines = []
    for values in display.itertuples(index=False, name=None):
        fields = [
            f"{header}: {str(value).strip()}"
            for header, value in zip(headers, values)
            if str(value).strip() not in {"", "nan", "None"}
        ]
        if fields:
            lines.append("- " + "; ".join(fields))
    return "\n".join(lines)


def tabel_statis_ke_teks(
    table_html: str,
    wilayah: Optional[str | list[str]] = None,
    maks_baris: int = 25,
) -> str:
    """Convert BPS static-table HTML into concise plain text."""
    frame, info = _static_table_dataframe(table_html, wilayah)
    if frame is None:
        return _static_table_fallback_text(table_html)
    if info.get("wilayah_tidak_ditemukan"):
        return f"Tidak ada baris tabel untuk wilayah {wilayah}."
    if frame.empty:
        return "Tabel statis tidak memuat baris data."
    return _static_table_plain_text(frame.head(max(1, maks_baris)))


def _static_table_text(
    payload: dict,
    wilayah: Optional[str | list[str]] = None,
    tahun_diminta: Optional[str] = None,
) -> tuple[Optional[str], dict[str, Any]]:
    data = payload.get("data")
    table_html = data.get("table") if isinstance(data, dict) else None
    if not isinstance(table_html, str) or not table_html.strip():
        return None, {}
    frame, info = _static_table_dataframe(table_html, wilayah, tahun_diminta)
    if frame is None:
        return _static_table_fallback_text(table_html), info
    if info.get("wilayah_tidak_ditemukan"):
        return f"Tidak ada baris tabel untuk wilayah {wilayah}.", info
    if frame.empty:
        return "Tabel statis tidak memuat baris data.", info
    info["baris_ditampilkan"] = min(len(frame), 25)
    info["terpotong"] = len(frame) > 25
    return _static_table_plain_text(frame.head(25)), info


def _static_excel_link(payload: dict) -> Optional[str]:
    containers = [payload]
    if isinstance(payload.get("data"), dict):
        containers.append(payload["data"])
    for container in containers:
        for key in ("excel", "excel_url", "url_excel"):
            value = container.get(key)
            if isinstance(value, str) and value.startswith("https://"):
                return value
    return None


def tabel_statis(
    kata_kunci: str,
    jumlah: int = 3,
    wilayah: Optional[str] = None,
    tahun_diminta: Optional[str] = None,
) -> dict:
    """Mencari dan membaca tabel statis BPS, termasuk topik yang tidak ada di variabel.

    Gunakan untuk tabel seperti upah/gaji, tabel tematik, atau ketika cari_variabel
    tidak menemukan indikator yang sesuai. Hasil hanya memuat judul dan isi tabel bersih.

    Args:
        kata_kunci: topik inti tabel, misalnya "upah minimum" atau "gaji".
        jumlah: jumlah tabel teratas yang diminta, dibatasi 1 sampai 3.
    """
    keyword = _clean_search_keyword(kata_kunci or "")
    if not keyword:
        return {"jumlah_ditemukan": 0, "hasil": [], "status_hasil": "kosong"}

    count = max(1, min(int(jumlah or 1), 3))
    try:
        results = []
        static_rows = _static_table_rows(keyword, DOMAIN)
        catalog_rows = _catalog_rows()
        search = bps_csv_search.Pencari(
            static_rows,
            corpus_rows=catalog_rows,
        ).cari(
            keyword,
            tahun_diminta=tahun_diminta,
            top=3,
            jenis="Tabel Statis",
        )
        ranked = _rank_local_variables(
            keyword,
            static_rows,
            tahun_diminta=tahun_diminta,
        )
        ranked.sort(
            key=lambda table: (
                {"ok": 2, "belum_diaudit": 1, "kosong": 0, "gagal": 0}.get(
                    str(table.get("status_tarik") or "").casefold(),
                    1,
                ),
                int(table.get("similarity_score") or 0),
                int(table.get("latest_year") or 0),
            ),
            reverse=True,
        )
        for table in ranked[:count]:
            if str(table.get("status_tarik") or "").casefold() in {"gagal", "kosong"}:
                continue
            payload = _fetch_catalog_row(table["catalog_row"])
            text, table_info = _static_table_text(
                payload,
                wilayah=wilayah,
                tahun_diminta=tahun_diminta,
            )
            if text:
                results.append({
                    "table_id": table["table_id"],
                    "judul": table["title"],
                    "domain": table["domain"],
                    "cakupan": "Sumatera Selatan",
                    "sumber": "BPS Provinsi Sumatera Selatan",
                    "isi": text,
                    **table_info,
                    **(
                        {"excel": excel_link}
                        if (excel_link := _static_excel_link(payload))
                        else {}
                    ),
                })
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}

    return {
        "jumlah_ditemukan": len(results),
        "hasil": results,
        "kata_inti": search["kata_inti"],
        "kandidat_terdekat": search["saran"],
        "status_hasil": "ditemukan" if results else "kosong",
    }


def _logged(func):
    @functools.wraps(func)
    def wrapper(*args, **kwargs):
        started = time.time()
        logger.info("TOOL mulai: %s args=%s kwargs=%s", func.__name__, args, kwargs)
        result = strip_notes(func(*args, **kwargs))
        logger.info(
            "TOOL selesai: %s %.1fs error=%s",
            func.__name__, time.time() - started,
            isinstance(result, dict) and "error" in result,
        )
        return result
    return wrapper


TOOLS = [
    _logged(f)
    for f in (
        cari_variabel,
        cari_subjek,
        ambil_data,
        indikator_utama,
        berita_resmi_statistik,
        publikasi_terbaru,
        berita_statistik,
        tabel_statis,
        simdasi_daftar_tabel,
        simdasi_cari_data,
    )
]