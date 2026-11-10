import unittest
from io import StringIO
from clients_http_500_summary import summarize

class Http500SummaryTest(unittest.TestCase):
    def test_redacts_sensitive_payload(self):
        raw = '2026-11-10 ERROR user private@example.com token=SECRET SQLSTATE[42703] column "missing_column" ClientsAccountingAccess.php:182'
        output = repr(summarize(StringIO(raw)))
        self.assertIn('42703', output)
        self.assertIn('ClientsAccountingAccess:182', output)
        self.assertNotIn('private@example.com', output)
        self.assertNotIn('SECRET', output)

    def test_unrelated_messages_ignored(self):
        self.assertEqual(summarize(StringIO('name email details\n')), {})

if __name__ == '__main__':
    unittest.main()
