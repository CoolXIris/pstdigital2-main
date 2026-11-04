import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from dump_bps_catalog import Catalog, CatalogError, run


class CatalogExportTests(unittest.TestCase):
    def test_api_error_is_retried_and_not_returned_as_an_empty_page(self):
        response = Mock()
        response.raise_for_status.return_value = None
        response.json.return_value = {"status": "ERROR"}
        client = Mock()
        client.get.return_value = response
        catalog = Catalog(client, "test-key", "1600")

        with patch("dump_bps_catalog.time.sleep"):
            with self.assertRaises(CatalogError):
                catalog.pages("list/model/subject/domain/1600")

        self.assertEqual(client.get.call_count, 3)

    def test_successful_empty_page_is_distinct_from_an_api_error(self):
        response = Mock()
        response.raise_for_status.return_value = None
        response.json.return_value = {"status": "OK", "data": [{"pages": 1}, []]}
        client = Mock()
        client.get.return_value = response

        self.assertEqual(Catalog(client, "test-key", "1600").pages("list/model/subject/domain/1600"), [])

    def test_request_failure_does_not_overwrite_existing_catalog_files(self):
        class FailingCatalog:
            domain = "1600"

            def pages(self, path):
                if path == "list/model/subject/domain/1600":
                    return [{"sub_id": "1", "title": "Subject"}]
                raise CatalogError("request failed")

            def latest_year(self, var_id):
                return "", 0

            def total(self, model):
                return "0"

        with tempfile.TemporaryDirectory() as directory:
            paths = [
                Path(directory) / f"katalog_{name}_1600.{extension}"
                for name, extension in (
                    ("subjek", "csv"),
                    ("variabel", "csv"),
                    ("ringkasan", "txt"),
                )
            ]
            for path in paths:
                path.write_text("existing catalog\n", encoding="utf-8")

            with self.assertRaises(CatalogError):
                run(FailingCatalog(), with_years=False, workers=1, out_dir=directory)

            self.assertEqual([path.read_text(encoding="utf-8") for path in paths], ["existing catalog\n"] * 3)


if __name__ == "__main__":
    unittest.main()
