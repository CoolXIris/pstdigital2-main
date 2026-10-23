"""CSV-backed BPS table catalog and safe JSON fetching."""

import csv
import hashlib
import logging
import os
import threading
from pathlib import Path
from typing import Any
from urllib.parse import quote, quote_plus, urlsplit, urlunsplit

import httpx

import bps_data_store


logger = logging.getLogger(__name__)


class CatalogError(Exception):
    pass


_REQUIRED_COLUMNS = {
    "kategori_subjek",
    "subjek",
    "id_subjek",
    "judul",
    "id_tabel",
    "jenis_tabel",
    "tahun",
    "link_json",
}
_EDITORIAL_LIST_TTL = 60 * 60
_EDITORIAL_DETAIL_TTL = 24 * 60 * 60
_EDITORIAL_MODELS = {"pressrelease", "publication", "news", "infographic"}
_http = httpx.Client(timeout=8.0)
_lock = threading.Lock()
_catalog_cache: tuple[tuple[str, int, int, int, int], list[dict[str, str]]] | None = None


def _catalog_path() -> Path:
    configured = os.getenv("BPS_CATALOG_CSV")
    return Path(configured).expanduser() if configured else Path(__file__).resolve().parent / "tabel_bps_sumsel.csv"


def _audit_path(catalog_path: Path) -> Path:
    configured = os.getenv("BPS_AUDIT_CSV", "").strip()
    return Path(configured).expanduser() if configured else (
        catalog_path.with_name(f"{catalog_path.stem}_audit{catalog_path.suffix}")
    )


def load_catalog() -> list[dict[str, str]]:
    global _catalog_cache

    path = _catalog_path()
    try:
        stat = path.stat()
    except OSError:
        raise CatalogError("File katalog CSV BPS tidak dapat ditemukan atau dibaca.") from None

    audit_path = _audit_path(path)
    try:
        audit_stat = audit_path.stat()
        audit_signature = (audit_stat.st_mtime_ns, audit_stat.st_size)
    except OSError:
        audit_signature = (0, 0)
    signature = (str(path.resolve()), stat.st_mtime_ns, stat.st_size, *audit_signature)
    with _lock:
        cached = _catalog_cache
        if cached is not None and cached[0] == signature:
            return [dict(row) for row in cached[1]]

    try:
        with path.open(encoding="utf-8-sig", newline="") as source:
            reader = csv.DictReader(source)
            headers = set(reader.fieldnames or [])
            if not _REQUIRED_COLUMNS.issubset(headers):
                raise CatalogError("Kolom wajib pada katalog CSV BPS tidak lengkap.")
            rows = []
            for raw_row in reader:
                row = {key: str(value or "").strip() for key, value in raw_row.items() if key}
                link = row.get("link_json", "")
                if not row.get("judul") or not link or link == "TAHUN_TIDAK_TERSEDIA":
                    continue
                rows.append(row)
    except CatalogError:
        raise
    except (OSError, UnicodeError, csv.Error):
        raise CatalogError("File katalog CSV BPS tidak dapat dibaca.") from None

    _merge_audit_status(rows, path)
    with _lock:
        _catalog_cache = (signature, rows)
    return [dict(row) for row in rows]


def _merge_audit_status(rows: list[dict[str, str]], catalog_path: Path) -> None:
    audit_path = _audit_path(catalog_path)
    if not audit_path.is_file() or audit_path.resolve() == catalog_path.resolve():
        return
    try:
        with audit_path.open(encoding="utf-8-sig", newline="") as source:
            audited = csv.DictReader(source)
            status_by_row = {
                (
                    str(row.get("id_tabel") or "").strip(),
                    str(row.get("tahun") or "").strip(),
                    str(row.get("link_json") or "").strip(),
                ): row
                for row in audited
                if row.get("status_tarik")
            }
    except (OSError, UnicodeError, csv.Error):
        logger.warning("File status audit katalog BPS tidak dapat dibaca.")
        return

    for row in rows:
        audited_row = status_by_row.get((
            row.get("id_tabel", ""),
            row.get("tahun", ""),
            row.get("link_json", ""),
        ))
        if audited_row:
            for field in (
                "status_tarik",
                "status_detail",
                "dicek_pada",
                "status_link",
                "status_link_detail",
                "dicek_link_pada",
            ):
                row[field] = str(audited_row.get(field) or "")


def fetch_json(row: dict[str, str]) -> dict[str, Any]:
    link = str(row.get("link_json") or "").strip()
    if not link or link == "TAHUN_TIDAK_TERSEDIA":
        raise CatalogError("Tautan data untuk baris katalog ini tidak tersedia.")

    parsed = urlsplit(link)
    path_segments = [segment for segment in parsed.path.rstrip("/").split("/") if segment]
    if (
        parsed.scheme != "https"
        or parsed.hostname != "webapi.bps.go.id"
        or not parsed.path.startswith("/v1/api/")
        or not parsed.path.rstrip("/").endswith("/key")
        or path_segments.count("key") != 1
        or parsed.query
        or parsed.fragment
    ):
        raise CatalogError("Format tautan katalog BPS tidak valid.")

    safe_link = urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))

    def request_api() -> dict[str, Any]:
        api_key = os.getenv("BPS_API_KEY", "").strip()
        if not api_key:
            raise CatalogError("API key BPS belum dikonfigurasi.")
        url = urlunsplit((
            parsed.scheme,
            parsed.netloc,
            f"{parsed.path}{quote(api_key, safe='')}/",
            "",
            "",
        ))
        try:
            response = _http.get(url)
        except httpx.RequestError:
            raise CatalogError("WebAPI BPS tidak dapat diakses saat ini.") from None

        try:
            payload = response.json()
        except ValueError:
            raise CatalogError("WebAPI BPS mengembalikan respons JSON yang tidak valid.") from None
        if not isinstance(payload, dict):
            raise CatalogError("Format respons WebAPI BPS tidak dikenali.")

        availability = response.headers.get("data-availability", "").strip().lower()
        if availability == "list-not-available":
            payload = {"status": "OK", "data": [{"pages": 1}, []]}
        elif response.status_code in {401, 403}:
            raise CatalogError("API key BPS tidak diterima.")
        elif response.status_code >= 400 or payload.get("status") != "OK":
            raise CatalogError("WebAPI BPS tidak dapat mengambil data pada tautan katalog.")
        return payload

    connection = None
    try:
        connection = bps_data_store.init_db()
        return bps_data_store.ambil_data(
            connection,
            {**row, "link_json": safe_link},
            request_api,
            fetch_error=CatalogError,
        )
    except bps_data_store.BpsDatabaseError:
        logger.exception("MySQL storage failed while loading BPS table data.")
        raise CatalogError("Penyimpanan data BPS tidak dapat diakses saat ini.") from None
    finally:
        if connection is not None:
            connection.close()


