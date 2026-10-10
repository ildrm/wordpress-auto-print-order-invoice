#!/usr/bin/env python3
"""GPL-2.0-or-later local WCIP print agent. Python 3.10+, standard library only."""
import argparse
import base64
import hashlib
import json
import logging
import os
from pathlib import Path
import re
import sqlite3
import ssl
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROUTE = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,126}\Z")
TOKEN = re.compile(r"[a-f0-9]{64}\Z")
MAX_PDF = 20 * 1024 * 1024


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise urllib.error.HTTPError(req.full_url, code, "Redirect refused", headers, fp)


class Client:
    def __init__(self, config):
        self.base = config["api_url"].rstrip("/")
        parsed = urllib.parse.urlsplit(self.base)
        if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password or parsed.fragment:
            raise ValueError("api_url must be the HTTPS WordPress REST namespace URL")
        if parsed.query and set(urllib.parse.parse_qs(parsed.query)) != {"rest_route"}:
            raise ValueError("Only the WordPress rest_route query is allowed")
        username = config["username"]
        password = os.environ.get(config.get("password_env", "WCIP_AGENT_PASSWORD"), "")
        if not password or any(c in username for c in ":\r\n\0"):
            raise ValueError("Set the Application Password environment variable and a valid username")
        self.auth = "Basic " + base64.b64encode((username + ":" + password).encode()).decode()
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=config.get("ca_bundle"))))

    def url(self, path):
        parsed = urllib.parse.urlsplit(self.base)
        if parsed.query:
            route = urllib.parse.parse_qs(parsed.query)["rest_route"][0].rstrip("/") + path
            return urllib.parse.urlunsplit(parsed._replace(query=urllib.parse.urlencode({"rest_route": route})))
        return self.base + path

    def post(self, path, payload):
        req = urllib.request.Request(self.url(path), json.dumps(payload).encode(), headers={"Authorization": self.auth, "Content-Type": "application/json", "Cache-Control": "no-store"}, method="POST")
        with self.opener.open(req, timeout=90) as response:
            if response.status != 200 or response.headers.get_content_type() != "application/json":
                raise ValueError("Unexpected response")
            body = response.read(30 * 1024 * 1024 + 1)
            if len(body) > 30 * 1024 * 1024:
                raise ValueError("Response too large")
            value = json.loads(body)
            if not isinstance(value, dict): raise ValueError("Invalid JSON response shape")
            return value


