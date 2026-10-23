"""Run deterministic chatbot checks with persistent per-session API call limits."""

from __future__ import annotations

import argparse
import json
import logging
import os
import re
import sys
import tempfile
import time
import uuid
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

from test_harness import (
    ROOT,
    TESTS_DIR,
    check_storage_schema,
    escape_secrets,
    load_local_environment,
    read_jsonl,
    write_jsonl,
)

sys.path.insert(0, str(ROOT))

INFRA_SKIP_LIMIT = 0.10


class InfrastructureError(RuntimeError):
    pass


class BudgetExceeded(RuntimeError):
    pass


class CallBudget:
    def __init__(self, path: Path, gemini_limit: int, bps_limit: int) -> None:
        self.path = path
        self.limits = {"gemini": gemini_limit, "bps": bps_limit}
        self.counts = {"gemini": 0, "bps": 0}
        self.exhausted = False
        if path.is_file():
            try:
                previous = json.loads(path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError) as exc:
                raise RuntimeError("Berkas anggaran API tidak dapat dibaca.") from exc
            for key in self.counts:
                self.counts[key] = int(previous.get(key, 0))

    def consume(self, provider: str) -> None:
        if self.counts[provider] >= self.limits[provider]:
            self.exhausted = True
            raise BudgetExceeded(f"Batas {provider} tercapai.")
        self.counts[provider] += 1
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.path.with_suffix(self.path.suffix + ".tmp")
        temporary.write_text(
            json.dumps(self.counts, sort_keys=True),
            encoding="utf-8",
        )
        temporary.replace(self.path)


def _load_catalog() -> list[dict[str, str]]:
    import csv

    with (ROOT / "tabel_bps_sumsel.csv").open(encoding="utf-8-sig", newline="") as source:
        return [dict(row) for row in csv.DictReader(source)]


def _table_type(case: dict[str, Any]) -> str:
    return str((case.get("ekspektasi") or {}).get("tipe") or "tidak_diketahui")


def _catalog_candidates(question: str, *, limit: int = 3) -> list[dict[str, Any]]:
    import bps_tools

    result = bps_tools.cari_variabel(question)
    candidates = result.get("hasil") or []
    return [
        {
            "judul": str(item.get("judul") or ""),
            "skor": item.get("skor_kemiripan"),
            "status_tarik": str(item.get("status_tarik") or ""),
        }
        for item in candidates[:limit]
        if isinstance(item, dict)
    ]


def _summary(rows: list[dict[str, Any]]) -> dict[str, Any]:
    visible = [row for row in rows if row.get("status") in {"PASS", "FAIL", "WARN"}]
    passed = sum(row["status"] == "PASS" for row in visible)
    failed = sum(row["status"] == "FAIL" for row in visible)
    skipped = sum(row["status"] == "SKIP" for row in rows)
    manual_review = sum(
        row["status"] == "SKIP" and row.get("skip_reason") == "manual_review" for row in rows
    )
    infra_skipped = sum(
        row["status"] == "SKIP" and row.get("skip_reason") != "manual_review" for row in rows
    )
    category_stats: dict[str, Counter[str]] = defaultdict(Counter)
    type_stats: dict[str, Counter[str]] = defaultdict(Counter)
    rule_stats: dict[str, Counter[str]] = defaultdict(Counter)

    for row in visible:
        category_stats[str(row.get("category") or "lainnya")][row["status"]] += 1
        type_stats[str(row.get("table_type") or "tidak_diketahui")][row["status"]] += 1
        for rule, result in (row.get("rules") or {}).items():
            value = result.get("passed")
            if value is not None:
                rule_stats[rule]["passed" if value else "failed"] += 1

    def serialize(groups: dict[str, Counter[str]]) -> dict[str, dict[str, int]]:
        return {
            name: {
                "pass": counts.get("PASS", 0),
                "fail": counts.get("FAIL", 0),
                "count": counts.get("PASS", 0) + counts.get("FAIL", 0),
            }
            if "PASS" in counts or "FAIL" in counts
            else {
                "pass": counts.get("passed", 0),
                "fail": counts.get("failed", 0),
                "count": counts.get("passed", 0) + counts.get("failed", 0),
            }
            for name, counts in sorted(groups.items())
        }

    rule_output = {
        name: {
            "pass": counts.get("passed", 0),
            "fail": counts.get("failed", 0),
            "count": counts.get("passed", 0) + counts.get("failed", 0),
        }
        for name, counts in sorted(rule_stats.items())
    }
    return {
        "tested": len(visible),
        "passed": passed,
        "failed": failed,
        "skipped": skipped,
        "manual_review": manual_review,
        "infra_skipped": infra_skipped,
        "total": len(rows),
        "graded_ratio": f"{len(visible)}/{len(rows)}",
        "score": round(100 * passed / len(visible), 2) if visible else None,
        "by_category": serialize(category_stats),
        "by_table_type": serialize(type_stats),
        "by_rule": rule_output,
    }


