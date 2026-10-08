"""Tools WebAPI BPS untuk Gemini (function calling)."""
import functools
import csv
import html
import itertools
import json
import logging
import math
import os
import re
import threading
import time
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from difflib import SequenceMatcher
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Callable, Optional
from urllib.parse import quote_plus

import httpx
from dotenv import load_dotenv

load_dotenv()
logger = logging.getLogger(__name__)

BASE = "https://webapi.bps.go.id/v1/api"
DOMAIN = os.getenv("BPS_DOMAIN", "1600")
WIB = timezone(timedelta(hours=7))
_http = httpx.Client(timeout=8.0)
_cache: dict[str, tuple[float, Any]] = {}
_cache_lock = threading.Lock()
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
_VARIABLE_INDEX_TTL = 24 * 60 * 60
_CATALOG_DIR = Path(__file__).resolve().parent
_SUBJECT_CATALOG_PATH = _CATALOG_DIR / "katalog_subjek_1600.csv"
_VARIABLE_CATALOG_PATH = _CATALOG_DIR / "katalog_variabel_1600.csv"
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
    ("fasilitas kesehatan", ("rumah sakit umum", "rumah sakit", "rsud", "faskes")),
    ("jenis kelamin", ("gender", "sex")),
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


def _cached(key: str, ttl: int, loader: Callable[[], Any]) -> Any:
    with _cache_lock:
        hit = _cache.get(key)
    if hit and time.time() - hit[0] < ttl:
        return hit[1]
    value = loader()
    with _cache_lock:
        _cache[key] = (time.time(), value)
    return value


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


def _get(path: str) -> dict:
    api_key = os.getenv("BPS_API_KEY")
    if not api_key:
        raise BpsError("BPS_API_KEY belum dikonfigurasi.")
    try:
        response = _http.get(f"{BASE}/{path.strip('/')}/key/{api_key}/")
    except httpx.RequestError:
        # Sengaja tanpa pesan asli: URL pada exception memuat API key.
        raise BpsError("WebAPI BPS tidak dapat diakses saat ini.") from None
    try:
        payload = response.json()
    except ValueError:
        raise BpsError("WebAPI BPS mengembalikan JSON yang tidak valid.") from None
    if not isinstance(payload, dict):
        raise BpsError("Format respons BPS tidak dikenali.")
    if response.status_code >= 500:
        raise BpsError("WebAPI BPS mengalami gangguan.")
    availability = response.headers.get("data-availability", "").strip().lower()
    if availability == "list-not-available":
        payload["status"] = "OK"
        payload["data"] = [{"pages": 1}, []]
        payload["data_availability"] = availability
        return payload
    if payload.get("status") != "OK":
        payload["status"] = "OK"
        payload["data"] = [{"pages": 1}, []]
        return payload
    return payload


def _list_rows(payload: dict) -> tuple[list[dict], int]:
    data = payload.get("data")
    if isinstance(data, list) and len(data) >= 2 and isinstance(data[1], list):
        meta = data[0] if isinstance(data[0], dict) else {}
        return data[1], int(meta.get("pages", 1) or 1)
    return [], 1


def _strip_html(text: Any, limit: int = 400) -> str:
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", str(text or ""))).strip()[:limit]


def _periods(var_id: int) -> list[dict]:
    """Daftar tahun tersedia untuk satu variabel, terbaru dulu."""
    def load() -> list[dict]:
        rows, _ = _list_rows(_get(f"list/model/th/domain/{DOMAIN}/var/{var_id}"))
        rows = [r for r in rows if str(r.get("th", "")).isdigit()]
        return sorted(rows, key=lambda r: int(r["th"]), reverse=True)

    return _cached(f"th:{DOMAIN}:{var_id}", 3600, load)


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
    result = str(keyword or "").casefold()
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
        if word not in _SEARCH_FILLER_WORDS and word not in _MONTH_WORDS
    ]
    canonical_synonyms = {
        synonym: group[0]
        for group in _VARIABLE_SYNONYM_GROUPS
        for synonym in group
    }
    words = [canonical_synonyms.get(word, word) for word in words]
    return " ".join(words)[:80].strip()


