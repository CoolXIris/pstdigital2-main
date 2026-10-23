"""Run bounded evaluation rounds and report one dominant train failure group."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile
import uuid
from collections import Counter
from pathlib import Path
from typing import Any

from test_harness import ROOT, TESTS_DIR, read_jsonl


CAUSES = {
    "R1": ("Pemilihan tabel", "Periksa pencarian CSV dan pemeringkatan kandidat tanpa mengubah ambang anti-mengarang."),
    "R2": ("Nilai/data", "Periksa ekstraksi nilai dan pemetaan variabel/wilayah dari sumber independen."),
    "R3": ("Tahun", "Periksa pemilihan tahun terbaru dan penjelasan tahun yang tidak tersedia."),
    "R4": ("Wilayah", "Periksa deteksi wilayah dan pencegahan benturan nama wilayah mirip."),
    "R5": ("Format jawaban", "Periksa instruksi dan normalisasi keluaran agar tidak berisi tabel Markdown/HTML."),
    "R6": ("Serialisasi jawaban", "Periksa pembersihan JSON/HTML mentah, baris kosong, dan angka duplikat."),
    "R7": ("Metadata/sumber", "Periksa penyebutan satuan, tahun, serta sumber terverifikasi."),
    "R8": ("Kerahasiaan", "Periksa redaksi kunci dan URL ber-key pada jawaban serta log."),
    "R9": ("Penolakan data luar", "Periksa klasifikasi data di luar cakupan tanpa menurunkan guard angka."),
    "R10": ("Klasifikasi pertanyaan umum", "Periksa intent umum agar tidak memanggil pencarian, DB, atau WebAPI."),
}


def _git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.strip()


def _dirty_paths() -> set[str]:
    paths: set[str] = set()
    for line in _git("status", "--porcelain").splitlines():
        path = line[3:].split(" -> ")[-1].strip().replace("\\", "/")
        if (
            path
            and path not in {"tests/report.md", "tests/results.jsonl"}
            and not path.startswith("tests/reports/")
            and "__pycache__/" not in path
        ):
            paths.add(path)
    return paths


def _revert_commit(commit: str) -> None:
    changed_files = set(filter(
        None,
        _git("diff-tree", "--no-commit-id", "--name-only", "-r", commit).splitlines(),
    ))
    conflicts = changed_files & _dirty_paths()
    if conflicts:
        raise RuntimeError(
            "Commit perbaikan gagal dan perlu di-revert, tetapi file terkait memiliki "
            "perubahan lokal: " + ", ".join(sorted(conflicts))
        )
    subprocess.run(
        ["git", "revert", "--no-commit", commit],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    message = (
        "revert: failed chatbot autofix\n\n"
        "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
    )
    subprocess.run(
        ["git", "commit", "-m", message],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )


def _rule_failures(rows: list[dict[str, Any]]) -> Counter[str]:
    failures: Counter[str] = Counter()
    for row in rows:
        if row.get("split") == "holdout" or row.get("status") != "FAIL":
            continue
        failures.update(row.get("failed_rules") or [])
    return failures


def _train_statuses(rows: list[dict[str, Any]]) -> dict[str, str]:
    return {
        str(row["id"]): str(row["status"])
        for row in rows
        if row.get("split") != "holdout" and row.get("id")
    }


def _regressions(previous: dict[str, str], current: dict[str, str]) -> list[str]:
    return sorted(
        test_id
        for test_id, old_status in previous.items()
        if old_status == "PASS" and current.get(test_id) == "FAIL"
    )


def _thresholds_met(summary: dict[str, Any]) -> bool:
    for split, threshold in (("train", 95.0), ("holdout", 90.0)):
        split_summary = summary.get("splits", {}).get(split)
        if not split_summary:
            return False
        for counts in split_summary.get("by_rule", {}).values():
            if counts.get("count", 0) and 100 * counts["pass"] / counts["count"] < threshold:
                return False
    return True


def _report_round(
    report_path: Path,
    *,
    round_number: int,
    branch: str,
    failures: Counter[str],
    regressions: list[str],
    blocked_reason: str | None,
) -> str:
    if failures:
        rule, count = failures.most_common(1)[0]
        label, plan = CAUSES.get(rule, ("Lainnya", "Tinjau aturan gagal berdasarkan log train."))
        section = [
            f"## Analisis putaran {round_number}",
            "",
            f"- Branch: `{branch}`",
            f"- Penyebab train terbanyak: {label} ({rule}, {count} kegagalan).",
            f"- Hipotesis akar masalah: kegagalan terkonsentrasi pada aturan {rule}; bukti rinci hanya berasal dari train/regression.",
            f"- Rencana satu kelompok: {plan}",
        ]
    else:
        section = [
            f"## Analisis putaran {round_number}",
            "",
            f"- Branch: `{branch}`",
            "- Tidak ada kegagalan train/regression yang dapat dikelompokkan.",
        ]
    if regressions:
        section.extend(["", f"- Regresi PASS ke FAIL: {', '.join(regressions)}."])
    if blocked_reason:
        section.extend(["", f"- Status perbaikan: dihentikan — {blocked_reason}"])
    content = report_path.read_text(encoding="utf-8") if report_path.exists() else ""
    return content.rstrip() + "\n\n" + "\n".join(section) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--max-rounds", type=int, default=5)
    parser.add_argument("--delay", type=float, default=1.0)
    parser.add_argument("--max-gemini-calls", type=int, default=300)
    parser.add_argument("--max-bps-calls", type=int, default=300)
    parser.add_argument("--repair-script", type=Path)
    args = parser.parse_args()
    if not 1 <= args.max_rounds <= 5:
        parser.error("--max-rounds harus antara 1 dan 5.")
    if args.delay < 0:
        parser.error("--delay tidak boleh negatif.")

    branch = _git("rev-parse", "--abbrev-ref", "HEAD")
    if not branch.startswith("autofix/"):
        raise SystemExit(f"Berhenti: branch aktif harus autofix/<tanggal>, bukan {branch}.")

    report_root = TESTS_DIR / "reports" / f"autofix-{uuid.uuid4().hex[:8]}"
    budget_file = Path(tempfile.gettempdir()) / f"bps-chatbot-autofix-budget-{uuid.uuid4().hex}.json"
    previous_statuses: dict[str, str] = {}
    previous_train_score: float | None = None
    previous_holdout_score: float | None = None
    no_improvement_rounds = 0
    pending_commit: str | None = None
    pending_baseline: dict[str, Any] | None = None
    previous_fix_failed = False
    repair_blocked: str | None = None

    try:
        for round_number in range(1, args.max_rounds + 1):
            output_dir = report_root / f"round-{round_number:02d}"
            command = [
                sys.executable,
                str(TESTS_DIR / "run_tests.py"),
                "--split",
                "all",
                "--delay",
                str(args.delay),
                "--max-gemini-calls",
                str(args.max_gemini_calls),
                "--max-bps-calls",
                str(args.max_bps_calls),
                "--budget-file",
                str(budget_file),
                "--output-dir",
                str(output_dir),
            ]
            completed = subprocess.run(command, cwd=ROOT, check=False)
            if completed.returncode not in {0, 2}:
                raise SystemExit(f"Runner gagal pada putaran {round_number} (exit {completed.returncode}).")

            summary = json.loads((output_dir / "summary.json").read_text(encoding="utf-8"))
            rows = read_jsonl(output_dir / "results.jsonl")
            train_summary = summary.get("splits", {}).get("train", {})
            train_score = train_summary.get("score")
            current_statuses = _train_statuses(rows)
            regressions = _regressions(previous_statuses, current_statuses)
            failures = _rule_failures(rows)

            repair_blocked = None
            reverted_fix = False
            if pending_commit and pending_baseline:
                before_splits = pending_baseline.get("splits", {})
                before_train = before_splits.get("train", {}).get("score")
                before_holdout = before_splits.get("holdout", {}).get("score")
                current_holdout = summary.get("splits", {}).get("holdout", {}).get("score")
                accepted = (
                    not regressions
                    and train_score is not None
                    and before_train is not None
                    and train_score > before_train
                    and (
                        current_holdout is None
                        or before_holdout is None
                        or current_holdout >= before_holdout
                    )
                )
                if not accepted:
                    _revert_commit(pending_commit)
                    reverted_fix = True
                    no_improvement_rounds += 1
                    previous_fix_failed = True
                    repair_blocked = (
                        "perbaikan gagal: skor train tidak naik, skor holdout turun, atau "
                        "terjadi regresi; commit perbaikan di-revert."
                    )
                else:
                    no_improvement_rounds = 0
                    previous_fix_failed = False
                pending_commit = None
                pending_baseline = None

            if summary.get("budget_exhausted"):
                repair_blocked = "batas panggilan API tercapai; tidak ada tes lanjutan."
            elif _thresholds_met(summary):
                repair_blocked = "ambang kelulusan train/holdout tercapai."
            elif not args.repair_script and not repair_blocked:
                repair_blocked = (
                    "tidak ada skrip perbaikan lokal; driver mengelompokkan bukti, "
                    "tetapi tidak mengedit kode secara otomatis."
                )

            report = _report_round(
                output_dir / "report.md",
                round_number=round_number,
                branch=branch,
                failures=failures,
                regressions=regressions,
                blocked_reason=repair_blocked,
            )
            (output_dir / "report.md").write_text(report, encoding="utf-8")
            if reverted_fix:
                if regressions:
                    (output_dir / "regressions.json").write_text(
                        json.dumps(regressions, ensure_ascii=False, indent=2),
                        encoding="utf-8",
                    )
                if no_improvement_rounds < 2 and round_number < args.max_rounds:
                    print("Perbaikan ditolak dan di-revert; pendekatan alternatif akan diuji pada putaran berikutnya.")
                    continue
                print("Perbaikan gagal; siklus dihentikan setelah revert.")
                break
            if regressions:
                (output_dir / "regressions.json").write_text(
                    json.dumps(regressions, ensure_ascii=False, indent=2),
                    encoding="utf-8",
                )
                if pending_commit:
                    _revert_commit(pending_commit)
                print("Regresi train ditemukan; siklus dihentikan.")
                break
            if summary.get("budget_exhausted") or _thresholds_met(summary):
                break
            if no_improvement_rounds >= 2:
                repair_blocked = "dua putaran berturut-turut tanpa perbaikan."
                print(repair_blocked)
                break
            if not args.repair_script:
                print(f"Putaran {round_number}: {repair_blocked}")
                break

            if not failures:
                print(f"Putaran {round_number}: tidak ada kegagalan train untuk diperbaiki.")
                break

            dirty_paths = _dirty_paths()
            if dirty_paths:
                repair_blocked = (
                    "worktree memiliki perubahan lokal yang belum di-commit; "
                    "perbaikan putaran diblokir agar perubahan lama tidak ikut ter-commit."
                )
                (output_dir / "report.md").write_text(
                    _report_round(
                        output_dir / "report.md",
                        round_number=round_number,
                        branch=branch,
                        failures=failures,
                        regressions=[],
                        blocked_reason=repair_blocked,
                    ),
                    encoding="utf-8",
                )
                print(f"Putaran {round_number}: {repair_blocked}")
                break

            rule, _ = failures.most_common(1)[0]
            label, plan = CAUSES.get(
                rule,
                ("Lainnya", "Tinjau aturan train yang gagal dan ubah satu kelompok penyebab."),
            )
            hypothesis = (
                f"Kegagalan train terbanyak berada pada {label} ({rule}); "
                "perbaikan harus umum dan tidak boleh mengubah tes atau guard anti-mengarang."
            )
            env = os.environ.copy()
            for secret_name in ("GEMINI_API_KEY", "GEMINI_CHAT_API_TOKEN", "BPS_API_KEY"):
                env.pop(secret_name, None)
            env.update({
                "BPS_AUTOFIX_ROUND": str(round_number),
                "BPS_AUTOFIX_RULE": rule,
                "BPS_AUTOFIX_HYPOTHESIS": hypothesis,
                "BPS_AUTOFIX_PLAN": plan,
                "BPS_AUTOFIX_PREVIOUS_FIX_FAILED": "1" if previous_fix_failed else "0",
            })
            before_commit = _git("rev-parse", "HEAD")
            repair_script = args.repair_script.resolve()
            if not repair_script.is_file():
                raise SystemExit(f"Skrip perbaikan tidak ditemukan: {repair_script}")
            repair_process = subprocess.run(
                [sys.executable, str(repair_script)],
                cwd=ROOT,
                env=env,
                check=False,
                capture_output=True,
                text=True,
                encoding="utf-8",
            )
            after_commit = _git("rev-parse", "HEAD")
            if repair_process.returncode != 0 or after_commit == before_commit:
                repair_blocked = "skrip perbaikan gagal atau tidak menghasilkan commit."
                (output_dir / "report.md").write_text(
                    _report_round(
                        output_dir / "report.md",
                        round_number=round_number,
                        branch=branch,
                        failures=failures,
                        regressions=[],
                        blocked_reason=repair_blocked,
                    ),
                    encoding="utf-8",
                )
                print(f"Putaran {round_number}: {repair_blocked}")
                break
            if _git("rev-parse", "--abbrev-ref", "HEAD") != branch:
                raise SystemExit("Skrip perbaikan berpindah branch; siklus dihentikan.")
            commit_message = _git("show", "-s", "--format=%B", after_commit)
            if "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>" not in commit_message:
                raise SystemExit("Commit perbaikan tidak memiliki trailer Co-authored-by.")
            pending_commit = after_commit
            pending_baseline = summary
            previous_statuses = current_statuses
            previous_train_score = train_score
            previous_holdout_score = summary.get("splits", {}).get("holdout", {}).get("score")

        last_report = report_root / f"round-{round_number:02d}" / "report.md"
        if last_report.exists():
            (TESTS_DIR / "report.md").write_text(last_report.read_text(encoding="utf-8"), encoding="utf-8")
            (TESTS_DIR / "results.jsonl").write_text(
                (report_root / f"round-{round_number:02d}" / "results.jsonl").read_text(encoding="utf-8"),
                encoding="utf-8",
            )
        print(f"Laporan putaran: {report_root}")
        print(f"Ringkasan akhir: {TESTS_DIR / 'report.md'}")
        print("Biaya panggilan API tersimpan dalam ringkasan setiap putaran (tanpa kunci).")
        return 2 if repair_blocked and not _thresholds_met(summary) else 0
    finally:
        try:
            budget_file.unlink(missing_ok=True)
        except OSError:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
