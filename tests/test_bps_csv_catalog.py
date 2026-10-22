import csv
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import httpx

import bps_csv_catalog
import bps_data_store
from mysql_test_adapter import MySQLStorageTestConnection


class BpsCsvCatalogTests(unittest.TestCase):
    def setUp(self):
        self._database_directory = tempfile.TemporaryDirectory()
        database_path = str(Path(self._database_directory.name) / "bps_test.db")
        self._database_patch = patch.object(
            bps_data_store,
            "init_db",
            side_effect=lambda: MySQLStorageTestConnection(database_path),
        )
        self._database_patch.start()
        with bps_csv_catalog._lock:
            bps_csv_catalog._catalog_cache = None

    def tearDown(self):
        self._database_patch.stop()
        self._database_directory.cleanup()

    def _write_catalog(self, path: Path, rows: list[dict[str, str]]) -> None:
        fields = [
            "kategori_subjek",
            "subjek",
            "id_subjek",
            "judul",
            "id_tabel",
            "jenis_tabel",
            "tahun",
            "link_json",
        ]
        with path.open("w", encoding="utf-8-sig", newline="") as target:
            writer = csv.DictWriter(target, fieldnames=fields)
            writer.writeheader()
            writer.writerows(rows)

    def _row(self, link: str) -> dict[str, str]:
        return {
            "kategori_subjek": "Sosial",
            "subjek": "Penduduk",
            "id_subjek": "1",
            "judul": "Jumlah Penduduk",
            "id_tabel": "262",
            "jenis_tabel": "Tabel Dinamis",
            "tahun": "2025",
            "link_json": link,
        }

    def test_load_catalog_skips_missing_links_and_year_sentinel(self):
        valid = self._row(
            "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/262/th/125/key/"
        )
        with tempfile.TemporaryDirectory() as directory:
            catalog_path = Path(directory) / "catalog.csv"
            self._write_catalog(catalog_path, [
                valid,
                self._row("TAHUN_TIDAK_TERSEDIA"),
                {**self._row(""), "judul": "Tidak ada link"},
            ])
            with patch.object(bps_csv_catalog, "_catalog_path", return_value=catalog_path):
                loaded = bps_csv_catalog.load_catalog()

        self.assertEqual([row["judul"] for row in loaded], ["Jumlah Penduduk"])

    def test_catalog_file_changes_are_reloaded(self):
        first = self._row(
            "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/262/th/125/key/"
        )
        second = {**first, "judul": "Penduduk Menurut Jenis Kelamin", "id_tabel": "642"}
        with tempfile.TemporaryDirectory() as directory:
            catalog_path = Path(directory) / "catalog.csv"
            self._write_catalog(catalog_path, [first])
            with patch.object(bps_csv_catalog, "_catalog_path", return_value=catalog_path):
                self.assertEqual(bps_csv_catalog.load_catalog()[0]["judul"], "Jumlah Penduduk")
                self._write_catalog(catalog_path, [second])
                self.assertEqual(
                    bps_csv_catalog.load_catalog()[0]["judul"],
                    "Penduduk Menurut Jenis Kelamin",
                )

    def test_fetch_uses_environment_key_and_caches_without_exposing_key(self):
        row = self._row(
            "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/262/th/125/key/"
        )
        request = httpx.Request(
            "GET",
            "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/262/th/125/key/test-key/",
        )
        response = httpx.Response(200, json={"status": "OK", "data": []}, request=request)
        with (
            patch.dict("os.environ", {"BPS_API_KEY": "test-key"}),
            patch.object(bps_csv_catalog._http, "get", return_value=response) as get,
        ):
            first = bps_csv_catalog.fetch_json(row)
            second = bps_csv_catalog.fetch_json(row)

        self.assertEqual(first, second)
        self.assertEqual(get.call_count, 1)
        self.assertIn("/key/test-key/", get.call_args.args[0])
        connection = bps_csv_catalog.bps_data_store.init_db()
        try:
            stored = connection.execute(
                "SELECT link_json, isi_json FROM data_tabel WHERE id_tabel=? AND tahun=?",
                ("262", "2025"),
            ).fetchone()
        finally:
            connection.close()
        self.assertNotIn("test-key", stored["link_json"])
        self.assertNotIn("test-key", stored["isi_json"])

    def test_fetch_rejects_missing_key_and_non_catalog_urls(self):
        row = self._row(
            "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/262/key/"
        )
        with patch.dict("os.environ", {"BPS_API_KEY": ""}):
            with self.assertRaisesRegex(bps_csv_catalog.CatalogError, "belum dikonfigurasi"):
                bps_csv_catalog.fetch_json(row)

        with patch.dict("os.environ", {"BPS_API_KEY": "test-key"}):
            for link in (
                "http://webapi.bps.go.id/v1/api/list/model/data/key/",
                "https://example.com/v1/api/list/model/data/key/",
                "https://webapi.bps.go.id/v1/api/list/model/data/key/?key=unsafe",
                "TAHUN_TIDAK_TERSEDIA",
            ):
                with self.subTest(link=link):
                    with self.assertRaises(bps_csv_catalog.CatalogError):
                        bps_csv_catalog.fetch_json({**row, "link_json": link})

    def test_fetch_reports_network_invalid_json_and_http_errors(self):
        row = self._row(
            "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/262/th/125/key/"
        )
        def clear_failure_log():
            connection = bps_data_store.init_db()
            try:
                connection.execute(
                    "DELETE FROM log_gagal WHERE id_tabel=? AND tahun=?",
                    ("262", "2025"),
                )
                connection.commit()
            finally:
                connection.close()

        with patch.dict("os.environ", {"BPS_API_KEY": "test-key"}):
            with patch.object(
                bps_csv_catalog._http,
                "get",
                side_effect=httpx.ConnectError(
                    "connection failed",
                    request=httpx.Request("GET", "https://webapi.bps.go.id"),
                ),
            ):
                with self.assertRaisesRegex(bps_csv_catalog.CatalogError, "tidak dapat diakses"):
                    bps_csv_catalog.fetch_json(row)
            clear_failure_log()

            invalid_json = httpx.Response(
                200,
                content=b"not-json",
                request=httpx.Request("GET", "https://webapi.bps.go.id"),
            )
            with patch.object(bps_csv_catalog._http, "get", return_value=invalid_json):
                with self.assertRaisesRegex(bps_csv_catalog.CatalogError, "JSON yang tidak valid"):
                    bps_csv_catalog.fetch_json(row)
            clear_failure_log()

            denied = httpx.Response(
                401,
                json={"status": "ERROR"},
                request=httpx.Request("GET", "https://webapi.bps.go.id"),
            )
            with patch.object(bps_csv_catalog._http, "get", return_value=denied):
                with self.assertRaisesRegex(bps_csv_catalog.CatalogError, "API key"):
                    bps_csv_catalog.fetch_json(row)

    def test_editorial_list_uses_environment_key_filters_and_one_hour_cache(self):
        request = httpx.Request(
            "GET",
            "https://webapi.bps.go.id/v1/api/list/model/pressrelease/lang/ind/"
            "domain/1600/year/2024/month/3/page/2/keyword/inflasi+Sumsel/key/test-key/",
        )
        response = httpx.Response(
            200,
            json={"status": "OK", "data": [{"pages": 1}, []]},
            request=request,
        )
        with (
            patch.dict("os.environ", {"BPS_API_KEY": "test-key"}),
            patch.object(bps_csv_catalog._http, "get", return_value=response) as get,
        ):
            first = bps_csv_catalog.fetch_editorial_json(
                "pressrelease",
                keyword="inflasi Sumsel",
                year=2024,
                month=3,
                page=2,
            )
            second = bps_csv_catalog.fetch_editorial_json(
                "pressrelease",
                keyword="inflasi Sumsel",
                year=2024,
                month=3,
                page=2,
            )

        self.assertEqual(first, second)
        self.assertEqual(get.call_count, 1)
        self.assertIn("/keyword/inflasi+Sumsel/key/test-key/", get.call_args.args[0])
        connection = bps_csv_catalog.bps_data_store.init_db()
        try:
            stored = connection.execute(
                "SELECT cache_key, isi_json FROM editorial_cache"
            ).fetchone()
        finally:
            connection.close()
        self.assertNotIn("test-key", stored["cache_key"])
        self.assertNotIn("test-key", stored["isi_json"])

    def test_editorial_detail_endpoint_and_invalid_parameters(self):
        request = httpx.Request(
            "GET",
            "https://webapi.bps.go.id/v1/api/view/model/news/lang/ind/id/123/key/test-key/",
        )
        response = httpx.Response(
            200,
            json={"status": "OK", "data": [{"news_id": "123", "abstract": "Ringkasan"}]},
            request=request,
        )
        with (
            patch.dict("os.environ", {"BPS_API_KEY": "test-key"}),
            patch.object(bps_csv_catalog._http, "get", return_value=response) as get,
        ):
            payload = bps_csv_catalog.fetch_editorial_json("news", identifier="123")
            cached_payload = bps_csv_catalog.fetch_editorial_json("news", identifier="123")

        self.assertEqual(payload["status"], "OK")
        self.assertEqual(payload, cached_payload)
        self.assertEqual(get.call_count, 1)
        self.assertIn("/view/model/news/lang/ind/id/123/key/test-key/", get.call_args.args[0])

        with patch.dict("os.environ", {"BPS_API_KEY": "test-key"}):
            for kwargs in (
                {"model": "data"},
                {"model": "publication", "month": 13},
                {"model": "publication", "year": "2024/25"},
                {"model": "news", "identifier": "a/b"},
            ):
                model = kwargs.pop("model")
                with self.subTest(model=model, kwargs=kwargs):
                    with self.assertRaises(bps_csv_catalog.CatalogError):
                        bps_csv_catalog.fetch_editorial_json(model, **kwargs)

    def test_editorial_fetch_reports_missing_key_and_unavailable_api(self):
        with patch.dict("os.environ", {"BPS_API_KEY": ""}):
            with self.assertRaisesRegex(bps_csv_catalog.CatalogError, "belum dikonfigurasi"):
                bps_csv_catalog.fetch_editorial_json("publication")

        request = httpx.Request("GET", "https://webapi.bps.go.id")
        unavailable = httpx.Response(
            200,
            json={"status": "OK", "data": []},
            headers={"data-availability": "list-not-available"},
            request=request,
        )
        with (
            patch.dict("os.environ", {"BPS_API_KEY": "test-key"}),
            patch.object(bps_csv_catalog._http, "get", return_value=unavailable),
        ):
            result = bps_csv_catalog.fetch_editorial_json("publication")
        self.assertEqual(result, {"status": "OK", "data": [{"pages": 1}, []]})

    def test_editorial_fetch_reports_network_invalid_json_and_bad_key_errors(self):
        with patch.dict("os.environ", {"BPS_API_KEY": "test-key"}):
            with patch.object(
                bps_csv_catalog._http,
                "get",
                side_effect=httpx.ConnectError(
                    "connection failed",
                    request=httpx.Request("GET", "https://webapi.bps.go.id"),
                ),
            ):
                with self.assertRaisesRegex(bps_csv_catalog.CatalogError, "tidak dapat diakses"):
                    bps_csv_catalog.fetch_editorial_json("news")

            invalid_json = httpx.Response(
                200,
                content=b"not-json",
                request=httpx.Request("GET", "https://webapi.bps.go.id"),
            )
            with patch.object(bps_csv_catalog._http, "get", return_value=invalid_json):
                with self.assertRaisesRegex(bps_csv_catalog.CatalogError, "JSON yang tidak valid"):
                    bps_csv_catalog.fetch_editorial_json("news")

            denied = httpx.Response(
                403,
                json={"status": "ERROR"},
                request=httpx.Request("GET", "https://webapi.bps.go.id"),
            )
            with patch.object(bps_csv_catalog._http, "get", return_value=denied):
                with self.assertRaisesRegex(bps_csv_catalog.CatalogError, "API key"):
                    bps_csv_catalog.fetch_editorial_json("news")


if __name__ == "__main__":
    unittest.main()
