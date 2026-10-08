import json
import unittest
from unittest.mock import patch

import httpx

import bps_tools


class BpsToolsTests(unittest.TestCase):
    def _response(self, status_code, payload, headers=None):
        return httpx.Response(
            status_code,
            headers=headers,
            content=json.dumps(payload).encode(),
            request=httpx.Request("GET", "https://webapi.bps.go.id/test"),
        )

    def test_list_not_available_is_a_valid_empty_response(self):
        response = self._response(
            404,
            {"status": "ERROR"},
            {"data-availability": "list-not-available"},
        )
        with (
            patch.dict("os.environ", {"BPS_API_KEY": "test-key"}),
            patch.object(bps_tools._http, "get", return_value=response),
        ):
            payload = bps_tools._get("list/model/var")

        self.assertEqual(payload["status"], "OK")
        self.assertEqual(payload["data"], [{"pages": 1}, []])
        self.assertEqual(payload["data_availability"], "list-not-available")

    def test_valid_non_ok_json_is_treated_as_empty(self):
        response = self._response(400, {"status": "ERROR", "message": "not found"})
        with (
            patch.dict("os.environ", {"BPS_API_KEY": "test-key"}),
            patch.object(bps_tools._http, "get", return_value=response),
        ):
            payload = bps_tools._get("list/model/var")

        self.assertEqual(payload["status"], "OK")
        self.assertEqual(payload["data"], [{"pages": 1}, []])

    def test_invalid_json_and_server_error_raise_bps_error(self):
        invalid_json = httpx.Response(
            200,
            content=b"not-json",
            request=httpx.Request("GET", "https://webapi.bps.go.id/test"),
        )
        server_error = self._response(503, {"status": "ERROR"})
        with patch.dict("os.environ", {"BPS_API_KEY": "test-key"}):
            with patch.object(bps_tools._http, "get", return_value=invalid_json):
                with self.assertRaises(bps_tools.BpsError):
                    bps_tools._get("list/model/var")
            with patch.object(bps_tools._http, "get", return_value=server_error):
                with self.assertRaises(bps_tools.BpsError):
                    bps_tools._get("list/model/var")

    def test_network_error_raises_bps_error(self):
        request = httpx.Request("GET", "https://webapi.bps.go.id/test")
        with (
            patch.dict("os.environ", {"BPS_API_KEY": "test-key"}),
            patch.object(
                bps_tools._http,
                "get",
                side_effect=httpx.ConnectError("connection failed", request=request),
            ),
        ):
            with self.assertRaises(bps_tools.BpsError):
                bps_tools._get("list/model/var")

    def test_variable_search_strips_region_and_uses_synonym_fallback(self):
        with (
            patch.object(
                bps_tools,
                "_variable_index",
                return_value={
                    "variables": [
                        {"var_id": "10", "title": "Jumlah Penduduk Miskin", "unit": "Orang"},
                        {"var_id": "11", "title": "Jumlah Penduduk", "unit": "Orang"},
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
                        }
                    ]
                },
            ),
            patch.object(bps_tools, "_latest_year", return_value=("2025", 8, False)),
        ):
            result = bps_tools.cari_variabel("penganguran")

        self.assertEqual(result["status_hasil"], "ditemukan")
        self.assertEqual(result["hasil"][0]["var_id"], 20)

    def test_refresh_variable_index_loads_all_subjects_and_pages_without_notes(self):
        def fake_get(path):
            if path == "list/model/subject/domain/1600":
                return {
                    "status": "OK",
                    "data": [{"pages": 2}, [{"sub_id": "1", "sub_name": "Penduduk"}]],
                }
            if path == "list/model/subject/domain/1600/page/2":
                return {
                    "status": "OK",
                    "data": [{"pages": 2}, [{"sub_id": "2", "sub_name": "Tenaga Kerja"}]],
                }
            if path == "list/model/var/domain/1600/subject/1":
                return {
                    "status": "OK",
                    "data": [{"pages": 2}, [{
                        "var_id": "101",
                        "title": "Jumlah Penduduk",
                        "unit": "Jiwa",
                        "notes": "<p>encoded note</p>",
                    }]],
                }
            if path == "list/model/var/domain/1600/subject/1/page/2":
                return {
                    "status": "OK",
                    "data": [{"pages": 2}, [{"var_id": "102", "title": "Kepadatan Penduduk"}]],
                }
            if path == "list/model/var/domain/1600/subject/2":
                return {
                    "status": "OK",
                    "data": [{"pages": 1}, [{"var_id": "201", "title": "Upah Buruh"}]],
                }
            self.fail(f"Unexpected BPS path: {path}")

        with patch.object(bps_tools, "_get", side_effect=fake_get):
            index = bps_tools.refresh_variable_index()
        with bps_tools._cache_lock:
            bps_tools._cache.pop(bps_tools._variable_index_key(), None)

        self.assertEqual({row["var_id"] for row in index["variables"]}, {"101", "102", "201"})
        self.assertNotIn("notes", index["variables"][0])

    def test_static_table_search_reads_and_cleans_table_html(self):
        def fake_get(path):
            if "list/model/statictable/" in path and "/keyword/upah" in path:
                return {
                    "status": "OK",
                    "data": [{"pages": 1}, [{
                        "table_id": "42",
                        "title": "Upah Minimum Provinsi Sumatera Selatan",
                        "notes": "<p>long encoded notes</p>",
                    }]],
                }
            if "list/model/statictable/" in path:
                return {"status": "OK", "data": [{"pages": 1}, []]}
            if "view/model/statictable/" in path:
                return {
                    "status": "OK",
                    "data": {
                        "table": "<table><tr><th>Tahun</th><th>Upah</th></tr><tr><td>2025</td><td>3.500</td></tr></table>",
                        "notes": "<p>long encoded notes</p>",
                    },
                }
            self.fail(f"Unexpected BPS path: {path}")

        with patch.object(bps_tools, "_get", side_effect=fake_get):
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

    def test_notes_fields_are_removed_recursively(self):
        cleaned = bps_tools.strip_notes({
            "title": "example",
            "notes": "<p>encoded and long</p>",
            "nested": [{"NoTeS": "also removed", "value": 1}],
        })
        self.assertEqual(cleaned, {"title": "example", "nested": [{"value": 1}]})


if __name__ == "__main__":
    unittest.main()