def _stem_keyword(word: str) -> str:
    stem = word
    for prefix in ("meng", "meny", "mem", "men", "peng", "peny", "pem", "pen", "per", "ber", "ter", "ke", "se", "pe", "me"):
        if stem.startswith(prefix) and len(stem) - len(prefix) >= 4:
            stem = stem[len(prefix):]
            break
    for suffix in ("kan", "nya", "an", "i"):
        if stem.endswith(suffix) and len(stem) - len(suffix) >= 4:
            stem = stem[:-len(suffix)]
            break
    return stem


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


def _variable_index_key() -> str:
    return f"variables-index:{DOMAIN}"


def _load_model_rows(path: str) -> list[dict]:
    rows: list[dict] = []
    pages = 1
    page = 1
    while page <= pages:
        page_path = path if page == 1 else f"{path}/page/{page}"
        payload = _get(page_path)
        data = payload.get("data")
        if data == "":
            return rows
        if not isinstance(data, list) or len(data) < 2 or not isinstance(data[1], list):
            raise BpsError("Format daftar variabel BPS tidak dikenali.")
        page_rows, pages = _list_rows(payload)
        if pages > 200:
            raise BpsError("WebAPI BPS melaporkan jumlah halaman variabel yang tidak wajar.")
        rows.extend(row for row in page_rows if isinstance(row, dict))
        page += 1
    return rows


def _load_variable_index() -> dict:
    subject_rows = _load_model_rows(f"list/model/subject/domain/{DOMAIN}")
    subjects = [
        (
            str(row.get("sub_id") or row.get("subject_id")),
            str(row.get("sub_name") or row.get("subject") or row.get("name") or ""),
        )
        for row in subject_rows
        if row.get("sub_id") is not None or row.get("subject_id") is not None
    ]

    def load_subject(subject: tuple[str, str]) -> list[dict]:
        subject_id, subject_name = subject
        rows = _load_model_rows(
            f"list/model/var/domain/{DOMAIN}/subject/{quote_plus(subject_id)}"
        )
        variables = []
        for row in rows:
            var_id = row.get("var_id")
            title = row.get("title") or row.get("var_name")
            if var_id is None or not isinstance(title, str) or not title.strip():
                continue
            variables.append({
                "var_id": str(var_id),
                "title": title.strip(),
                "unit": row.get("unit") or row.get("unit_name") or row.get("satuan"),
                "subject": subject_name,
            })
        return variables

    with ThreadPoolExecutor(max_workers=6) as pool:
        subject_variables = list(pool.map(load_subject, subjects))
    variables_by_id: dict[str, dict] = {}
    for variables in subject_variables:
        for variable in variables:
            variables_by_id.setdefault(variable["var_id"], variable)
    variables = sorted(
        variables_by_id.values(),
        key=lambda variable: (variable["title"].casefold(), variable["var_id"]),
    )
    return {
        "variables": variables,
        "subjects": [
            {
                "sub_id": subject_id,
                "subject": subject_name,
                "category": "",
                "category_id": "",
                "jumlah_tabel": None,
            }
            for subject_id, subject_name in subjects
        ],
        "refreshed_at": datetime.now(WIB).isoformat(timespec="seconds"),
    }


