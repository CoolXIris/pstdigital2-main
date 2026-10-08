"""Tools WebAPI BPS untuk Gemini (function calling)."""
import functools
import html
import itertools
import json
import logging
import os
import re
import threading
import time
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
_VARIABLE_SYNONYMS = {
    "kemiskinan": "miskin",
    "miskin": "kemiskinan",
    "pengangguran": "penganggur",
    "penganggur": "pengangguran",
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
                for key in ("var_id", "title", "sifat_data", "catatan_sumber", "vervar_label")
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
    words = keyword.split()
    candidates = [keyword]
    synonym_phrase = " ".join(_VARIABLE_SYNONYMS.get(word, word) for word in words)
    if synonym_phrase != keyword:
        candidates.append(synonym_phrase)
    for word in sorted(set(words), key=len, reverse=True):
        if len(word) < 4:
            continue
        candidates.extend((word, _stem_keyword(word), _VARIABLE_SYNONYMS.get(word, word)))
    return list(dict.fromkeys(query for query in candidates if query))[:7]


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
        "refreshed_at": datetime.now(WIB).isoformat(timespec="seconds"),
    }


def refresh_variable_index() -> dict:
    index = _load_variable_index()
    with _cache_lock:
        _cache[_variable_index_key()] = (time.time(), index)
    return index


def _variable_index() -> dict:
    return _cached(_variable_index_key(), _VARIABLE_INDEX_TTL, _load_variable_index)


def _token_match_score(term: str, title_tokens: set[str]) -> float:
    variants = {term, _stem_keyword(term), _VARIABLE_SYNONYMS.get(term, term)}
    for variant in tuple(variants):
        variants.add(_stem_keyword(variant))
        variants.add(_VARIABLE_SYNONYMS.get(variant, variant))
    if variants & title_tokens:
        return 1.0

    threshold = 0.78 if len(term) >= 7 else 0.84 if len(term) >= 5 else 0.9
    best_score = max(
        (
            SequenceMatcher(None, variant, title_token).ratio()
            for variant in variants
            for title_token in title_tokens
            if min(len(variant), len(title_token)) >= 4
        ),
        default=0.0,
    )
    return best_score if best_score >= threshold else 0.0


def _rank_local_variables(keyword: str, variables: list[dict]) -> list[dict]:
    terms = [
        word for word in re.findall(r"[a-z0-9]+", keyword.casefold())
        if len(word) >= 3
    ]
    ranked: list[tuple[int, float, dict]] = []
    for variable in variables:
        title = " ".join(
            str(variable.get(field) or "")
            for field in ("title", "subject")
        ).casefold()
        title_tokens = set(re.findall(r"[a-z0-9]+", title))
        scores = [_token_match_score(term, title_tokens) for term in terms]
        matches = sum(score > 0 for score in scores)
        if matches:
            ranked.append((matches, sum(scores), variable))
    ranked.sort(
        key=lambda item: (
            item[0],
            item[1],
            str(item[2].get("title") or "").casefold(),
        ),
        reverse=True,
    )
    return [variable for _, _, variable in ranked]


def cari_variabel(kata_kunci: str) -> dict:
    """Mencari variabel (indikator) data tahunan BPS Sumatera Selatan berdasarkan kata kunci.

    Gunakan 1-2 kata inti, mis. "jumlah penduduk", "penduduk miskin", "IPM". Jangan
    menyertakan nama wilayah; wilayah diisi pada ambil_data. Hasil berisi var_id, judul,
    satuan, tahun_terbaru, dan seri_lama. Pilih variabel yang judulnya paling sesuai;
    hindari seri_lama=True kecuali tidak ada alternatif, dan sebutkan periode terakhirnya
    jika terpaksa menggunakannya. memuat_tahun_mendatang=True berarti variabel itu proyeksi.

    Args:
        kata_kunci: kata inti indikator yang dicari.
    """
    keyword = _remove_region_names(" ".join((kata_kunci or "").split()))[:80]
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
        with ThreadPoolExecutor(max_workers=6) as pool:
            latest = list(pool.map(lambda v: _latest_year(int(v["var_id"])), candidates))
    except BpsError as exc:
        return {"error": str(exc), "status_hasil": "gagal_teknis"}
    except (KeyError, ValueError, TypeError):
        return {"error": "Format respons BPS tidak dikenali.", "status_hasil": "gagal_teknis"}

    hasil = [
        {
            "var_id": int(v["var_id"]),
            "judul": v.get("title"),
            "satuan": v.get("unit"),
            "tahun_terbaru": year,
            "jumlah_tahun": count,
            "memuat_tahun_mendatang": future,
        }
        for v, (year, count, future) in zip(candidates, latest)
    ]
    newest = max((int(item["tahun_terbaru"]) for item in hasil if item["tahun_terbaru"]), default=0)
    for item in hasil:
        item["seri_lama"] = bool(item["tahun_terbaru"]) and int(item["tahun_terbaru"]) < newest - 2
    hasil.sort(key=lambda item: item["seri_lama"])

    result = {
        "jumlah_ditemukan": len(variables),
        "hasil": hasil,
        "status_hasil": "ditemukan" if variables else "kosong",
    }
    if not variables:
        result["petunjuk"] = "Tidak ada hasil. Coba satu kata inti tanpa nama wilayah."
    return result


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
    return " ".join(word for word in words if word not in {"kab", "kabupaten", "kota"})


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


