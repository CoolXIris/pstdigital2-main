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
_ABBREVIATION_PATTERN = re.compile(
    r"(?P<phrase>[A-Za-z][A-Za-z\s,/-]{3,80}?)\s*\((?P<abbr>[A-Z][A-Z0-9-]{1,9})\)"
    r"|(?P<abbr_first>[A-Z][A-Z0-9-]{1,9})\s*\((?P<phrase_first>[^()]{4,80})\)"
)


def tokenize(text: str) -> list[str]:
    normalized = str(text or "").casefold().replace("-", " ").replace("/", " ")
    return _TOKEN_PATTERN.findall(normalized)


def stem(word: str) -> str:
    value = word.casefold()
    for prefix in (
        "meng", "meny", "memper", "peng", "peny", "pem", "per",
        "men", "pen", "ber", "ter", "ke", "di", "me", "pe",
    ):
        if value.startswith(prefix) and len(value) - len(prefix) >= 4:
            value = value[len(prefix):]
            break
    for suffix in ("kan", "nya", "an", "i"):
        if value.endswith(suffix) and len(value) - len(suffix) >= 4:
            value = value[:-len(suffix)]
            break
    return value


class Pencari:
    def __init__(
        self,
        rows: Iterable[dict[str, Any]],
        corpus_rows: Iterable[dict[str, Any]] | None = None,
    ) -> None:
        rows = list(rows)
        self.tables: dict[str, dict[str, Any]] = {}
        self.abbreviation_aliases: dict[str, set[str]] = {}
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
            row_status = str(row.get("status_tarik") or "").casefold()
            previous_status = str(table.get("status_tarik") or "").casefold()
            status_rank = {"ok": 2, "belum_diaudit": 1, "kosong": 0, "gagal": 0}
            if status_rank.get(row_status, 1) > status_rank.get(previous_status, 1):
                table["status_tarik"] = row_status
                table["status_detail"] = str(row.get("status_detail") or "")
            aliases = row.get("aliases") or []
            if isinstance(aliases, str):
                aliases = [aliases]
            context_aliases = row.get("context_aliases") or []
            if isinstance(context_aliases, str):
                context_aliases = [context_aliases]
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
            table.setdefault("_title_tokens", set()).update(
                stem(token) for token in tokenize(title)
            )
            table.setdefault("_context_tokens", set()).update(
                stem(token)
                for token in tokenize(
                    " ".join((table["subjek"], *(str(alias) for alias in context_aliases)))
                )
            )
            for text in (title, table["subjek"]):
                for match in _ABBREVIATION_PATTERN.finditer(text):
                    abbreviation = (
                        match.group("abbr") or match.group("abbr_first") or ""
                    ).casefold()
                    phrase = match.group("phrase") or match.group("phrase_first") or ""
                    phrase = " ".join(tokenize(phrase))
                    if abbreviation and phrase:
                        self.abbreviation_aliases.setdefault(abbreviation, set()).add(phrase)
                        self.abbreviation_aliases.setdefault(phrase, set()).add(abbreviation)

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
        self.unseen_idf = math.log((count + 1) / 0.5) + 1
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
            table["_alias_tokens"] = alias_tokens
            table["_tokens"].update(table["_context_tokens"])

    def kata_inti(self, pertanyaan: str) -> list[str]:
        text = _ABBREVIATION_PATTERN.sub(
            lambda match: match.group("abbr") or match.group("abbr_first") or "",
            str(pertanyaan or ""),
        )
        text = text.casefold().replace("-", " ").replace("/", " ")
        tokens = set(tokenize(text))
        if (
            tokens & {"laki", "pria", "lelaki", "cowok"}
            and tokens & {"perempuan", "wanita", "cewek"}
        ):
            gender_tokens = {
                "laki", "pria", "lelaki", "cowok", "perempuan", "wanita", "cewek",
            }
            other_terms = [
                token if token in sinonim.SYN or token in sinonim.RELATED else stem(token)
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
            normalized = (
                token if token in sinonim.SYN or token in sinonim.RELATED else stem(token)
            )
            if normalized and normalized not in core:
                core.append(normalized)
        return core

    def _term_weight(self, term: str) -> float:
        return max(
            (
                self.idf.get(stem(token), self.unseen_idf)
                for alternative in self._alternatives(term)
                for token in tokenize(alternative)
            ),
            default=self.unseen_idf,
        )

    def kata_topik(self, pertanyaan: str, core: list[str] | None = None) -> str | None:
        """Pick the main topic term: highest IDF among core terms, preferring non-verbal words."""
        core = core if core is not None else self.kata_inti(pertanyaan)
        if not core:
            return None
        verbal = {
            stem(token)
            for token in tokenize(str(pertanyaan or "").casefold())
            if re.match(r"(?:di|ter|ber|me|mem|men|meng|meny)[a-z]{4,}", token)
            and stem(token) != token
        }
        nominal = [term for term in core if term not in verbal] or core
        return max(nominal, key=lambda term: (self._term_weight(term), len(term)))

    def _alternatives(self, term: str) -> list[str]:
        return list(dict.fromkeys([
            *sinonim.SYN.get(term, (term,)),
            *sorted(self.abbreviation_aliases.get(term, ())),
        ]))

    @staticmethod
    def _token_match_quality(query_token: str, document_tokens: set[str]) -> float:
        query_stem = stem(query_token)
        best = 0.0
        for document_token in document_tokens:
            if query_token.casefold() == document_token.casefold():
                return 1.0
            document_stem = stem(document_token)
            if query_stem == document_stem:
                return 1.0
            if min(len(query_stem), len(document_stem)) >= 4:
                shorter, longer = sorted((query_stem, document_stem), key=len)
                if longer.startswith(shorter) and len(longer) - len(shorter) <= 2:
                    best = max(best, 0.65)
                if SequenceMatcher(None, query_stem, document_stem).ratio() >= 0.90:
                    best = max(best, 1.0)
        return best

    @staticmethod
    def _token_match(query_token: str, document_tokens: set[str]) -> bool:
        return Pencari._token_match_quality(query_token, document_tokens) > 0

    def _match_term(
        self,
        term: str,
        tokens: set[str],
        context_tokens: set[str] | None = None,
        alias_tokens: set[str] | None = None,
    ) -> tuple[float, str | None, bool]:
        alternatives = self._alternatives(term)
        for alternative in alternatives:
            alternative_tokens = tokenize(alternative)
            qualities = [
                self._token_match_quality(token, tokens)
                for token in alternative_tokens
            ]
            if alternative_tokens and all(qualities):
                idf = max(
                    (self.idf.get(token, 1.0) for token in alternative_tokens),
                    default=1.0,
                )
                quality = min(qualities)
                return idf * quality, alternative, quality < 1.0
            if alias_tokens:
                alias_qualities = [
                    self._token_match_quality(token, alias_tokens)
                    for token in alternative_tokens
                ]
                if alternative_tokens and all(alias_qualities):
                    idf = max(
                        (self.idf.get(token, 1.0) for token in alternative_tokens),
                        default=1.0,
                    )
                    return idf * min(alias_qualities), alternative, False
            if context_tokens:
                context_qualities = [
                    self._token_match_quality(token, context_tokens)
                    for token in alternative_tokens
                ]
                if alternative_tokens and all(context_qualities):
                    idf = max(
                        (self.idf.get(token, 1.0) for token in alternative_tokens),
                        default=1.0,
                    )
                    return idf * min(context_qualities) * 0.35, alternative, True

        for related in sinonim.RELATED.get(term, ()):
            related_tokens = tokenize(related)
            if related_tokens and all(
                self._token_match(token, tokens) for token in related_tokens
            ):
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
        question_without_expansion = _ABBREVIATION_PATTERN.sub(
            lambda match: match.group("abbr") or match.group("abbr_first") or "",
            str(pertanyaan or ""),
        )
        words = tokenize(question_without_expansion)
        generic = [
            stem(word) for word in words
            if word in sinonim.GENERIC and not word.isdigit()
        ]
        support = [
            stem(word) for word in words
            if word not in sinonim.STOP
            and word not in sinonim.GENERIC
            and stem(word) not in core
            and not (
                "jenis kelamin" in core
                and word in {
                    "laki", "pria", "lelaki", "cowok",
                    "perempuan", "wanita", "cewek",
                }
            )
            and not word.isdigit()
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
                weight, matched_as, related = self._match_term(
                    term,
                    table["_title_tokens"],
                    table["_context_tokens"],
                    table["_alias_tokens"],
                )
                if matched_as is not None:
                    matched_terms.append(term)
                    synonyms_used[term] = matched_as
                    weighted_match += weight * 2
                    if not related:
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
            if not matched_terms:
                rejected.append({
                    "table": table,
                    "reason": "tidak ada kata inti atau sinonim yang cocok",
                    "score": weighted_match,
                })
                continue

            generic_bonus = sum(
                self.idf.get(word, 1.0) * 0.1
                for word in generic
                if self._token_match(word, table["_tokens"])
            )
            support_bonus = sum(
                self.idf.get(word, 1.0) * 0.25
                for word in support
                if self._token_match(word, table["_tokens"])
            )
            requested_year = str(tahun_diminta or "").strip()
            year_bonus = 2.0 if requested_year and requested_year in table["tahun"] else 0.0
            audit_status = str(table.get("status_tarik") or "").casefold() or "belum_diaudit"
            audit_bonus = 8.0 if audit_status == "ok" else -8.0 if audit_status in {"gagal", "kosong"} else 0.0
            newest_year = max(
                (int(year) for year in table["tahun"] if year.isdigit()),
                default=0,
            )
            score = (
                weighted_match + generic_bonus + support_bonus + year_bonus
                + exact_match_bonus + audit_bonus
            )
            similarity = min(
                100,
                round(
                    coverage * 70
                    + (weighted_match / total_weight * 20 if total_weight else 0)
                    + (exact_match_count / len(core) * 10 if core else 0)
                    + min(support_bonus, 5)
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
                "status_tarik": audit_status,
                "tahun_terbaru": str(newest_year) if newest_year else None,
            })

        topic_word = self.kata_topik(pertanyaan, core)
        for item in matches:
            item["topik_cocok"] = topic_word is not None and topic_word in item["kata_inti_cocok"]

        matches.sort(
            key=lambda item: (
                item["topik_cocok"],
                len(item["kata_inti_cocok"]),
                {"ok": 2, "belum_diaudit": 1, "kosong": 0, "gagal": 0}.get(
                    item.get("status_tarik", ""), 1
                ),
                item["skor"],
                int(item.get("tahun_terbaru") or 0),
                -len(item["judul"]),
                item["judul"].casefold(),
            ),
            reverse=True,
        )

        suggestions = self._suggest(core, rejected, top)
        output = {
            "kata_inti": core,
            "kata_topik": topic_word,
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

    def _suggest(
        self,
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
                        all(
                            self._token_match(token, table["_tokens"])
                            for token in tokenize(alternative)
                        )
                        for alternative in self._alternatives(term)
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