def _load_catalog_index() -> dict:
    def read_rows(path: Path) -> list[dict]:
        if not path.is_file():
            return []
        try:
            with path.open(encoding="utf-8-sig", newline="") as source:
                return list(csv.DictReader(source))
        except (OSError, UnicodeError, csv.Error) as exc:
            raise BpsError(f"Katalog BPS lokal tidak dapat dibaca: {path.name}.") from exc

    subjects = []
    for row in read_rows(_SUBJECT_CATALOG_PATH):
        subject_id = (row.get("sub_id") or "").strip()
        subject_name = (row.get("subjek") or "").strip()
        if not subject_id or not subject_name:
            continue
        table_count = (row.get("jumlah_tabel") or "").strip()
        subjects.append({
            "sub_id": subject_id,
            "subject": subject_name,
            "category": (row.get("kategori") or "").strip(),
            "category_id": (row.get("subcat_id") or "").strip(),
            "jumlah_tabel": int(table_count) if table_count.isdigit() else None,
        })

    subject_by_id = {subject["sub_id"]: subject for subject in subjects}
    variables = []
    for row in read_rows(_VARIABLE_CATALOG_PATH):
        variable_id = (row.get("var_id") or "").strip()
        title = (row.get("judul") or "").strip()
        if not variable_id.isdigit() or not title:
            continue
        subject_id = (row.get("sub_id") or "").strip()
        subject = subject_by_id.get(subject_id, {})
        latest_year = (row.get("tahun_terbaru") or "").strip()
        year_count = (row.get("jumlah_tahun") or "").strip()
        variables.append({
            "var_id": variable_id,
            "title": title,
            "unit": (row.get("satuan") or "").strip() or None,
            "subject_id": subject_id,
            "subject": (row.get("subjek") or "").strip() or subject.get("subject", ""),
            "category": (row.get("kategori") or "").strip() or subject.get("category", ""),
            "category_id": subject.get("category_id", ""),
            "latest_year": latest_year if latest_year.isdigit() else None,
            "year_count": int(year_count) if year_count.isdigit() else None,
            "aliases": _variable_aliases(title),
        })

    return {"variables": variables, "subjects": subjects}


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
    catalog = _load_catalog_index()
    try:
        remote = _load_variable_index()
    except BpsError:
        if not catalog["variables"]:
            raise
        logger.warning("WebAPI BPS tidak tersedia; memakai metadata dari katalog CSV lokal.")
        remote = {"variables": [], "subjects": []}
    return _combine_variable_indexes(remote, catalog)


def refresh_variable_index() -> dict:
    index = _load_searchable_variable_index()
    with _cache_lock:
        _cache[_variable_index_key()] = (time.time(), index)
    return index


def _variable_index() -> dict:
    return _cached(_variable_index_key(), _VARIABLE_INDEX_TTL, _load_searchable_variable_index)