def fetch_editorial_json(
    model: str,
    *,
    domain: str = "1600",
    keyword: str | None = None,
    year: int | str | None = None,
    month: int | str | None = None,
    page: int | str = 1,
    identifier: str | int | None = None,
) -> dict[str, Any]:
    """Fetch a BPS editorial list or detail response using the environment key."""
    model = str(model).strip().lower()
    if model not in _EDITORIAL_MODELS:
        raise CatalogError("Jenis arsip BPS yang diminta tidak didukung.")

    def numeric_segment(name: str, value: int | str | None, maximum: int) -> str | None:
        if value is None or str(value).strip() == "":
            return None
        text = str(value).strip()
        if not text.isdecimal() or not 1 <= int(text) <= maximum:
            raise CatalogError(f"Parameter {name} pada permintaan arsip BPS tidak valid.")
        return text

    safe_domain = numeric_segment("domain", domain, 999999999)
    if safe_domain is None:
        raise CatalogError("Domain BPS tidak valid.")

    if identifier is not None:
        safe_identifier = str(identifier).strip()
        if not safe_identifier or len(safe_identifier) > 100 or "/" in safe_identifier:
            raise CatalogError("ID detail arsip BPS tidak valid.")
        segments = [
            "view", "model", model, "lang", "ind", "id",
            quote(safe_identifier, safe=""),
        ]
        cache_identity = f"view:{model}:{safe_identifier}"
    else:
        safe_year = numeric_segment("year", year, 9999)
        safe_month = numeric_segment("month", month, 12)
        safe_page = numeric_segment("page", page, 1000)
        if safe_month is not None and int(safe_month) > 12:
            raise CatalogError("Parameter month pada permintaan arsip BPS tidak valid.")
        segments = ["list", "model", model, "lang", "ind", "domain", safe_domain]
        if safe_year is not None:
            segments.extend(["year", safe_year])
        if safe_month is not None:
            segments.extend(["month", safe_month])
        segments.extend(["page", safe_page or "1"])
        safe_keyword = str(keyword or "").strip()
        if len(safe_keyword) > 200:
            raise CatalogError("Kata kunci arsip BPS terlalu panjang.")
        if safe_keyword:
            segments.extend(["keyword", quote_plus(safe_keyword, safe="")])
        cache_identity = "list:" + model + ":" + ":".join(segments[4:])

    cache_key = hashlib.sha256(cache_identity.encode()).hexdigest()
    ttl_seconds = _EDITORIAL_DETAIL_TTL if identifier is not None else _EDITORIAL_LIST_TTL
    force_refresh = identifier is None and year is None
    connection = None
    try:
        connection = bps_data_store.init_db()
        cached = bps_data_store.editorial_cached(
            connection,
            cache_key,
            ttl_seconds,
            force_refresh=force_refresh,
        )
        if cached is not None:
            return cached

        api_key = os.getenv("BPS_API_KEY", "").strip()
        if not api_key:
            raise CatalogError("API key BPS belum dikonfigurasi.")
        segments.extend(["key", quote(api_key, safe="")])
        url = urlunsplit((
            "https",
            "webapi.bps.go.id",
            "/v1/api/" + "/".join(segments) + "/",
            "",
            "",
        ))
        try:
            response = _http.get(url)
        except httpx.RequestError:
            raise CatalogError("WebAPI BPS tidak dapat diakses untuk memuat arsip saat ini.") from None

        if response.status_code in {401, 403}:
            raise CatalogError("API key BPS tidak diterima.")
        if response.status_code >= 400:
            raise CatalogError("WebAPI BPS tidak dapat memuat arsip yang diminta.")

        try:
            payload = response.json()
        except ValueError:
            raise CatalogError("WebAPI BPS mengembalikan respons JSON yang tidak valid.") from None
        if not isinstance(payload, dict):
            raise CatalogError("Format respons arsip WebAPI BPS tidak dikenali.")

        availability = response.headers.get("data-availability", "").strip().lower()
        if availability == "list-not-available":
            payload = {"status": "OK", "data": [{"pages": 1}, []]}
        elif payload.get("status") != "OK":
            raise CatalogError("WebAPI BPS tidak dapat memuat arsip yang diminta.")

        bps_data_store.save_editorial(connection, cache_key, payload)
        return payload
    except bps_data_store.BpsDatabaseError:
        logger.exception("MySQL storage failed while loading BPS editorial data.")
        raise CatalogError("Penyimpanan arsip BPS tidak dapat diakses saat ini.") from None
    finally:
        if connection is not None:
            connection.close()