def _markdown_report(
    split_summaries: dict[str, dict[str, Any]],
    rows: list[dict[str, Any]],
    budget: CallBudget,
    environment_issues: list[str],
) -> str:
    lines = [
        "# Laporan Pengujian Chatbot BPS",
        "",
        f"- Panggilan Gemini: {budget.counts['gemini']}/{budget.limits['gemini']}",
        f"- Panggilan WebAPI BPS: {budget.counts['bps']}/{budget.limits['bps']}",
        "- Penilaian kualitas LLM: tidak dijalankan; hasil status hanya berdasarkan aturan deterministik.",
        "",
    ]
    for split, summary in split_summaries.items():
        lines.extend([
            f"## {split.title()}",
            "",
            f"- Dinilai/total: {summary['graded_ratio']} (manual_review: {summary['manual_review']}; SKIP infrastruktur: {summary['infra_skipped']})",
            f"- Skor: {summary['score'] if summary['score'] is not None else 'n/a'}%",
            f"- PASS: {summary['passed']}; FAIL: {summary['failed']}; SKIP: {summary['skipped']}",
            "",
            "### Per kategori",
            "",
            "| Kategori | PASS | FAIL | Total |",
            "|---|---:|---:|---:|",
        ])
        for name, counts in summary["by_category"].items():
            lines.append(f"| {name} | {counts['pass']} | {counts['fail']} | {counts['count']} |")
        lines.extend([
            "",
            "### Per jenis tabel",
            "",
            "| Jenis | PASS | FAIL | Total |",
            "|---|---:|---:|---:|",
        ])
        for name, counts in summary["by_table_type"].items():
            lines.append(f"| {name} | {counts['pass']} | {counts['fail']} | {counts['count']} |")
        lines.extend([
            "",
            "### Per aturan",
            "",
            "| Aturan | PASS | FAIL | Total |",
            "|---|---:|---:|---:|",
        ])
        for name, counts in summary["by_rule"].items():
            lines.append(f"| {name} | {counts['pass']} | {counts['fail']} | {counts['count']} |")
        lines.append("")

    failures = [
        row for row in rows
        if row.get("split") != "holdout" and row.get("status") == "FAIL"
    ]
    lines.extend(["## Kegagalan train/regression", ""])
    if not failures:
        lines.append("Tidak ada kegagalan train/regression yang tercatat.")
    else:
        for row in failures:
            lines.extend([
                f"### {row.get('id', 'unknown')}",
                "",
                f"- Pertanyaan: {row.get('pertanyaan', '')}",
                f"- Jawaban: {row.get('jawaban', '')}",
                f"- Kunci: `{json.dumps(row.get('kunci_jawaban', []), ensure_ascii=False)}`",
                f"- Aturan gagal: {', '.join(row.get('failed_rules', []))}",
                f"- Tabel terpilih: {row.get('selected_table', 'tidak terdeteksi')}",
                f"- Kandidat teratas: `{json.dumps(row.get('kandidat_teratas', []), ensure_ascii=False)}`",
                "",
            ])

    data_issues = [row for row in rows if row.get("data_issue")]
    lines.extend(["## data_issue", ""])
    if data_issues:
        for row in data_issues:
            lines.append(
                f"- {row.get('id')}: {row.get('data_issue')}"
                f" Pertanyaan: {row.get('pertanyaan', '')}"
            )
    if environment_issues:
        lines.extend(f"- Lingkungan: {issue}" for issue in environment_issues)
    if not data_issues and not environment_issues:
        lines.append("- Tidak ada prasyarat lingkungan yang terdeteksi.")
    return "\n".join(lines).rstrip() + "\n"


