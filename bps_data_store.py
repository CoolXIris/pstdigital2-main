"""Persistent MySQL storage for raw and structured BPS table responses."""

import html
import json
import logging
import os
import re
import time
from datetime import datetime
from typing import Any, Callable, Optional
from urllib.parse import parse_qs, unquote, urlsplit

import pymysql
from pymysql.cursors import DictCursor
logger = logging.getLogger(__name__)

_REGION_ALIAS_GROUPS = {
    "sumatera selatan": ("sumsel",),
    "ogan komering ulu": ("oku",),
    "ogan komering ilir": ("oki",),
    "musi banyuasin": ("muba",),
    "musi rawas utara": ("mura", "muratara"),
    "penukal abab lematang ilir": ("pali",),
    "ogan komering ulu selatan": ("oku selatan",),
    "ogan komering ulu timur": ("oku timur",),
}


class BpsDatabaseError(RuntimeError):
    """Raised when the active Laravel MySQL database cannot serve BPS storage."""


class BpsConnection:
    """Small DB-API adapter exposing dict rows and the storage module's execute API."""

    def __init__(self, raw_connection: pymysql.Connection) -> None:
        self._raw_connection = raw_connection

    def execute(self, query: str, parameters: tuple[Any, ...] = ()) -> Any:
        cursor = self._raw_connection.cursor()
        try:
            cursor.execute(query.replace("?", "%s"), parameters)
        except pymysql.MySQLError as exc:
            cursor.close()
            raise BpsDatabaseError("Query penyimpanan data BPS gagal.") from exc
        return cursor

    def executemany(self, query: str, parameters: list[tuple[Any, ...]]) -> Any:
        cursor = self._raw_connection.cursor()
        try:
            cursor.executemany(query.replace("?", "%s"), parameters)
        except pymysql.MySQLError as exc:
            cursor.close()
            raise BpsDatabaseError("Penyimpanan nilai data BPS gagal.") from exc
        return cursor

    def commit(self) -> None:
        try:
            self._raw_connection.commit()
        except pymysql.MySQLError as exc:
            raise BpsDatabaseError("Penyimpanan data BPS gagal disimpan.") from exc

    def close(self) -> None:
        self._raw_connection.close()


def _mysql_settings() -> dict[str, Any]:
    connection_url = os.getenv("DB_URL", "").strip()
    if connection_url:
        parsed = urlsplit(connection_url)
        if parsed.scheme.lower() not in {"mysql", "mysql2", "mariadb"}:
            raise BpsDatabaseError("DB_URL harus menunjuk ke koneksi MySQL aktif Laravel.")
        database = unquote(parsed.path.lstrip("/"))
        if not database:
            raise BpsDatabaseError("Nama database MySQL belum dikonfigurasi.")
        query = parse_qs(parsed.query)
        return {
            "host": parsed.hostname or "127.0.0.1",
            "port": parsed.port or 3306,
            "user": unquote(parsed.username or ""),
            "password": unquote(parsed.password or ""),
            "database": database,
            "charset": query.get("charset", [os.getenv("DB_CHARSET", "utf8mb4")])[0],
        }

    driver = os.getenv("DB_CONNECTION", "mysql").strip().lower()
    if driver not in {"mysql", "mariadb"}:
        raise BpsDatabaseError(
            "Penyimpanan BPS Python harus memakai koneksi MySQL aktif Laravel."
        )
    database = os.getenv("DB_DATABASE", "").strip()
    if not database:
        raise BpsDatabaseError("Nama database MySQL belum dikonfigurasi.")
    settings: dict[str, Any] = {
        "host": os.getenv("DB_HOST", "127.0.0.1").strip(),
        "port": int(os.getenv("DB_PORT", "3306")),
        "user": os.getenv("DB_USERNAME", "").strip(),
        "password": os.getenv("DB_PASSWORD", ""),
        "database": database,
        "charset": os.getenv("DB_CHARSET", "utf8mb4").strip() or "utf8mb4",
    }
    socket_path = os.getenv("DB_SOCKET", "").strip()
    if socket_path:
        settings["unix_socket"] = socket_path
    return settings


