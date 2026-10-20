import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import httpx

import bps_data_store
import bps_csv_catalog
import bps_tools
from mysql_test_adapter import MySQLStorageTestConnection


class BpsDataStoreTests(unittest.TestCase):
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

    def _dynamic_payload(self):
        regions = [
            ("1600", "Sumatera Selatan"),
            ("1601", "Ogan Komering Ulu"),
            ("1602", "Ogan Komering Ilir"),
            ("1603", "Muara Enim"),
            ("1604", "Lahat"),
            ("1605", "Musi Rawas"),
            ("1606", "Musi Banyuasin"),
            ("1607", "Banyuasin"),
            ("1608", "Ogan Komering Ulu Selatan"),
            ("1609", "Ogan Komering Ulu Timur"),
            ("1610", "Ogan Ilir"),
            ("1611", "Empat Lawang"),
            ("1612", "Pali"),
            ("1613", "Musi Rawas Utara"),
            ("1671", "Palembang"),
            ("1672", "Prabumulih"),
            ("1673", "Pagar Alam"),
            ("1674", "Lubuklinggau"),
        ]
        categories = [
            ("190", "SD"),
            ("191", "SMP"),
            ("192", "SMA"),
            ("193", "SMK"),
            ("194", "Diploma"),
            ("195", "Sarjana"),
            ("196", "Lainnya"),
        ]
        content = {}
        for region_id, region in regions:
            for category_id, category in categories:
                value = 4024 if (region, category) == ("Lahat", "SD") else 1864
                content[f"{region_id}362{category_id}1250"] = value
        return {
            "status": "OK",
            "var": [{
                "val": 362,
                "label": "Jumlah Guru",
                "unit": "Orang",
                "subj": "Pendidikan",
                "note": "<p>Catatan <strong>tabel</strong> BPS</p>",
            }],
            "vervar": [{"val": region_id, "label": label} for region_id, label in regions],
            "turvar": [{"val": category_id, "label": label} for category_id, label in categories],
            "tahun": [{"val": 125, "label": "2025"}],
            "datacontent": content,
            "labelvervar": "Kabupaten/Kota",
            "last_update": "2026-10-01",
        }

    def test_dynamic_values_decode_all_combinations_and_query_sd_for_two_regions(self):
        connection = bps_data_store.init_db()
        try:
            count = bps_data_store.simpan_nilai(
                connection,
                self._dynamic_payload(),
                table_id="362",
            )
            connection.commit()
            self.assertEqual(count, 18 * 7)

            matched_regions = bps_data_store.deteksi_wilayah(
                connection,
                "362",
                "guru SD di Pali dan Lahat tahun 2025",
            )
            matched_categories = bps_data_store.deteksi_kategori(
                connection,
                "362",
                "guru SD di Pali dan Lahat tahun 2025",
            )
            result = bps_data_store.cari_nilai(
                connection,
                "362",
                wilayah=matched_regions,
                tahun=["2025"],
                turvar=matched_categories,
            )
        finally:
            connection.close()

        self.assertEqual(matched_regions, ["Pali", "Lahat"])
        self.assertEqual(matched_categories, ["SD"])
        self.assertEqual(
            {row["wilayah"]: row["nilai"] for row in result["baris"]},
            {"Lahat": 4024.0, "Pali": 1864.0},
        )
        self.assertEqual(result["meta"]["satuan"], "Orang")
        self.assertEqual(result["meta"]["last_update_bps"], "2026-10-01")
        self.assertEqual(result["meta"]["catatan"], "Catatan tabel BPS")

    def test_category_detection_matches_multiple_labels_in_one_request(self):
        connection = bps_data_store.init_db()
        try:
            bps_data_store.simpan_nilai(
                connection,
                self._dynamic_payload(),
                table_id="362",
            )
            connection.commit()
            categories = bps_data_store.deteksi_kategori(
                connection,
                "362",
                "jenjang SMP dan SD",
            )
        finally:
            connection.close()

        self.assertEqual(set(categories), {"SD", "SMP"})

    def test_chat_tool_reads_guru_sd_for_pali_and_lahat_from_persistent_storage(self):
        row = {
            "id_tabel": "362",
            "jenis_tabel": "Tabel Dinamis",
            "tahun": "2025",
            "judul": "Jumlah Guru Menurut Jenjang Pendidikan",
            "link_json": (
                "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/"
                "var/362/th/125/key/"
            ),
        }
        request = httpx.Request(
            "GET",
            "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/"
            "var/362/th/125/key/test-key/",
        )
        response = httpx.Response(200, json=self._dynamic_payload(), request=request)
        with (
            patch.dict(os.environ, {"BPS_API_KEY": "test-key"}),
            patch.object(bps_tools, "_periods", return_value=[{"th": "2025", "th_id": "125"}]),
            patch.object(bps_tools, "_dynamic_table_row", return_value=row),
            patch.object(bps_csv_catalog._http, "get", return_value=response) as get,
        ):
            lahat = bps_tools.ambil_data(362, tahun="2025", wilayah="Lahat", kategori="SD")
            pali = bps_tools.ambil_data(362, tahun="2025", wilayah="PALI", kategori="SD")

        self.assertEqual(lahat["data"][0]["nilai"], 4024)
        self.assertEqual(lahat["data"][0]["wilayah"], "Lahat")
        self.assertEqual(pali["data"][0]["nilai"], 1864)
        self.assertEqual(pali["data"][0]["wilayah"], "Pali")
        self.assertEqual(lahat["last_update_bps"], "2026-10-01")
        self.assertEqual(get.call_count, 1)

    def test_chat_tool_filters_multiple_categories_for_sumsel_and_two_regencies(self):
        row = {
            "id_tabel": "362",
            "jenis_tabel": "Tabel Dinamis",
            "tahun": "2025",
            "judul": "Jumlah Guru (Jiwa)",
            "link_json": (
                "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/"
                "var/362/th/125/key/"
            ),
        }
        request = httpx.Request(
            "GET",
            "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/"
            "var/362/th/125/key/test-key/",
        )
        response = httpx.Response(200, json=self._dynamic_payload(), request=request)
        with (
            patch.dict(os.environ, {"BPS_API_KEY": "test-key"}),
            patch.object(bps_tools, "_periods", return_value=[{"th": "2025", "th_id": "125"}]),
            patch.object(bps_tools, "_dynamic_table_row", return_value=row),
            patch.object(bps_csv_catalog._http, "get", return_value=response) as get,
        ):
            regional = {
                region: bps_tools.ambil_data(
                    362,
                    tahun="2025",
                    wilayah=region,
                    kategori="SMP dan SD",
                )
                for region in ("Pali", "Lahat")
            }
            sumsel = bps_tools.ambil_data(
                362,
                tahun="2025",
                wilayah="Sumatera Selatan",
                kategori="SMP",
            )

        for region, result in regional.items():
            self.assertEqual(
                {row["kategori"] for row in result["data"]},
                {"SD", "SMP"},
            )
            self.assertTrue(all(row["wilayah"] == region for row in result["data"]))
        self.assertEqual(
            [(row["wilayah"], row["kategori"]) for row in sumsel["data"]],
            [("Sumatera Selatan", "SMP")],
        )
        self.assertEqual(get.call_count, 1)

    def test_read_through_uses_ttl_upserts_without_removing_historical_years(self):
        connection = bps_data_store.init_db()
        calls = []
        try:
            first_row = {
                "id_tabel": "101",
                "jenis_tabel": "Tabel Dinamis",
                "tahun": "2025",
                "judul": "Penduduk",
                "link_json": "https://webapi.bps.go.id/v1/api/list/model/data/key/",
            }
            first_payload = {"status": "OK", "data": [{"marker": "2025"}]}
            fetcher = lambda: calls.append("fetch") or first_payload
            self.assertEqual(
                bps_data_store.ambil_data(connection, first_row, fetcher, RuntimeError),
                first_payload,
            )
            self.assertEqual(
                bps_data_store.ambil_data(connection, first_row, fetcher, RuntimeError),
                first_payload,
            )
            self.assertEqual(calls, ["fetch"])

            older_row = {**first_row, "tahun": "2024"}
            bps_data_store.ambil_data(
                connection,
                older_row,
                lambda: {"status": "OK", "data": [{"marker": "2024"}]},
                RuntimeError,
            )
            records = connection.execute(
                "SELECT tahun FROM data_tabel WHERE id_tabel=? ORDER BY tahun",
                ("101",),
            ).fetchall()
        finally:
            connection.close()

        self.assertEqual([record["tahun"] for record in records], ["2024", "2025"])

    def test_expired_cache_falls_back_to_stored_values_and_reports_staleness(self):
        connection = bps_data_store.init_db()
        row = {
            "id_tabel": "262",
            "jenis_tabel": "Tabel Dinamis",
            "tahun": "2025",
            "judul": "Jumlah Penduduk",
            "link_json": "https://webapi.bps.go.id/v1/api/list/model/data/key/",
        }
        try:
            original = {"status": "OK", "data": [{"source": "stored"}]}
            bps_data_store.ambil_data(connection, row, lambda: original, RuntimeError)
            connection.execute(
                "UPDATE data_tabel SET diambil_pada=? WHERE id_tabel=? AND tahun=?",
                (0, "262", "2025"),
            )
            connection.commit()
            result = bps_data_store.ambil_data(
                connection,
                row,
                lambda: (_ for _ in ()).throw(RuntimeError("API unavailable")),
                RuntimeError,
            )
        finally:
            connection.close()

        self.assertEqual(result["data"], [{"source": "stored"}])
        self.assertIn("mungkin bukan yang terbaru", result["_catatan"])

    def test_ttl_uses_seven_ninety_and_thirty_days(self):
        self.assertEqual(bps_data_store.ttl_detik("Tabel Statis", ""), 30 * 86400)
        self.assertEqual(bps_data_store.ttl_detik("Tabel Dinamis", "2026", 2026), 7 * 86400)
        self.assertEqual(bps_data_store.ttl_detik("SIMDASI", "2025", 2026), 7 * 86400)
        self.assertEqual(bps_data_store.ttl_detik("Tabel Dinamis", "2024", 2026), 90 * 86400)


if __name__ == "__main__":
    unittest.main()
