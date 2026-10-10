"""Run only in the disposable Docker fixture after agent.php prepare. No physical printer."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

if os.environ.get('WCIP_RUN_AGENT_TEST') != '1': raise SystemExit('Explicit opt in required')
ROOT = Path('/var/www/html/wp-content/plugins/wc-invoice-printer')
spec = importlib.util.spec_from_file_location('agent', ROOT/'print-agent/wcip_agent.py')
agent = importlib.util.module_from_spec(spec); spec.loader.exec_module(agent)
auth = json.loads(Path('/tmp/wcip-agent-auth.json').read_text())
os.environ['WCIP_AGENT_PASSWORD'] = auth['password']
config = {'api_url':'https://wordpress/?rest_route=/wc-invoice-printer/v1', 'username':auth['username'], 'route':'Office', 'backend':'cups', 'printer':'WCIP_Virtual', 'executable':'/usr/bin/lp', 'ca_bundle':'/tmp/wcip-agent-tls.pem'}
client = agent.Client(config)
count = 0
def check(value, message):
    global count
    if not value: raise AssertionError(message)
    count += 1
def refused(call, status):
    try: call()
    except urllib.error.HTTPError as error: check(error.code == status, 'Wrong denial status'); return
    raise AssertionError('Unauthorized request succeeded')
def phase(name):
    subprocess.run(['wp','--allow-root','eval-file',str(ROOT/'tests/Integration/agent.php')], env=dict(os.environ,WCIP_AGENT_PHASE=name), check=True, stdout=subprocess.DEVNULL)
def claim():
    time.sleep(3.1)
    return client.post('/agent/claim', {'route':'Office'})['job']

refused(lambda: client.post('/agent/claim', {'route':'Other'}), 403)
refused(lambda: client.post('/print', {'order_ids':[1],'template_id':'classic','provider_id':'agent','printer_id':'Office','copies':1}), 403)
first = claim(); check(first['id'] == auth['jobs'][0], 'FIFO job'); check(agent.validate_job(first, 'Office').startswith(b'%PDF-'), 'Real PDF/hash')
refused(lambda: client.post(f"/agent/jobs/{first['id']}/receipt",{'token':first['token'],'outcome':'submitted'}), 409)
refused(lambda: client.post(f"/agent/jobs/{first['id']}/start",{'token':'b'*64}), 403)
phase('expire')
second = claim(); check(second['id'] == first['id'] and second['token'] != first['token'], 'Expired prepared lease safely reclaims')
refused(lambda: client.post(f"/agent/jobs/{first['id']}/start",{'token':first['token']}), 403)
check(client.post(f"/agent/jobs/{second['id']}/start",{'token':second['token']})['start'], 'Print authorization')
refused(lambda: client.post(f"/agent/jobs/{second['id']}/start",{'token':second['token']}), 409)
for _ in range(2): check(client.post(f"/agent/jobs/{second['id']}/receipt",{'token':second['token'],'outcome':'submitted'})['recorded'], 'Idempotent success receipt')
refused(lambda: client.post(f"/agent/jobs/{second['id']}/receipt",{'token':second['token'],'outcome':'unknown'}), 409)
automatic = claim(); check(automatic['id'] == auth['jobs'][1], 'Automatic job')
phase('invalidate')
refused(lambda: client.post(f"/agent/jobs/{automatic['id']}/start",{'token':automatic['token']}), 409)

with tempfile.TemporaryDirectory(prefix='wcip-agent-check-') as directory:
    journal = agent.Journal(str(Path(directory)/'journal.sqlite3'), client.base)
    time.sleep(3.1)
    agent.cycle(client, journal, config)
    check(journal.db.execute('SELECT outcome FROM jobs WHERE id=?',(auth['jobs'][2],)).fetchone() == ('submitted',), 'Agent spooled real PDF to virtual CUPS queue')
    # Simulate a crash after local dispatch without a recorded result.
    lost = claim(); check(lost['id'] == auth['jobs'][3], 'Crash fixture job')
    check(client.post(f"/agent/jobs/{lost['id']}/start",{'token':lost['token']})['start'], 'Crash start authorized')
    journal.save(lost,'printing'); journal.db.close()
    journal = agent.Journal(str(Path(directory)/'journal.sqlite3'), client.base)
    agent.flush_receipts(client,journal)
    check(journal.existing(lost) == ('done',), 'Restart reported unknown without replay')
    journal.db.close()
check(claim() is None, 'No completed/uncertain jobs replayed')
print(f'PASS: {count} HTTPS Application Password / WordPress / agent / virtual CUPS assertions')