def _selected_table_id(
    case: dict[str, Any],
    reply: str,
    tool_calls: list[Any],
    catalog: list[dict[str, str]],
) -> str | list[str] | None:
    from judge import _expected_tables, _title_table_ids, _tool_table_ids

    expected = _expected_tables(case.get("ekspektasi") or {})
    selected = _tool_table_ids(tool_calls) | _title_table_ids(reply, catalog)
    if not selected:
        return None
    ordered = sorted(selected)
    if expected and len(expected) == 1:
        return ordered[0]
    return ordered


def _instrument(main_module: Any, budget: CallBudget) -> tuple[Counter[str], list[Any]]:
    import bps_csv_catalog
    import bps_data_store
    import httpx

    metrics: Counter[str] = Counter()
    originals: list[Any] = []
    original_get_client = main_module.get_client

    class MeteredModels:
        def __init__(self, models: Any) -> None:
            self._models = models

        def generate_content(self, *args: Any, **kwargs: Any) -> Any:
            budget.consume("gemini")
            return self._models.generate_content(*args, **kwargs)

        def __getattr__(self, name: str) -> Any:
            return getattr(self._models, name)

    class MeteredClient:
        def __init__(self, client: Any) -> None:
            self.models = MeteredModels(client.models)

        def __getattr__(self, name: str) -> Any:
            return getattr(original_get_client(), name)

    main_module.get_client = lambda: MeteredClient(original_get_client())
    originals.append(("main.get_client", original_get_client))

    original_http_get = httpx.Client.get

    def metered_http_get(client: Any, url: Any, *args: Any, **kwargs: Any) -> Any:
        host = urlsplit(str(url)).hostname
        if host == "webapi.bps.go.id":
            budget.consume("bps")
            metrics["bps_calls"] += 1
        return original_http_get(client, url, *args, **kwargs)

    httpx.Client.get = metered_http_get
    originals.append(("httpx.Client.get", original_http_get))

    original_load_catalog = bps_csv_catalog.load_catalog

    def metered_catalog(*args: Any, **kwargs: Any) -> Any:
        metrics["csv_calls"] += 1
        return original_load_catalog(*args, **kwargs)

    bps_csv_catalog.load_catalog = metered_catalog
    originals.append(("bps_csv_catalog.load_catalog", original_load_catalog))

    original_init_db = bps_data_store.init_db

    def metered_init_db(*args: Any, **kwargs: Any) -> Any:
        metrics["db_calls"] += 1
        return original_init_db(*args, **kwargs)

    bps_data_store.init_db = metered_init_db
    originals.append(("bps_data_store.init_db", original_init_db))
    return metrics, originals


def _restore_instrumentation(originals: list[Any]) -> None:
    import bps_csv_catalog
    import bps_data_store
    import httpx
    import main

    for name, original in reversed(originals):
        if name == "main.get_client":
            main.get_client = original
        elif name == "httpx.Client.get":
            httpx.Client.get = original
        elif name == "bps_csv_catalog.load_catalog":
            bps_csv_catalog.load_catalog = original
        elif name == "bps_data_store.init_db":
            bps_data_store.init_db = original


