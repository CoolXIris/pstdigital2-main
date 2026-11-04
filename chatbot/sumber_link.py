"""Build trusted BPS source links from CSV and WebAPI metadata."""

from __future__ import annotations

import re
import unicodedata
from collections.abc import Iterable, Mapping
from urllib.parse import parse_qsl, quote, urlsplit

BASE = "https://sumsel.bps.go.id"
_TABLE_TYPES = {
    "tabel statis": "1",
    "tabel dinamis": "2",
    "simdasi": "3",
}
_CONTENT_LISTS = {
    "pressrelease": f"{BASE}/id/pressrelease",
    "publication": f"{BASE}/id/publication",
    "news": f"{BASE}/id/news",
}
_URL_FIELDS = ("url_halaman", "page_url", "web_url", "permalink", "url", "link")
_PDF_FIELDS = ("pdf", "pdf_url", "url_pdf", "download_url", "dl", "tautan")


def slug_judul(judul: str) -> str:
    """Mirror the verified BPS slug rule: each non-ASCII-alphanumeric char becomes '-'."""
    normalized = unicodedata.normalize("NFKD", str(judul or ""))
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii").lower()
    return re.sub(r"[^a-z0-9]", "-", ascii_text)


def _trusted_url(value: object) -> str:
    if not isinstance(value, str):
        return ""
    url = value.strip()
    parts = urlsplit(url)
    host = (parts.hostname or "").lower()
    if (
        parts.scheme != "https"
        or not (host == "bps.go.id" or host.endswith(".bps.go.id"))
        or parts.username
        or parts.password
        or re.search(r"/key/[^/]+", parts.path, re.IGNORECASE)
        or any(
            key.casefold() in {"key", "api_key", "token"}
            for key, _ in parse_qsl(parts.query)
        )
    ):
        return ""
    return url


def link_tabel(row_csv: Mapping[str, object]) -> str:
    """Return a verified table page, using only known BPS route codes for raw IDs."""
    url = _trusted_url(row_csv.get("url_halaman"))
    link_status = str(row_csv.get("status_link") or "").casefold()
    if url and link_status not in {
        "gagal",
        "tidak_valid",
    }:
        return url

    subject_id = str(row_csv.get("id_subjek") or "").strip()
    if link_status in {"gagal", "tidak_valid"}:
        return (
            f"{BASE}/id/statistics-table?subject={subject_id}"
            if subject_id.isdecimal()
            else ""
        )

    raw_id = str(row_csv.get("id_raw") or "").strip()
    table_type = str(row_csv.get("jenis_tabel") or "").strip().casefold()
    source_code = _TABLE_TYPES.get(table_type)
    title = str(row_csv.get("judul") or "").strip()
    if raw_id and source_code and title:
        candidate = (
            f"{BASE}/id/statistics-table/{source_code}/"
            f"{quote(raw_id, safe='=')}/{slug_judul(title)}.html"
        )
        return _trusted_url(candidate)

    if subject_id.isdecimal():
        return f"{BASE}/id/statistics-table?subject={subject_id}"
    return ""


def link_konten(
    model: str,
    item: Mapping[str, object],
    detail: Mapping[str, object] | None = None,
) -> dict[str, str]:
    """Resolve an editorial page and optional PDF using URLs actually returned by BPS."""
    model_key = str(model or "").strip().casefold()
    merged = {**dict(item), **dict(detail or {})}

    page_url = next(
        (url for field in _URL_FIELDS if (url := _trusted_url(merged.get(field)))
         and urlsplit(url).hostname == "sumsel.bps.go.id"),
        "",
    )
    pdf_url = next(
        (url for field in _PDF_FIELDS if (url := _trusted_url(merged.get(field)))),
        "",
    )
    if not page_url:
        page_url = _CONTENT_LISTS.get(model_key, "")
    if model_key == "infographic" and not page_url:
        page_url = pdf_url

    return {"url": page_url, "pdf_url": pdf_url}


def metadata_tabel(
    row_csv: Mapping[str, object],
    *,
    tahun: object = None,
    tanggal_rilis: object = None,
) -> dict[str, object]:
    return {
        "jenis": str(row_csv.get("jenis_tabel") or ""),
        "judul": str(row_csv.get("judul") or ""),
        "url": link_tabel(row_csv),
        "tahun": tahun or row_csv.get("tahun") or None,
        "tanggal_rilis": tanggal_rilis or None,
    }


def susun_sumber(daftar_sumber: Iterable[Mapping[str, object]]) -> str:
    """Format unique source entries as Markdown lines ready to append to a reply."""
    lines: list[str] = []
    seen: set[tuple[str, str]] = set()
    for source in daftar_sumber:
        title = str(source.get("judul") or "").strip()
        url = _trusted_url(source.get("url"))
        pdf_url = _trusted_url(source.get("pdf_url"))
        if not url:
            url = pdf_url
            pdf_url = ""
        if not url:
            continue
        identity = (url, pdf_url)
        if identity in seen:
            continue
        seen.add(identity)
        label = title or "BPS Provinsi Sumatera Selatan"
        line = f"- [{label}]({url})"
        if pdf_url and pdf_url != url:
            line += f" · [PDF]({pdf_url})"
        lines.append(line)

    return "Sumber:\n" + "\n".join(lines) if lines else ""


def hapus_link_model(teks: str) -> str:
    """Remove model-provided links, including any model-written source section."""
    lines = []
    in_source_section = False
    for line in str(teks or "").splitlines():
        if re.match(r"^\s*(?:sumber(?:\s*/\s*pdf)?|sources?)\s*:", line, re.IGNORECASE):
            in_source_section = True
            continue
        if in_source_section:
            if not line.strip():
                in_source_section = False
                lines.append("")
                continue
            if re.match(r"^\s*(?:[-*+]\s+|https?://)", line, re.IGNORECASE):
                continue
            in_source_section = False
        line = re.sub(
            r"<a\b[^>]*>(.*?)</a\s*>",
            r"\1",
            line,
            flags=re.IGNORECASE | re.DOTALL,
        )
        line = re.sub(r"\[([^\]]+)\]\(\s*https?://[^)]+\)", r"\1", line, flags=re.IGNORECASE)
        line = re.sub(r"https?://[^\s<>\])]+", "", line, flags=re.IGNORECASE)
        lines.append(line.rstrip())
    cleaned = "\n".join(lines)
    cleaned = re.sub(r"[ \t]+\n", "\n", cleaned)
    cleaned = re.sub(r"\n{3,}", "\n\n", cleaned)
    return cleaned.strip()