def validate_job(job, route):
    if not isinstance(job, dict): raise ValueError("Invalid job shape")
    if type(job.get("id")) is not int or job["id"] < 1 or job.get("route") != route or type(job.get("copies")) is not int or not 1 <= job["copies"] <= 20:
        raise ValueError("Invalid job identity, route or copies")
    if not isinstance(job.get("token"), str) or not TOKEN.fullmatch(job["token"]):
        raise ValueError("Invalid claim token")
    value = job.get("pdf_base64")
    if not isinstance(value, str) or len(value) > ((MAX_PDF + 2) // 3) * 4:
        raise ValueError("Invalid PDF size")
    pdf = base64.b64decode(value, validate=True)
    if len(pdf) > MAX_PDF or not pdf.startswith(b"%PDF-") or hashlib.sha256(pdf).hexdigest() != job.get("sha256"):
        raise ValueError("PDF integrity check failed")
    return pdf


def command(config, copies, path):
    printer = config["printer"]
    if not isinstance(printer, str) or not printer or printer.startswith("-") or any(c in printer for c in "\0\r\n"):
        raise ValueError("Configure a named local printer")
    executable = str(Path(config["executable"]).resolve(strict=True))
    backend = config["backend"]
    if backend == "sumatra":
        if sys.platform != "win32":
            raise ValueError("The SumatraPDF backend requires Windows")
        return [executable, "-print-to", printer, "-print-settings", f"{copies}x,noscale", "-silent", str(path)]
    if backend == "cups":
        return [executable, "-d", printer, "-n", str(copies), "-o", "job-sheets=none", str(path)]
    raise ValueError("Backend must be sumatra or cups")


def spool(config, pdf, copies):
    # Only deployment-owned printer/executable settings enter argv. No shell or server command.
    with tempfile.TemporaryDirectory(prefix="wcip-agent-") as directory:
        path = Path(directory) / "document.pdf"
        path.write_bytes(pdf)
        try:
            result = subprocess.run(command(config, copies, path), shell=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=180, check=False)
        except ValueError:
            return "failed"  # Validation prevented starting the process.
        except OSError:
            return "failed"  # The process did not start.
        except subprocess.TimeoutExpired:
            return "unknown"
        # Spooler/driver failures can happen after partial output. Do not infer rejection.
        return "submitted" if result.returncode == 0 else "unknown"


class Journal:
    def __init__(self, path, namespace):
        self.db = sqlite3.connect(path, timeout=30)
        self.db.execute("PRAGMA synchronous=FULL")
        self.db.execute("CREATE TABLE IF NOT EXISTS jobs (site TEXT, id INTEGER, token TEXT, phase TEXT, outcome TEXT, PRIMARY KEY(site,id))")
        self.site = hashlib.sha256(namespace.encode()).hexdigest()
        self.db.commit()

    def save(self, job, phase, outcome=None):
        self.db.execute("INSERT INTO jobs VALUES(?,?,?,?,?) ON CONFLICT(site,id) DO UPDATE SET token=excluded.token,phase=excluded.phase,outcome=excluded.outcome", (self.site, job["id"], job["token"], phase, outcome))
        self.db.commit()

    def existing(self, job):
        return self.db.execute("SELECT phase FROM jobs WHERE site=? AND id=?", (self.site, job["id"])).fetchone()

    def pending(self):
        return self.db.execute("SELECT id,token,phase,outcome FROM jobs WHERE site=? AND phase NOT IN ('done','abandoned')", (self.site,)).fetchall()


def flush_receipts(client, journal):
    for id_, token, phase, outcome in journal.pending():
        job = {"id": id_, "token": token}
        # A restart during start/printing is never permission to spool again.
        outcome = outcome or "unknown"
        journal.save(job, "starting" if phase == "starting" else "receipt", outcome)
        try:
            response = client.post(f"/agent/jobs/{id_}/receipt", {"token": token, "outcome": outcome})
            if response.get("recorded") is not True:
                raise ValueError("Receipt not recorded")
            journal.save(job, "done", outcome)
        except urllib.error.HTTPError as error:
            if error.code in (403, 409):
                # Server refused/expired this identity; retain the local evidence for review.
                journal.save(job, "abandoned" if phase == "starting" else "done", outcome)
                logging.warning("Job %d receipt refused; inspect job history before any reprint", id_)
            else:
                raise


def cycle(client, journal, config):
    flush_receipts(client, journal)
    response = client.post("/agent/claim", {"route": config["route"]})
    job = response.get("job")
    if job is None:
        return
    pdf = validate_job(job, config["route"])
    existing = journal.existing(job)
    if existing and existing[0] != "abandoned":
        logging.error("Job %d already exists in durable journal; refusing a duplicate", job["id"])
        return
    journal.save(job, "starting")
    try:
        started = client.post(f"/agent/jobs/{job['id']}/start", {"token": job["token"]})
        if started.get("start") is not True:
            raise ValueError("No print authorization")
    except urllib.error.HTTPError as error:
        if error.code in (403, 409):
            journal.save(job, "abandoned", "failed")
            return
        raise
    journal.save(job, "printing")  # Commit before invoking any printer process.
    outcome = spool(config, pdf, job["copies"])
    journal.save(job, "receipt", outcome)
    flush_receipts(client, journal)
    logging.info("Job %d: %s; physical output still requires operator confirmation", job["id"], outcome)


def main():
    if os.name != "nt": os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", required=True, type=Path)
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    config = json.loads(args.config.read_text(encoding="utf-8"))
    if not ROUTE.fullmatch(config["route"]):
        parser.error("Invalid logical queue route")
    # Resolve local paths relative to the config, including under Windows Task Scheduler.
    for key in ("executable", "journal", "ca_bundle"):
        if config.get(key): config[key] = str((args.config.resolve().parent / config[key]).resolve())
    command(config, 1, Path(tempfile.gettempdir()) / "wcip-validation.pdf")
    client = Client(config)
    # An exclusive local lock prevents two polling processes from sharing one journal.
    lock = open(config["journal"] + ".lock", "a+b")
    if sys.platform == "win32":
        import msvcrt
        lock.seek(0); lock.write(b"0"); lock.flush(); lock.seek(0)
        msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
    else:
        import fcntl
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    journal = Journal(config["journal"], client.base)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    while True:
        try:
            cycle(client, journal, config)
        except (urllib.error.URLError, ValueError, OSError, sqlite3.Error):
            logging.error("Agent request or local storage failed; retained journal will be checked on the next cycle")
        if args.once: break
        time.sleep(max(3, min(300, int(config.get("poll_seconds", 10)))))
    journal.db.close(); lock.close()


if __name__ == "__main__":
    main()