def _token_match_score(term: str, title_tokens: set[str]) -> float:
    variants = {term, _VARIABLE_SYNONYMS.get(term, term)}
    for group in _VARIABLE_SYNONYM_GROUPS:
        if term in group:
            variants.update(group)
    fuzzy_variants = set(variants)
    for variant in tuple(variants):
        variants.add(_stem_keyword(variant))
        variants.add(_VARIABLE_SYNONYMS.get(variant, variant))
    if variants & title_tokens:
        return 1.0

    threshold = 0.78 if len(term) >= 7 else 0.84 if len(term) >= 5 else 0.9
    best_score = max(
        (
            SequenceMatcher(None, variant, title_token).ratio()
            for variant in fuzzy_variants
            for title_token in title_tokens
            if min(len(variant), len(title_token)) >= 4
            and abs(len(variant) - len(title_token)) <= max(2, len(variant) // 4)
        ),
        default=0.0,
    )
    return best_score if best_score >= threshold else 0.0


def _rank_local_variables(keyword: str, variables: list[dict]) -> list[dict]:
    terms = list(dict.fromkeys(
        word for word in re.findall(r"[a-z0-9]+", keyword.casefold())
        if len(word) >= 3 and word != "total"
    ))
    male_terms = set(_GENDER_MALE_SYNONYMS)
    female_terms = set(_GENDER_FEMALE_SYNONYMS)
    if set(terms) & male_terms and set(terms) & female_terms:
        terms = [
            term for term in terms
            if term not in male_terms and term not in female_terms
        ]
        terms.extend(("jenis", "kelamin"))
    if not terms:
        return []

    title_tokens_by_id = []
    document_frequency: Counter[str] = Counter()
    for variable in variables:
        searchable = " ".join([
            str(variable.get("title") or ""),
            " ".join(variable.get("aliases") or []),
        ]).casefold()
        tokens = set(re.findall(r"[a-z0-9]+", searchable))
        title_tokens_by_id.append((variable, tokens))
        document_frequency.update(term for term in terms if _token_match_score(term, tokens) > 0)

    quantity_cue = "total" in re.findall(r"[a-z0-9]+", keyword.casefold())
    query_phrase = " ".join(terms)
    ranked: list[tuple[float, int, int, int, str, dict]] = []
    for variable, title_tokens in title_tokens_by_id:
        weighted_score = 0.0
        total_weight = 0.0
        matched_terms = 0
        related_match = False
        fuzzy_match = False
        for term in terms:
            weight = 1.0 + math.log(
                (len(variables) + 1) / (document_frequency[term] + 1)
            )
            match_score = _token_match_score(term, title_tokens)
            if match_score and match_score < 1.0:
                fuzzy_match = True
            if not match_score:
                related_terms = _RELATED_VARIABLE_SYNONYMS.get(term, ())
                if any(
                    all(
                        word in title_tokens or _stem_keyword(word) in title_tokens
                        for word in re.findall(r"[a-z0-9]+", related)
                    )
                    for related in related_terms
                ):
                    match_score = 0.55
                    related_match = True
            weighted_score += weight * match_score
            total_weight += weight
            matched_terms += match_score > 0

        if total_weight == 0 or matched_terms == 0:
            continue

        score = weighted_score / total_weight
        title = str(variable.get("title") or "")
        if quantity_cue and re.search(r"\b(jumlah|total)\b", title, re.IGNORECASE):
            score = min(1.0, score + 0.08)
        score_percent = round(score * 100)
        variable_with_score = dict(variable)
        variable_with_score["similarity_score"] = score_percent
        variable_with_score["match_type"] = (
            "related" if related_match else "fuzzy" if fuzzy_match else "direct"
        )
        normalized_title = " ".join(re.findall(r"[a-z0-9]+", title.casefold()))
        phrase_position = normalized_title.find(query_phrase)
        if phrase_position < 0:
            phrase_position = len(normalized_title) + 1
        ranked.append((
            score,
            matched_terms,
            -phrase_position,
            -len(title),
            title.casefold(),
            variable_with_score,
        ))
    ranked.sort(key=lambda item: item[:4], reverse=True)
    return [item[5] for item in ranked if item[0] >= 0.35]


def catalog_variable_matches(question: str) -> bool:
    keyword = _clean_search_keyword(question or "")
    catalog = _load_catalog_index()
    return bool(_rank_local_variables(keyword, catalog["variables"]))


def cari_variabel(kata_kunci: str) -> dict:
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

    Pencarian juga menormalkan sinonim umum seperti pria/laki-laki,
    wanita/perempuan, dan rumah sakit/fasilitas kesehatan. Pastikan judul
    hasil tetap sesuai karena sebagian sinonim dapat menunjuk kategori yang lebih luas.
    """
    keyword = _clean_search_keyword(kata_kunci or "")
    if not keyword:
        return {
            "jumlah_ditemukan": 0,
            "hasil": [],
            "status_hasil": "kosong",
            "petunjuk": "Masukkan kata inti indikator tanpa nama wilayah.",
        }

    try:
        index = _variable_index()
        variables = _rank_local_variables(keyword, index["variables"])
        candidates = variables[:15]
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}
    except (KeyError, ValueError, TypeError):
        return {"error": "Format respons BPS tidak dikenali.", "status_hasil": "gagal_teknis"}

    hasil = []
    for variable in candidates:
        try:
            year, count, future = _latest_year(int(variable["var_id"]))
        except BpsError:
            year = variable.get("latest_year")
            count = variable.get("year_count") or 0
            future = False
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
        })
    newest = max((int(item["tahun_terbaru"]) for item in hasil if item["tahun_terbaru"]), default=0)
    for item in hasil:
        item["seri_lama"] = bool(item["tahun_terbaru"]) and int(item["tahun_terbaru"]) < newest - 2
    hasil.sort(key=lambda item: item["seri_lama"])

    result = {
        "jumlah_ditemukan": len(variables),
        "hasil": hasil,
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


def warm_cache() -> None:
    now = datetime.now(WIB)

    def brs(i: int) -> None:
        year, month0 = divmod(now.year * 12 + (now.month - 1) - i, 12)
        try:
            _brs_month(year, month0 + 1)
        except BpsError:
            pass

    cutoff = time.time() - 600
    with _cache_lock:
        stale_keys = [
            key for key, (timestamp, _) in _cache.items()
            if key != _variable_index_key() and timestamp < cutoff
        ]
        for key in stale_keys:
            if _cache[key][0] < cutoff:
                _cache.pop(key, None)

    with ThreadPoolExecutor(max_workers=6) as pool:
        list(pool.map(brs, range(12)))
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


def _format_data(payload: dict, var_id: int, period: dict, wilayah: Optional[str], available: list[str]) -> dict:
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
    vervar = _labels(payload.get("vervar")) or {"0": ""}
    turvar = _labels(payload.get("turvar")) or {"0": ""}
    turth = _labels(payload.get("turtahun") or payload.get("turth")) or {"0": ""}
    content = payload.get("datacontent") or {}
    th_id = str(period["th_id"])

    # Kunci datacontent diasumsikan: vervar + var + turvar + th + turtahun (VERIFIKASI dengan respons asli).
    index = {
        f"{v}{var_id}{t}{th_id}{tt}": (vervar[v], turvar[t], turth[tt])
        for v, t, tt in itertools.product(vervar, turvar, turth)
    }
    rows = []
    for key, value in content.items():
        parsed = index.get(str(key))
        if parsed is None:
            continue
        row = {"wilayah": parsed[0], "kategori": parsed[1], "periode": parsed[2], "nilai": value}
        rows.append({k: v for k, v in row.items() if v not in ("", None)})

    if content and not rows:
        return {
            "catatan": "Kunci data tidak dapat didekode; berikut data mentah.",
            "judul": var_info.get("label"),
            "satuan": var_info.get("unit"),
            "tahun": period["th"],
            "vervar": vervar,
            "turvar": turvar,
            "datacontent": dict(list(content.items())[:150]),
        }

    if resolved_region:
        target_names = set(_region_aliases(resolved_region))
        rows = [r for r in rows if _norm_region(r.get("wilayah", "")) in target_names]
        if not rows:
            return {
                "judul": judul,
                "error": f"Data untuk {resolved_region} tidak tersedia pada variabel ini.",
                "wilayah_ditafsirkan": resolved_region,
                "wilayah_tersedia": [v for v in vervar.values() if v][:30],
                "status_hasil": "kosong",
            }

    return {
        "judul": judul,
        "sifat_data": "proyeksi, bukan hasil pencacahan" if "proyeksi" in str(judul).lower() else "data resmi",
        "satuan": var_info.get("unit"),
        "definisi": str(var_info.get("def") or "")[:300],
        "tahun": period["th"],
        "tahun_mendatang": int(period["th"]) > datetime.now(WIB).year,
        "rentang_tahun_tersedia": f"{available[-1]} sampai {available[0]}",
        **({"wilayah_ditafsirkan": resolved_region} if resolved_region else {}),
        "jumlah_baris": len(rows),
        "terpotong": len(rows) > 150,
        "data": rows[:150],
    }


def ambil_data(
    var_id: int,
    tahun: Optional[str] = None,
    wilayah: Optional[str] = None,
    tahun_mulai: Optional[str] = None,
    tahun_akhir: Optional[str] = None,
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
                payload = _cached(
                    f"data:{DOMAIN}:{vid}:{period['th_id']}",
                    1800,
                    lambda: _get(
                        f"list/model/data/domain/{DOMAIN}/var/{vid}/th/{period['th_id']}"
                    ),
                )
                return _format_data(payload, vid, period, resolved_region, available)

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
        payload = _cached(
            f"data:{DOMAIN}:{vid}:{chosen['th_id']}",
            1800,
            lambda: _get(f"list/model/data/domain/{DOMAIN}/var/{vid}/th/{chosen['th_id']}"),
        )
        return _format_data(payload, vid, chosen, resolved_region, available)
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
    """
    if not isinstance(nama, str) or nama not in _INDIKATOR_UTAMA:
        return {
            "error": "Nama indikator tidak didukung.",
            "nama_didukung": list(_INDIKATOR_UTAMA),
            "status_hasil": "kosong",
        }

    indicator = _INDIKATOR_UTAMA[nama]
    if tahun_mulai is not None or tahun_akhir is not None:
        result = ambil_data(
            int(indicator["var_id"]),
            wilayah=wilayah,
            tahun_mulai=tahun_mulai,
            tahun_akhir=tahun_akhir,
        )
    else:
        result = ambil_data(int(indicator["var_id"]), tahun=tahun, wilayah=wilayah)
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


def _brs_month(year: int, month: int) -> list[dict]:
    def load() -> list[dict]:
        base = f"list/model/pressrelease/domain/{DOMAIN}/year/{year}/month/{month}"
        rows, pages = _list_rows(_get(base))
        if pages > 1:
            more, _ = _list_rows(_get(f"{base}/page/2"))
            rows = rows + more
        return rows

    return _cached(f"brs:{DOMAIN}:{year}-{month}", 1800, load)


def berita_resmi_statistik(
    kata_kunci: Optional[str] = None,
    jumlah: int = 5,
    tahun: Optional[int] = None,
    bulan: Optional[str] = None,
) -> dict:
    """Mengambil Berita Resmi Statistik (BRS) terbaru BPS Sumatera Selatan.

    Gunakan untuk indikator bulanan/triwulanan dan pertanyaan tentang rilis terbaru: inflasi,
    NTP, ekspor-impor, pariwisata/hotel, penumpang, ketenagakerjaan, kemiskinan, atau
    pertumbuhan ekonomi triwulan. Untuk nilai tingkat pengangguran provinsi, gunakan
    indikator kanonik tingkat_pengangguran; gunakan tool ini jika yang ditanya adalah rilisnya.
    Angka utama BRS biasanya tertulis pada judul. Isi kata_kunci dengan 1-2 kata inti
    (mis. "inflasi"); kosongkan untuk BRS terbaru secara umum.

    Args:
        kata_kunci: kata inti topik, misalnya "inflasi" atau "penduduk miskin".
        jumlah: banyak entri yang diminta (1-10).
        tahun: filter tahun yang tercantum pada judul BRS (opsional).
        bulan: filter nama bulan yang tercantum pada judul BRS (opsional).
    """
    count = max(1, min(int(jumlah or 5), 10))
    keyword = _clean_search_keyword(kata_kunci or "")
    token_groups = [
        [token for token in query.split() if len(token) >= 3]
        for query in _search_keyword_variants(keyword)
    ]
    token_groups = [tokens for tokens in token_groups if tokens]
    now = datetime.now(WIB)
    found: list[tuple[int, str, dict]] = []
    month_names = {
        "januari": ("januari", "jan"),
        "februari": ("februari", "feb"),
        "maret": ("maret", "mar"),
        "april": ("april", "apr"),
        "mei": ("mei",),
        "juni": ("juni", "jun"),
        "juli": ("juli", "jul"),
        "agustus": ("agustus", "agu", "ags"),
        "september": ("september", "sep"),
        "oktober": ("oktober", "okt"),
        "november": ("november", "nov"),
        "desember": ("desember", "des"),
    }
    requested_month = (bulan or "").strip().lower()
    month_variants = month_names.get(requested_month, (requested_month,)) if requested_month else ()
    current_month_index = now.year * 12 + now.month - 1
    if tahun is None:
        month_indices = [current_month_index - back for back in range(12)]
    elif requested_month in month_names:
        month_number = list(month_names).index(requested_month) + 1
        target_index = int(tahun) * 12 + month_number - 1
        month_indices = [target_index + offset for offset in range(3)]
    else:
        start_index = int(tahun) * 12
        month_indices = list(range(start_index, start_index + 24))

    def load_month(month_index: int) -> tuple[int, int, list[dict]]:
        year, month0 = divmod(month_index, 12)
        return year, month0 + 1, _brs_month(year, month0 + 1)

    def matching_score(item: dict) -> Optional[int]:
        title = str(item.get("title", ""))
        searchable_text = f"{title} {_strip_html(item.get('abstract'))}".lower()
        score = max(
            (
                sum(token in searchable_text for token in tokens) * 100
                + len(token_groups) - index
                for index, tokens in enumerate(token_groups)
            ),
            default=0,
        )
        if token_groups and score == 0:
            return None
        if tahun is not None and not re.search(rf"\b{int(tahun)}\b", title):
            return None
        if month_variants and not any(
            re.search(rf"\b{re.escape(variant)}\b", title.lower())
            for variant in month_variants
        ):
            return None
        return score

    try:
        if tahun is not None:
            with ThreadPoolExecutor(max_workers=6) as pool:
                month_results = list(pool.map(load_month, month_indices))
            for _, _, month_rows in month_results:
                for item in month_rows:
                    score = matching_score(item)
                    if score is not None:
                        found.append((score, str(item.get("rl_date", "")), item))
        else:
            for month_index in month_indices:
                _, _, month_rows = load_month(month_index)
                for item in month_rows:
                    score = matching_score(item)
                    if score is not None:
                        found.append((score, str(item.get("rl_date", "")), item))
                if not bulan and (
                    (not token_groups and found) or len(found) >= count
                ):
                    break
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}

    found.sort(key=lambda x: (x[0], x[1]), reverse=True)
    result = {
        "hasil": [
            {
                "judul": item.get("title"),
                "tanggal_rilis": item.get("rl_date"),
                "ringkasan": _strip_html(item.get("abstract")),
                "tautan": item.get("pdf") or item.get("url"),
            }
            for _, _, item in found[:count]
        ]
    }
    if (bulan or tahun is not None) and not found:
        result["periode_diminta_tidak_ditemukan"] = {
            "bulan": bulan,
            "tahun": tahun,
        }
    return result


