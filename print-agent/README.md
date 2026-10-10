# WCIP local print agent

This open-source agent connects a Windows, Linux or macOS printer computer to WooCommerce Invoice Printer 1.4.0. It polls WordPress over verified HTTPS, uses a WordPress Application Password and persists a private SQLite journal before printer dispatch. Python 3.10+ and its standard library are sufficient for the agent. Windows uses the open-source SumatraPDF application; Linux/macOS use CUPS `lp`. No paid cloud printing account or service is required.

See [cross platform setup and recovery](../docs/cross-platform-printing.md). Extract this folder outside any web directory, copy `config.example.json` to `config.json`, configure the paired user/route and local printer/executable, set `WCIP_AGENT_PASSWORD`, then run:

```sh
python3 wcip_agent.py --config config.json --once
python3 wcip_agent.py --config config.json
```

On Windows use `py -3` or the full Python executable path. To start automatically, use the printer account's logon task. Keep the computer awake. A workstation does not require an inbound network port or an open browser. HTTPS REST and Application Passwords must be permitted by the site's hosting policy.

Preserve the configured journal through restarts and upgrades. A successful local process exit means submitted to the spooler. It never confirms paper output. Inspect local spooler/history/paper before reprinting an unknown job. Do not run two processes for the same pairing or erase the journal to replay jobs. Secrets come from the configured environment variable and are never logged. Restrict local configuration, environment access and the journal to the printing account.

Tests: `python3 -m unittest discover -s print-agent -p test_agent.py` from the project root. Windows command tests validate argument construction; actual printer output needs native hardware acceptance.

The agent source is GPL-2.0-or-later, like the parent plugin. Python, SumatraPDF and CUPS retain their own open-source licenses; their binaries are not bundled. The standalone release archive also includes `docs/cross-platform-printing.md`, so its setup link remains usable after extraction.
