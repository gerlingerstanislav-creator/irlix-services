import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from clients_purge_log_summary import summarize


class ClientsPurgeLogSummaryTests(unittest.TestCase):
    def test_redacts_personal_data_and_keeps_sqlstate(self):
        logs = [
            '2026-11-10 production.ERROR SQLSTATE[23503] failing row for '
            'aleksandr@example.com constraint "client_members_employee_fk"',
            '2026-11-10 #3 /app/app/Services/EmployeePurgeService.php(154)',
        ]
        fingerprint, dates = summarize(logs)
        rendered = repr(fingerprint)
        self.assertIn('SQLSTATE=23503', rendered)
        self.assertIn('client_members_employee_fk', rendered)
        self.assertIn('EmployeePurgeService:154', rendered)
        self.assertNotIn('aleksandr@example.com', rendered)
        self.assertNotIn('failing row', rendered)
        self.assertEqual(len(dates), 2)

    def test_does_not_print_ordinary_log_rows(self):
        fingerprint, _ = summarize(['2026-11-10 User with email secret@example.com logged in'])
        self.assertFalse(fingerprint)


if __name__ == '__main__':
    unittest.main()
