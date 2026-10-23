import unittest
import json

from sumber_link import hapus_link_model, link_konten, link_tabel, slug_judul, susun_sumber
import main


class SumberLinkTests(unittest.TestCase):
    def test_slug_preserves_each_replacement_hyphen(self):
        self.assertEqual(
            slug_judul("Jumlah Fasilitas Kesehatan. (Unit)"),
            "jumlah-fasilitas-kesehatan---unit-",
        )
        self.assertEqual(slug_judul("Desa/Kelurahan"), "desa-kelurahan")

    def test_table_link_uses_page_url_then_raw_id_then_subject_fallback(self):
        self.assertEqual(
            link_tabel({
                "url_halaman": "https://sumsel.bps.go.id/id/statistics-table/2/raw/title.html",
                "jenis_tabel": "Tabel Dinamis",
            }),
            "https://sumsel.bps.go.id/id/statistics-table/2/raw/title.html",
        )
        self.assertEqual(
            link_tabel({
                "jenis_tabel": "SIMDASI",
                "id_raw": "YkhCUFJUaExVWGww=",
                "judul": "Jumlah Desa/Kelurahan",
            }),
            "https://sumsel.bps.go.id/id/statistics-table/3/YkhCUFJUaExVWGww=/jumlah-desa-kelurahan.html",
        )
        self.assertEqual(
            link_tabel({
                "status_link": "gagal",
                "id_subjek": "565",
                "id_raw": "YkhCUFJUaExVWGww=",
                "jenis_tabel": "SIMDASI",
                "judul": "Jumlah Desa/Kelurahan",
            }),
            "https://sumsel.bps.go.id/id/statistics-table?subject=565",
        )
        self.assertEqual(link_tabel({"url_halaman": "https://example.com/fake"}), "")

    def test_editorial_link_uses_api_pdf_and_verified_listing_fallback(self):
        links = link_konten(
            "pressrelease",
            {"title": "BRS", "pdf": "https://webapi.bps.go.id/download.php?f=hash"},
        )
        self.assertEqual(links["url"], "https://sumsel.bps.go.id/id/pressrelease")
        self.assertEqual(links["pdf_url"], "https://webapi.bps.go.id/download.php?f=hash")
        self.assertEqual(
            link_konten("news", {"title": "Berita"})["url"],
            "https://sumsel.bps.go.id/id/news",
        )
        self.assertEqual(
            link_konten("infographic", {"dl": "https://webapi.bps.go.id/download.php?f=hash"})["url"],
            "https://webapi.bps.go.id/download.php?f=hash",
        )

    def test_source_lines_are_deduplicated_and_model_urls_removed(self):
        source = {"judul": "Tabel A", "url": "https://sumsel.bps.go.id/id/statistics-table/2/a"}
        self.assertEqual(
            susun_sumber([source, source]),
            "Sumber:\n- [Tabel A](https://sumsel.bps.go.id/id/statistics-table/2/a)",
        )
        self.assertEqual(
            hapus_link_model("Lihat [BPS](https://example.com/x).\nSumber: https://fake.test"),
            "Lihat BPS.",
        )
        self.assertEqual(
            hapus_link_model("Isi.\nSumber/PDF:\n- [PDF](https://example.com/file.pdf)"),
            "Isi.",
        )
        self.assertEqual(
            susun_sumber([{
                "judul": "Tabel rahasia",
                "url": "https://webapi.bps.go.id/v1/key/do-not-leak/",
            }]),
            "",
        )

    def test_verified_source_is_appended_after_answer(self):
        result = main._answer_with_sources(
            "Jumlah rumah sakit adalah 72 unit.",
            [{
                "metadata_sumber": {
                    "jenis": "Tabel Dinamis",
                    "judul": "Jumlah Fasilitas Kesehatan",
                    "url": "https://sumsel.bps.go.id/id/statistics-table/2/id/jumlah-fasilitas.html",
                    "tahun": "2025",
                },
            }],
            tools_used=True,
        )

        self.assertEqual(
            result["reply"],
            "Jumlah rumah sakit adalah 72 unit.\n\nSumber:\n"
            "- [Jumlah Fasilitas Kesehatan](https://sumsel.bps.go.id/id/statistics-table/2/id/jumlah-fasilitas.html)",
        )

    def test_general_answer_does_not_get_a_source_line(self):
        result = main._answer_with_sources(
            "PDRB adalah nilai tambah barang dan jasa yang dihasilkan suatu wilayah.",
            [],
            tools_used=False,
        )

        self.assertEqual(result["reply"], "PDRB adalah nilai tambah barang dan jasa yang dihasilkan suatu wilayah.")
        self.assertNotIn("sources", result)

    def test_php_editorial_context_produces_a_trusted_source_and_pdf(self):
        item = {
            "judul": "BRS Sumatera Selatan",
            "tanggal": "2026-10-22",
            "tautan": "https://webapi.bps.go.id/download.php?f=verified-file",
        }
        for marker in (
            "[Sumber: WebAPI BPS; Berita Resmi Statistik]",
            "[Sumber: Berita Resmi Statistik BPS, kata kunci bencana]",
        ):
            with self.subTest(marker=marker):
                context = f"{marker}\n1. {json.dumps(item, ensure_ascii=False)}"
                sources = main._source_metadata_from_reference(
                    context,
                    "BRS terbaru",
                    "BRS Sumatera Selatan",
                )

                self.assertEqual(len(sources), 1)
                self.assertEqual(sources[0]["url"], "https://sumsel.bps.go.id/id/pressrelease")
                self.assertEqual(sources[0]["pdf_url"], item["tautan"])


if __name__ == "__main__":
    unittest.main()