def init_db() -> BpsConnection:
    """Connect to the active Laravel MySQL database and verify the BPS migration."""
    connection: Optional[BpsConnection] = None
    try:
        raw_connection = pymysql.connect(
            **_mysql_settings(),
            connect_timeout=30,
            read_timeout=30,
            write_timeout=30,
            autocommit=False,
            cursorclass=DictCursor,
        )
        connection = BpsConnection(raw_connection)
        for table in ("data_tabel", "tabel_meta", "nilai_data", "editorial_cache"):
            connection.execute(f"SELECT 1 FROM {table} LIMIT 0").close()
        return connection
    except BpsDatabaseError as exc:
        if connection is not None:
            connection.close()
        mysql_error = exc.__cause__
        if isinstance(mysql_error, pymysql.MySQLError) and getattr(mysql_error, "args", [None])[0] == 1146:
            raise BpsDatabaseError(
                "Tabel penyimpanan BPS belum tersedia; jalankan migrasi Laravel."
            ) from mysql_error
        raise
    except pymysql.MySQLError as exc:
        if connection is not None:
            connection.close()
        if getattr(exc, "args", [None])[0] == 1146:
            raise BpsDatabaseError(
                "Tabel penyimpanan BPS belum tersedia; jalankan migrasi Laravel."
            ) from exc
        logger.error("Active MySQL connection for BPS storage failed: %s", type(exc).__name__)
        raise BpsDatabaseError("Database MySQL aktif tidak dapat diakses untuk data BPS.") from exc


def ttl_detik(jenis_tabel: str, tahun: Any, current_year: Optional[int] = None) -> int:
    """Return the read-through freshness window for one catalog table row."""
    if jenis_tabel == "Tabel Statis":
        return 30 * 86400
    try:
        year = int(str(tahun).strip()[:4])
    except (TypeError, ValueError):
        return 90 * 86400
    latest_year = current_year if current_year is not None else datetime.now().year
    return (7 if year >= latest_year - 1 else 90) * 86400


def _json_payload(value: str) -> dict[str, Any]:
    parsed = json.loads(value)
    if not isinstance(parsed, dict):
        raise ValueError("Stored BPS response is not a JSON object.")
    return parsed


def ambil_data(
    con: BpsConnection,
    row: dict[str, str],
    fetcher: Callable[[], dict[str, Any]],
    fetch_error: type[Exception],
) -> dict[str, Any]:
    """Read a fresh response from MySQL, otherwise fetch and UPSERT it."""
    table_id = str(row.get("id_tabel") or "").strip()
    year = str(row.get("tahun") or "").strip()
    if not table_id:
        raise ValueError("A catalog table ID is required.")

    cached = con.execute(
        "SELECT isi_json, diambil_pada FROM data_tabel WHERE id_tabel=? AND tahun=?",
        (table_id, year),
    ).fetchone()
    now = time.time()
    if cached is not None:
        try:
            payload = _json_payload(cached["isi_json"])
        except (json.JSONDecodeError, ValueError):
            logger.warning("Stored BPS table JSON is invalid for table %s, year %s.", table_id, year)
        else:
            ttl = ttl_detik(row.get("jenis_tabel", ""), year)
            if now - float(cached["diambil_pada"]) < ttl:
                return payload

    try:
        payload = fetcher()
    except fetch_error as exc:
        if cached is not None:
            try:
                stale_payload = _json_payload(cached["isi_json"])
            except (json.JSONDecodeError, ValueError):
                stale_payload = None
            if stale_payload is not None:
                logger.warning(
                    "Serving stale BPS table %s year %s after refresh failure: %s",
                    table_id,
                    year,
                    type(exc).__name__,
                )
                stale_payload["_catatan"] = (
                    "Data berasal dari penyimpanan sebelumnya karena WebAPI BPS "
                    "belum dapat memperbaruinya; data mungkin bukan yang terbaru."
                )
                return stale_payload
        raise

    serialized = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
    con.execute(
        """
        INSERT INTO data_tabel
            (id_tabel, jenis_tabel, tahun, judul, link_json, isi_json, diambil_pada)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            jenis_tabel=VALUES(jenis_tabel),
            judul=VALUES(judul),
            link_json=VALUES(link_json),
            isi_json=VALUES(isi_json),
            diambil_pada=VALUES(diambil_pada)
        """,
        (
            table_id,
            str(row.get("jenis_tabel") or ""),
            year,
            str(row.get("judul") or ""),
            str(row.get("link_json") or ""),
            serialized,
            now,
        ),
    )
    if row.get("jenis_tabel") == "Tabel Dinamis":
        simpan_nilai(con, payload, table_id=table_id, fetched_at=now)
    con.commit()
    return payload


