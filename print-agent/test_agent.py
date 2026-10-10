import base64
import hashlib
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import urllib.error

spec = importlib.util.spec_from_file_location("agent", Path(__file__).with_name("wcip_agent.py"))
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


class AgentTests(unittest.TestCase):
    def job(self):
        pdf = b"%PDF-1.4\nfixture"
        return {"id": 17, "route": "Office", "copies": 2, "token": "a" * 64, "pdf_base64": base64.b64encode(pdf).decode(), "sha256": hashlib.sha256(pdf).hexdigest()}

    def test_pdf_integrity_and_bounded_copy_validation(self):
        job = self.job()
        self.assertTrue(agent.validate_job(job, "Office").startswith(b"%PDF-"))
        for key, value in (("route", "Another"), ("copies", True), ("copies", 21), ("sha256", "0" * 64), ("token", "bad"), ("pdf_base64", "not-base64")):
            bad = dict(job, **{key: value})
            with self.assertRaises(ValueError): agent.validate_job(bad, "Office")

    def test_windows_command_uses_named_printer_copies_and_no_shell(self):
        with tempfile.NamedTemporaryFile() as exe, patch.object(agent.sys, "platform", "win32"):
            config = {"backend": "sumatra", "executable": exe.name, "printer": "Warehouse A4"}
            command = agent.command(config, 2, Path("invoice.pdf"))
            self.assertEqual(command[1:], ["-print-to", "Warehouse A4", "-print-settings", "2x,noscale", "-silent", "invoice.pdf"])

    def test_unix_command_and_untrusted_option_like_printer(self):
        with tempfile.NamedTemporaryFile() as exe:
            config = {"backend": "cups", "executable": exe.name, "printer": "Office"}
            self.assertEqual(agent.command(config, 3, Path("invoice.pdf"))[1:], ["-d", "Office", "-n", "3", "-o", "job-sheets=none", "invoice.pdf"])
            config["printer"] = "-d Another"
            with self.assertRaises(ValueError): agent.command(config, 1, Path("invoice.pdf"))

    def test_process_timeout_and_nonzero_exit_never_claim_rejection(self):
        with tempfile.NamedTemporaryFile() as exe:
            config = {"backend": "cups", "executable": exe.name, "printer": "Office"}
            with patch.object(agent.subprocess, "run", side_effect=subprocess.TimeoutExpired("lp", 180)):
                self.assertEqual(agent.spool(config, b"%PDF-1.4", 1), "unknown")
            with patch.object(agent.subprocess, "run", return_value=subprocess.CompletedProcess([], 1)):
                self.assertEqual(agent.spool(config, b"%PDF-1.4", 1), "unknown")

    def test_restart_during_printing_reports_unknown_without_spooling_again(self):
        with tempfile.TemporaryDirectory() as d:
            journal = agent.Journal(str(Path(d) / "jobs.sqlite3"), "https://shop.test")
            journal.save(self.job(), "printing")
            journal.db.close()
            journal = agent.Journal(str(Path(d) / "jobs.sqlite3"), "https://shop.test")
            class Client:
                def post(self, path, payload):
                    self.payload = payload
                    return {"recorded": True}
            client = Client()
            with patch.object(agent, "spool") as spool:
                agent.flush_receipts(client, journal)
                spool.assert_not_called()
            self.assertEqual(client.payload["outcome"], "unknown")
            self.assertEqual(journal.existing(self.job()), ("done",))
            journal.db.close()

    def test_receipt_network_loss_preserves_success_for_retry(self):
        with tempfile.TemporaryDirectory() as d:
            journal = agent.Journal(str(Path(d) / "jobs.sqlite3"), "https://shop.test")
            journal.save(self.job(), "receipt", "submitted")
            class Client:
                def post(self, path, payload): raise urllib.error.URLError("offline")
            with self.assertRaises(urllib.error.URLError): agent.flush_receipts(Client(), journal)
            self.assertEqual(journal.pending()[0][3], "submitted")
            journal.db.close()

    def test_redirect_and_plain_http_are_refused(self):
        with self.assertRaises(ValueError): agent.Client({"api_url": "http://shop.test", "username": "agent"})
        with self.assertRaises(urllib.error.HTTPError): agent.NoRedirect().redirect_request(urllib.request.Request("https://shop.test"), None, 302, "Found", {}, "https://other.test")

    def test_lost_start_ack_never_calls_the_printer(self):
        with tempfile.TemporaryDirectory() as d:
            journal = agent.Journal(str(Path(d) / "jobs.sqlite3"), "https://shop.test")
            job = self.job()
            class Client:
                def post(self, path, payload):
                    if path == "/agent/claim": return {"job": job}
                    raise urllib.error.URLError("lost start acknowledgement")
            with patch.object(agent, "spool") as spool:
                with self.assertRaises(urllib.error.URLError): agent.cycle(Client(), journal, {"route": "Office"})
                spool.assert_not_called()
            self.assertEqual(journal.existing(job), ("starting",))
            journal.db.close()

    def test_plain_wordpress_rest_route_url_supported(self):
        client = object.__new__(agent.Client)
        client.base = "https://shop.test/?rest_route=/wc-invoice-printer/v1"
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(client.url("/agent/claim")).query)["rest_route"], ["/wc-invoice-printer/v1/agent/claim"])


if __name__ == "__main__": unittest.main()
