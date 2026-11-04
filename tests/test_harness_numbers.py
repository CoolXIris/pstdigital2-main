import unittest

from test_harness import parse_indonesian_number


class IndonesianNumberTests(unittest.TestCase):
    def test_numeric_inputs_are_preserved_before_locale_parsing(self):
        self.assertEqual(parse_indonesian_number(0), 0.0)
        self.assertEqual(parse_indonesian_number(0.0), 0.0)
        self.assertEqual(parse_indonesian_number(1.234), 1.234)

    def test_indonesian_formatted_strings_keep_locale_rules(self):
        self.assertEqual(parse_indonesian_number("1.234,5"), 1234.5)
        self.assertEqual(parse_indonesian_number("1.234"), 1234.0)


if __name__ == "__main__":
    unittest.main()
