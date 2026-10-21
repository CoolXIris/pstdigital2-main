"""Keyword-based catalog search with core-term coverage and IDF weighting."""

import logging
import math
import os
import re
from collections import Counter
from difflib import SequenceMatcher
from typing import Any, Iterable

import sinonim


logger = logging.getLogger(__name__)
_TOKEN_PATTERN = re.compile(r"[a-z]+", re.IGNORECASE)


def tokenize(text: str) -> list[str]:
    normalized = str(text or "").casefold().replace("-", " ").replace("/", " ")
    return _TOKEN_PATTERN.findall(normalized)


def stem(word: str) -> str:
    for suffix in ("nya", "kan", "an"):
        if word.endswith(suffix) and len(word) > len(suffix) + 3:
            return word[:-len(suffix)]
    return word


class Pencari:
    def __init__(
        self,
        rows: Iterable[dict[str, Any]],
        corpus_rows: Iterable[dict[str, Any]] | None = None,
    ) -> None:
        rows = list(rows)
        self.tables: dict[str, dict[str, Any]] = {}
        for row in rows:
            table_id = str(
                row.get("id_tabel") or row.get("var_id") or row.get("table_id") or ""
            ).strip()
            title = str(row.get("judul") or row.get("title") or "").strip()
            if not table_id or not title:
                continue

            table = self.tables.setdefault(table_id, {
                "id": table_id,
                "judul": title,
                "subjek": str(row.get("subjek") or row.get("subject") or ""),
                "jenis": str(row.get("jenis_tabel") or row.get("jenis") or ""),
                "tahun": set(),
                "rows": [],
                "aliases": set(),
                "_documents": set(),
            })
            table["rows"].append(row)
            aliases = row.get("aliases") or []
            if isinstance(aliases, str):
                aliases = [aliases]
            table["aliases"].update(
                str(alias).strip() for alias in aliases if str(alias).strip()
            )
            years = row.get("years") or []
            if isinstance(years, (str, int)):
                years = [years]
            years = list(years) + [row.get("tahun")]
            table["tahun"].update(
                str(year)[:4]
                for year in years
                if str(year or "").strip() and str(year).strip().isdigit()
            )
            table["_documents"].update(
                stem(token)
                for token in tokenize(f"{title} {table['subjek']}")
            )

        documents: dict[str, set[str]] = {}
        for row in corpus_rows if corpus_rows is not None else rows:
            table_id = str(
                row.get("id_tabel") or row.get("var_id") or row.get("table_id") or ""
            ).strip()
            title = str(row.get("judul") or row.get("title") or "").strip()
            subject = str(row.get("subjek") or row.get("subject") or "").strip()
            if table_id and title:
                documents.setdefault(table_id, set()).update(
                    stem(token) for token in tokenize(f"{title} {subject}")
                )
        document_frequency: Counter[str] = Counter()
        for document in documents.values():
            document_frequency.update(document)
        count = len(documents)
        self.idf = {
            token: math.log((count + 1) / (frequency + 0.5)) + 1
            for token, frequency in document_frequency.items()
        }
        for table in self.tables.values():
            alias_tokens = {
                stem(token)
                for alias in table["aliases"]
                for token in tokenize(alias)
            }
            table["_tokens"] = table["_documents"] | alias_tokens

    def kata_inti(self, pertanyaan: str) -> list[str]:
        text = str(pertanyaan or "").casefold().replace("-", " ").replace("/", " ")
        tokens = set(tokenize(text))
        if (
            tokens & {"laki", "pria", "lelaki", "cowok"}
            and tokens & {"perempuan", "wanita", "cewek"}
        ):
            gender_tokens = {
                "laki", "pria", "lelaki", "cowok", "perempuan", "wanita", "cewek",
            }
            other_terms = [
                token if token in sinonim.SYN else stem(token)
                for token in tokenize(text)
                if token not in gender_tokens
                and token not in sinonim.STOP
                and token not in sinonim.GENERIC
                and not token.isdigit()
            ]
            return list(dict.fromkeys([*other_terms, "jenis kelamin"]))
        core: list[str] = []
        phrases = sorted(
            (phrase for phrase in sinonim.SYN if " " in phrase),
            key=len,
            reverse=True,
        )
        for phrase in phrases:
            pattern = rf"(?<!\w){re.escape(phrase)}(?!\w)"
            if re.search(pattern, text):
                core.append(phrase)
                text = re.sub(pattern, " ", text)

        for token in tokenize(text):
            if token.isdigit() or token in sinonim.STOP or token in sinonim.GENERIC:
                continue
            normalized = token if token in sinonim.SYN else stem(token)
            if normalized and normalized not in core:
                core.append(normalized)
        return core

    @staticmethod
    def _alternatives(term: str) -> list[str]:
        return list(sinonim.SYN.get(term, (term,)))

    def _match_term(
        self,
        term: str,
        tokens: set[str],
    ) -> tuple[float, str | None, bool]:
        alternatives = self._alternatives(term)
        for alternative in alternatives:
            alternative_tokens = [stem(token) for token in tokenize(alternative)]
            if alternative_tokens and all(token in tokens for token in alternative_tokens):
                idf = max(
                    (self.idf.get(token, 1.0) for token in alternative_tokens),
                    default=1.0,
                )
                return idf, alternative, False

        for related in sinonim.RELATED.get(term, ()):
            related_tokens = [stem(token) for token in tokenize(related)]
            if related_tokens and all(token in tokens for token in related_tokens):
                idf = max(
                    (self.idf.get(token, 1.0) for token in related_tokens),
                    default=1.0,
                )
                return idf * 0.55, related, True
        return 0.0, None, False

    def cari(
        self,
        pertanyaan: str,
        tahun_diminta: int | str | None = None,
        top: int = 3,
        jenis: str | None = None,
    ) -> dict[str, Any]:
        core = self.kata_inti(pertanyaan)
        words = tokenize(pertanyaan)
        generic = [
            stem(word) for word in words
            if word in sinonim.GENERIC and not word.isdigit()
        ]
        matches: list[dict[str, Any]] = []
        rejected: list[dict[str, Any]] = []

        for table in self.tables.values():
            if jenis and table["jenis"] and table["jenis"] != jenis:
                continue
            matched_terms: list[str] = []
            synonyms_used: dict[str, str] = {}
            exact_match_count = 0
            related_match = False
            weighted_match = 0.0
            exact_match_bonus = 0.0
            total_weight = 0.0
            for term in core:
                alternatives = self._alternatives(term)
                term_weight = max(
                    (
                        self.idf.get(stem(token), 1.0)
                        for alternative in alternatives
                        for token in tokenize(alternative)
                    ),
                    default=self.idf.get(stem(term), 1.0),
                ) * 2
                total_weight += term_weight
                weight, matched_as, related = self._match_term(term, table["_tokens"])
                if matched_as is not None:
                    matched_terms.append(term)
                    synonyms_used[term] = matched_as
                    weighted_match += weight * 2
                    if matched_as == term:
                        exact_match_bonus += 3.0
                        exact_match_count += 1
                    related_match = related_match or related

            coverage = len(matched_terms) / len(core) if core else 0.0
            reason = (
                "tidak ada kata inti yang cocok"
                if not matched_terms
                else f"kecocokan kata inti {len(matched_terms)}/{len(core)}"
            )
            if not core:
                rejected.append({"table": table, "reason": "kata inti kosong", "score": 0.0})
                continue
            if not matched_terms or (len(core) >= 2 and coverage < 0.5):
                rejected.append({
                    "table": table,
                    "reason": reason,
                    "score": weighted_match,
                })
                continue

            generic_bonus = sum(
                self.idf.get(word, 1.0) * 0.2
                for word in generic
                if stem(word) in table["_tokens"]
            )
            requested_year = str(tahun_diminta or "").strip()
            year_bonus = 2.0 if requested_year and requested_year in table["tahun"] else 0.0
            score = weighted_match + generic_bonus + year_bonus + exact_match_bonus
            similarity = min(
                100,
                round(
                    coverage * 70
                    + (weighted_match / total_weight * 20 if total_weight else 0)
                    + (exact_match_count / len(core) * 10 if core else 0)
                    + generic_bonus
                    + year_bonus
                ),
            )
            if related_match:
                similarity = min(similarity, 79)
            matches.append({
                **{
                    key: value
                    for key, value in table.items()
                    if not key.startswith("_")
                },
                "tahun": sorted(table["tahun"], key=int, reverse=True),
                "skor": score,
                "skor_kemiripan": similarity,
                "kata_inti_cocok": matched_terms,
                "sinonim_digunakan": synonyms_used,
                "jenis_kecocokan": "related" if related_match else "direct",
                "alasan": reason,
            })

        matches.sort(
            key=lambda item: (
                len(item["kata_inti_cocok"]),
                item["skor"],
                -len(item["judul"]),
                item["judul"].casefold(),
            ),
            reverse=True,
        )

        suggestions = self._suggest(core, rejected, top)
        output = {
            "kata_inti": core,
            "hasil": matches[:max(0, top)],
            "saran": suggestions,
        }
        if os.getenv("BPS_SEARCH_DEBUG", "").casefold() in {"1", "true", "yes", "on"}:
            logger.debug(
                "BPS_CATALOG_SEARCH core=%s synonyms=%s selected=%s rejected=%s",
                core,
                [item["sinonim_digunakan"] for item in matches[:max(0, top)]],
                [
                    {
                        "id": item["id"],
                        "judul": item["judul"],
                        "skor": round(item["skor"], 3),
                        "alasan": item["alasan"],
                    }
                    for item in matches[:max(0, top)]
                ],
                [
                    {
                        "id": item["table"]["id"],
                        "judul": item["table"]["judul"],
                        "alasan": item["reason"],
                    }
                    for item in rejected
                ],
            )
        return output

    @staticmethod
    def _suggest(
        core: list[str],
        rejected: list[dict[str, Any]],
        top: int,
    ) -> list[dict[str, Any]]:
        if not core or top <= 0:
            return []
        query = " ".join(core)
        ranked = []
        for entry in rejected:
            table = entry["table"]
            title = f"{table['judul']} {table['subjek']}".casefold()
            sequence_score = SequenceMatcher(None, query, title).ratio()
            token_score = (
                sum(
                    1 for term in core
                    if any(
                        stem(token) in table["_tokens"]
                        for token in Pencari._alternatives(term)
                        for token in tokenize(token)
                    )
                )
                / len(core)
            )
            score = token_score * 100 + sequence_score * 20
            ranked.append((score, table))
        ranked.sort(key=lambda item: (item[0], item[1]["judul"].casefold()), reverse=True)
        return [
            {
                "id": table["id"],
                "judul": table["judul"],
                "subjek": table["subjek"],
                "skor": round(score, 3),
                "alasan": "kandidat alternatif; tidak lolos ambang kecocokan kata inti",
            }
            for score, table in ranked[:top]
        ]
