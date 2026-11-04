# Chatbot evaluation

Generate the bank from `tabel_bps_sumsel.csv` and read-only `nilai_data`/`tabel_meta` keys:

```powershell
python tests\generate_questions.py
```

The output is split physically: `questions.jsonl` contains train cases and `holdout.jsonl` contains holdout cases. All variants of a catalog table use the same split. Cases without an independent numeric key are marked `manual_review` and skipped by automatic scoring. The generator does not call Gemini or WebAPI BPS.

Run one split or the full suite:

```powershell
python tests\run_tests.py --split train
python tests\run_tests.py --split holdout
python tests\run_tests.py --split all
```

Every run writes `report.md` and `results.jsonl`. When holdout is included, only aggregate holdout scores are written; per-question holdout answers and failures are not recorded. `GEMINI_API_KEY`, `GEMINI_CHAT_API_TOKEN`, and `BPS_API_KEY` are loaded from the repository `.env` when present, never printed, and redacted from saved output. A per-run limit of 300 Gemini and 300 BPS requests is enforced; retries are limited to one by default and requests are spaced by one second.

The runner checks the storage schema without migrating it. If required tables are absent, affected data questions are recorded as `data_issue`/`SKIP`; no source threshold is weakened to hide the issue.

`regression.jsonl` contains the permanent cases and expected values supplied for those regressions. R1-R10 are deterministic. Optional LLM quality scoring is not enabled.

`autofix_loop.py` is the bounded round driver:

```powershell
python tests\autofix_loop.py --max-rounds 5
```

Without `--repair-script`, it runs the baseline, reports the dominant train-only cause and stops rather than editing production source automatically. A local repair script can be supplied with `--repair-script path\to\repair.py`; it receives `BPS_AUTOFIX_ROUND`, `BPS_AUTOFIX_RULE`, `BPS_AUTOFIX_HYPOTHESIS`, and `BPS_AUTOFIX_PLAN`, and must make one targeted change and create a commit with the required co-author trailer. The runner removes API credentials from that script's environment, requires a clean worktree, reruns train and holdout, and reverts an unaccepted commit. Holdout details are never passed to the repair script.