def _dimensions(values: Any, default_label: str = "") -> list[dict[str, str]]:
    if not isinstance(values, list) or not values:
        return [{"val": "0", "label": default_label}]
    output = []
    for item in values:
        if not isinstance(item, dict) or item.get("val") is None:
            continue
        output.append({
            "val": str(item["val"]),
            "label": str(item.get("label") or "").strip(),
        })
    return output or [{"val": "0", "label": default_label}]


def simpan_nilai(
    con: BpsConnection,
    data: dict[str, Any],
    *,
    table_id: Optional[str] = None,
    fetched_at: Optional[float] = None,
) -> int:
    """Decode BPS dynamic keys by enumerating dimension combinations, not slicing."""
    variables = data.get("var")
    content = data.get("datacontent")
    if not isinstance(variables, list) or not variables or not isinstance(content, dict):
        return 0
    variable = next((item for item in variables if isinstance(item, dict)), None)
    if variable is None or variable.get("val") is None:
        logger.warning("Dynamic BPS table response is missing variable metadata.")
        return 0

    variable_id = str(table_id or variable["val"])
    if str(variable["val"]) != variable_id:
        logger.warning(
            "BPS catalog table ID %s differs from response variable ID %s.",
            variable_id,
            variable["val"],
        )
    regions = _dimensions(data.get("vervar"))
    categories = _dimensions(data.get("turvar"))
    years = _dimensions(data.get("tahun"))
    subyears = _dimensions(data.get("turtahun") or data.get("turth"))

    key_index: dict[str, list[tuple[dict[str, str], dict[str, str], dict[str, str], dict[str, str]]]] = {}
    for region in regions:
        for category in categories:
            for year in years:
                for subyear in subyears:
                    key = (
                        f"{region['val']}{variable_id}{category['val']}"
                        f"{year['val']}{subyear['val']}"
                    )
                    key_index.setdefault(key, []).append((region, category, year, subyear))

    rows = []
    decoded_keys: set[str] = set()
    for raw_key, raw_value in content.items():
        key = str(raw_key)
        matches = key_index.get(key, [])
        if len(matches) != 1:
            continue
        region, category, year, subyear = matches[0]
        try:
            numeric_value = float(raw_value)
        except (TypeError, ValueError):
            try:
                normalized_value = str(raw_value).strip()
                numeric_value = (
                    float(normalized_value.replace(",", "."))
                    if "," in normalized_value and "." not in normalized_value
                    else None
                )
            except ValueError:
                numeric_value = None
        rows.append((
            variable_id,
            region["val"],
            region["label"],
            category["val"],
            category["label"],
            year["label"] or year["val"],
            subyear["label"] or subyear["val"],
            numeric_value,
        ))
        decoded_keys.add(key)

    unreadable = len(content) - len(decoded_keys)
    if unreadable:
        logger.warning(
            "BPS table %s contains %s datacontent key(s) that could not be decoded.",
            variable_id,
            unreadable,
        )

    now = fetched_at if fetched_at is not None else time.time()
    note = variable.get("note") or ""
    clean_note = re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]*>", " ", str(note)))).strip()
    con.execute(
        """
        INSERT INTO tabel_meta
            (id_tabel, judul, satuan, subjek, label_wilayah, catatan, last_update_bps, diambil_pada)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            judul=VALUES(judul),
            satuan=VALUES(satuan),
            subjek=VALUES(subjek),
            label_wilayah=VALUES(label_wilayah),
            catatan=VALUES(catatan),
            last_update_bps=VALUES(last_update_bps),
            diambil_pada=VALUES(diambil_pada)
        """,
        (
            variable_id,
            str(variable.get("label") or variable.get("title") or ""),
            str(variable.get("unit") or ""),
            str(variable.get("subj") or ""),
            str(data.get("labelvervar") or ""),
            clean_note,
            str(data.get("last_update") or ""),
            now,
        ),
    )
    if rows:
        con.executemany(
            """
            INSERT INTO nilai_data
                (id_tabel, wilayah_id, wilayah, turvar_id, turvar, tahun, turtahun, nilai)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                wilayah=VALUES(wilayah),
                turvar=VALUES(turvar),
                nilai=VALUES(nilai)
            """,
            rows,
        )
    return len(rows)


def _norm(value: str) -> str:
    return " ".join(re.sub(r"[^\w\s]", " ", value.casefold(), flags=re.UNICODE).split())


def _label_aliases(label: str) -> set[str]:
    normalized = _norm(label)
    aliases = {normalized}
    for canonical, short_names in _REGION_ALIAS_GROUPS.items():
        group = {canonical, *short_names}
        if normalized in group:
            aliases.update(group)
    return {alias for alias in aliases if alias}


