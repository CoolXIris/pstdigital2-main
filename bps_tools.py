"""Tools WebAPI BPS untuk Gemini (function calling)."""
import functools
import itertools
import logging
import os
import re
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from difflib import SequenceMatcher
from datetime import datetime, timedelta, timezone
from typing import Any, Callable, Literal, Optional
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


def _get(path: str) -> dict:
    api_key = os.getenv("BPS_API_KEY")
    if not api_key:
        raise BpsError("BPS_API_KEY belum dikonfigurasi.")
    try:
        response = _http.get(f"{BASE}/{path.strip('/')}/key/{api_key}/")
        response.raise_for_status()
        payload = response.json()
    except Exception:
        # Sengaja tanpa pesan asli: URL pada exception memuat API key.
        raise BpsError("WebAPI BPS tidak dapat diakses saat ini.") from None
    if payload.get("status") != "OK":
        raise BpsError("WebAPI BPS tidak mengembalikan data.")
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
    try:
        periods = _periods(var_id)
    except BpsError:
        return None, 0, False
    this_year = datetime.now(WIB).year
    past = [p for p in periods if int(p["th"]) <= this_year]
    latest = past or periods
    has_future = any(int(p["th"]) > this_year for p in periods)
    return (str(latest[0]["th"]) if latest else None), len(periods), has_future


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
    keyword = " ".join((kata_kunci or "").split())[:80]
    if not keyword:
        return {"error": "kata_kunci kosong."}

    def search(q: str) -> list[dict]:
        def load() -> list[dict]:
            found: list[dict] = []
            for page in (1, 2, 3):
                path = f"list/model/var/domain/{DOMAIN}/keyword/{quote_plus(q)}"
                if page > 1:
                    path += f"/page/{page}"
                payload = _get(path)
                data = payload.get("data")
                if not isinstance(data, list) or len(data) < 2 or not isinstance(data[1], list):
                    raise BpsError("Format respons BPS tidak dikenali.")
                rows, pages = _list_rows(payload)
                found += rows
                if page >= pages:
                    break
            return found

        return _cached(f"var:{DOMAIN}:{q.lower()}", 3600, load)

    try:
        variables: list[dict] = []
        words = sorted({word for word in keyword.split() if len(word) >= 4}, key=len, reverse=True)
        for query in [keyword, *[word for word in words if word != keyword]][:3]:
            variables = search(query)
            if variables:
                break

        candidates = variables[:15]
        with ThreadPoolExecutor(max_workers=6) as pool:
            latest = list(pool.map(lambda v: _latest_year(int(v["var_id"])), candidates))
    except BpsError as exc:
        return {"error": str(exc)}
    except (KeyError, ValueError, TypeError):
        return {"error": "Format respons BPS tidak dikenali."}

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

    result = {"jumlah_ditemukan": len(variables), "hasil": hasil}
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
        stale_keys = [key for key, (timestamp, _) in _cache.items() if timestamp < cutoff]
        for key in stale_keys:
            if _cache[key][0] < cutoff:
                _cache.pop(key, None)

    with ThreadPoolExecutor(max_workers=6) as pool:
        list(pool.map(brs, range(12)))
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


_INDIKATOR_UTAMA: dict[str, dict[str, Any]] = {
    "jumlah_penduduk": {
        "var_id": 262,
        "sifat_data": "estimasi",
        "catatan_sumber": (
            "Catatan metadata BPS mencantumkan sumber sensus, proyeksi, dan laporan kabupaten/kota "
            "untuk periode berbeda; bedakan dari seri proyeksi jangka panjang."
        ),
    },
    "proyeksi_penduduk": {
        "var_id": 51,
        "sifat_data": "proyeksi",
        "catatan_sumber": (
            "Seri proyeksi jangka panjang hingga 2035; angka tahun yang sama dapat berbeda "
            "dari estimasi jumlah penduduk menurut kabupaten/kota (var_id 262)."
        ),
    },
    "kepadatan_penduduk": {"var_id": 268},
    "angka_harapan_hidup": {"var_id": 960},
    "gini": {"var_id": 257},
    "kemiskinan_persen": {"var_id": 608},
    "kemiskinan_persen_kab_kota": {"var_id": 604},
    "kemiskinan_jumlah": {"var_id": 157},
    "kemiskinan_jumlah_kab_kota": {"var_id": 683},
    "ipm": {"var_id": 959},
}


