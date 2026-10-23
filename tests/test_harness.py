"""Shared helpers for the chatbot evaluation scripts."""

from __future__ import annotations

import hashlib
import json
import os
import re
from pathlib import Path
from typing import Any, Iterable

ROOT = Path(__file__).resolve().parents[1]
TESTS_DIR = ROOT / "tests"
QUESTION_FIELDS = {
    "id",
    "pertanyaan",
    "kategori",
    "ekspektasi",
    "tingkat_kesulitan",
    "split",
}


def load_local_environment() -> None:
    from dotenv import load_dotenv

    load_dotenv(ROOT / ".env", override=False)


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    with path.open(encoding="utf-8") as source:
        for line_number, line in enumerate(source, 1):
            if not line.strip():
                continue
            try:
                value = json.loads(line)
            except json.JSONDecodeError as exc:
                raise ValueError(f"{path.name}:{line_number}: JSON tidak valid.") from exc
            if not isinstance(value, dict):
                raise ValueError(f"{path.name}:{line_number}: setiap baris harus berupa objek.")
            rows.append(value)
    return rows


def write_jsonl(path: Path, rows: Iterable[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="\n") as target:
        for row in rows:
            target.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")


def stable_split(table_id: str, training_ids: set[str], special_id: str | None = None) -> str:
    if table_id:
        return "train" if table_id in training_ids else "holdout"
    digest = hashlib.sha256((special_id or "").encode("utf-8")).digest()
    return "train" if int.from_bytes(digest[:4], "big") % 10 < 7 else "holdout"


def normalize_text(value: Any) -> str:
    text = str(value or "").casefold()
    text = re.sub(r"[^\w\s]", " ", text, flags=re.UNICODE)
    return " ".join(text.split())


def normalize_region(value: Any) -> str:
    text = normalize_text(value)
    text = re.sub(r"^(provinsi|kabupaten|kota)\s+", "", text)
    return text


def escape_secrets(text: str, secrets: Iterable[str] = ()) -> str:
    cleaned = str(text or "")
    for secret in secrets:
        if secret:
            cleaned = cleaned.replace(secret, "[REDACTED]")
    cleaned = re.sub(
        r"(https?://(?:webapi\.bps\.go\.id|[^/\s]+)/(?:[^/\s]*/)*key/)[^/\s\"']+",
        r"\1[REDACTED]",
        cleaned,
        flags=re.IGNORECASE,
    )
    cleaned = re.sub(
        r"([?&](?:key|api_key|token)=)[^&\s\"']+",
        r"\1[REDACTED]",
        cleaned,
        flags=re.IGNORECASE,
    )
    return cleaned


def parse_indonesian_number(value: Any) -> float | None:
    text = str(value or "").strip().replace(" ", "")
    if not text:
        return None
    if "," in text:
        text = text.replace(".", "").replace(",", ".")
    elif re.fullmatch(r"-?\d{1,3}(?:\.\d{3})+", text):
        text = text.replace(".", "")
    try:
        return float(text)
    except ValueError:
        return None


def check_storage_schema() -> str | None:
    """Read required table names without running migrations or changing the database."""
    try:
        import pymysql
        from pymysql.cursors import DictCursor

        import sys

        sys.path.insert(0, str(ROOT))
        import bps_data_store

        load_local_environment()
        connection = pymysql.connect(
            **bps_data_store._mysql_settings(),
            connect_timeout=5,
            read_timeout=5,
            cursorclass=DictCursor,
            autocommit=True,
        )
        try:
            with connection.cursor() as cursor:
                cursor.execute("SHOW TABLES")
                existing = {str(next(iter(row.values()))) for row in cursor.fetchall()}
        finally:
            connection.close()
    except Exception as exc:
        return f"Database sumber tidak dapat diperiksa ({type(exc).__name__})."

    required = {"data_tabel", "tabel_meta", "nilai_data", "editorial_cache", "log_gagal"}
    missing = sorted(required - existing)
    if missing:
        return f"Tabel penyimpanan BPS belum tersedia: {', '.join(missing)}."
    return None
