import unittest
from unittest.mock import Mock

import audit_link


class AuditLinkTests(unittest.TestCase):
    def test_empty_simdasi_payload_is_classified_as_empty(self):
        self.assertFalse(audit_link._has_payload_data({
            "status": "OK",
            "data": [{"page": 1}, {"kolom": {"x": {}}, "data": []}],
        }))
        self.assertTrue(audit_link._has_payload_data({
            "status": "OK",
            "data": [{"page": 1}, {"kolom": {"x": {}}, "data": [{"label": "Lahat"}]}],
        }))

    def test_audit_reports_http_status_without_logging_request_url(self):
        response = Mock(
            status_code=403,
            headers={},
        )
        client = Mock()
        client.get.return_value = response

        status, detail = audit_link._check_link(
            client,
            "https://webapi.bps.go.id/v1/api/list/model/data/key/",
            "test-secret",
            retries=0,
            retry_delay=0,
        )

        self.assertEqual(status, "gagal")
        self.assertEqual(detail, "HTTP 403")
        self.assertIn("test-secret", client.get.call_args.args[0])
        self.assertNotIn("test-secret", detail)


if __name__ == "__main__":
    unittest.main()