def publikasi_terbaru(kata_kunci: Optional[str] = None, jumlah: int = 5) -> dict:
    """Mengambil daftar publikasi BPS Sumatera Selatan, terbaru dulu.

    Args:
        kata_kunci: topik publikasi (opsional), mis. "pertanian".
        jumlah: banyak entri yang diminta (1-10).
    """
    count = max(1, min(int(jumlah or 5), 10))
    path = f"list/model/publication/domain/{DOMAIN}"
    if kata_kunci and kata_kunci.strip():
        path += f"/keyword/{quote_plus(' '.join(kata_kunci.split())[:80])}"
    try:
        rows, _ = _list_rows(_get(path))
    except BpsError as exc:
        return {"error": str(exc)}

    rows.sort(key=lambda r: str(r.get("rl_date", "")), reverse=True)
    return {
        "hasil": [
            {
                "judul": r.get("title"),
                "tanggal_rilis": r.get("rl_date"),
                "ringkasan": _strip_html(r.get("abstract")),
                "tautan": r.get("pdf") or r.get("url"),
            }
            for r in rows[:count]
        ]
    }


def _static_table_rows(keyword: str, domain: str) -> list[dict]:
    tables_by_id: dict[str, dict] = {}
    for query in _search_keyword_variants(keyword)[:8]:
        base = f"list/model/statictable/domain/{domain}/keyword/{quote_plus(query)}"
        for page in (1, 2):
            path = base if page == 1 else f"{base}/page/{page}"
            payload = _get(path)
            rows, pages = _list_rows(payload)
            for row in rows:
                if not isinstance(row, dict):
                    continue
                table_id = row.get("table_id")
                title = row.get("title")
                if table_id is not None and isinstance(title, str) and title.strip():
                    tables_by_id.setdefault(str(table_id), {
                        "table_id": str(table_id),
                        "title": title.strip(),
                        "domain": domain,
                    })
            if page >= pages:
                break
    return list(tables_by_id.values())