def ambil_data(var_id: int, tahun: Optional[str] = None, wilayah: Optional[str] = None) -> dict:
    """Mengambil nilai data satu variabel BPS Sumatera Selatan (hasil cari_variabel).

    Tanpa tahun, yang diambil adalah tahun terbaru yang sudah berjalan (bukan tahun proyeksi
    mendatang). Isi tahun untuk tahun lain, termasuk proyeksi masa depan jika tersedia. Isi
    parameter wilayah (mis. "Palembang", "Muara Enim") jika pengguna menanyakan satu
    kabupaten/kota; kosongkan untuk data tingkat provinsi atau semua wilayah.

    Args:
        var_id: ID variabel dari hasil cari_variabel.
        tahun: tahun yang diminta, mis. "2025". Kosongkan untuk tahun terbaru.
        wilayah: nama kabupaten/kota untuk memfilter baris data.
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
                }
        periods = _periods(vid)
        if not periods:
            return {"error": "Variabel ini tidak memiliki data tahunan."}
        available = [str(p["th"]) for p in periods]
        if tahun:
            chosen = next((p for p in periods if str(p["th"]) == str(tahun).strip()), None)
            if chosen is None:
                return {
                    "error": f"Tahun {tahun} tidak tersedia.",
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
        return {"error": str(exc)}
    except (KeyError, ValueError, TypeError):
        return {"error": "Format respons BPS tidak dikenali."}


def indikator_utama(
    nama: str,
    tahun: Optional[str] = None,
    wilayah: Optional[str] = None,
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
    """
    if not isinstance(nama, str) or nama not in _INDIKATOR_UTAMA:
        return {
            "error": "Nama indikator tidak didukung.",
            "nama_didukung": list(_INDIKATOR_UTAMA),
        }

    indicator = _INDIKATOR_UTAMA[nama]
    result = ambil_data(int(indicator["var_id"]), tahun=tahun, wilayah=wilayah)
    if "error" in result:
        return {"nama_indikator": nama, "var_id": int(indicator["var_id"]), **result}
    result["nama_indikator"] = nama
    result["var_id"] = int(indicator["var_id"])
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

    Gunakan untuk indikator bulanan/triwulanan dan pertanyaan "terbaru": inflasi, NTP,
    ekspor-impor, pariwisata/hotel, penumpang, ketenagakerjaan/pengangguran, kemiskinan,
    pertumbuhan ekonomi triwulan. Angka utama biasanya tertulis pada judul. Isi kata_kunci
    dengan 1-2 kata inti (mis. "inflasi"); kosongkan untuk BRS terbaru secara umum.

    Args:
        kata_kunci: kata inti topik, misalnya "inflasi" atau "penduduk miskin".
        jumlah: banyak entri yang diminta (1-10).
        tahun: filter tahun yang tercantum pada judul BRS (opsional).
        bulan: filter nama bulan yang tercantum pada judul BRS (opsional).
    """
    count = max(1, min(int(jumlah or 5), 10))
    tokens = [t for t in (kata_kunci or "").lower().split() if len(t) >= 3]
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
    try:
        for back in range(12):
            year, month0 = divmod(now.year * 12 + (now.month - 1) - back, 12)
            for item in _brs_month(year, month0 + 1):
                title = str(item.get("title", ""))
                score = sum(t in title.lower() for t in tokens)
                if tokens and score == 0:
                    continue
                if tahun is not None and not re.search(rf"\b{int(tahun)}\b", title):
                    continue
                if month_variants and not any(
                    re.search(rf"\b{re.escape(variant)}\b", title.lower())
                    for variant in month_variants
                ):
                    continue
                found.append((score, str(item.get("rl_date", "")), item))
            if (not tokens and not bulan and tahun is None and found) or (
                len(found) >= count and not bulan and tahun is None
            ):
                break
    except BpsError as exc:
        return {"error": str(exc)}

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
    for query in _search_keyword_variants(keyword)[:4]:
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
    keyword = _remove_region_names(" ".join((kata_kunci or "").split()))[:80]
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
        ambil_data,
        indikator_utama,
        berita_resmi_statistik,
        publikasi_terbaru,
        tabel_statis,
    )
]