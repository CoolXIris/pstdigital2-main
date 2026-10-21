import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import bps_tools
import bps_csv_search
import bps_data_store
from mysql_test_adapter import MySQLStorageTestConnection


class BpsToolsTests(unittest.TestCase):
    def setUp(self):
        self._database_directory = tempfile.TemporaryDirectory()
        database_path = str(Path(self._database_directory.name) / "bps_test.db")
        self._database_patch = patch.object(
            bps_data_store,
            "init_db",
            side_effect=lambda: MySQLStorageTestConnection(database_path),
        )
        self._database_patch.start()

    def tearDown(self):
        self._database_patch.stop()
        self._database_directory.cleanup()

    def test_province_prefix_resolves_to_sumsel(self):
        self.assertEqual(
            bps_tools._resolve_region("Provinsi Sumatera Selatan"),
            ("Provinsi Sumatera Selatan", []),
        )

    def test_simdasi_catalog_lists_titles_and_available_years(self):
        rows = [
            {
                "id_tabel": "table-id",
                "judul": "Tingkat Pengangguran Terbuka (TPT) Menurut Kabupaten/Kota",
                "jenis_tabel": "SIMDASI",
                "subjek": "Ketenagakerjaan",
                "kategori_subjek": "Penduduk dan Ketenagakerjaan",
                "tahun": str(year),
                "link_json": f"https://webapi.bps.go.id/v1/api/interoperabilitas/datasource/simdasi/id/25/wilayah/1600000/tahun/{year}/id_tabel/table-id/key/",
            }
            for year in (2024, 2025)
        ]
        with patch.object(bps_tools, "_catalog_rows", return_value=rows):
            result = bps_tools.simdasi_daftar_tabel("daftar tabel SIMDASI")

        self.assertEqual(result["jumlah_ditemukan"], 1)
        self.assertEqual(result["hasil"][0]["judul"], rows[0]["judul"])
        self.assertEqual(result["hasil"][0]["id_tabel"], "table-id")
        self.assertEqual(result["hasil"][0]["tahun_tersedia"], [2024, 2025])

    def test_simdasi_data_filters_requested_region_indicator_and_month(self):
        table = {
            "id_tabel": "table-id",
            "judul": "Tingkat Pengangguran Terbuka (TPT) Menurut Kabupaten/Kota",
            "subject": "Ketenagakerjaan",
            "ketersediaan_tahun": [2024, 2025],
        }
        catalog_rows = [
            {
                "id_tabel": table["id_tabel"],
                "judul": table["judul"],
                "jenis_tabel": "SIMDASI",
                "subjek": table["subject"],
                "kategori_subjek": "",
                "tahun": str(year),
                "link_json": f"https://webapi.bps.go.id/v1/api/interoperabilitas/datasource/simdasi/id/25/wilayah/1600000/tahun/{year}/id_tabel/table-id/key/",
            }
            for year in table["ketersediaan_tahun"]
        ]
        detail = {
            "data": [{
                "page": 1,
                "pages": 1,
            }, {
                "judul_tabel": f"{table['judul']}, 2025",
                "tahun_data": 2025,
                "subject": "Ketenagakerjaan",
                "kolom": {
                    "tpt_feb": {"nama_variabel": "Tingkat Pengangguran Terbuka (TPT) - Februari"},
                    "tpt_aug": {"nama_variabel": "Tingkat Pengangguran Terbuka (TPT) - Agustus"},
                    "tpak_aug": {"nama_variabel": "Tingkat Partisipasi Angkatan Kerja (TPAK) - Agustus"},
                },
                "data": [
                    {
                        "label": "Lahat",
                        "variables": {
                            "tpt_feb": {"value": "2,91"},
                            "tpt_aug": {"value": "2,84"},
                            "tpak_aug": {"value": "70,72"},
                        },
                    },
                    {
                        "label": "Palembang",
                        "variables": {
                            "tpt_feb": {"value": "3,10"},
                            "tpt_aug": {"value": "3,00"},
                            "tpak_aug": {"value": "68,00"},
                        },
                    },
                ],
            }],
        }
        with (
            patch.object(bps_tools, "_catalog_rows", return_value=catalog_rows),
            patch.object(bps_tools, "_fetch_catalog_row", return_value=detail) as fetch,
        ):
            result = bps_tools.simdasi_cari_data("TPT Agustus", "2025", "Lahat")

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["tahun"], 2025)
        self.assertEqual(result["wilayah_ditafsirkan"], "Kabupaten Lahat")
        self.assertEqual(result["data"], [{
            "wilayah": "Lahat",
            "variabel": "Tingkat Pengangguran Terbuka (TPT) - Agustus",
            "nilai": "2,84",
        }])
        fetch.assert_called_once_with(catalog_rows[1])

    def test_variable_search_strips_region_and_uses_synonym_fallback(self):
        with (
            patch.object(
                bps_tools,
                "_variable_index",
                return_value={
                    "variables": [
                        {"var_id": "10", "title": "Jumlah Penduduk Miskin", "unit": "Orang", "latest_year": "2025"},
                        {"var_id": "11", "title": "Jumlah Penduduk", "unit": "Orang", "latest_year": "2025"},
                    ]
                },
            ),
            patch.object(bps_tools, "_latest_year", return_value=("2025", 10, False)),
        ):
            result = bps_tools.cari_variabel("kemiskinan Kota Palembang")

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["hasil"][0]["var_id"], 10)
        self.assertEqual(result["hasil"][0]["tahun_terbaru"], "2025")

    def test_local_variable_search_tolerates_typos(self):
        with (
            patch.object(
                bps_tools,
                "_variable_index",
                return_value={
                    "variables": [
                        {
                            "var_id": "20",
                            "title": "Tingkat Pengangguran Terbuka",
                            "unit": "Persen",
                            "latest_year": "2025",
                        }
                    ]
                },
            ),
            patch.object(bps_tools, "_latest_year", return_value=("2025", 8, False)),
        ):
            result = bps_tools.cari_variabel("penganguran")

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["hasil"][0]["var_id"], 20)

    def test_variable_search_scores_related_and_exact_catalog_candidates(self):
        variables = [
            {
                "var_id": "246",
                "title": "Jumlah Tindak Pidana",
                "unit": "Kasus",
                "latest_year": "2025",
            },
            {
                "var_id": "248",
                "title": "Penyelesaian Tindak Pidana",
                "unit": "Persen",
                "latest_year": "2024",
            },
            {
                "var_id": "227",
                "title": "Jumlah PNS Menurut Pendidikan Tertinggi",
                "unit": "Orang",
                "latest_year": "2025",
            },
            {
                "var_id": "229",
                "title": "Jumlah PNS Menurut Golongan Kepangkatan",
                "unit": "Orang",
                "latest_year": "2025",
            },
        ]
        with (
            patch.object(bps_tools, "_variable_index", return_value={"variables": variables}),
            patch.object(bps_tools, "_latest_year", return_value=("2025", 8, False)),
        ):
            prison_result = bps_tools.cari_variabel(
                "info total jumlah yang masuk penjara di Sumsel"
            )
            pns_result = bps_tools.cari_variabel("jumlah PNS Sumsel")
            unrelated_result = bps_tools.cari_variabel("zebra cross Sumsel")

        self.assertEqual(prison_result["status_hasil"], "kandidat_mirip")
        self.assertEqual(prison_result["hasil"][0]["var_id"], 246)
        self.assertEqual(prison_result["hasil"][0]["jenis_kecocokan"], "related")
        self.assertEqual(pns_result["status_hasil"], "ditemukan")
        self.assertEqual(
            {item["var_id"] for item in pns_result["hasil"]},
            {227, 229},
        )
        self.assertTrue(all(item["skor_kemiripan"] == 100 for item in pns_result["hasil"]))
        self.assertEqual(unrelated_result["status_hasil"], "kosong")
        self.assertEqual(unrelated_result["hasil"], [])
        self.assertTrue(bps_tools.catalog_variable_matches("orang masuk penjara Sumsel"))
        self.assertFalse(bps_tools.catalog_variable_matches("zebra cross Sumsel"))

    def test_catalog_csv_loads_variable_metadata_subjects_and_rls_alias(self):
        rows = [{
            "kategori_subjek": "Sosial dan Kependudukan",
            "subjek": "Pendidikan",
            "id_subjek": "28",
            "judul": "Rata-Rata Lama Sekolah",
            "id_tabel": "308",
            "jenis_tabel": "Tabel Dinamis",
            "tahun": "2024",
            "link_json": "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/308/th/124/key/",
        }]
        with patch.object(bps_tools, "_catalog_rows", return_value=rows):
            catalog = bps_tools._load_catalog_index()

        variable = catalog["variables"][0]
        self.assertEqual(variable["var_id"], "308")
        self.assertEqual(variable["latest_year"], "2024")
        self.assertEqual(variable["category"], "Sosial dan Kependudukan")
        self.assertIn("rls", variable["aliases"])
        self.assertEqual(catalog["subjects"][0]["subject"], "Pendidikan")

    def test_variable_search_finds_catalog_rls_without_live_period_metadata(self):
        variable = {
            "var_id": "308",
            "title": "Rata-Rata Lama Sekolah",
            "unit": "Tahun",
            "subject": "Pendidikan",
            "category": "Sosial dan Kependudukan",
            "latest_year": "2024",
            "year_count": 10,
            "aliases": ["rls"],
        }
        with (
            patch.object(bps_tools, "_variable_index", return_value={"variables": [variable]}),
            patch.object(bps_tools, "_latest_year", side_effect=bps_tools.BpsError("offline")),
        ):
            result = bps_tools.cari_variabel("RLS Sumsel")

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["hasil"][0]["var_id"], 308)
        self.assertEqual(result["hasil"][0]["tahun_terbaru"], "2024")
        self.assertEqual(result["hasil"][0]["subjek"], "Pendidikan")

    def test_catalog_indicator_matching_recognizes_unlisted_topics(self):
        catalog = {
            "variables": [{
                "title": "Penduduk Menurut Status Perkawinan",
                "aliases": [],
            }],
        }
        with patch.object(bps_tools, "_load_catalog_index", return_value=catalog):
            self.assertTrue(
                bps_tools.catalog_variable_matches("perkawinan di Sumsel tahun 2026")
            )
            self.assertFalse(bps_tools.catalog_variable_matches("topik tidak terkait"))

    def test_subject_search_returns_complete_catalog_fields(self):
        index = {
            "subjects": [{
                "sub_id": "28",
                "subject": "Pendidikan",
                "category": "Sosial dan Kependudukan",
                "category_id": "1",
                "jumlah_tabel": None,
            }],
            "variables": [{"subject_id": "28"}],
        }
        with patch.object(bps_tools, "_variable_index", return_value=index):
            result = bps_tools.cari_subjek("Pendidikan")

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["hasil"][0]["subcat_id"], "1")
        self.assertEqual(result["hasil"][0]["jumlah_variabel_katalog"], 1)

    def test_keyword_cleanup_removes_regions_years_months_and_fillers(self):
        self.assertEqual(
            bps_tools._clean_search_keyword(
                "Tolong berapa jumlah warga Kota Palembang tahun 2024 bulan Maret?"
            ),
            "penduduk",
        )

    def test_keyword_variants_include_roots_and_related_synonyms(self):
        variants = bps_tools._search_keyword_variants("kemiskinan")
        self.assertEqual(variants[:2], ["miskin", "kemiskinan"])
        population_variants = bps_tools._search_keyword_variants("jiwa")
        self.assertEqual(population_variants[0], "penduduk")
        self.assertIn("warga", population_variants)
        self.assertIn("gaji", bps_tools._search_keyword_variants("upah"))
        self.assertIn("pengangguran", bps_tools._search_keyword_variants("penganggur"))

    def test_ntp_abbreviation_finds_the_nilai_tukar_petani_variable(self):
        catalog = bps_tools._load_catalog_index()
        with (
            patch.object(bps_tools, "_variable_index", return_value=catalog),
            patch.object(
                bps_tools,
                "_latest_year",
                side_effect=bps_tools.BpsError("Use catalog year metadata."),
            ),
        ):
            result = bps_tools.cari_variabel("ntp")

        self.assertEqual(bps_tools._clean_search_keyword("ntp"), "tukar petani")
        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["hasil"][0]["var_id"], 645)
        self.assertEqual(result["hasil"][0]["tahun_terbaru"], "2026")

    def test_catalog_variable_search_expands_common_gender_and_healthcare_terms(self):
        catalog = bps_tools._load_catalog_index()
        with (
            patch.object(bps_tools, "_variable_index", return_value=catalog),
            patch.object(bps_tools, "_latest_year", return_value=("2025", 10, False)),
        ):
            male = bps_tools.cari_variabel("pria")
            female = bps_tools.cari_variabel("wanita")
            both = bps_tools.cari_variabel("pria dan wanita")
            hospitals = bps_tools.cari_variabel("rumah sakit")
            puskesmas = bps_tools.cari_variabel("puskesmas")
            posyandu = bps_tools.cari_variabel("posyandu")

        self.assertEqual(male["status_hasil"], "ditemukan")
        self.assertTrue(any("laki-laki" in item["judul"].casefold() for item in male["hasil"]))
        self.assertEqual(female["status_hasil"], "ditemukan")
        self.assertTrue(any("perempuan" in item["judul"].casefold() for item in female["hasil"]))
        self.assertTrue(any("jenis kelamin" in item["judul"].casefold() for item in both["hasil"]))
        self.assertEqual(hospitals["hasil"][0]["judul"], "Jumlah Fasilitas Kesehatan. (Unit)")
        self.assertEqual(puskesmas["hasil"][0]["var_id"], 779)
        self.assertEqual(posyandu["hasil"][0]["var_id"], 779)
        self.assertTrue(bps_tools.catalog_variable_matches("pria dan wanita"))
        self.assertTrue(bps_tools.catalog_variable_matches("rumah sakit"))
        self.assertTrue(bps_tools.catalog_variable_matches("puskesmas"))
        self.assertTrue(bps_tools.catalog_variable_matches("posyandu"))

    def test_ambil_data_returns_a_bounded_year_range(self):
        periods = [
            {"th": str(year), "th_id": str(year)}
            for year in (2026, 2025, 2024, 2023)
        ]

        def fake_format(*args):
            period = args[2]
            return {
                "judul": "Jumlah Penduduk",
                "satuan": "Jiwa",
                "tahun": period["th"],
                "data": [{"wilayah": "Sumatera Selatan", "nilai": int(period["th"])}],
            }

        with (
            patch.object(bps_tools, "_periods", return_value=periods),
            patch.object(
                bps_tools,
                "_dynamic_table_row",
                side_effect=lambda _var_id, year: {"tahun": year},
            ),
            patch.object(bps_tools, "_fetch_catalog_row", return_value={"status": "OK"}),
            patch.object(bps_tools, "_format_data", side_effect=fake_format),
        ):
            result = bps_tools.ambil_data(
                262,
                tahun_mulai="2023",
                tahun_akhir="2026",
            )

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["tahun_tersedia"], ["2023", "2024", "2025", "2026"])
        self.assertEqual(result["jumlah_tahun"], 4)
        self.assertEqual(
            [entry["data"][0]["nilai"] for entry in result["data_per_tahun"]],
            [2023, 2024, 2025, 2026],
        )

    def test_ambil_data_rejects_ranges_over_ten_years(self):
        with patch.object(bps_tools, "_periods", return_value=[{"th": "2026", "th_id": "1"}]):
            result = bps_tools.ambil_data(
                262,
                tahun_mulai="2010",
                tahun_akhir="2020",
            )

        self.assertEqual(result["status_hasil"], "kosong")
        self.assertTrue(result["input_tidak_valid"])
        self.assertIn("maksimal 10 tahun", result["error"])

    def test_ambil_data_lists_unavailable_years_in_a_partial_range(self):
        periods = [{"th": "2025", "th_id": "25"}, {"th": "2024", "th_id": "24"}]

        def fake_format(*args):
            period = args[2]
            return {
                "judul": "Jumlah Penduduk",
                "tahun": period["th"],
                "data": [{"wilayah": "Sumatera Selatan", "nilai": 1}],
            }

        with (
            patch.object(bps_tools, "_periods", return_value=periods),
            patch.object(
                bps_tools,
                "_dynamic_table_row",
                side_effect=lambda _var_id, year: {"tahun": year},
            ),
            patch.object(bps_tools, "_fetch_catalog_row", return_value={"status": "OK"}),
            patch.object(bps_tools, "_format_data", side_effect=fake_format),
        ):
            result = bps_tools.ambil_data(
                262,
                tahun_mulai="2023",
                tahun_akhir="2026",
            )

        self.assertEqual(result["tahun_tersedia"], ["2024", "2025"])
        self.assertEqual(result["tahun_tidak_tersedia"], ["2023", "2026"])

    def test_editorial_search_sorts_results_fetches_details_and_limits_to_three(self):
        calls = []

        def fake_fetch(model, **kwargs):
            calls.append((model, kwargs))
            if kwargs.get("identifier"):
                item_id = kwargs["identifier"]
                return {
                    "status": "OK",
                    "data": [{
                        "br_id": item_id,
                        "abstract": f"<p>Abstrak {item_id}</p>",
                    }],
                }
            return {
                "status": "OK",
                "data": [{
                    "pages": 1,
                }, [
                    {
                        "br_id": "older",
                        "title": "Inflasi 2024",
                        "rl_date": "2024-01-10",
                        "pdf": "https://bps.go.id/older.pdf",
                    },
                    {
                        "br_id": "newer",
                        "title": "Inflasi 2025",
                        "rl_date": "2025-02-10",
                        "pdf": "https://bps.go.id/newer.pdf",
                    },
                    {
                        "br_id": "newest",
                        "title": "Inflasi Terkini",
                        "rl_date": "2025-03-10",
                        "pdf": "https://bps.go.id/newest.pdf",
                    },
                    {
                        "br_id": "middle",
                        "title": "Inflasi Tengah",
                        "rl_date": "2025-02-20",
                    },
                ]],
            }

        with patch.object(bps_tools.bps_csv_catalog, "fetch_editorial_json", side_effect=fake_fetch):
            result = bps_tools.berita_resmi_statistik(
                "inflasi",
                jumlah=5,
                tahun=2025,
                bulan="3",
                halaman=2,
            )

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(
            [item["id"] for item in result["hasil"]],
            ["newest", "middle", "newer"],
        )
        self.assertEqual(result["hasil"][0]["ringkasan"], "Abstrak newest")
        self.assertEqual(result["hasil"][0]["tautan"], "https://bps.go.id/newest.pdf")
        list_call = calls[0]
        self.assertEqual(list_call[0], "pressrelease")
        self.assertEqual(list_call[1]["keyword"], "inflasi")
        self.assertEqual(list_call[1]["year"], 2025)
        self.assertEqual(list_call[1]["month"], 3)
        self.assertEqual(list_call[1]["page"], 2)
        self.assertEqual(len(calls), 4)

    def test_publication_and_news_use_their_own_models_and_friendly_errors(self):
        with patch.object(
            bps_tools.bps_csv_catalog,
            "fetch_editorial_json",
            return_value={"status": "OK", "data": [{"pages": 1}, []]},
        ) as fetch:
            publication = bps_tools.publikasi_terbaru("pertanian", tahun=2024)
            news = bps_tools.berita_statistik("sensus")

        self.assertEqual(publication["jenis_konten"], "publication")
        self.assertEqual(news["jenis_konten"], "news")
        self.assertEqual(publication["status_hasil"], "kosong")
        self.assertEqual(fetch.call_args_list[0].args[0], "publication")
        self.assertEqual(fetch.call_args_list[1].args[0], "news")

        with patch.object(
            bps_tools.bps_csv_catalog,
            "fetch_editorial_json",
            side_effect=bps_tools.bps_csv_catalog.CatalogError("API key BPS tidak diterima."),
        ):
            failed = bps_tools.berita_statistik()
        self.assertEqual(failed["status_hasil"], "gagal_teknis")
        self.assertNotIn("key/", failed.get("error", ""))

        with patch.object(
            bps_tools.bps_csv_catalog,
            "fetch_editorial_json",
            return_value={"status": "OK", "data": {"unexpected": "shape"}},
        ):
            malformed = bps_tools.berita_statistik()
        self.assertEqual(malformed["status_hasil"], "gagal_teknis")
        self.assertIn("Format detail arsip", malformed["error"])

    def test_refresh_variable_index_is_derived_from_csv(self):
        rows = [
            {
                "kategori_subjek": "Sosial",
                "subjek": "Penduduk",
                "id_subjek": "1",
                "judul": "Jumlah Penduduk",
                "id_tabel": "101",
                "jenis_tabel": "Tabel Dinamis",
                "tahun": "2025",
                "link_json": "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/101/th/125/key/",
            },
            {
                "kategori_subjek": "Sosial",
                "subjek": "Penduduk",
                "id_subjek": "1",
                "judul": "Kepadatan Penduduk",
                "id_tabel": "102",
                "jenis_tabel": "Tabel Dinamis",
                "tahun": "2024",
                "link_json": "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/102/th/124/key/",
            },
            {
                "kategori_subjek": "Ekonomi",
                "subjek": "Tenaga Kerja",
                "id_subjek": "2",
                "judul": "Upah Buruh",
                "id_tabel": "201",
                "jenis_tabel": "Tabel Dinamis",
                "tahun": "2025",
                "link_json": "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/201/th/125/key/",
            },
        ]
        with patch.object(bps_tools, "_catalog_rows", return_value=rows):
            index = bps_tools.refresh_variable_index()

        self.assertEqual({row["var_id"] for row in index["variables"]}, {"101", "102", "201"})

    def test_static_table_search_reads_and_cleans_table_html(self):
        catalog_row = {
            "kategori_subjek": "Ketenagakerjaan",
            "subjek": "Upah dan Gaji",
            "id_subjek": "3",
            "judul": "Upah Minimum Provinsi Sumatera Selatan",
            "id_tabel": "42",
            "jenis_tabel": "Tabel Statis",
            "tahun": "",
            "link_json": "https://webapi.bps.go.id/v1/api/view/model/statictable/domain/1600/id/42/lang/ind/key/",
        }
        payload = {
            "status": "OK",
            "data": {
                "table": "<table><tr><th>Tahun</th><th>Upah</th></tr><tr><td>2025</td><td>3.500</td></tr></table>",
                "notes": "<p>long encoded notes</p>",
            },
        }
        with (
            patch.object(bps_tools, "_catalog_rows", return_value=[catalog_row]),
            patch.object(bps_tools, "_fetch_catalog_row", return_value=payload),
        ):
            result = bps_tools.tabel_statis("upah minimum", jumlah=1)

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["hasil"][0]["table_id"], "42")
        self.assertIn("Tahun", result["hasil"][0]["isi"])
        self.assertIn("3.500", result["hasil"][0]["isi"])
        self.assertNotIn("notes", result["hasil"][0])

    def test_canonical_indicator_map_uses_the_shared_json_source(self):
        with open("config/bps_indicators.json", encoding="utf-8") as source:
            shared_map = json.load(source)

        expected = {
            variable["name"]: variable["var_id"]
            for group in shared_map["groups"]
            for variable in group["variables"]
        }
        self.assertEqual(
            {name: item["var_id"] for name, item in bps_tools._INDIKATOR_UTAMA.items()},
            expected,
        )
        self.assertEqual(bps_tools.nama_indikator_utama(), list(expected))

    def test_golden_questions_route_to_the_expected_indicator_and_region(self):
        import main

        with open("tests/Fixtures/bps-golden-questions.json", encoding="utf-8") as source:
            cases = json.load(source)
        for case in cases:
            with self.subTest(question=case["question"]):
                intent = main._canonical_indicator_intent(case["question"])
                self.assertIsNotNone(intent)
                self.assertEqual(intent[0], case["indicator"])
                self.assertEqual(intent[2], case["region"])

    def test_canonical_indicators_select_the_aggregate_vervar_category(self):
        with patch.object(
            bps_tools,
            "ambil_data",
            return_value={
                "tahun": "2026",
                "jumlah_baris": 3,
                "data": [
                    {"wilayah": "Perkotaan", "nilai": 5.1},
                    {"wilayah": "Pedesaan", "nilai": 12.4},
                    {"wilayah": "Perkotaan+Pedesaan", "nilai": 9.2},
                ],
            },
        ) as fetch:
            result = bps_tools.indikator_utama("kemiskinan_persen")

        fetch.assert_called_once_with(608, tahun=None, wilayah=None)
        self.assertEqual(result["data"], [{"wilayah": "Perkotaan+Pedesaan", "nilai": 9.2}])
        self.assertEqual(result["jumlah_baris"], 1)

    def test_unemployment_indicator_selects_total_sex_category_as_provincial_value(self):
        with patch.object(
            bps_tools,
            "ambil_data",
            return_value={
                "tahun": "2025",
                "jumlah_baris": 3,
                "data": [
                    {"wilayah": "Laki-Laki", "nilai": 3.56},
                    {"wilayah": "Perempuan", "nilai": 3.92},
                    {"wilayah": "Jumlah", "nilai": 3.69},
                ],
            },
        ) as fetch:
            result = bps_tools.indikator_utama("tingkat_pengangguran")

        fetch.assert_called_once_with(334, tahun=None, wilayah=None)
        self.assertEqual(result["data"], [{"wilayah": "Jumlah", "nilai": 3.69}])
        self.assertEqual(result["wilayah_cakupan"], "Provinsi Sumatera Selatan")

    def test_unresolved_query_log_redacts_contact_details(self):
        import main

        with self.assertLogs(main.logger, level="WARNING") as captured:
            main._record_unresolved_question(
                "Data untuk user@example.com atau 081234567890?",
                "no_tool_result",
                "user@example.com",
            )

        self.assertIn("BPS_QUERY_UNRESOLVED", captured.output[0])
        self.assertIn("[EMAIL]", captured.output[0])
        self.assertIn("[PHONE]", captured.output[0])
        self.assertNotIn("user@example.com", captured.output[0])
        self.assertNotIn("081234567890", captured.output[0])

    def test_unresolved_query_log_records_a_cleaned_search_keyword(self):
        import main

        with self.assertLogs(main.logger, level="WARNING") as captured:
            main._record_unresolved_question(
                "Tolong cari jumlah warga Kota Palembang tahun 2024",
                "verified_empty_result",
            )

        entry = json.loads(captured.output[0].split("BPS_QUERY_UNRESOLVED ", 1)[1])
        self.assertEqual(entry["keyword"], "penduduk")
        self.assertIn("Palembang", entry["question"])
        self.assertIn("2024", entry["question"])

    def test_notes_fields_are_removed_recursively(self):
        cleaned = bps_tools.strip_notes({
            "title": "example",
            "notes": "<p>encoded and long</p>",
            "nested": [{"NoTeS": "also removed", "value": 1}],
        })
        self.assertEqual(cleaned, {"title": "example", "nested": [{"value": 1}]})

    def test_requested_query_examples_use_core_terms_and_synonyms(self):
        rows = [
            {
                "id_tabel": "salary",
                "judul": "Rata-rata Upah/Gaji Pekerja Menurut Kabupaten/Kota",
                "subjek": "Ketenagakerjaan",
                "jenis_tabel": "Tabel Dinamis",
                "tahun": "2025",
            },
            {
                "id_tabel": "calories",
                "judul": "Rata-rata Konsumsi Kalori per Kapita",
                "subjek": "Konsumsi dan Pengeluaran",
                "jenis_tabel": "Tabel Dinamis",
                "tahun": "2025",
            },
            {
                "id_tabel": "pdrb",
                "judul": "Produk Domestik Regional Bruto Menurut Kabupaten/Kota",
                "subjek": "Produk Domestik Regional Bruto",
                "jenis_tabel": "Tabel Dinamis",
                "tahun": "2025",
            },
        ]
        search = bps_csv_search.Pencari(rows)

        question = "rata-rata gaji di sumsel berapa untuk 2026"
        core = search.kata_inti(question)
        result = search.cari(question, tahun_diminta=2026)
        self.assertEqual(core, ["gaji"])
        self.assertEqual(result["hasil"][0]["id"], "salary")
        self.assertNotIn("2026", result["hasil"][0]["tahun"])

        result = search.cari("penghasilan pekerja di sumsel 2025", tahun_diminta=2025)
        self.assertEqual(result["hasil"][0]["id"], "salary")
        self.assertIn("penghasilan", result["hasil"][0]["sinonim_digunakan"])

        result = search.cari("PDRB kabupaten dan kota sumatera selatan terbaru")
        self.assertEqual(result["hasil"][0]["id"], "pdrb")

        result = search.cari("rata-rata konsumsi kalori sumsel")
        self.assertEqual(result["hasil"][0]["id"], "calories")

        result = search.cari("harga mobil listrik di Lahat")
        self.assertEqual(result["hasil"], [])
        self.assertLessEqual(len(result["saran"]), 3)
        self.assertTrue(result["saran"])

    def test_static_table_parser_deduplicates_filters_year_and_bounds_rows(self):
        rows = "".join(
            f"<tr><td>Kabupaten Lahat</td><td>{index}</td><td>{index}</td>"
            f"<td>{index + 1}</td></tr>"
            for index in range(30)
        )
        table_html = (
            "<table><thead><tr><th>Wilayah</th><th>2024</th><th>Duplikat</th>"
            f"<th>2025</th></tr></thead><tbody>{rows}</tbody></table>"
        )
        frame, info = bps_tools._static_table_dataframe(
            table_html,
            wilayah="Lahat",
            tahun_diminta="2026",
        )

        self.assertIsNotNone(frame)
        self.assertEqual(len(frame.columns), 2)
        self.assertEqual(len(frame), 30)
        self.assertEqual(info["tahun_tersedia"], ["2024", "2025"])
        self.assertEqual(info["tahun_diminta_tidak_ditemukan"], "2026")
        self.assertEqual(info["tahun_terbaru_tersedia"], "2025")
        self.assertEqual(list(frame.columns), ["Wilayah", "2025"])
        latest_frame, _ = bps_tools._static_table_dataframe(
            table_html,
            wilayah="Lahat",
        )
        self.assertEqual(list(latest_frame.columns), ["Wilayah", "2025"])
        rendered = bps_tools.tabel_statis_ke_teks(table_html, wilayah="Lahat")
        self.assertLessEqual(rendered.count("\n"), 26)


if __name__ == "__main__":
    unittest.main()
