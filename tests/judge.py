"""Deterministic rules for judging chatbot responses."""

from __future__ import annotations

import re
from typing import Any, Iterable

from test_harness import normalize_region, normalize_text, parse_indonesian_number

RULE_NAMES = {
    "R1": "Tabel benar",
    "R2": "Angka cocok",
    "R3": "Tahun jujur",
    "R4": "Wilayah benar",
    "R5": "Tanpa tabel buatan",
    "R6": "Tanpa JSON/HTML/angka ganda",
    "R7": "Ada satuan, tahun, dan sumber",
    "R8": "Tanpa API key/URL ber-key",
    "R9": "Jujur untuk pertanyaan di luar data",
    "R10": "Pertanyaan umum tidak memanggil data",
}

_NUMBER_PATTERN = re.compile(r"(?<!\w)-?\d[\d. ]*(?:,\d+)?%?")
_NO_DATA_PATTERN = re.compile(
    r"\b(tidak ditemukan|belum ditemukan|tidak tersedia|belum tersedia|"
    r"belum dapat diverifikasi|tidak ada data|tidak tercakup|tidak memiliki informasi|"
    r"tidak mempunyai informasi|tidak menyediakan|di luar cakupan|bukan cakupan)\b",
    re.IGNORECASE,
)
_TABLE_SEPARATOR = re.compile(r"^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$")
_HTML_TAG = re.compile(r"</?[a-z][^>]*>", re.IGNORECASE)


def _reply_numbers(reply: str) -> list[float]:
    values: list[float] = []
    for match in _NUMBER_PATTERN.finditer(reply):
        raw = match.group(0).strip().rstrip("%").strip()
        number = parse_indonesian_number(raw)
        if number is not None:
            values.append(number)
    return values


def _expected_tables(expectation: dict[str, Any]) -> set[str]:
    tables = expectation.get("tabel") or []
    if isinstance(tables, str):
        tables = [tables]
    return {str(item) for item in tables if str(item).strip()}


def _tool_table_ids(tool_calls: Iterable[Any]) -> set[str]:
    result: set[str] = set()
    for call in tool_calls:
        text = str(call)
        for pattern in (
            r"\b(?:id_tabel|var_id)\s*[:=]\s*['\"]?([A-Za-z0-9_-]+)",
            r"\b(?:id_tabel|var_id)['\"]?\s*:\s*['\"]?([A-Za-z0-9_-]+)",
        ):
            result.update(re.findall(pattern, text, flags=re.IGNORECASE))
    return result


def _title_table_ids(reply: str, catalog: Iterable[dict[str, Any]]) -> set[str]:
    normalized_reply = normalize_text(reply)
    matches: list[tuple[int, str]] = []
    for row in catalog:
        title = normalize_text(row.get("judul"))
        if len(title) >= 12 and title in normalized_reply:
            matches.append((len(title), str(row.get("id_tabel") or "")))
    if not matches:
        return set()
    longest = max(length for length, _ in matches)
    return {table_id for length, table_id in matches if length == longest and table_id}


def _title_keywords_match(
    reply: str,
    tool_calls: Iterable[Any],
    catalog: Iterable[dict[str, Any]],
    keywords: Iterable[str],
    excluded_keywords: Iterable[str] = (),
) -> bool:
    required = [normalize_text(value) for value in keywords if normalize_text(value)]
    excluded = [normalize_text(value) for value in excluded_keywords if normalize_text(value)]
    if not required:
        return False
    selected_ids = _tool_table_ids(tool_calls)
    matched_rows = [
        row for row in catalog
        if selected_ids and str(row.get("id_tabel") or "") in selected_ids
    ]
    if not matched_rows:
        matched_rows = [
            row for row in catalog
            if normalize_text(row.get("judul"))
            and normalize_text(row.get("judul")) in normalize_text(reply)
        ]
    for row in matched_rows:
        title = normalize_text(row.get("judul"))
        if all(keyword in title for keyword in required) and not any(
            keyword in title for keyword in excluded
        ):
            return True
    return False


def _title_alternative_match(
    reply: str,
    tool_calls: Iterable[Any],
    catalog: Iterable[dict[str, Any]],
    alternatives: Iterable[str],
    excluded_keywords: Iterable[str] = (),
) -> bool:
    choices = [normalize_text(value) for value in alternatives if normalize_text(value)]
    excluded = [normalize_text(value) for value in excluded_keywords if normalize_text(value)]
    selected_ids = _tool_table_ids(tool_calls)
    rows = [
        row for row in catalog
        if selected_ids and str(row.get("id_tabel") or "") in selected_ids
    ]
    if not rows:
        normalized_reply = normalize_text(reply)
        rows = [
            row for row in catalog
            if normalize_text(row.get("judul"))
            and normalize_text(row.get("judul")) in normalized_reply
        ]
    for row in rows:
        title = normalize_text(row.get("judul"))
        if any(choice in title for choice in choices) and not any(
            keyword in title for keyword in excluded
        ):
            return True
    return False