def _run_case(
    case: dict[str, Any],
    *,
    main_module: Any,
    budget: CallBudget,
    counters: Counter[str],
    catalog: list[dict[str, str]],
    secrets: list[str],
    delay: float,
    retries: int,
    answer_cache: dict[str, Any] | None = None,
) -> dict[str, Any]:
    from fastapi import HTTPException
    from judge import judge_response

    before = counters.copy()
    reply = ""
    result_data: dict[str, Any] = {}
    attempts = 0
    cache_key = " ".join(str(case["pertanyaan"]).casefold().split())
    cached = answer_cache.get(cache_key) if answer_cache is not None else None
    while cached is None:
        attempts += 1
        try:
            response = main_module.chat_endpoint(
                main_module.ChatRequest(question=str(case["pertanyaan"])),
                authorization=f"Bearer {os.getenv('GEMINI_CHAT_API_TOKEN', '')}",
            )
            result_data = response if isinstance(response, dict) else {}
            reply = str(result_data.get("reply") or result_data.get("data") or "")
            break
        except HTTPException as exc:
            reply = f"Permintaan gagal dengan HTTP {exc.status_code}."
            result_data = {"error_status": exc.status_code}
            if exc.status_code in {429, 502} and attempts <= retries and not budget.exhausted:
                time.sleep(max(delay, 2.0))
                continue
            break
        except BudgetExceeded:
            budget.exhausted = True
            reply = "Pengujian dihentikan karena batas panggilan API tercapai."
            break
        except Exception as exc:
            reply = f"Pengujian gagal dengan {type(exc).__name__}."
            result_data = {"error_type": type(exc).__name__}
            break

    if cached is not None:
        reply, result_data, deltas = cached
        tool_calls = result_data.get("tool_calls") or []
        if not isinstance(tool_calls, list):
            tool_calls = []
    else:
        tool_calls = result_data.get("tool_calls") or []
        if not isinstance(tool_calls, list):
            tool_calls = []
        counters["tool_calls"] += len(tool_calls)
        deltas = {
            name: int(counters[name] - before[name])
            for name in ("bps_calls", "csv_calls", "db_calls")
        }
        deltas["tool_calls"] = len(tool_calls)
        if answer_cache is not None and not result_data.get("error_status") and not budget.exhausted:
            answer_cache[cache_key] = (reply, result_data, dict(deltas))
    result = judge_response(
        case,
        reply,
        catalog=catalog,
        tool_calls=tool_calls,
        metrics=deltas,
        secrets=secrets,
        answer_status=result_data.get("status"),
    )
    if result_data.get("error_status") in {429, 502, 503}:
        result["status"] = "SKIP"
        result["skip_reason"] = "infra"
        result["failed_rules"] = []
    safe_reply = escape_secrets(reply, secrets)
    record = {
        **result,
        "split": case.get("split"),
        "category": case.get("kategori"),
        "table_type": _table_type(case),
        "pertanyaan": escape_secrets(str(case.get("pertanyaan") or ""), secrets),
        "jawaban": safe_reply,
        "kunci_jawaban": case.get("kunci_jawaban") or [],
        "selected_table": _selected_table_id(case, reply, tool_calls, catalog),
        "attempts": attempts,
        "api_metrics": deltas,
    }
    if result_data.get("error_status") in {429, 502, 503}:
        record["data_issue"] = (
            f"Permintaan layanan gagal dengan HTTP {result_data['error_status']}."
        )
    if record["status"] == "FAIL" and case.get("split") != "holdout":
        try:
            record["kandidat_teratas"] = _catalog_candidates(str(case["pertanyaan"]))
        except Exception as exc:
            record["kandidat_teratas"] = [{"error": type(exc).__name__}]
    else:
        record["kandidat_teratas"] = []
    if delay > 0:
        time.sleep(delay)
    return record