def deteksi_wilayah(
    con: BpsConnection,
    id_tabel: str,
    teks: str,
) -> list[str]:
    """Find matching database region labels, preferring the longest alias."""
    labels = [
        str(row["wilayah"])
        for row in con.execute(
            "SELECT DISTINCT wilayah FROM nilai_data WHERE id_tabel=? AND wilayah<>''",
            (str(id_tabel),),
        )
    ]
    text = _norm(teks)
    matches: list[tuple[int, int, str]] = []
    for label in labels:
        for alias in _label_aliases(label):
            for match in re.finditer(
                rf"(?<!\w){re.escape(alias)}(?!\w)",
                text,
                flags=re.IGNORECASE,
            ):
                matches.append((match.start(), -len(alias), label))
    selected: list[str] = []
    occupied: list[tuple[int, int]] = []
    for start, negative_length, label in sorted(matches):
        end = start - negative_length
        if any(start < occupied_end and end > occupied_start for occupied_start, occupied_end in occupied):
            continue
        occupied.append((start, end))
        if label not in selected:
            selected.append(label)
    return selected


def deteksi_kategori(
    con: BpsConnection,
    id_tabel: str,
    teks: str,
) -> list[str]:
    """Match explicitly mentioned category labels from the structured table."""
    text = _norm(teks)
    labels = [
        str(row["turvar"])
        for row in con.execute(
            "SELECT DISTINCT turvar FROM nilai_data WHERE id_tabel=? AND turvar<>''",
            (str(id_tabel),),
        )
    ]
    matches = []
    for label in labels:
        normalized = _norm(label)
        if normalized and re.search(rf"(?<!\w){re.escape(normalized)}(?!\w)", text):
            matches.append(label)
    return sorted(set(matches), key=lambda value: (len(_norm(value)), value.casefold()), reverse=True)


def cari_nilai(
    con: BpsConnection,
    id_tabel: str,
    wilayah: Optional[list[str]] = None,
    tahun: Optional[list[str]] = None,
    turvar: Optional[list[str]] = None,
    turtahun: Optional[list[str]] = None,
) -> dict[str, Any]:
    """Query only matching structured values and their table metadata."""
    sql = (
        "SELECT wilayah_id, wilayah, turvar_id, turvar, tahun, turtahun, nilai "
        "FROM nilai_data WHERE id_tabel=?"
    )
    arguments: list[Any] = [str(id_tabel)]
    for column, values in (
        ("wilayah", wilayah),
        ("tahun", tahun),
        ("turvar", turvar),
        ("turtahun", turtahun),
    ):
        if values:
            sql += f" AND {column} IN ({','.join('?' for _ in values)})"
            arguments.extend(values)
    sql += " ORDER BY CAST(tahun AS UNSIGNED), wilayah, turvar, turtahun"
    metadata = con.execute(
        "SELECT judul, satuan, subjek, label_wilayah, catatan, last_update_bps "
        "FROM tabel_meta WHERE id_tabel=?",
        (str(id_tabel),),
    ).fetchone()
    return {
        "meta": dict(metadata) if metadata is not None else None,
        "baris": [dict(row) for row in con.execute(sql, arguments)],
    }


def editorial_cached(
    con: BpsConnection,
    cache_key: str,
    ttl_seconds: int,
    *,
    force_refresh: bool = False,
) -> Optional[dict[str, Any]]:
    if force_refresh:
        return None
    row = con.execute(
        "SELECT isi_json, diambil_pada FROM editorial_cache WHERE cache_key=?",
        (cache_key,),
    ).fetchone()
    if row is None or time.time() - float(row["diambil_pada"]) >= ttl_seconds:
        return None
    try:
        return _json_payload(row["isi_json"])
    except (json.JSONDecodeError, ValueError):
        logger.warning("Stored BPS editorial response is invalid.")
        return None


def save_editorial(
    con: BpsConnection,
    cache_key: str,
    payload: dict[str, Any],
    fetched_at: Optional[float] = None,
) -> None:
    con.execute(
        """
        INSERT INTO editorial_cache (cache_key, isi_json, diambil_pada)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            isi_json=VALUES(isi_json),
            diambil_pada=VALUES(diambil_pada)
        """,
        (
            cache_key,
            json.dumps(payload, ensure_ascii=False, separators=(",", ":")),
            fetched_at if fetched_at is not None else time.time(),
        ),
    )
    con.commit()
