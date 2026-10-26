import base64
import csv
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from update_bps import sync_catalog


def encoded(value: str) -> str:
    return base64.b64encode(value.encode()).decode()


class FakeResponse:
    def __init__(self, payload: dict):
        self.payload = payload

    def raise_for_status(self) -> None:
        return None

    def json(self) -> dict:
        return self.payload


class FakeClient:
    def __init__(self, periods: dict[str, list[dict[str, str]]]):
        self.periods = periods

    def get(self, url: str) -> FakeResponse:
        variable_id = url.split("/var/", 1)[1].split("/", 1)[0]
        items = self.periods[variable_id]
        return FakeResponse({
            "status": "OK",
            "data": [{"pages": 1}, items],
        })


class FailedClient:
    def get(self, url: str) -> FakeResponse:
        return FakeResponse({"status": "ERROR"})


class UpdateBpsTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.csv_path = Path(self.temp_dir.name) / "catalog.csv"
        self.fieldnames = [
            "kategori_subjek", "subjek", "id_subjek", "judul", "id_tabel",
            "jenis_tabel", "tahun", "link_json", "id_raw", "url_halaman",
            "custom_column",
        ]
        rows = [
            {
                "kategori_subjek": "Sosial",
                "subjek": "Lama",
                "id_subjek": "1",
                "judul": "Judul sebelum sinkronisasi",
                "id_tabel": "185",
                "jenis_tabel": "Tabel Statis",
                "tahun": "",
                "link_json": "old static endpoint",
                "id_raw": "",
                "url_halaman": "",
                "custom_column": "keep-static",
            },
            {
                "kategori_subjek": "Sosial",
                "subjek": "Pendidikan",
                "id_subjek": "2",
                "judul": "Variabel lama",
                "id_tabel": "798",
                "jenis_tabel": "Tabel Dinamis",
                "tahun": "2020",
                "link_json": "https://webapi.bps.go.id/v1/api/list/model/data/domain/1600/var/798/th/120/key/",
                "id_raw": "",
                "url_halaman": "",
                "custom_column": "keep-dynamic",
            },
            {
                "kategori_subjek": "Sosial",
                "subjek": "Pendidikan",
                "id_subjek": "2",
                "judul": "Tabel SIMDASI lama",
                "id_tabel": "sim-old",
                "jenis_tabel": "SIMDASI",
                "tahun": "2022",
                "link_json": "https://webapi.bps.go.id/v1/api/interoperabilitas/datasource/simdasi/id/25/wilayah/1600000/tahun/2022/id_tabel/sim-old/key/",
                "id_raw": "",
                "url_halaman": "",
                "custom_column": "keep-simdasi",
            },
            {
                "kategori_subjek": "Arsip",
                "subjek": "Lama",
                "id_subjek": "9",
                "judul": "CSV only row",
                "id_tabel": "999",
                "jenis_tabel": "Tabel Statis",
                "tahun": "",
                "link_json": "legacy endpoint",
                "id_raw": "",
                "url_halaman": "",
                "custom_column": "must-remain",
            },
        ]
        with self.csv_path.open("w", encoding="utf-8-sig", newline="") as target:
            writer = csv.DictWriter(target, fieldnames=self.fieldnames)
            writer.writeheader()
            writer.writerows(rows)

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def test_sync_updates_existing_rows_adds_missing_tables_and_keeps_csv_only_rows(self) -> None:
        tables = [
            {
                "id": encoded("185#1"),
                "title": "Judul resmi terbaru",
                "tablesource": "1",
                "id_subject": "11",
                "subject": "Subjek terkini",
                "id_subcat": "10",
                "subcat_name": "Kategori terkini",
            },
            {
                "id": encoded("500#1"),
                "title": "Tabel statis baru",
                "tablesource": "1",
                "id_subject": "11",
                "subject": "Subjek terkini",
                "id_subcat": "10",
                "subcat_name": "Kategori terkini",
            },
            {
                "id": encoded("798#2"),
                "title": "Variabel lama",
                "tablesource": "2",
                "latest_period": "2022",
                "id_subject": "2",
                "subject": "Pendidikan",
                "id_subcat": "1",
                "subcat_name": "Sosial",
            },
            {
                "id": encoded("900#2"),
                "title": "Variabel dinamis baru",
                "tablesource": "2",
                "latest_period": "2023",
                "id_subject": "2",
                "subject": "Pendidikan",
                "id_subcat": "1",
                "subcat_name": "Sosial",
            },
            {
                "id": encoded("sim-old#3#1600"),
                "title": "Tabel SIMDASI lama",
                "tablesource": "3",
                "latest_period": "2023",
                "id_subject": "2",
                "subject": "Pendidikan",
                "id_subcat": "1",
                "subcat_name": "Sosial",
            },
            {
                "id": encoded("sim-new#3#1600"),
                "title": "Tabel SIMDASI baru",
                "tablesource": "3",
                "latest_period": "2024",
                "id_subject": "2",
                "subject": "Pendidikan",
                "id_subcat": "1",
                "subcat_name": "Sosial",
            },
        ]
        periods = {
            "798": [
                {"th": "2020", "th_id": "120"},
                {"th": "2021", "th_id": "121"},
                {"th": "2022", "th_id": "122"},
            ],
            "900": [
                {"th": "2022", "th_id": "122"},
                {"th": "2023", "th_id": "123"},
            ],
        }

        summary = sync_catalog(
            self.csv_path,
            tables,
            FakeClient(periods),
            "test-key",
            "1600",
        )

        with self.csv_path.open(encoding="utf-8-sig", newline="") as source:
            rows = list(csv.DictReader(source))
        by_id_year = {(row["id_tabel"], row["tahun"]): row for row in rows}

        self.assertEqual(summary["added_tables"], 3)
        self.assertEqual(summary["added_periods"], 6)
        self.assertGreater(summary["updated_rows"], 0)
        self.assertIn(("999", ""), by_id_year)
        self.assertEqual(by_id_year[("999", "")]["custom_column"], "must-remain")
        self.assertEqual(by_id_year[("185", "")]["judul"], "Judul resmi terbaru")
        self.assertEqual(by_id_year[("500", "")]["jenis_tabel"], "Tabel Statis")
        self.assertIn(("798", "2021"), by_id_year)
        self.assertIn(("798", "2022"), by_id_year)
        self.assertIn(("900", "2022"), by_id_year)
        self.assertIn(("900", "2023"), by_id_year)
        self.assertIn(("sim-old", "2023"), by_id_year)
        self.assertIn(("sim-new", "2024"), by_id_year)

        repeat_summary = sync_catalog(
            self.csv_path,
            tables,
            FakeClient(periods),
            "test-key",
            "1600",
        )
        self.assertEqual(repeat_summary["added_tables"], 0)
        self.assertEqual(repeat_summary["added_rows"], 0)
        self.assertEqual(repeat_summary["added_periods"], 0)
        self.assertEqual(repeat_summary["updated_rows"], 0)

    def test_sync_failure_leaves_csv_unchanged(self) -> None:
        original = self.csv_path.read_bytes()
        table = {
            "id": encoded("900#2"),
            "title": "Tabel dinamis baru",
            "tablesource": "2",
            "latest_period": "2023",
        }

        with patch("update_bps.time.sleep"):
            with self.assertRaisesRegex(RuntimeError, "gagal memuat periode"):
                sync_catalog(self.csv_path, [table], FailedClient(), "test-key", "1600")

        self.assertEqual(self.csv_path.read_bytes(), original)


if __name__ == "__main__":
    unittest.main()