def _regions_present(
    expected_regions: Iterable[str],
    reply: str,
    aliases: dict[str, list[str]] | None = None,
) -> bool:
    normalized_reply = normalize_region(reply)
    directional_suffixes = ("utara", "selatan", "timur", "barat")
    for region in expected_regions:
        options = [str(region)]
        if aliases:
            options.extend(str(alias) for alias in aliases.get(str(region), []))
        if not any(
            _region_option_present(option, normalized_reply, directional_suffixes)
            for option in options
        ):
            return False
    return True


def _region_option_present(
    region: str,
    normalized_reply: str,
    directional_suffixes: tuple[str, ...],
) -> bool:
    normalized = normalize_region(region)
    if not normalized:
        return False
    escaped = re.escape(normalized).replace(r"\ ", r"\s+")
    exact = re.search(
        rf"(?<!\w){escaped}(?!\w)(?!\s+(?:{'|'.join(directional_suffixes)})(?!\w))",
        normalized_reply,
    )
    if exact:
        return True
    acronym = "".join(word[0] for word in normalized.split() if word)
    return bool(
        len(acronym) >= 3
        and re.search(rf"(?<!\w){re.escape(acronym)}(?!\w)", normalized_reply)
    )


def _key_numbers_match(keys: list[dict[str, Any]], reply: str, expected_years: list[str]) -> bool:
    found_numbers = _reply_numbers(reply)
    year_numbers = {float(year) for year in expected_years if year.isdigit()}
    for key in keys:
        expected = parse_indonesian_number(key.get("nilai"))
        if expected is None:
            continue
        region = str(key.get("wilayah") or "")
        if not region:
            if not any(abs(actual - expected) <= max(0.000001, abs(expected) * 0.000001) for actual in found_numbers):
                return False
            continue

        aliases = [region]
        normalized_region = normalize_region(region)
        acronym = "".join(word[0] for word in normalized_region.split() if word)
        if len(acronym) >= 3:
            aliases.append(acronym)
        occurrences: list[int] = []
        normalized_reply = reply.casefold()
        for alias in aliases:
            normalized_alias = normalize_region(alias)
            if normalized_alias:
                escaped = re.escape(normalized_alias).replace(r"\ ", r"\s+")
                occurrences.extend(
                    match.start()
                    for match in re.finditer(rf"(?<!\w){escaped}(?!\w)", normalized_reply)
                )
        if not occurrences:
            if not any(abs(actual - expected) <= max(0.000001, abs(expected) * 0.000001) for actual in found_numbers):
                return False
            continue

        number_matches = [
            (match.start(), parse_indonesian_number(match.group(0).strip().rstrip("%").strip()))
            for match in _NUMBER_PATTERN.finditer(reply)
        ]
        number_matches = [
            (position, value)
            for position, value in number_matches
            if value is not None and value not in year_numbers
        ]
        if not number_matches:
            return False
        closest = min(
            number_matches,
            key=lambda item: min(abs(item[0] - position) for position in occurrences),
        )[1]
        if abs(closest - expected) > max(0.000001, abs(expected) * 0.000001):
            return False
    return True


def _rule(condition: bool, detail: str = "") -> dict[str, Any]:
    return {"passed": bool(condition), "detail": detail}