def run(
    split: str,
    *,
    budget_file: Path,
    output_dir: Path,
    delay: float,
    retries: int,
    gemini_limit: int,
    bps_limit: int,
) -> dict[str, Any]:
    load_local_environment()
    os.environ["GEMINI_DEBUG"] = "1"
    logging.disable(logging.CRITICAL)
    from judge import judge_response
    import main

    budget = CallBudget(budget_file, gemini_limit, bps_limit)
    train_cases = read_jsonl(TESTS_DIR / "questions.jsonl") if split in {"train", "all"} else []
    holdout_cases = read_jsonl(TESTS_DIR / "holdout.jsonl") if split in {"holdout", "all"} else []
    regression_cases = read_jsonl(TESTS_DIR / "regression.jsonl") if split in {"train", "all"} else []
    catalog = _load_catalog()
    secrets = [
        os.getenv("GEMINI_API_KEY", ""),
        os.getenv("GEMINI_CHAT_API_TOKEN", ""),
        os.getenv("BPS_API_KEY", ""),
    ]
    environment_issues: list[str] = []
    if not os.getenv("GEMINI_CHAT_API_TOKEN"):
        environment_issues.append("GEMINI_CHAT_API_TOKEN tidak dikonfigurasi; panggilan chatbot dilewati.")
    if not os.getenv("GEMINI_API_KEY"):
        environment_issues.append("GEMINI_API_KEY tidak dikonfigurasi; panggilan chatbot dilewati.")
    storage_issue = check_storage_schema()
    if storage_issue:
        environment_issues.append(storage_issue)
    credentials_ready = bool(
        os.getenv("GEMINI_CHAT_API_TOKEN") and os.getenv("GEMINI_API_KEY")
    )
    os.environ["CHATBOT_TEMPERATURE"] = "0"
    answer_cache: dict[str, Any] = {}

    def _needs_data(case: dict[str, Any]) -> bool:
        expectation = case.get("ekspektasi") or {}
        return not bool(expectation.get("tanpa_data")) and str(expectation.get("tipe") or "") != "general"

    gradable = [
        case for case in train_cases + regression_cases + holdout_cases
        if case.get("status_kunci") != "manual_review"
    ]
    infra_blocked = [
        case for case in gradable
        if not credentials_ready or (storage_issue and _needs_data(case))
    ]
    if gradable and len(infra_blocked) / len(gradable) > INFRA_SKIP_LIMIT:
        raise InfrastructureError(
            f"{len(infra_blocked)} dari {len(gradable)} tes tidak dapat dijalankan karena infrastruktur "
            f"(> {int(INFRA_SKIP_LIMIT * 100)}%): "
            + "; ".join(environment_issues or ["penyebab tidak diketahui"])
        )

    counters, originals = _instrument(main, budget)
    detailed_train: list[dict[str, Any]] = []
    holdout_rows: list[dict[str, Any]] = []
    all_rows: list[dict[str, Any]] = []
    try:
        for case in train_cases + regression_cases:
            data_question = (
                not bool((case.get("ekspektasi") or {}).get("tanpa_data"))
                and str((case.get("ekspektasi") or {}).get("tipe") or "") != "general"
            )
            if case.get("status_kunci") == "manual_review" or not credentials_ready or (storage_issue and data_question):
                skipped = {
                    "id": case.get("id"),
                    "status": "SKIP",
                    "failed_rules": [],
                    "rules": {},
                    "split": case.get("split", "train"),
                    "category": case.get("kategori"),
                    "table_type": _table_type(case),
                    "pertanyaan": case.get("pertanyaan"),
                    "jawaban": "",
                    "kunci_jawaban": case.get("kunci_jawaban") or [],
                    "selected_table": None,
                    "kandidat_teratas": [],
                    "quality": {"status": "not_run"},
                    "skip_reason": "manual_review" if case.get("status_kunci") == "manual_review" else "infra",
                }
                if storage_issue and data_question:
                    skipped["data_issue"] = storage_issue
                detailed_train.append(skipped)
                all_rows.append(skipped)
                continue
            record = _run_case(
                case,
                main_module=main,
                budget=budget,
                counters=counters,
                catalog=catalog,
                secrets=secrets,
                delay=delay,
                retries=retries,
                answer_cache=answer_cache,
            )
            detailed_train.append(record)
            all_rows.append(record)
            if budget.exhausted:
                environment_issues.append("Panggilan API mencapai anggaran; pengujian dihentikan.")
                break

        if not budget.exhausted:
            for case in holdout_cases:
                data_question = (
                    not bool((case.get("ekspektasi") or {}).get("tanpa_data"))
                    and str((case.get("ekspektasi") or {}).get("tipe") or "") != "general"
                )
                if case.get("status_kunci") == "manual_review" or not credentials_ready or (storage_issue and data_question):
                    holdout_rows.append({
                        "status": "SKIP",
                        "skip_reason": "manual_review" if case.get("status_kunci") == "manual_review" else "infra",
                        "category": case.get("kategori"),
                        "table_type": _table_type(case),
                        "rules": {},
                        "data_issue": storage_issue if storage_issue and data_question else None,
                    })
                    continue
                holdout_rows.append(_run_case(
                    case,
                    main_module=main,
                    budget=budget,
                    counters=counters,
                    catalog=catalog,
                    secrets=secrets,
                    delay=delay,
                    retries=retries,
                    answer_cache=answer_cache,
                ))
                if budget.exhausted:
                    environment_issues.append("Panggilan API mencapai anggaran; pengujian dihentikan.")
                    break
    finally:
        _restore_instrumentation(originals)

    split_summaries: dict[str, dict[str, Any]] = {}
    if split in {"train", "all"}:
        split_summaries["train"] = _summary(detailed_train)
    if split in {"holdout", "all"}:
        split_summaries["holdout"] = _summary(holdout_rows)

    output_dir.mkdir(parents=True, exist_ok=True)
    output_rows = list(detailed_train)
    if split in {"holdout", "all"}:
        output_rows.append({
            "record_type": "holdout_aggregate_only",
            "split": "holdout",
            "summary": split_summaries.get("holdout", {}),
        })
    write_jsonl(output_dir / "results.jsonl", output_rows)
    report = _markdown_report(split_summaries, detailed_train, budget, environment_issues)
    (output_dir / "report.md").write_text(report, encoding="utf-8")
    summary = {
        "split": split,
        "splits": split_summaries,
        "gemini_calls": budget.counts["gemini"],
        "bps_calls": budget.counts["bps"],
        "limits": budget.limits,
        "budget_exhausted": budget.exhausted,
        "environment_issues": environment_issues,
    }
    (output_dir / "summary.json").write_text(
        json.dumps(summary, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    if output_dir.resolve() != TESTS_DIR.resolve():
        (TESTS_DIR / "report.md").write_text(report, encoding="utf-8")
        write_jsonl(TESTS_DIR / "results.jsonl", output_rows)
    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--split", choices=("train", "holdout", "all"), required=True)
    parser.add_argument("--delay", type=float, default=1.0)
    parser.add_argument("--retries", type=int, default=1)
    parser.add_argument("--max-gemini-calls", type=int, default=300)
    parser.add_argument("--max-bps-calls", type=int, default=300)
    parser.add_argument("--budget-file", type=Path)
    parser.add_argument("--output-dir", type=Path, default=TESTS_DIR)
    args = parser.parse_args()

    if args.delay < 0 or args.retries < 0:
        parser.error("--delay dan --retries tidak boleh negatif.")
    budget_file = args.budget_file or (
        Path(tempfile.gettempdir()) / f"bps-chatbot-budget-{uuid.uuid4().hex}.json"
    )
    try:
        summary = run(
            args.split,
            budget_file=budget_file,
            output_dir=args.output_dir,
            delay=args.delay,
            retries=args.retries,
            gemini_limit=args.max_gemini_calls,
            bps_limit=args.max_bps_calls,
        )
    except InfrastructureError as exc:
        print(f"BERHENTI (infrastruktur): {exc}")
        return 3
    infra_failure = False
    for split_name, split_summary in summary["splits"].items():
        print(
            f"{split_name}: dinilai/total={split_summary['graded_ratio']} "
            f"score={split_summary['score']}% "
            f"PASS={split_summary['passed']} FAIL={split_summary['failed']} "
            f"SKIP={split_summary['skipped']} (manual_review={split_summary['manual_review']}, "
            f"infra={split_summary['infra_skipped']})"
        )
        non_manual = split_summary["total"] - split_summary["manual_review"]
        if non_manual and split_summary["infra_skipped"] / non_manual > INFRA_SKIP_LIMIT:
            infra_failure = True
            print(f"  PERINGATAN: SKIP infrastruktur pada {split_name} melebihi {int(INFRA_SKIP_LIMIT * 100)}%; skor tidak valid.")
    print(f"API calls: Gemini={summary['gemini_calls']} BPS={summary['bps_calls']}")
    if summary["budget_exhausted"]:
        print("Pengujian dihentikan karena batas API tercapai.")
    if args.budget_file is None:
        try:
            budget_file.unlink(missing_ok=True)
        except OSError:
            pass
    return 2 if summary["budget_exhausted"] else (3 if infra_failure else 0)


if __name__ == "__main__":
    raise SystemExit(main())