def indikator_utama(
    nama: Literal[
        "jumlah_penduduk",
        "proyeksi_penduduk",
        "kepadatan_penduduk",
        "angka_harapan_hidup",
        "gini",
        "kemiskinan_persen",
        "kemiskinan_persen_kab_kota",
        "kemiskinan_jumlah",
        "kemiskinan_jumlah_kab_kota",
        "ipm",
    ],
) -> dict:
    """Mengambil data terbaru untuk indikator BPS Sumatera Selatan yang sudah dikurasi.

    nama wajib persis salah satu dari: jumlah_penduduk, proyeksi_penduduk,
    kepadatan_penduduk, angka_harapan_hidup, gini, kemiskinan_persen,
    kemiskinan_persen_kab_kota, kemiskinan_jumlah, kemiskinan_jumlah_kab_kota, ipm.
    Tool ini mengembalikan data seluruh wilayah dan tidak menerima nama wilayah atau
    var_id bebas. Jumlah penduduk menggunakan seri estimasi var_id 262; proyeksi
    menggunakan var_id 51. Untuk indikator yang tidak tercantum, gunakan cari_variabel.

    Args:
        nama: nama indikator kanonik dari daftar tetap.
    """
    if not isinstance(nama, str) or nama not in _INDIKATOR_UTAMA:
        return {
            "error": "Nama indikator tidak didukung.",
            "nama_didukung": list(_INDIKATOR_UTAMA),
        }

    indicator = _INDIKATOR_UTAMA[nama]
    result = ambil_data(int(indicator["var_id"]))
    if "error" in result:
        return result
    result["nama_indikator"] = nama
    if "sifat_data" in indicator:
        result["sifat_data"] = indicator["sifat_data"]
    if "catatan_sumber" in indicator:
        result["catatan_sumber"] = indicator["catatan_sumber"]
    return result


def _brs_month(year: int, month: int) -> list[dict]:
    def load() -> list[dict]:
        base = f"list/model/pressrelease/domain/{DOMAIN}/year/{year}/month/{month}"
        rows, pages = _list_rows(_get(base))
        if pages > 1:
            more, _ = _list_rows(_get(f"{base}/page/2"))
            rows = rows + more
        return rows

    return _cached(f"brs:{DOMAIN}:{year}-{month}", 1800, load)


def berita_resmi_statistik(kata_kunci: Optional[str] = None, jumlah: int = 5) -> dict:
    """Mengambil Berita Resmi Statistik (BRS) terbaru BPS Sumatera Selatan.

    Gunakan untuk indikator bulanan/triwulanan dan pertanyaan "terbaru": inflasi, NTP,
    ekspor-impor, pariwisata/hotel, penumpang, ketenagakerjaan/pengangguran, kemiskinan,
    pertumbuhan ekonomi triwulan. Angka utama biasanya tertulis pada judul. Isi kata_kunci
    dengan 1-2 kata inti (mis. "inflasi"); kosongkan untuk BRS terbaru secara umum.

    Args:
        kata_kunci: kata inti topik, misalnya "inflasi" atau "penduduk miskin".
        jumlah: banyak entri yang diminta (1-10).
    """
    count = max(1, min(int(jumlah or 5), 10))
    tokens = [t for t in (kata_kunci or "").lower().split() if len(t) >= 3]
    now = datetime.now(WIB)
    found: list[tuple[int, str, dict]] = []
    try:
        for back in range(12):
            year, month0 = divmod(now.year * 12 + (now.month - 1) - back, 12)
            for item in _brs_month(year, month0 + 1):
                title = str(item.get("title", ""))
                score = sum(t in title.lower() for t in tokens)
                if tokens and score == 0:
                    continue
                found.append((score, str(item.get("rl_date", "")), item))
            if (not tokens and found) or len(found) >= count:
                break
    except BpsError as exc:
        return {"error": str(exc)}

    found.sort(key=lambda x: (x[0], x[1]), reverse=True)
    return {
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


def _logged(func):
    @functools.wraps(func)
    def wrapper(*args, **kwargs):
        started = time.time()
        logger.info("TOOL mulai: %s args=%s kwargs=%s", func.__name__, args, kwargs)
        result = func(*args, **kwargs)
        logger.info(
            "TOOL selesai: %s %.1fs error=%s",
            func.__name__, time.time() - started,
            isinstance(result, dict) and "error" in result,
        )
        return result
    return wrapper


TOOLS = [_logged(f) for f in (cari_variabel, ambil_data, indikator_utama, berita_resmi_statistik, publikasi_terbaru)]