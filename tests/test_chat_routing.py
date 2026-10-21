import unittest
from unittest.mock import patch

import main


class ChatRoutingTests(unittest.TestCase):
    def test_greetings_do_not_route_to_data_tools(self):
        self.assertTrue(main.GREETING_ONLY.fullmatch("halo, terima kasih"))
        self.assertTrue(main.GREETING_ONLY.fullmatch("terima kasih"))
        self.assertFalse(main.GREETING_ONLY.fullmatch("halo, berapa IPM?"))

    def test_question_intent_classifies_data_concepts_services_and_greetings(self):
        cases = (
            ("berapa inflasi", "nilai"),
            ("IPM Prabumulih", "nilai"),
            ("penduduk PALI 2023-2026", "rentang_waktu"),
            ("BRS terbaru", "brs"),
            ("BRS inflasi 2024", "brs"),
            ("inflasi 2024", "nilai"),
            ("publikasi terbaru", "publikasi"),
            ("Kota Palembang dalam angka 2024", "publikasi"),
            ("laporan pertanian terbaru", "publikasi"),
            ("berita kegiatan BPS terbaru", "berita"),
            ("sensus penduduk terbaru", "berita"),
            ("berapa jumlah penduduk hasil sensus 2020", "nilai"),
            ("Daftar tabel SIMDASI", "nilai"),
            ("apa itu PDRB ADHB", "definisi"),
            ("Apa perbedaan PDRB ADHB dan ADHK?", "definisi"),
            ("Bagaimana membaca PDRB ADHB?", "definisi"),
            ("Berapa PDRB Kota Palembang?", "nilai"),
            ("Berapa PDRB Kota Palembang tahun 2025?", "nilai"),
            ("PDRB terbaru", "nilai"),
            ("BRS PDRB terbaru", "brs"),
            ("Berapa tingkat pengangguran Sumatera Selatan terbaru?", "nilai"),
            ("RLS Sumatera Selatan", "nilai"),
            ("perkawinan di Sumsel 2026?", "nilai"),
            ("harga mobil listrik di Lahat", "nilai"),
            ("Jelaskan IPM tahun 2025", "nilai"),
            ("Apa itu IPM di Palembang?", "nilai"),
            ("cara membaca inflasi", "definisi"),
            ("di mana mencari data", "panduan"),
            ("cara minta data", "panduan"),
            ("halo", "sapaan"),
        )
        for question, expected in cases:
            with self.subTest(question=question):
                self.assertEqual(main._classify_question_intent(question), expected)

    def test_multi_topic_parser_does_not_split_regions_or_gender_categories(self):
        with patch.object(main.bps_tools, "cari_variabel", return_value={"hasil": []}) as search:
            self.assertEqual(
                main._multi_topic_candidates("jumlah penduduk di Pali dan Lahat"),
                [],
            )
            self.assertEqual(
                main._multi_topic_candidates("jumlah penduduk laki-laki dan perempuan"),
                [],
            )
        search.assert_not_called()

    def test_multi_topic_parser_keeps_ambiguous_topics_and_alternatives(self):
        candidates = {
            "miskin": [
                {
                    "var_id": 683,
                    "judul": "Jumlah Penduduk Miskin",
                    "skor_kemiripan": 100,
                    "jenis_kecocokan": "direct",
                },
                {
                    "var_id": 604,
                    "judul": "Persentase Penduduk Miskin",
                    "skor_kemiripan": 100,
                    "jenis_kecocokan": "direct",
                },
            ],
            "kesehatan": [{
                "var_id": 847,
                "judul": "Jumlah Tenaga Kesehatan",
                "skor_kemiripan": 100,
                "jenis_kecocokan": "direct",
            }],
        }
        with (
            patch.object(main.bps_tools, "cari_variabel", return_value={"status_hasil": "ditemukan"}),
            patch.object(
                main,
                "_rank_variable_candidate_options",
                side_effect=lambda _result, keyword, _region, **_kwargs: candidates[keyword],
            ),
        ):
            result = main._multi_topic_candidates(
                "data kemiskinan dan kesehatan di Pali dan Lahat tahun 2021 sampai 2024"
            )

        self.assertEqual([item["keyword"] for item in result], ["miskin", "kesehatan"])
        self.assertIsNone(result[0]["candidate"])
        self.assertEqual(len(result[0]["suggestions"]), 2)
        self.assertEqual(result[1]["candidate"]["var_id"], 847)

    def test_close_catalog_scores_keep_alternative_indicator_candidates(self):
        options = main._rank_variable_candidate_options(
            {
                "hasil": [
                    {
                        "var_id": 10,
                        "judul": "PDRB Kabupaten/Kota Sumatera Selatan Harga Berlaku",
                        "skor_kemiripan": 94,
                        "tahun_terbaru": "2022",
                    },
                    {
                        "var_id": 11,
                        "judul": "PDRB Kabupaten/Kota Sumatera Selatan Harga Konstan",
                        "skor_kemiripan": 92,
                        "tahun_terbaru": "2025",
                    },
                ],
            },
            "PDRB kabupaten kota",
            "Provinsi Sumatera Selatan",
        )

        self.assertEqual([item["var_id"] for item in options], [10, 11])

    def test_latest_followup_fetches_all_indicators_from_prior_recommendations(self):
        recommendation = (
            "Berikut adalah data BPS yang sering digunakan untuk penelitian ekonomi:\n"
            "- PDRB atas Dasar Harga Berlaku (ADHB) dan atas Dasar Harga Konstan (ADHK), "
            "baik menurut lapangan usaha maupun pengeluaran.\n"
            "- Persentase dan jumlah penduduk miskin serta garis kemiskinan.\n"
            "- Indeks Pembangunan Manusia (IPM).\n"
            "- Tingkat Ketimpangan (Gini Ratio).\n"
            "- Tingkat Pengangguran Terbuka (TPT), jumlah angkatan kerja, dan tingkat "
            "partisipasi angkatan kerja (TPAK).\n"
            "- Jumlah penduduk, laju pertumbuhan penduduk, dan kepadatan penduduk."
        )
        dynamic_titles = {
            "PDRB tahunan atas dasar harga berlaku lapangan usaha": (
                "PDRB Tahunan Atas Dasar Harga Berlaku Menurut Lapangan Usaha"
            ),
            "PDRB atas dasar harga konstan lapangan usaha": (
                "PDRB Triwulanan Atas Dasar Harga Konstan Menurut Lapangan Usaha "
                "(Miliar Rupiah)"
            ),
            "PDRB tahunan atas dasar harga berlaku pengeluaran": (
                "PDRB Tahunan Atas Dasar Harga Berlaku Menurut Pengeluaran"
            ),
            "PDRB tahunan atas dasar harga konstan pengeluaran": (
                "PDRB Tahunan Atas Dasar Harga Konstan Menurut Pengeluaran"
            ),
            "garis kemiskinan": "Garis Kemiskinan",
            "jumlah angkatan kerja": "Jumlah Angkatan Kerja",
            "tingkat partisipasi angkatan kerja": "Tingkat Partisipasi Angkatan Kerja",
            "pertumbuhan penduduk": "Laju Pertumbuhan Penduduk",
        }

        def search_variable(keyword):
            return {
                "status_hasil": "ditemukan",
                "hasil": [{
                    "var_id": 9000 + list(dynamic_titles).index(keyword),
                    "judul": dynamic_titles[keyword],
                    "skor_kemiripan": 100,
                    "jenis_kecocokan": "direct",
                }],
            }

        fetched = []

        def fetch_data(candidate, requested_year, region, year_range):
            fetched.append((candidate["judul"], requested_year, region, year_range))
            return {
                "judul": candidate["judul"],
                "satuan": "indeks",
                "tahun": "2026",
                "status_hasil": "ditemukan",
                "data": [{"wilayah": region, "nilai": 1}],
            }

        history = [
            main.Turn(prompt="Data apa yang diperlukan untuk skripsi ekonomi Sumsel?", response=recommendation),
            main.Turn(
                prompt="minta seluruh data ini yang terbaru",
                response="Data yang cocok belum ditemukan.",
            ),
        ]
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main.bps_tools, "cari_variabel", side_effect=search_variable),
            patch.object(main, "_fetch_candidate_data", side_effect=fetch_data),
        ):
            result = main.chat_endpoint(main.ChatRequest(
                question="minta seluruh data ini yang terbaru",
                history=history,
            ))

        self.assertEqual(len(fetched), 17)
        self.assertTrue(all(not year for _, year, _, _ in fetched))
        for title in (
            "Atas Dasar Harga Berlaku",
            "Atas Dasar Harga Konstan",
            "Lapangan Usaha",
            "Pengeluaran",
            "Persentase Penduduk Miskin",
            "Jumlah Penduduk Miskin",
            "Garis Kemiskinan",
            "Indeks Pembangunan Manusia",
            "Rasio Gini",
            "Tingkat Pengangguran",
            "Jumlah Angkatan Kerja",
            "Tingkat Partisipasi Angkatan Kerja",
            "Jumlah Penduduk",
            "Kepadatan Penduduk",
        ):
            self.assertIn(title, result["reply"])
        self.assertIn("Laju Pertumbuhan Penduduk", result["reply"])
        self.assertNotIn("Data yang cocok belum ditemukan", result["reply"])

    def test_multi_topic_route_fetches_each_topic_for_each_region_and_year_range(self):
        topics = [
            {
                "keyword": "ipm",
                "candidate": {
                    "var_id": 959,
                    "judul": "Indeks Pembangunan Manusia",
                    "skor_kemiripan": 100,
                    "jenis_kecocokan": "direct",
                },
                "suggestions": [],
            },
            {
                "keyword": "penganggur",
                "candidate": {
                    "var_id": 334,
                    "judul": "Tingkat Pengangguran",
                    "skor_kemiripan": 100,
                    "jenis_kecocokan": "direct",
                },
                "suggestions": [],
            },
        ]
        regions = ["Kabupaten Lahat", "Kabupaten Penukal Abab Lematang Ilir"]

        def fetch(candidate, _requested_year, region, _year_range):
            value = candidate["var_id"] + (1 if region == regions[0] else 2)
            return {
                "judul": candidate["judul"],
                "satuan": "Indeks",
                "status_hasil": "ditemukan",
                "data_per_tahun": [
                    {
                        "tahun": year,
                        "data": [{"wilayah": region, "nilai": value}],
                    }
                    for year in ("2021", "2024")
                ],
            }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "_multi_topic_candidates", return_value=topics),
            patch.object(main.bps_tools, "resolve_regions_from_text", return_value=regions),
            patch.object(main, "_fetch_candidate_data", side_effect=fetch) as fetch_data,
        ):
            result = main.chat_endpoint(main.ChatRequest(
                question="IPM dan tingkat pengangguran di Lahat dan PALI tahun 2021-2024"
            ))

        self.assertEqual(fetch_data.call_count, 4)
        self.assertIn("Indeks Pembangunan Manusia", result["reply"])
        self.assertIn("Tingkat Pengangguran", result["reply"])
        self.assertIn("2021", result["reply"])
        self.assertIn("2024", result["reply"])
        self.assertIn("Kabupaten Lahat", result["reply"])
        self.assertIn("Kabupaten Penukal Abab Lematang Ilir", result["reply"])
        self.assertIn("Sumber: BPS Provinsi Sumatera Selatan", result["reply"])
        self.assertTrue(result["tools_used"])

    def test_numeric_reply_must_be_present_in_user_visible_tool_data(self):
        records = [{
            "result": {
                "status_hasil": "ditemukan",
                "var_id": 959,
                "tahun": "2025",
                "data": [{"wilayah": "Prabumulih", "nilai": 83.27}],
            }
        }]
        self.assertTrue(
            main._reply_numbers_are_verified(
                "IPM Prabumulih tahun 2025 sebesar 83,27.",
                records,
            )
        )
        self.assertFalse(
            main._reply_numbers_are_verified(
                "IPM Prabumulih tahun 2025 sebesar 81,22.",
                records,
            )
        )
        self.assertFalse(
            main._reply_numbers_are_verified("ID variabel 959.", records)
        )
        self.assertTrue(
            main._reply_numbers_are_verified(
                "Jumlah penduduk sekitar 9 juta jiwa.",
                [{"result": {"data": [{"nilai": 9_017_142}]}}],
            )
        )
        self.assertTrue(
            main._reply_numbers_are_verified(
                "Garis kemiskinan Rp12,5 ribu.",
                [],
                "Garis kemiskinan tercatat Rp12,5 ribu.",
            )
        )
        self.assertFalse(
            main._reply_numbers_are_verified(
                "Garis kemiskinan Rp18,5 ribu.",
                [],
                "Garis kemiskinan tercatat Rp12,5 ribu.",
            )
        )

    def test_verified_numeric_age_ranges_do_not_fail_numeric_reply_validation(self):
        records = [{
            "result": {
                "status_hasil": "ditemukan",
                "judul": "Jumlah Penduduk Menurut Kelompok Umur",
                "tahun": "2026",
                "data": [{
                    "wilayah": "45 - 49",
                    "kategori": "Laki-Laki + Perempuan",
                    "nilai": 612_721,
                }],
            }
        }]

        self.assertTrue(
            main._reply_numbers_are_verified(
                "Jumlah penduduk usia 45–49 tahun pada 2026 sebanyak 612.721 jiwa.",
                records,
            )
        )
        self.assertTrue(
            main._reply_numbers_are_verified(
                "Jumlah penduduk usia 45 sampai dengan 49 tahun sebanyak 612.721 jiwa.",
                records,
            )
        )
        self.assertFalse(
            main._reply_numbers_are_verified(
                "Jumlah penduduk usia 45–49 tahun sebanyak 610.000 jiwa.",
                records,
            )
        )
        self.assertFalse(
            main._reply_numbers_are_verified(
                "Ada 45 orang.",
                records,
            )
        )

    def test_age_group_query_accepts_verified_age_ranges_in_ai_answer(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[
                            main.types.Part(
                                text=(
                                    "Pada kelompok umur 45-49 tahun, jumlah penduduk "
                                    "Sumatera Selatan pada 2026 sebanyak 999.999 jiwa."
                                )
                            )
                        ],
                    )
                )
            ]
        )
        fake_models = type(
            "FakeModels",
            (),
            {"generate_content": lambda *_args, **_kwargs: response},
        )()
        fake_client = type("FakeClient", (), {"models": fake_models})()
        search_result = {
            "status_hasil": "ditemukan",
            "hasil": [{
                "var_id": 278,
                "judul": "Jumlah Penduduk Menurut Kelompok Umur",
                "satuan": "Jiwa",
                "tahun_terbaru": "2026",
                "skor_kemiripan": 95,
                "jenis_kecocokan": "direct",
            }],
        }
        verified_data = {
            "judul": "Jumlah Penduduk Menurut Kelompok Umur",
            "var_id": 278,
            "tahun": "2026",
            "satuan": "Jiwa",
            "data": [
                {"wilayah": "45 - 49", "kategori": "Laki-Laki", "nilai": 311_682},
                {"wilayah": "45 - 49", "kategori": "Perempuan", "nilai": 301_039},
                {
                    "wilayah": "45 - 49",
                    "kategori": "Laki-Laki + Perempuan",
                    "nilai": 612_721,
                },
                {"wilayah": "50 - 54", "kategori": "Laki-Laki", "nilai": 275_609},
                {"wilayah": "50 - 54", "kategori": "Perempuan", "nilai": 268_370},
                {
                    "wilayah": "50 - 54",
                    "kategori": "Laki-Laki + Perempuan",
                    "nilai": 543_979,
                },
            ],
        }
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(
                main,
                "_dynamic_variable_intent",
                return_value=("total penduduk umur", "", "Provinsi Sumatera Selatan"),
            ),
            patch.object(main.bps_tools, "cari_variabel", return_value=search_result),
            patch.object(main.bps_tools, "ambil_data", return_value=verified_data),
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="total warga sumsel per umur")
            )

        self.assertIn("45 - 49: 612.721", result["reply"])
        self.assertIn("50 - 54: 543.979", result["reply"])
        self.assertNotIn("311.682", result["reply"])
        self.assertNotIn("999.999", result["reply"])
        self.assertTrue(result["tools_used"])

    def test_age_group_fallback_sorts_ages_and_places_total_last(self):
        data = {
            "judul": "Jumlah Penduduk Menurut Kelompok Umur",
            "tahun": "2026",
            "satuan": "Jiwa",
            "data": [
                {"wilayah": "50 - 54", "kategori": "Laki-Laki + Perempuan", "nilai": 5},
                {"wilayah": "Jumlah", "kategori": "Laki-Laki + Perempuan", "nilai": 6},
                {"wilayah": "65+", "kategori": "Laki-Laki + Perempuan", "nilai": 4},
                {"wilayah": "0 - 4", "kategori": "Laki-Laki + Perempuan", "nilai": 1},
                {"wilayah": "45 - 49", "kategori": "Laki-Laki + Perempuan", "nilai": 3},
                {"wilayah": "5 - 9", "kategori": "Laki-Laki + Perempuan", "nilai": 2},
            ],
        }

        reply = main._format_indicator_fallback(
            data,
            "Provinsi Sumatera Selatan",
            "",
        )
        ordered_labels = [
            "0 - 4:",
            "5 - 9:",
            "45 - 49:",
            "50 - 54:",
            "65+:",
            "Jumlah:",
        ]

        self.assertEqual(
            [reply.index(label) for label in ordered_labels],
            sorted(reply.index(label) for label in ordered_labels),
        )

    def test_catalog_abbreviation_ranks_the_matching_variable(self):
        result = {
            "hasil": [
                {
                    "var_id": 892,
                    "judul": "Rata-rata Lama Sekolah (RLS) menurut jenis kelamin",
                    "alias": ["rls"],
                    "tahun_terbaru": "2025",
                },
                {
                    "var_id": 308,
                    "judul": "Rata-Rata Lama Sekolah",
                    "alias": ["rls"],
                    "tahun_terbaru": "2024",
                },
            ]
        }

        selected = main._rank_variable_candidates(result, "rls", "Provinsi Sumatera Selatan")
        selected_by_gender = main._rank_variable_candidates(
            result,
            "rls jenis kelamin",
            "Provinsi Sumatera Selatan",
        )

        self.assertEqual(selected["var_id"], 308)
        self.assertEqual(selected_by_gender["var_id"], 892)

    def test_unlisted_catalog_topic_routes_to_dynamic_api_lookup(self):
        question = "perkawinan di Sumsel 2026?"
        with patch.object(main.bps_tools, "catalog_variable_matches", return_value=True):
            self.assertEqual(main._classify_question_intent(question), "nilai")
            self.assertEqual(
                main._dynamic_variable_intent(question),
                ("perkawinan", "2026", "Provinsi Sumatera Selatan"),
            )

    def test_school_levels_are_parsed_as_categories_not_indicator_keywords(self):
        question = "jumlah guru SMP SD di Pali dan Lahat"

        self.assertEqual(
            main._dynamic_variable_intent(question)[0],
            "jumlah guru",
        )
        self.assertEqual(main._explicit_category_hint(question), "SD, SMP")
        variable_search = main.bps_tools.cari_variabel("jumlah guru")
        candidates = main._rank_variable_candidate_options(
            variable_search,
            "jumlah guru SD SMP",
            None,
        )
        self.assertEqual([candidate["var_id"] for candidate in candidates], [362])

    def test_regional_health_category_uses_best_matching_table_not_incidental_title_hit(self):
        question = "berapa jumlah puskesmas di Pali"
        intent = main._dynamic_variable_intent(question)
        self.assertIsNotNone(intent)
        if intent is None:
            self.fail("A health-facility question should create a catalog intent.")

        search_result = main.bps_tools.cari_variabel(intent[0])
        candidates = main._rank_variable_candidate_options(
            search_result,
            intent[0],
            intent[2],
            prefer_latest=True,
        )
        self.assertEqual([candidate["var_id"] for candidate in candidates], [779])
        self.assertEqual(main._explicit_category_hint(question), "Puskesmas")

    def test_chat_passes_health_category_through_csv_table_lookup(self):
        question = "berapa jumlah puskesmas di Pali"
        intent = main._dynamic_variable_intent(question)
        self.assertIsNotNone(intent)
        if intent is None:
            self.fail("A health-facility question should create a catalog intent.")
        candidate = {
            "var_id": 779,
            "judul": "Jumlah Fasilitas Kesehatan. (Unit)",
            "satuan": "Unit",
            "tahun_terbaru": "2025",
            "skor_kemiripan": 100,
            "jenis_kecocokan": "direct",
        }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(
                main.bps_tools,
                "cari_variabel",
                return_value={"status_hasil": "ditemukan", "hasil": [candidate]},
            ) as search,
            patch.object(
                main,
                "_fetch_candidate_data",
                return_value={
                    "judul": candidate["judul"],
                    "tahun": "2025",
                    "satuan": "Unit",
                    "sumber": "BPS Provinsi Sumatera Selatan",
                    "data": [{"wilayah": "Pali", "kategori": "Puskesmas", "nilai": 10}],
                },
            ) as fetch,
        ):
            result = main.chat_endpoint(main.ChatRequest(question=question))

        search.assert_called_once_with(intent[0])
        fetch.assert_called_once()
        self.assertEqual(fetch.call_args.args[0]["var_id"], 779)
        self.assertEqual(fetch.call_args.kwargs.get("kategori"), "Puskesmas")
        self.assertIn("(Puskesmas) tahun 2025: 10 Unit", result["reply"])
        self.assertIn("BPS Provinsi Sumatera Selatan", result["reply"])

    def test_chat_returns_requested_teacher_categories_for_regions_and_province(self):
        question = "jumlah guru SMP SD di Pali dan Lahat"
        candidate = {
            "var_id": 362,
            "judul": "Jumlah Guru (Jiwa)",
            "satuan": "Orang",
            "tahun_terbaru": "2025",
            "skor_kemiripan": 100,
            "jenis_kecocokan": "direct",
        }

        def fetch_data(_candidate, requested_year, region, year_range, kategori=None):
            self.assertEqual(kategori, "SD, SMP")
            self.assertFalse(requested_year)
            self.assertIsNone(year_range)
            return {
                "judul": "Jumlah Guru (Jiwa)",
                "satuan": "Orang",
                "tahun": "2025",
                "sumber": "BPS Provinsi Sumatera Selatan",
                "last_update_bps": "2026-04-02",
                "data": [
                    {"wilayah": region, "kategori": "SD", "nilai": 4024},
                    {"wilayah": region, "kategori": "SMP", "nilai": 1864},
                ],
            }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(
                main.bps_tools,
                "cari_variabel",
                return_value={"status_hasil": "ditemukan", "hasil": [candidate]},
            ) as search,
            patch.object(main, "_fetch_candidate_data", side_effect=fetch_data) as fetch,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(main.ChatRequest(question=question))

        search.assert_called_once_with("jumlah guru")
        self.assertEqual(fetch.call_count, 2)
        self.assertIn("Kabupaten Penukal Abab Lematang Ilir (SD): 4.024", result["reply"])
        self.assertIn("Kabupaten Penukal Abab Lematang Ilir (SMP): 1.864", result["reply"])
        self.assertIn("Kabupaten Lahat (SD): 4.024", result["reply"])
        self.assertIn("Kabupaten Lahat (SMP): 1.864", result["reply"])
        self.assertIn("Sumber: BPS Provinsi Sumatera Selatan.", result["reply"])
        self.assertIn("Pembaruan data: 2026-04-02.", result["reply"])

    def test_prison_term_alone_routes_to_the_related_catalog_candidate(self):
        question = "orang masuk penjara Sumsel"
        self.assertEqual(main._classify_question_intent(question), "nilai")
        self.assertEqual(
            main._dynamic_variable_intent(question),
            ("penjara", "", "Provinsi Sumatera Selatan"),
        )

    def test_related_prison_query_shows_verified_crime_data_with_caveat(self):
        request = main.ChatRequest(
            question="info total jumlah yang masuk penjara di Sumsel"
        )
        search_result = {
            "status_hasil": "kandidat_mirip",
            "hasil": [{
                "var_id": 246,
                "judul": "Jumlah Tindak Pidana",
                "satuan": "Kasus",
                "tahun_terbaru": "2025",
                "skor_kemiripan": 63,
                "jenis_kecocokan": "related",
            }],
        }
        data_result = {
            "judul": "Jumlah Tindak Pidana",
            "satuan": "Kasus",
            "tahun": "2025",
            "data": [{
                "wilayah": "Provinsi Sumatera Selatan",
                "nilai": 15383,
            }],
        }
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main.bps_tools, "cari_variabel", return_value=search_result),
            patch.object(main.bps_tools, "ambil_data", return_value=data_result),
            patch.object(main, "get_client") as gemini,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
            self.assertLogs(main.logger, level="INFO") as captured,
        ):
            result = main.chat_endpoint(request)

        gemini.assert_not_called()
        self.assertIn("Jumlah Tindak Pidana", result["reply"])
        self.assertIn('untuk "jumlah yang masuk penjara"', result["reply"])
        self.assertNotIn("63/100", result["reply"])
        self.assertNotIn("skor kemiripan", result["reply"].casefold())
        self.assertIn('"skor_kemiripan": 63', "\n".join(captured.output))
        self.assertIn("15.383 kasus", result["reply"].casefold())
        self.assertIn("bukan jumlah orang yang dipenjara", result["reply"])
        self.assertIn("Apakah data ini yang Anda cari?", result["reply"])

    def test_religion_query_shows_requested_category_without_similarity_score(self):
        request = main.ChatRequest(question="total agama islam di sumsel")
        search_result = {
            "status_hasil": "kandidat_mirip",
            "hasil": [
                {
                    "var_id": 241,
                    "judul": "Jumlah Penganut Agama",
                    "satuan": "Orang",
                    "tahun_terbaru": "2022",
                    "skor_kemiripan": 54,
                    "jenis_kecocokan": "direct",
                },
                {
                    "var_id": 637,
                    "judul": "Jumlah Penduduk Menurut Agama",
                    "satuan": "Jiwa",
                    "tahun_terbaru": "2022",
                    "skor_kemiripan": 54,
                    "jenis_kecocokan": "direct",
                },
            ],
        }
        data_result = {
            "judul": "Jumlah Penganut Agama",
            "satuan": "Orang",
            "tahun": "2022",
            "data": [
                {"wilayah": "Sumatera Selatan", "kategori": "Islam", "nilai": 8286975},
                {"wilayah": "Sumatera Selatan", "kategori": "Protestan", "nilai": 174145},
                {"wilayah": "Sumatera Selatan", "kategori": "Katolik", "nilai": 99830},
                {"wilayah": "Sumatera Selatan", "kategori": "Hindu", "nilai": 73148},
                {"wilayah": "Sumatera Selatan", "kategori": "Budha", "nilai": 159573},
            ],
        }
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main.bps_tools, "cari_variabel", return_value=search_result),
            patch.object(main.bps_tools, "ambil_data", return_value=data_result) as fetch,
            patch.object(main, "get_client") as gemini,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(request)

        gemini.assert_not_called()
        fetch.assert_called_once()
        self.assertEqual(fetch.call_args.args[0], 241)
        self.assertIn('maaf, saya belum menemukan indikator khusus untuk "total agama islam"', result["reply"].casefold())
        self.assertIn('ada data "Jumlah Penganut Agama" tahun 2022', result["reply"])
        self.assertIn("Khusus penganut Islam di Sumatera Selatan", result["reply"])
        self.assertIn("8.286.975 orang", result["reply"])
        self.assertNotIn("174.145", result["reply"])
        self.assertNotIn("kemiripan", result["reply"].casefold())
        self.assertIn("juga mencakup penganut agama lain", result["reply"])
        self.assertIn("Apakah data ini yang Anda cari?", result["reply"])

    def test_pns_ambiguity_fetches_and_shows_both_sumsel_breakdowns(self):
        request = main.ChatRequest(question="jumlah PNS Sumsel 2026")
        search_result = {
            "status_hasil": "ditemukan",
            "hasil": [
                {
                    "var_id": 227,
                    "judul": "Jumlah PNS Menurut Pendidikan Tertinggi",
                    "satuan": "Orang",
                    "tahun_terbaru": "2025",
                    "skor_kemiripan": 100,
                    "jenis_kecocokan": "direct",
                },
                {
                    "var_id": 229,
                    "judul": "Jumlah PNS Menurut Golongan Kepangkatan",
                    "satuan": "Orang",
                    "tahun_terbaru": "2025",
                    "skor_kemiripan": 100,
                    "jenis_kecocokan": "direct",
                },
            ],
        }

        def fetch(variable_id, tahun=None, wilayah=None):
            if wilayah:
                return {
                    "judul": (
                        "Jumlah PNS Menurut Pendidikan Tertinggi"
                        if variable_id == 227
                        else "Jumlah PNS Menurut Golongan Kepangkatan"
                    ),
                    "error": "Data untuk Provinsi Sumatera Selatan tidak tersedia pada variabel ini.",
                    "wilayah_tersedia": (
                        ["Sampai Dengan SD", "SLTP/Sederajat"]
                        if variable_id == 227
                        else ["Golongan I", "Golongan II"]
                    ),
                    "tahun_tersedia": ["2025"],
                    "status_hasil": "kosong",
                }
            return {
                "judul": (
                    "Jumlah PNS Menurut Pendidikan Tertinggi"
                    if variable_id == 227
                    else "Jumlah PNS Menurut Golongan Kepangkatan"
                ),
                "satuan": "Orang",
                "tahun": "2025",
                "data": [{
                    "wilayah": (
                        "Sampai Dengan SD" if variable_id == 227 else "Golongan II"
                    ),
                    "kategori": "Laki-Laki + Perempuan",
                    "nilai": 146 if variable_id == 227 else 12268,
                }],
            }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main.bps_tools, "cari_variabel", return_value=search_result),
            patch.object(main.bps_tools, "ambil_data", side_effect=fetch),
            patch.object(main, "get_client") as gemini,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(request)

        gemini.assert_not_called()
        self.assertIn("Jumlah PNS Menurut Pendidikan Tertinggi", result["reply"])
        self.assertIn("Jumlah PNS Menurut Golongan Kepangkatan", result["reply"])
        self.assertIn("146 orang", result["reply"])
        self.assertIn("12.268 orang", result["reply"])
        self.assertIn("Data 2026 belum tersedia", result["reply"])
        self.assertNotIn("kemiripan", result["reply"].casefold())
        self.assertIn("pendidikan tertinggi", result["reply"])
        self.assertIn("golongan kepangkatan", result["reply"])

    def test_gender_synonyms_select_population_by_sex_variable(self):
        question = "jumlah penduduk pria dan wanita di Sumsel terbaru"
        catalog = main.bps_tools._load_catalog_index()
        self.assertIsNone(main._canonical_indicator_intent(question))
        with (
            patch.object(main.bps_tools, "_variable_index", return_value=catalog),
            patch.object(
                main.bps_tools,
                "_latest_year",
                side_effect=main.bps_tools.BpsError("Use catalog year metadata."),
            ),
        ):
            intent = main._dynamic_variable_intent(question)
            self.assertIsNotNone(intent)
            if intent is None:
                self.fail("Gender synonyms should create a dynamic variable intent.")
            search_result = main.bps_tools.cari_variabel(intent[0])
            options = main._rank_variable_candidate_options(
                search_result,
                intent[0],
                intent[2],
                prefer_latest=True,
            )

        self.assertEqual([item["var_id"] for item in options], [642])

    def test_exact_marital_status_candidates_outrank_related_kawin_indicators(self):
        result = {
            "hasil": [
                {
                    "var_id": 238,
                    "judul": "Persentase Perempuan Pernah Kawin Berumur 15-49 Tahun",
                    "tahun_terbaru": "2025",
                },
                {
                    "var_id": 796,
                    "judul": "Penduduk Perempuan Berumur 10 Tahun Ke Atas Menurut Status Perkawinan",
                    "tahun_terbaru": "2025",
                },
                {
                    "var_id": 794,
                    "judul": "Penduduk Laki-Laki Berumur 10 Tahun Ke Atas Menurut Status Perkawinan",
                    "tahun_terbaru": "2025",
                },
            ]
        }

        options = main._rank_variable_candidate_options(result, "perkawinan", "Provinsi Sumatera Selatan")

        self.assertEqual({item["var_id"] for item in options}, {794, 796})

    def test_unqualified_variable_search_prefers_the_unique_most_recent_candidate(self):
        result = {
            "hasil": [
                {
                    "var_id": 375,
                    "judul": "Jumlah Kasus Penderita Penyakit",
                    "tahun_terbaru": "2025",
                },
                {
                    "var_id": 781,
                    "judul": "Jumlah Kasus Penyakit",
                    "tahun_terbaru": "2020",
                },
            ]
        }

        options = main._rank_variable_candidate_options(
            result,
            "kasus penyakit",
            "Provinsi Sumatera Selatan",
            prefer_latest=True,
        )

        self.assertEqual([item["var_id"] for item in options], [375])

    def test_unqualified_disease_question_sends_latest_verified_candidate_to_ai(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Pada 2025 tercatat 42 kasus penderita penyakit.")],
                    )
                )
            ]
        )
        fake_models = type(
            "FakeModels",
            (),
            {"generate_content": lambda *_args, **_kwargs: response},
        )()
        fake_client = type("FakeClient", (), {"models": fake_models})()
        search_result = {
            "status_hasil": "ditemukan",
            "hasil": [
                {
                    "var_id": 375,
                    "judul": "Jumlah Kasus Penderita Penyakit",
                    "tahun_terbaru": "2025",
                },
                {
                    "var_id": 781,
                    "judul": "Jumlah Kasus Penyakit",
                    "tahun_terbaru": "2020",
                },
            ],
        }
        verified_data = {
            "judul": "Jumlah Kasus Penderita Penyakit",
            "var_id": 375,
            "tahun": "2025",
            "satuan": "Kasus",
            "data": [{"wilayah": "Sumatera Selatan", "nilai": 42}],
        }
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(
                main,
                "_dynamic_variable_intent",
                return_value=("kasus penyakit", "", "Provinsi Sumatera Selatan"),
            ),
            patch.object(main.bps_tools, "cari_variabel", return_value=search_result),
            patch.object(main.bps_tools, "ambil_data", return_value=verified_data) as fetch_data,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="jumlah kasus penyakit di Sumsel")
            )

        fetch_data.assert_called_once_with(
            375,
            tahun=None,
            wilayah="Provinsi Sumatera Selatan",
        )
        self.assertEqual(result["reply"], "Pada 2025 tercatat 42 kasus penderita penyakit.")
        self.assertEqual(result["tools_used"], True)

    def test_ntp_sumsel_latest_uses_the_current_variable_instead_of_static_tables(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[
                            main.types.Part(
                                text="NTP Sumatera Selatan pada Agustus 2026 sebesar 150,53 persen."
                            )
                        ],
                    )
                )
            ]
        )
        fake_models = type(
            "FakeModels",
            (),
            {"generate_content": lambda *_args, **_kwargs: response},
        )()
        fake_client = type("FakeClient", (), {"models": fake_models})()
        search_result = {
            "status_hasil": "ditemukan",
            "hasil": [{
                "var_id": 645,
                "judul": "Perkembangan Nilai Tukar Petani",
                "satuan": "Persen",
                "tahun_terbaru": "2026",
                "skor_kemiripan": 100,
                "jenis_kecocokan": "direct",
            }],
        }
        data_result = {
            "judul": "Perkembangan Nilai Tukar Petani",
            "satuan": "Persen",
            "tahun": "2026",
            "data": [
                {"wilayah": "NTP", "periode": "Agustus", "nilai": 150.53},
                {"wilayah": "NTUP", "periode": "Agustus", "nilai": 149.92},
            ],
        }
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "cari_variabel", return_value=search_result) as search,
            patch.object(main.bps_tools, "ambil_data", return_value=data_result) as fetch,
            patch.object(main.bps_tools, "tabel_statis") as static_table,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="ntp sumsel terbaru")
            )

        search.assert_called_once_with("tukar petani")
        fetch.assert_called_once_with(
            645,
            tahun=None,
            wilayah="Provinsi Sumatera Selatan",
        )
        static_table.assert_not_called()
        self.assertIn("150,53 persen", result["reply"])
        self.assertTrue(result["tools_used"])

    def test_ambiguous_gender_series_return_latest_values_with_requested_year_notice(self):
        candidates = [
            {
                "var_id": 794,
                "judul": "Penduduk Laki-Laki Menurut Status Perkawinan",
            },
            {
                "var_id": 796,
                "judul": "Penduduk Perempuan Menurut Status Perkawinan",
            },
        ]

        def fake_data(var_id, tahun=None, wilayah=None, **kwargs):
            if tahun == "2026":
                return {"tahun_tersedia": ["2025"], "status_hasil": "kosong"}
            gender = "Laki-laki" if var_id == 794 else "Perempuan"
            return {
                "judul": candidates[0 if var_id == 794 else 1]["judul"],
                "tahun": "2025",
                "satuan": "Orang",
                "data": [{"wilayah": "Sumatera Selatan", "kategori": "Belum Kawin", "nilai": var_id}],
            }

        with patch.object(main.bps_tools, "ambil_data", side_effect=fake_data):
            combined = main._combine_gender_series(
                candidates,
                "2026",
                "Provinsi Sumatera Selatan",
                None,
            )

        self.assertEqual(combined["tahun_diminta_tidak_tersedia"], "2026")
        self.assertEqual(len(combined["data_per_tahun"][0]["data"]), 2)
        self.assertIn(
            "Data tahun 2026 belum tersedia",
            main._format_indicator_fallback(combined, "Provinsi Sumatera Selatan", "2026"),
        )
        self.assertTrue(
            main._reply_numbers_are_verified(
                "Nilai Laki-laki 794 dan Perempuan 796.",
                [{"result": combined}],
            )
        )

    def test_concept_answer_can_include_numbers_without_tools_or_numeric_guard(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(
                            text=(
                                "Month-to-month membandingkan bulan ini dengan bulan sebelumnya. "
                                "Year-on-year membandingkan dengan bulan yang sama tahun lalu. "
                                "Contoh ilustrasi, perubahan dari 100 ke 102 berarti 2%."
                            )
                        )],
                    )
                )
            ]
        )

        class FakeModels:
            def __init__(self):
                self.calls = []

            def generate_content(self, **kwargs):
                self.calls.append(kwargs)
                return response

        fake_models = FakeModels()
        fake_client = type("FakeClient", (), {"models": fake_models})()
        request = main.ChatRequest(
            question="Bagaimana cara membaca inflasi month-to-month dan year-on-year?"
        )
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(request)

        self.assertIn("Month-to-month", result["reply"])
        self.assertIn("Year-on-year", result["reply"])
        self.assertIn("Contoh ilustrasi", result["reply"])
        self.assertFalse(result["tools_used"])
        self.assertIsNone(fake_models.calls[0]["config"].tools)

    def test_concept_markers_are_removed_from_search_keywords(self):
        self.assertEqual(
            main.bps_tools._clean_search_keyword("Apa perbedaan PDRB ADHB dan ADHK?"),
            "pdrb adhb adhk",
        )

    def test_pdrb_difference_uses_glossary_without_calling_tools(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(
                            text="ADHB memakai harga berlaku, sedangkan ADHK memakai harga konstan."
                        )],
                    )
                )
            ]
        )

        class FakeModels:
            def __init__(self):
                self.calls = []

            def generate_content(self, **kwargs):
                self.calls.append(kwargs)
                return response

        fake_models = FakeModels()
        fake_client = type("FakeClient", (), {"models": fake_models})()
        reference = "Glosarium BPS: ADHB memakai harga pada periode berjalan; ADHK memakai harga konstan."
        request = main.ChatRequest(
            question="Apa perbedaan PDRB ADHB dan ADHK?",
            context=reference,
        )
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(request)

        prompt_text = "\n".join(
            part.text
            for content in fake_models.calls[0]["contents"]
            for part in (content.parts or [])
            if part.text
        )
        self.assertEqual(result["reply"], "ADHB memakai harga berlaku, sedangkan ADHK memakai harga konstan.")
        self.assertFalse(result["tools_used"])
        self.assertIsNone(fake_models.calls[0]["config"].tools)
        self.assertIn("KLASIFIKASI MAKSUD: definisi", prompt_text)
        self.assertIn(reference, prompt_text)

    def test_value_intent_retries_once_with_forced_tool(self):
        no_tool_response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Inflasi 2,5%.")],
                    )
                )
            ]
        )
        grounded_response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Inflasi tercatat 2,5%.")],
                    )
                )
            ]
        )
        records = [{
            "name": "berita_resmi_statistik",
            "arguments": {"kata_kunci": "inflasi"},
            "result": {
                "status_hasil": "ditemukan",
                "hasil": [{"judul": "Inflasi Sumatera Selatan 2,5 persen"}],
            },
        }]
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "_dynamic_variable_intent", return_value=None),
            patch.object(
                main,
                "_generate_with_manual_tools",
                side_effect=[(no_tool_response, []), (grounded_response, records)],
            ) as generate,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(main.ChatRequest(question="berapa inflasi"))

        self.assertEqual(result["reply"], "Inflasi tercatat 2,5%.")
        self.assertEqual(generate.call_count, 2)
        self.assertNotIn("force_tool", generate.call_args_list[0].kwargs)
        self.assertTrue(generate.call_args_list[1].kwargs["force_tool"])

    def test_forced_tool_generation_sets_function_calling_mode_any(self):
        function_call_response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[
                            main.types.Part(
                                function_call=main.types.FunctionCall(
                                    name="lookup",
                                    args={"keyword": "inflasi"},
                                )
                            )
                        ],
                    )
                )
            ]
        )
        summary_response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Inflasi tersedia.")],
                    )
                )
            ]
        )

        class FakeModels:
            def __init__(self):
                self.calls = []

            def generate_content(self, **kwargs):
                self.calls.append(kwargs)
                return function_call_response if len(self.calls) == 1 else summary_response

        fake_models = FakeModels()
        fake_client = type("FakeClient", (), {"models": fake_models})()
        def lookup(keyword):
            return {"hasil": [{"kata_kunci": keyword}]}

        with (
            patch.object(main.bps_tools, "TOOLS", [lookup]),
            patch.object(main, "get_client", return_value=fake_client),
        ):
            response, records = main._generate_with_manual_tools([], 1, force_tool=True)

        self.assertEqual(response.text, "Inflasi tersedia.")
        self.assertEqual(len(records), 1)
        config = fake_models.calls[0]["config"]
        self.assertEqual(config.tool_config.function_calling_config.mode, "ANY")

    def test_required_tool_still_missing_after_forced_retry_returns_502(self):
        no_tool_response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Inflasi 2,5%.")],
                    )
                )
            ]
        )
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "_dynamic_variable_intent", return_value=None),
            patch.object(
                main,
                "_generate_with_manual_tools",
                return_value=(no_tool_response, []),
            ) as generate,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            with self.assertRaises(main.HTTPException) as raised:
                main.chat_endpoint(main.ChatRequest(question="berapa inflasi"))

        self.assertEqual(raised.exception.status_code, 502)
        self.assertEqual(generate.call_count, 2)
        self.assertTrue(generate.call_args_list[1].kwargs["force_tool"])

    def test_data_answer_with_unsupported_number_uses_verified_fallback(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="IPM Prabumulih tahun 2025 sebesar 81,22.")],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {"models": type(
                "FakeModels",
                (),
                {"generate_content": lambda *_args, **_kwargs: response},
            )()},
        )()
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(
                main.bps_tools,
                "indikator_utama",
                return_value={
                    "judul": "IPM",
                    "tahun": "2025",
                    "data": [{"wilayah": "Prabumulih", "nilai": 83.27}],
                },
            ),
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(main.ChatRequest(question="IPM Prabumulih"))

        self.assertIn("83,27", result["reply"])
        self.assertNotIn("81,22", result["reply"])
        self.assertTrue(result["tools_used"])

    def test_regional_tpt_year_query_uses_simdasi_before_provincial_release(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="TPT Lahat tahun 2025 sebesar 99,99 persen.")],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {"models": type(
                "FakeModels",
                (),
                {"generate_content": lambda *_args, **_kwargs: response},
            )()},
        )()
        simdasi_data = {
            "judul": "TPT Kabupaten/Kota Sumatera Selatan",
            "tahun": 2025,
            "wilayah_ditafsirkan": "Kabupaten Lahat",
            "data": [{
                "wilayah": "Lahat",
                "variabel": "Tingkat Pengangguran Terbuka (TPT) - Agustus",
                "nilai": "2,84",
            }],
            "status_hasil": "ditemukan",
        }
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "simdasi_cari_data", return_value=simdasi_data) as simdasi,
            patch.object(main.bps_tools, "berita_resmi_statistik") as press_release,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="berapa TPT di Lahat tahun 2025?")
            )

        simdasi.assert_called_once_with(
            kata_kunci="TPT",
            tahun="2025",
            wilayah="Kabupaten Lahat",
        )
        press_release.assert_not_called()
        self.assertIn("2,84", result["reply"])
        self.assertNotIn("99,99", result["reply"])

    def test_regional_tpt_latest_query_falls_back_to_simdasi(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="TPT Lahat sebesar 99,99 persen.")],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {"models": type(
                "FakeModels",
                (),
                {"generate_content": lambda *_args, **_kwargs: response},
            )()},
        )()
        simdasi_data = {
            "judul": "TPT Kabupaten/Kota Sumatera Selatan",
            "tahun": 2025,
            "wilayah_ditafsirkan": "Kabupaten Lahat",
            "data": [{
                "wilayah": "Lahat",
                "variabel": "Tingkat Pengangguran Terbuka (TPT) - Agustus",
                "nilai": "2,84",
            }],
            "status_hasil": "ditemukan",
        }
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(
                main.bps_tools,
                "indikator_utama",
                return_value={"error": "Variabel ini hanya tersedia untuk provinsi."},
            ),
            patch.object(main.bps_tools, "simdasi_cari_data", return_value=simdasi_data) as simdasi,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(main.ChatRequest(question="berapa TPT di Lahat?"))

        simdasi.assert_called_once_with(
            kata_kunci="TPT",
            tahun=None,
            wilayah="Kabupaten Lahat",
        )
        self.assertIn("2,84", result["reply"])
        self.assertNotIn("99,99", result["reply"])

    def test_relevant_knowledge_context_preempts_webapi_for_non_latest_request(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Dalam dokumen, garis kemiskinan tercatat Rp12,5 ribu.")],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {"models": type(
                "FakeModels",
                (),
                {"generate_content": lambda *_args, **_kwargs: response},
            )()},
        )()
        context = "[Sumber: Pedoman Kemiskinan 2024] Garis kemiskinan tercatat Rp12,5 ribu."
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "indikator_utama") as canonical,
            patch.object(main.bps_tools, "cari_variabel") as search,
            patch.object(main.bps_tools, "tabel_statis") as static_tables,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="berapa garis kemiskinan?", context=context)
            )

        canonical.assert_not_called()
        search.assert_not_called()
        static_tables.assert_not_called()
        self.assertIn("12,5", result["reply"])

    def test_latest_request_uses_webapi_even_when_knowledge_has_old_data(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="IPM Sumatera Selatan tahun 2025 sebesar 75,5.")],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {"models": type(
                "FakeModels",
                (),
                {"generate_content": lambda *_args, **_kwargs: response},
            )()},
        )()
        context = "[Sumber: Ringkasan IPM] IPM Sumatera Selatan tahun 2022 sebesar 70,0."
        api_data = {
            "judul": "Indeks Pembangunan Manusia",
            "tahun": "2025",
            "data": [{"wilayah": "Sumatera Selatan", "nilai": 75.5}],
            "status_hasil": "ditemukan",
        }
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "indikator_utama", return_value=api_data) as canonical,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="berapa IPM terbaru?", context=context)
            )

        canonical.assert_called_once()
        self.assertIn("75,5", result["reply"])
        self.assertNotIn("70,0", result["reply"])

    def test_requested_year_missing_from_knowledge_routes_to_webapi(self):
        self.assertFalse(
            main._prefer_knowledge_context(
                "berapa IPM tahun 2025?",
                "[Sumber: Ringkasan IPM] IPM tahun 2022 sebesar 70,0.",
            )
        )
        self.assertTrue(
            main._prefer_knowledge_context(
                "berapa IPM tahun 2022?",
                "[Sumber: Ringkasan IPM] IPM tahun 2022 sebesar 70,0.",
            )
        )

    def test_canonical_indicator_uses_region_and_requested_level(self):
        self.assertEqual(
            main._canonical_indicator_intent("jumlah penduduk"),
            ("jumlah_penduduk", "", "Provinsi Sumatera Selatan"),
        )
        self.assertEqual(
            main._canonical_indicator_intent("jumlah penduduk miskin Palembang"),
            ("kemiskinan_jumlah_kab_kota", "", "Kota Palembang"),
        )
        self.assertEqual(
            main._canonical_indicator_intent("jumlah penduduk kabupaten/kota"),
            ("jumlah_penduduk", "", None),
        )
        self.assertEqual(
            main._canonical_indicator_intent("proyeksi penduduk tahun 2030"),
            ("proyeksi_penduduk", "2030", "Provinsi Sumatera Selatan"),
        )
        self.assertEqual(
            main._canonical_indicator_intent("Berapa PDRB Kota Palembang?"),
            ("pdrb_adhb_kab_kota", "", "Kota Palembang"),
        )
        self.assertEqual(
            main._canonical_indicator_intent("PDRB ADHK Kota Palembang tahun 2025"),
            ("pdrb_adhk_kab_kota", "2025", "Kota Palembang"),
        )
        self.assertEqual(
            main._canonical_indicator_intent("pertumbuhan PDRB Kota Palembang"),
            ("pdrb_pertumbuhan_kab_kota", "", "Kota Palembang"),
        )
        self.assertEqual(
            main._canonical_indicator_intent(
                "Berapa tingkat pengangguran Sumatera Selatan terbaru?"
            ),
            ("tingkat_pengangguran", "", "Provinsi Sumatera Selatan"),
        )

    def test_province_without_aggregate_is_distinguished_from_subregional_data(self):
        province = "Provinsi Sumatera Selatan"
        self.assertTrue(
            main._has_subregional_values_for_province(
                {
                    "error": "Data provinsi tidak tersedia.",
                    "wilayah_tersedia": ["Ogan Ilir", "Palembang"],
                },
                province,
            )
        )
        self.assertFalse(
            main._has_subregional_values_for_province(
                {
                    "error": "Data provinsi tidak tersedia.",
                    "wilayah_tersedia": ["Laki-Laki", "Perempuan"],
                },
                province,
            )
        )

    def test_candidate_fallback_fetches_and_displays_every_subregion(self):
        province = "Provinsi Sumatera Selatan"
        province_error = {
            "judul": "Jumlah Indikator Menurut Kabupaten/Kota",
            "error": f"Data untuk {province} tidak tersedia.",
            "wilayah_tersedia": ["Ogan Ilir", "Palembang"],
            "status_hasil": "kosong",
        }
        subregional_data = {
            "judul": "Jumlah Indikator Menurut Kabupaten/Kota",
            "tahun": "2025",
            "satuan": "Orang",
            "data": [
                {"wilayah": "Ogan Ilir", "nilai": 123},
                {"wilayah": "Palembang", "nilai": 456},
            ],
        }
        candidate = {
            "var_id": 999,
            "judul": "Jumlah Indikator Menurut Kabupaten/Kota",
            "skor_kemiripan": 100,
            "jenis_kecocokan": "direct",
        }

        with patch.object(
            main.bps_tools,
            "ambil_data",
            side_effect=[province_error, subregional_data],
        ) as fetch:
            result = main._fetch_candidate_data(candidate, "", province, None)

        self.assertEqual(fetch.call_count, 2)
        self.assertIsNone(fetch.call_args_list[1].kwargs.get("wilayah"))
        self.assertEqual(result["wilayah_diminta_tidak_tersedia"], province)
        reply = main._format_candidate_suggestions(
            "indikator Sumsel",
            [candidate],
            [result],
            province,
            "",
        )
        self.assertIn("data agregat Provinsi Sumatera Selatan tidak tersedia", reply)
        self.assertIn("Ogan Ilir: 123 orang", reply)
        self.assertIn("Palembang: 456 orang", reply)

    def test_candidate_subregional_fallback_keeps_available_year_range(self):
        province = "Provinsi Sumatera Selatan"
        empty_range = {
            "judul": "Indikator Menurut Kabupaten/Kota",
            "data_per_tahun": [],
            "status_hasil": "kosong",
        }
        province_error = {
            "judul": "Indikator Menurut Kabupaten/Kota",
            "error": f"Data untuk {province} tidak tersedia.",
            "wilayah_tersedia": ["Ogan Ilir", "Palembang"],
        }
        range_data = {
            "judul": "Indikator Menurut Kabupaten/Kota",
            "data_per_tahun": [{
                "tahun": "2025",
                "satuan": "Orang",
                "data": [
                    {"wilayah": "Ogan Ilir", "nilai": 123},
                    {"wilayah": "Palembang", "nilai": 456},
                ],
            }],
        }
        with patch.object(
            main.bps_tools,
            "ambil_data",
            side_effect=[empty_range, province_error, range_data],
        ) as fetch:
            result = main._fetch_candidate_data(
                {"var_id": 999, "judul": "Indikator Menurut Kabupaten/Kota"},
                "",
                province,
                (2024, 2025),
            )

        self.assertEqual(fetch.call_count, 3)
        self.assertEqual(result["wilayah_diminta_tidak_tersedia"], province)
        self.assertIn("2025, Ogan Ilir: 123 Orang", main._format_indicator_fallback(
            result,
            province,
            "2024-2025",
        ))

    def test_subregional_fallback_preserves_every_available_category(self):
        reply = main._format_indicator_fallback(
            {
                "judul": "Jumlah Penduduk Menurut Kabupaten/Kota dan Jenis Kelamin",
                "tahun": "2025",
                "satuan": "Jiwa",
                "wilayah_diminta_tidak_tersedia": "Provinsi Sumatera Selatan",
                "data": [
                    {"wilayah": "Ogan Ilir", "kategori": "Laki-Laki", "nilai": 10},
                    {"wilayah": "Ogan Ilir", "kategori": "Perempuan", "nilai": 11},
                    {"wilayah": "Palembang", "kategori": "Laki-Laki", "nilai": 20},
                    {"wilayah": "Palembang", "kategori": "Perempuan", "nilai": 21},
                ],
            },
            "Provinsi Sumatera Selatan",
            "",
        )

        self.assertIn("Ogan Ilir (Laki-Laki): 10", reply)
        self.assertIn("Ogan Ilir (Perempuan): 11", reply)
        self.assertIn("Palembang (Laki-Laki): 20", reply)
        self.assertIn("Palembang (Perempuan): 21", reply)

    def test_canonical_province_fallback_retries_with_all_subregions(self):
        province = "Provinsi Sumatera Selatan"
        province_error = {
            "judul": "Laju Pertumbuhan PDRB Kabupaten/Kota",
            "error": f"Data untuk {province} tidak tersedia.",
            "wilayah_ditafsirkan": province,
            "wilayah_tersedia": ["Ogan Ilir", "Palembang"],
            "status_hasil": "kosong",
        }
        subregional_data = {
            "judul": "Laju Pertumbuhan PDRB Kabupaten/Kota",
            "tahun": "2025",
            "satuan": "Persen",
            "data": [
                {"wilayah": "Ogan Ilir", "nilai": 4.97},
                {"wilayah": "Palembang", "nilai": 5.6},
            ],
        }
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[
                            main.types.Part(
                                text=(
                                    "Maaf, data agregat Provinsi Sumatera Selatan tidak tersedia. "
                                    "Namun, pertumbuhan PDRB tahun 2025 tercatat 4,97 persen di Ogan Ilir "
                                    "dan 5,6 persen di Palembang."
                                )
                            )
                        ],
                    )
                )
            ]
        )
        class FakeModels:
            calls = []

            def generate_content(self, **kwargs):
                self.calls.append(kwargs)
                return response

        fake_client = type(
            "FakeClient",
            (),
            {"models": FakeModels()},
        )()

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(
                main.bps_tools,
                "indikator_utama",
                side_effect=[province_error, subregional_data],
            ) as indicator,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="Pertumbuhan PDRB Sumsel terbaru")
            )

        self.assertEqual(indicator.call_count, 2)
        self.assertEqual(indicator.call_args_list[0].kwargs["wilayah"], province)
        self.assertIsNone(indicator.call_args_list[1].kwargs["wilayah"])
        prompt = fake_client.models.calls[0]["contents"][-1].parts[0].text
        self.assertIn("wilayah_diminta_tidak_tersedia", prompt)
        self.assertIn("jangan menjumlahkan nilai kabupaten/kota", prompt)
        self.assertIn("Ogan Ilir", result["reply"])
        self.assertIn("Palembang", result["reply"])

    def test_previous_year_followup_keeps_the_prior_canonical_series(self):
        history = [
            main.Turn(
                prompt="Jumlah penduduk Sumatera Selatan tahun 2025",
                response="Jumlah Penduduk di Sumatera Selatan tahun 2025: 8.9 juta jiwa. Angka ini merupakan estimasi.",
            )
        ]
        self.assertEqual(
            main._followup_data_intent("bagaimana tahun lalu?", history),
            ("canonical", ("jumlah_penduduk", "2024", "Provinsi Sumatera Selatan")),
        )

        projection_history = [
            main.Turn(
                prompt="Proyeksi jumlah penduduk tahun 2026",
                response="Proyeksi Jumlah Penduduk di Sumatera Selatan tahun 2026: 9 juta jiwa.",
            )
        ]
        self.assertEqual(
            main._followup_data_intent("bagaimana tahun lalu?", projection_history),
            ("canonical", ("proyeksi_penduduk", "2025", "Provinsi Sumatera Selatan")),
        )

    def test_previous_year_followup_reuses_dynamic_topic(self):
        history = [
            main.Turn(
                prompt="Berapa produksi padi tahun 2025?",
                response="Produksi padi tahun 2025 tercatat menurut seri tahunan.",
            )
        ]
        self.assertEqual(
            main._followup_data_intent("dan tahun lalu?", history),
            ("dynamic", ("produksi padi", "2024", "Provinsi Sumatera Selatan")),
        )

    def test_previous_year_followup_fetches_the_same_canonical_indicator(self):
        history = [
            main.Turn(
                prompt="Jumlah penduduk Sumatera Selatan tahun 2025",
                response="Jumlah Penduduk di Sumatera Selatan tahun 2025: 8.9 juta jiwa.",
            )
        ]
        request = main.ChatRequest(question="bagaimana tahun lalu?", history=history)
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Jumlah penduduk tahun 2024.")],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {"models": type("FakeModels", (), {"generate_content": lambda *_args, **_kwargs: response})()},
        )()
        data = {
            "judul": "Jumlah Penduduk Menurut Kabupaten/Kota",
            "tahun": "2024",
            "satuan": "Jiwa",
            "sifat_data": "estimasi",
            "data": [{"wilayah": "Sumatera Selatan", "nilai": 8_900_000}],
        }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "indikator_utama", return_value=data) as indicator,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            main.chat_endpoint(request)

        indicator.assert_called_once_with(
            "jumlah_penduduk",
            tahun="2024",
            wilayah="Provinsi Sumatera Selatan",
        )

    def test_upah_uses_static_table_and_removes_notes_before_gemini(self):
        class FakeModels:
            def __init__(self):
                self.calls = []

            def generate_content(self, **kwargs):
                self.calls.append(kwargs)
                return main.types.GenerateContentResponse(
                    candidates=[
                        main.types.Candidate(
                            content=main.types.Content(
                                role="model",
                                parts=[main.types.Part(text="Upah minimum tahun 2025 tercantum di tabel.")],
                            )
                        )
                    ]
                )

        fake_models = FakeModels()
        fake_client = type("FakeClient", (), {"models": fake_models})()
        request = main.ChatRequest(question="Berapa upah minimum Sumsel tahun 2025?")
        table_result = {
            "status_hasil": "ditemukan",
            "hasil": [{
                "judul": "Upah Minimum Provinsi",
                "domain": "1600",
                "isi": "2025: 3.500.000",
                "notes": "<p>html-encoded long note</p>",
            }],
            "notes": "<p>top-level note</p>",
        }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "tabel_statis", return_value=table_result) as static_table,
            patch.object(main.bps_tools, "cari_variabel") as variable_search,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(request)

        static_table.assert_called_once_with(
            "upah minimum",
            tahun_diminta="2025",
        )
        variable_search.assert_not_called()
        self.assertEqual(result["reply"], "Upah minimum tahun 2025 tercantum di tabel.")
        gemini_text = fake_models.calls[0]["contents"][-1].parts[0].text
        self.assertIn("3.500.000", gemini_text)
        self.assertNotIn("notes", gemini_text.lower())
        self.assertNotIn("html-encoded", gemini_text)

    def test_dynamic_search_excludes_region_and_year(self):
        self.assertEqual(
            main._dynamic_variable_intent("berapa produksi padi Palembang tahun 2025"),
            ("produksi padi", "2025", "Kota Palembang"),
        )
        self.assertEqual(
            main._dynamic_variable_intent("harga mobil listrik di Lahat"),
            ("harga mobil listrik", "", "Kabupaten Lahat"),
        )
        self.assertEqual(
            main._dynamic_variable_intent("produksi padi menurut kabupaten/kota"),
            ("produksi padi", "", None),
        )

    def test_resolves_multiple_regions_in_question_order(self):
        self.assertEqual(
            main.bps_tools.resolve_regions_from_text(
                "jumlah penduduk menurut jenis kelamin di Lahat dan PALI"
            ),
            ["Kabupaten Lahat", "Kabupaten Penukal Abab Lematang Ilir"],
        )

    def test_requested_category_breakdown_includes_non_total_categories(self):
        result = main._format_indicator_fallback(
            {
                "judul": "Jumlah Penduduk menurut Jenis Kelamin",
                "tahun": "2026",
                "satuan": "Jiwa",
                "data": [
                    {"wilayah": "Lahat", "kategori": "Laki-Laki", "nilai": 214138},
                    {"wilayah": "Lahat", "kategori": "Perempuan", "nilai": 205434},
                    {"wilayah": "Lahat", "kategori": "Laki-Laki + Perempuan", "nilai": 419572},
                ],
            },
            "Kabupaten Lahat",
            "",
            include_categories=True,
        )

        self.assertIn("Lahat (Laki-Laki): 214.138", result)
        self.assertIn("Lahat (Perempuan): 205.434", result)

    def test_dynamic_indicator_returns_requested_details_for_multiple_regions(self):
        search_result = {
            "hasil": [
                {
                    "var_id": 642,
                    "judul": "Jumlah Penduduk menurut Jenis Kelamin",
                    "tahun_terbaru": "2026",
                    "skor_kemiripan": 100,
                },
                {
                    "var_id": 813,
                    "judul": "Jumlah Penduduk Menurut Jenis Kelamin dan Kabupaten/Kota (Hasil SP2020)",
                    "tahun_terbaru": "2026",
                    "skor_kemiripan": 100,
                },
            ]
        }

        def fetch(variable_id, tahun=None, wilayah=None, **_kwargs):
            values = {
                "Kabupaten Lahat": (214138, 205434, 419572),
                "Kabupaten Penukal Abab Lematang Ilir": (103430, 99689, 203119),
            }
            male, female, total = values[wilayah]
            return {
                "judul": search_result["hasil"][0]["judul"],
                "tahun": "2026",
                "satuan": "Jiwa",
                "data": [
                    {"wilayah": "Lahat" if "Lahat" in wilayah else "Pali", "kategori": "Laki-Laki", "nilai": male},
                    {"wilayah": "Lahat" if "Lahat" in wilayah else "Pali", "kategori": "Perempuan", "nilai": female},
                    {"wilayah": "Lahat" if "Lahat" in wilayah else "Pali", "kategori": "Laki-Laki + Perempuan", "nilai": total},
                ],
            }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main.bps_tools, "cari_variabel", return_value=search_result),
            patch.object(main.bps_tools, "ambil_data", side_effect=fetch) as get_data,
            patch.object(main, "get_client") as gemini,
        ):
            result = main.chat_endpoint(
                main.ChatRequest(
                    question="Berapa jumlah penduduk menurut jenis kelamin di Lahat dan PALI?"
                )
            )

        gemini.assert_not_called()
        self.assertEqual(
            [call.kwargs["wilayah"] for call in get_data.call_args_list],
            ["Kabupaten Lahat", "Kabupaten Penukal Abab Lematang Ilir"],
        )
        self.assertEqual({call.args[0] for call in get_data.call_args_list}, {642})
        self.assertIn("Lahat (Laki-Laki): 214.138", result["reply"])
        self.assertIn("Lahat (Perempuan): 205.434", result["reply"])
        self.assertIn("Pali (Laki-Laki): 103.430", result["reply"])
        self.assertIn("Pali (Perempuan): 99.689", result["reply"])

    def test_canonical_indicator_returns_values_for_multiple_regions(self):
        def fetch_indicator(_name, tahun=None, wilayah=None, **_kwargs):
            return {
                "judul": "PDRB Atas Dasar Harga Berlaku",
                "tahun": "2025",
                "satuan": "Miliar rupiah",
                "data": [{
                    "wilayah": "Lahat" if "Lahat" in wilayah else "Pali",
                    "nilai": 1234 if "Lahat" in wilayah else 567,
                }],
            }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main.bps_tools, "indikator_utama", side_effect=fetch_indicator) as indicator,
            patch.object(main, "get_client") as gemini,
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="Berapa PDRB Lahat dan PALI terbaru?")
            )

        gemini.assert_not_called()
        self.assertIn("Lahat: 1.234", result["reply"])
        self.assertIn("Pali: 567", result["reply"])
        self.assertEqual(
            [call.kwargs["wilayah"] for call in indicator.call_args_list[-2:]],
            ["Kabupaten Lahat", "Kabupaten Penukal Abab Lematang Ilir"],
        )

    def test_variable_ranking_prefers_matching_regional_indicator(self):
        result = main._rank_variable_candidates(
            {
                "hasil": [
                    {
                        "var_id": 10,
                        "judul": "Produksi Padi Menurut Kabupaten/Kota",
                        "tahun_terbaru": "2025",
                    },
                    {"var_id": 11, "judul": "Produksi Jagung", "tahun_terbaru": "2025"},
                ]
            },
            "produksi padi",
            "Kota Palembang",
        )
        self.assertEqual(result["var_id"], 10)

    def test_editorial_filters_extract_keyword_year_month_and_page(self):
        self.assertEqual(
            main._editorial_request_parameters(
                "BRS inflasi Agustus 2026 halaman 2"
            ),
            ("inflasi", 2026, 8, 2),
        )
        self.assertEqual(
            main._editorial_request_parameters("berita BPS terbaru"),
            (None, None, None, 1),
        )
        self.assertEqual(main._year_range_intent("Jumlah warga PALI 2023-2026"), (2023, 2026))

    def test_full_pali_name_is_removed_from_dynamic_keyword(self):
        self.assertEqual(
            main._dynamic_variable_intent(
                "Jumlah warga Kabupaten Penukal Abab Lematang Ilir 2023-2026"
            ),
            ("jumlah penduduk", "2023", "Kabupaten Penukal Abab Lematang Ilir"),
        )

    def test_dynamic_keyword_removes_filler_month_and_year(self):
        self.assertEqual(
            main._dynamic_variable_intent(
                "Tolong berapa jumlah warga Kota Palembang pada bulan Maret tahun 2024?"
            ),
            ("jumlah penduduk", "2024", "Kota Palembang"),
        )

    def test_canonical_chat_routes_requested_year_range(self):
        reply = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Jumlah penduduk PALI tahun 2023 sampai 2026.")],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {"models": type("FakeModels", (), {"generate_content": lambda *_args, **_kwargs: reply})()},
        )()
        data = {
            "judul": "Jumlah Penduduk Menurut Kabupaten/Kota",
            "tahun_mulai": 2023,
            "tahun_akhir": 2026,
            "data_per_tahun": [],
            "tahun_tersedia": [],
            "tahun_tidak_tersedia": [],
        }
        request = main.ChatRequest(
            question="Jumlah warga Kabupaten Penukal Abab Lematang Ilir 2023-2026"
        )

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "indikator_utama", return_value=data) as indicator,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            main.chat_endpoint(request)

        indicator.assert_called_once_with(
            "jumlah_penduduk",
            wilayah="Kabupaten Penukal Abab Lematang Ilir",
            tahun_mulai="2023",
            tahun_akhir="2026",
        )

    def test_regional_pdrb_value_routes_to_canonical_series_after_concept_question(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[
                            main.types.Part(
                                text=(
                                    "PDRB ADHB Kota Palembang tahun 2024 sebesar "
                                    "208196.7 miliar rupiah."
                                )
                            )
                        ],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {
                "models": type(
                    "FakeModels",
                    (),
                    {"generate_content": lambda *_args, **_kwargs: response},
                )()
            },
        )()
        request = main.ChatRequest(
            question="Berapa PDRB palembang 2024",
            history=[
                main.Turn(
                    prompt="Apa perbedaan PDRB ADHB dan ADHK?",
                    response="ADHB memakai harga berlaku, sedangkan ADHK memakai harga konstan.",
                )
            ],
        )
        result_data = {
            "judul": "Produk Domestik Regional Bruto atas Dasar Harga Berlaku",
            "tahun": "2024",
            "satuan": "miliar rupiah",
            "var_id": 860,
            "data": [{"wilayah": "Palembang", "nilai": 208196.7}],
        }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "indikator_utama", return_value=result_data) as indicator,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(request)

        self.assertEqual(main._classify_question_intent(request.question), "nilai")
        self.assertEqual(
            main._classify_question_intent("Apa perbedaan PDRB ADHB dan ADHK?"),
            "definisi",
        )
        indicator.assert_called_once_with(
            "pdrb_adhb_kab_kota",
            tahun="2024",
            wilayah="Kota Palembang",
        )
        self.assertIn("208196.7", result["reply"])
        self.assertTrue(result["tools_used"])

    def test_regional_pdrb_adhk_uses_requested_year_and_series(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[
                            main.types.Part(
                                text=(
                                    "PDRB ADHK Kota Palembang tahun 2025 sebesar "
                                    "131646.95 miliar rupiah."
                                )
                            )
                        ],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {
                "models": type(
                    "FakeModels",
                    (),
                    {"generate_content": lambda *_args, **_kwargs: response},
                )()
            },
        )()
        result_data = {
            "judul": "Produk Domestik Regional Bruto atas Dasar Harga Konstan 2010",
            "tahun": "2025",
            "satuan": "Miliar Rupiah",
            "var_id": 859,
            "data": [{"wilayah": "Palembang", "nilai": 131646.95}],
        }

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "indikator_utama", return_value=result_data) as indicator,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(
                main.ChatRequest(question="Berapa PDRB ADHK Palembang 2025?")
            )

        indicator.assert_called_once_with(
            "pdrb_adhk_kab_kota",
            tahun="2025",
            wilayah="Kota Palembang",
        )
        self.assertIn("131646.95", result["reply"])
        self.assertTrue(result["tools_used"])

    def test_latest_provincial_unemployment_uses_aggregate_canonical_value(self):
        response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[
                            main.types.Part(
                                text=(
                                    "Tingkat pengangguran Sumatera Selatan tahun 2025 "
                                    "sebesar 3,69 persen."
                                )
                            )
                        ],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {
                "models": type(
                    "FakeModels",
                    (),
                    {"generate_content": lambda *_args, **_kwargs: response},
                )()
            },
        )()
        result_data = {
            "judul": "Tingkat Pengangguran",
            "tahun": "2025",
            "satuan": "Persen",
            "var_id": 334,
            "wilayah_cakupan": "Provinsi Sumatera Selatan",
            "data": [{"wilayah": "Jumlah", "nilai": 3.69}],
        }
        request = main.ChatRequest(
            question="Berapa tingkat pengangguran Sumatera Selatan terbaru?"
        )

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(main.bps_tools, "indikator_utama", return_value=result_data) as indicator,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(request)

        indicator.assert_called_once_with(
            "tingkat_pengangguran",
            tahun=None,
            wilayah=None,
        )
        self.assertIn("3,69 persen", result["reply"])
        self.assertTrue(result["tools_used"])
        fallback = main._format_indicator_fallback(
            result_data,
            "Provinsi Sumatera Selatan",
            "",
        )
        self.assertIn("Sumatera Selatan", fallback)
        self.assertNotIn("di Jumlah", fallback)

    def test_year_only_monthly_query_uses_historical_brs_search(self):
        reply = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Rilis inflasi 2024 ditemukan.")],
                    )
                )
            ]
        )
        fake_client = type(
            "FakeClient",
            (),
            {"models": type("FakeModels", (), {"generate_content": lambda *_args, **_kwargs: reply})()},
        )()
        request = main.ChatRequest(question="BRS inflasi 2024")
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.object(
                main.bps_tools,
                "berita_resmi_statistik",
                return_value={
                    "hasil": [{
                        "judul": "Inflasi Sumatera Selatan 2024",
                        "ringkasan": "Inflasi tercatat.",
                    }]
                },
            ) as brs,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            main.chat_endpoint(request)

        brs.assert_called_once_with(
            kata_kunci="inflasi",
            jumlah=3,
            tahun=2024,
            bulan=None,
            halaman=1,
        )

    def test_empty_minimum_wage_search_returns_source_explanation(self):
        request = main.ChatRequest(question="Penghasilan minimum di Sumsel berapa?")
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(
                main.bps_tools,
                "tabel_statis",
                return_value={"hasil": [], "status_hasil": "kosong"},
            ) as table_search,
            patch.object(
                main.bps_tools,
                "cari_variabel",
                return_value={"hasil": [], "status_hasil": "kosong"},
            ) as variable_search,
            patch.object(main, "get_client") as gemini,
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            result = main.chat_endpoint(request)

        table_search.assert_called_once_with("upah minimum")
        variable_search.assert_called_once_with("upah")
        gemini.assert_not_called()
        self.assertIn("tidak menemukan data", result["reply"])
        self.assertIn("keputusan Gubernur", result["reply"])

    def test_editorial_intents_route_keyword_period_and_page_to_their_api_models(self):
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(
                main.bps_tools,
                "berita_statistik",
                return_value={"hasil": [], "status_hasil": "kosong"},
            ) as news,
        ):
            main.chat_endpoint(
                main.ChatRequest(question="berita inflasi Maret 2024 halaman 2")
            )

        news.assert_called_once_with(
            kata_kunci="inflasi",
            jumlah=3,
            tahun=2024,
            bulan="3",
            halaman=2,
        )

        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(
                main.bps_tools,
                "publikasi_terbaru",
                return_value={"hasil": [], "status_hasil": "kosong"},
            ) as publication,
        ):
            main.chat_endpoint(
                main.ChatRequest(question="publikasi Palembang dalam angka 2024")
            )

        publication.assert_called_once_with(
            kata_kunci="palembang",
            jumlah=3,
            tahun=2024,
            bulan=None,
            halaman=1,
        )

    def test_tool_results_distinguish_empty_and_technical_failure(self):
        self.assertEqual(main._label_tool_result({"hasil": []})["status_hasil"], "kosong")
        self.assertEqual(
            main._label_tool_result({
                "hasil": [{"skor_kemiripan": 55, "jenis_kecocokan": "related"}],
                "status_hasil": "kandidat_mirip",
            })["status_hasil"],
            "kandidat_mirip",
        )
        self.assertEqual(
            main._label_tool_result({"error": "Data untuk Kabupaten PALI tidak tersedia."})["status_hasil"],
            "kosong",
        )
        self.assertEqual(
            main._label_tool_result({"error": "Hasil pencarian tidak ditemukan."})["status_hasil"],
            "kosong",
        )
        self.assertEqual(
            main._label_tool_result({"error": "WebAPI BPS mengalami gangguan."})["status_hasil"],
            "gagal_teknis",
        )
        self.assertEqual(
            main._label_tool_result({"data": [{"nilai": 1}]})["status_hasil"],
            "ditemukan",
        )
        instructions = main.build_instructions()
        self.assertIn("Jika status_hasil='gagal_teknis'", instructions)
        self.assertIn("jangan menyatakan data tidak ada di BPS", instructions)
        self.assertIn("Jangan menolak pertanyaan statistik umum", instructions)

    def test_tool_logs_redact_api_keys(self):
        with (
            patch.dict(
                "os.environ",
                {"BPS_API_KEY": "bps-secret", "GEMINI_API_KEY": "gemini-secret"},
            ),
            self.assertLogs(main.logger, level="INFO") as captured,
        ):
            main._safe_tool_log(
                "lookup",
                {"query": "example"},
                {"echo": "bps-secret gemini-secret"},
            )

        self.assertNotIn("bps-secret", "\n".join(captured.output))
        self.assertNotIn("gemini-secret", "\n".join(captured.output))

    def test_manual_tool_limit_summarizes_without_tools(self):
        def lookup(keyword):
            return {
                "hasil": [{"keyword": keyword}],
                "notes": "<p>encoded table note</p>",
            }

        function_call_response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[
                            main.types.Part(
                                function_call=main.types.FunctionCall(
                                    name="lookup",
                                    args={"keyword": "miskin"},
                                )
                            )
                        ],
                    )
                )
            ]
        )
        summary_response = main.types.GenerateContentResponse(
            candidates=[
                main.types.Candidate(
                    content=main.types.Content(
                        role="model",
                        parts=[main.types.Part(text="Ringkasan hasil tool.")],
                    )
                )
            ]
        )

        class FakeModels:
            def __init__(self):
                self.calls = []

            def generate_content(self, **kwargs):
                self.calls.append(kwargs)
                return function_call_response if len(self.calls) == 1 else summary_response

        fake_models = FakeModels()
        fake_client = type("FakeClient", (), {"models": fake_models})()
        with (
            patch.object(main.bps_tools, "TOOLS", [lookup]),
            patch.object(main, "get_client", return_value=fake_client),
        ):
            response, records = main._generate_with_manual_tools([], 1)

        self.assertEqual(response.text, "Ringkasan hasil tool.")
        self.assertEqual(len(fake_models.calls), 2)
        self.assertIsNone(fake_models.calls[1]["config"].tools)
        self.assertIn("Batas panggilan tool tercapai", fake_models.calls[1]["contents"][-1].parts[0].text)
        self.assertEqual(records[0]["result"]["status_hasil"], "ditemukan")
        self.assertNotIn("notes", records[0]["result"])

    def test_empty_gemini_answer_returns_server_error(self):
        empty_response = main.types.GenerateContentResponse(candidates=[])
        request = main.ChatRequest(question="Jelaskan layanan statistik BPS.")
        fake_client = type(
            "FakeClient",
            (),
            {"models": type(
                "FakeModels",
                (),
                {"generate_content": lambda *_args, **_kwargs: empty_response},
            )()},
        )()
        with (
            patch.object(main, "verify_token"),
            patch.object(main, "allow_request", return_value=True),
            patch.object(main, "get_client", return_value=fake_client),
            patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}),
        ):
            with self.assertRaises(main.HTTPException) as raised:
                main.chat_endpoint(request)

        self.assertEqual(raised.exception.status_code, 502)

    def test_indicator_fallback_formats_verified_value_and_period(self):
        reply = main._format_indicator_fallback(
            {
                "judul": "Jumlah Penduduk Menurut Kabupaten/Kota",
                "tahun": "2025",
                "satuan": "Jiwa",
                "sifat_data": "estimasi",
                "data": [{"wilayah": "Palembang", "nilai": 1234567}],
            },
            "Kota Palembang",
            "",
        )
        self.assertIn("Palembang tahun 2025", reply)
        self.assertIn("1.234.567 Jiwa", reply)
        self.assertIn("estimasi", reply)

    def test_indicator_fallback_asks_for_category_without_claiming_total_is_missing(self):
        reply = main._format_indicator_fallback(
            {
                "judul": "Jumlah Penduduk Menurut Kabupaten/Kota",
                "tahun": "2025",
                "satuan": "Jiwa",
                "sifat_data": "estimasi",
                "data": [
                    {"wilayah": "Sumatera Selatan", "kategori": "Laki-Laki", "nilai": 4},
                    {"wilayah": "Sumatera Selatan", "kategori": "Perempuan", "nilai": 5},
                ],
            },
            "Provinsi Sumatera Selatan",
            "2025",
        )
        self.assertIn("Laki-Laki", reply)
        self.assertIn("Perempuan", reply)
        self.assertIn("Sebutkan kategori", reply)
        self.assertNotIn("tidak memuat baris total/agregat", reply)
        self.assertIn("estimasi", reply)
        self.assertNotEqual(reply, main.NOT_FOUND)

    def test_indicator_fallback_lists_regional_pdrb_without_total_warning(self):
        reply = main._format_indicator_fallback(
            {
                "judul": "PDRB Menurut Kabupaten/Kota",
                "tahun": "2025",
                "satuan": "Miliar Rupiah",
                "data": [
                    {"wilayah": "Lahat", "nilai": 120},
                    {"wilayah": "PALI", "nilai": 80},
                ],
            },
            None,
            "2025",
        )

        self.assertIn("Lahat: 120", reply)
        self.assertIn("PALI: 80", reply)
        self.assertNotIn("total/agregat", reply)

    def test_explicit_total_without_province_row_is_reported_without_summing(self):
        reply = main._format_indicator_fallback(
            {
                "judul": "PDRB Menurut Kabupaten/Kota",
                "tahun": "2025",
                "satuan": "Miliar Rupiah",
                "data": [
                    {"wilayah": "Lahat", "nilai": 120},
                    {"wilayah": "PALI", "nilai": 80},
                ],
            },
            None,
            "2025",
            minta_total=True,
        )

        self.assertIn("Baris total Provinsi Sumatera Selatan", reply)
        self.assertIn("tidak menjumlahkan kabupaten/kota", reply)
        self.assertNotIn("200 Miliar", reply)

    def test_static_fallback_discloses_missing_year_and_download_link(self):
        reply = main._format_static_table_fallback(
            {
                "hasil": [{
                    "judul": "Upah/Gaji Menurut Tahun",
                    "isi": "| Tahun | Nilai |\n|---|---|\n| 2025 | 3500 |",
                    "tahun_diminta_tidak_ditemukan": "2026",
                    "tahun_terbaru_tersedia": "2025",
                    "terpotong": True,
                    "excel": "https://example.test/table.xlsx",
                }],
            },
            "gaji",
        )

        self.assertIn("Tahun 2026 belum tersedia", reply)
        self.assertIn("2025", reply)
        self.assertIn("https://example.test/table.xlsx", reply)

    def test_total_request_is_distinguished_from_indicator_quantity(self):
        self.assertFalse(main._requests_total_aggregate("jumlah PDRB kabupaten dan kota"))
        self.assertTrue(main._requests_total_aggregate("total PDRB Sumatera Selatan"))

    def test_indicator_fallback_uses_explicit_total_category_only(self):
        reply = main._format_indicator_fallback(
            {
                "judul": "Proyeksi Jumlah Penduduk",
                "tahun": "2026",
                "satuan": "Jiwa",
                "sifat_data": "proyeksi",
                "data": [
                    {"wilayah": "Sumatera Selatan", "kategori": "Laki-Laki", "nilai": 4},
                    {"wilayah": "Sumatera Selatan", "kategori": "Perempuan", "nilai": 5},
                    {"wilayah": "Sumatera Selatan", "kategori": "Laki-Laki + Perempuan", "nilai": 9},
                    {"wilayah": "Sumatera Selatan", "kategori": "Tidak ada", "nilai": 99},
                ],
            },
            "Provinsi Sumatera Selatan",
            "",
        )
        self.assertIn(": 9 Jiwa.", reply)
        self.assertIn("proyeksi", reply)
        self.assertNotIn("99", reply)


if __name__ == "__main__":
    unittest.main()