def _static_table_text(payload: dict) -> Optional[str]:
    data = payload.get("data")
    table_html = data.get("table") if isinstance(data, dict) else None
    if not isinstance(table_html, str) or not table_html.strip():
        return None
    table_html = html.unescape(table_html)
    table_html = re.sub(
        r"<(?:br\s*/?|/(?:td|th|tr|p|div|li|h[1-6]))[^>]*>",
        "\n",
        table_html,
        flags=re.IGNORECASE,
    )
    text = re.sub(r"<[^>]+>", " ", table_html)
    lines = [
        re.sub(r"\s+", " ", line).strip()
        for line in text.splitlines()
    ]
    cleaned = "\n".join(line for line in lines if line)
    return cleaned[:5000].rstrip() or None


def tabel_statis(kata_kunci: str, jumlah: int = 3) -> dict:
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
        domains = [DOMAIN] if DOMAIN == "0000" else [DOMAIN, "0000"]
        for domain in domains:
            ranked = _rank_local_variables(keyword, _static_table_rows(keyword, domain))
            for table in ranked[:count]:
                path = (
                    f"view/model/statictable/domain/{table['domain']}/lang/ind/id/"
                    f"{quote_plus(table['table_id'])}"
                )
                text = _static_table_text(_get(path))
                if text:
                    results.append({
                        "table_id": table["table_id"],
                        "judul": table["title"],
                        "domain": table["domain"],
                        "cakupan": "nasional" if table["domain"] == "0000" else "Sumatera Selatan",
                        "isi": text,
                    })
            if results:
                break
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}

    return {
        "jumlah_ditemukan": len(results),
        "hasil": results,
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
        tabel_statis,
    )
]