def judge_response(
    case: dict[str, Any],
    reply: str,
    *,
    catalog: Iterable[dict[str, Any]] = (),
    tool_calls: Iterable[Any] = (),
    metrics: dict[str, int] | None = None,
    secrets: Iterable[str] = (),
    answer_status: str | None = None,
) -> dict[str, Any]:
    expectation = case.get("ekspektasi") or {}
    keys = case.get("kunci_jawaban") or []
    if not isinstance(keys, list):
        keys = []
    normalized_reply = normalize_text(reply)
    expected_tables = _expected_tables(expectation)
    rules: dict[str, dict[str, Any]] = {}
    no_data = bool(expectation.get("tanpa_data"))
    question_type = str(expectation.get("tipe") or "")

    title_keywords = expectation.get("judul_kata") or []
    title_alternatives = expectation.get("judul_kata_salah_satu") or []
    excluded_title_keywords = expectation.get("judul_tidak_memuat") or []
    if expected_tables:
        selected = _tool_table_ids(tool_calls) | _title_table_ids(reply, catalog)
        rules["R1"] = _rule(
            bool(selected & expected_tables),
            f"expected={sorted(expected_tables)} selected={sorted(selected)}",
        )
    elif title_keywords:
        rules["R1"] = _rule(
            _title_keywords_match(
                reply,
                tool_calls,
                catalog,
                title_keywords,
                excluded_title_keywords,
            ),
            f"expected_title_terms={title_keywords}",
        )
    elif title_alternatives:
        rules["R1"] = _rule(
            _title_alternative_match(
                reply,
                tool_calls,
                catalog,
                title_alternatives,
                excluded_title_keywords,
            ),
            f"expected_one_title_term={title_alternatives}",
        )
    else:
        rules["R1"] = {"passed": None, "detail": "not_applicable"}

    if keys:
        expected_numbers = [
            parse_indonesian_number(key.get("nilai"))
            for key in keys
            if key.get("nilai") is not None
        ]
        rules["R2"] = _rule(
            bool(expected_numbers)
            and _key_numbers_match(keys, reply, [str(item) for item in (expectation.get("tahun") or [])]),
            f"expected={expected_numbers}",
        )
    else:
        rules["R2"] = {"passed": None, "detail": "no_independent_numeric_key"}

    expected_years = [str(year) for year in (expectation.get("tahun") or []) if str(year)]
    if isinstance(expectation.get("tahun"), str) and expectation["tahun"]:
        expected_years = [expectation["tahun"]]
    requested_year = str(expectation.get("tahun_diminta") or "")
    if expected_years:
        if no_data:
            year_ok = all(year in reply for year in expected_years) or bool(_NO_DATA_PATTERN.search(reply))
        else:
            year_ok = all(year in reply for year in expected_years)
        if requested_year and requested_year not in expected_years:
            year_ok = (
                year_ok
                and requested_year in reply
                and bool(_NO_DATA_PATTERN.search(reply))
            )
        rules["R3"] = _rule(year_ok, f"expected_years={expected_years}")
    else:
        rules["R3"] = {"passed": None, "detail": "no_year_expectation"}

    expected_regions = expectation.get("wilayah") or []
    if isinstance(expected_regions, str):
        expected_regions = [expected_regions]
    if expected_regions:
        rules["R4"] = _rule(
            _regions_present(
                expected_regions,
                reply,
                expectation.get("wilayah_alias"),
            ),
            f"expected_regions={expected_regions}",
        )
    else:
        rules["R4"] = {"passed": None, "detail": "no_region_expectation"}

    lines = reply.splitlines()
    has_markdown_table = any(
        line.lstrip().startswith("|") or _TABLE_SEPARATOR.match(line)
        for line in lines
    )
    has_html_table = bool(re.search(r"<\s*/?\s*table\b", reply, re.IGNORECASE))
    rules["R5"] = _rule(not has_markdown_table and not has_html_table)

    repeated_number = bool(
        re.search(r"(?<!\w)(\d+(?:[.,]\d+)?)(?:\s+\1)(?!\w)", reply)
    )
    too_many_blank_lines = bool(re.search(r"\n(?:[ \t]*\n){2,}", reply))
    raw_json = False
    stripped = reply.strip()
    if stripped.startswith(("{", "[")):
        try:
            import json

            json.loads(stripped)
            raw_json = True
        except json.JSONDecodeError:
            pass
    rules["R6"] = _rule(
        not (raw_json or _HTML_TAG.search(reply) or repeated_number or too_many_blank_lines)
    )

    if keys:
        required_units = [str(key.get("satuan") or "").strip() for key in keys]
        unit_ok = all(
            not unit or normalize_text(unit) in normalized_reply
            for unit in required_units
        )
        year_ok = not expected_years or any(year in reply for year in expected_years)
        source_ok = bool(re.search(r"\b(BPS|Badan Pusat Statistik)\b", reply, re.IGNORECASE))
        rules["R7"] = _rule(unit_ok and year_ok and source_ok, "requires unit, year, and BPS source")
    else:
        rules["R7"] = {"passed": None, "detail": "no_independent_numeric_key"}

    secret_list = [secret for secret in secrets if secret]
    contains_secret = any(secret in reply for secret in secret_list)
    keyed_url = bool(re.search(
        r"https?://[^\s)>\]]*(?:/key/[^/\s)>\]]+|[?&](?:key|api_key|token)=)",
        reply,
        re.IGNORECASE,
    ))
    rules["R8"] = _rule(not (contains_secret or keyed_url))

    if no_data:
        numeric_claim = bool(_reply_numbers(reply))
        candidate_table = bool(re.search(r"^\s*\|.*\|\s*$", reply, re.MULTILINE)) or "<table" in reply.lower()
        structured_status = answer_status in {"tidak_ditemukan", "di_luar_cakupan"}
        rules["R9"] = _rule(
            structured_status and not numeric_claim and not candidate_table,
            f"status={answer_status!r}; requires structured status, no numeric claim, no candidate table",
        )
    else:
        rules["R9"] = {"passed": None, "detail": "not_applicable"}

    if question_type == "general":
        counters = metrics or {}
        no_data_access = all(
            int(counters.get(name, 0)) == 0
            for name in ("bps_calls", "csv_calls", "db_calls", "tool_calls")
        )
        rules["R10"] = _rule(no_data_access, f"data_access={counters}")
    else:
        rules["R10"] = {"passed": None, "detail": "not_applicable"}

    failures = [name for name, value in rules.items() if value["passed"] is False]
    status = "FAIL" if failures else "PASS"
    if case.get("status_kunci") == "manual_review":
        status = "SKIP"
    return {
        "id": case.get("id"),
        "status": status,
        "rules": rules,
        "failed_rules": failures,
        "quality": {"status": "not_run", "note": "LLM-judge opsional tidak dijalankan."},
    }
