# Cross platform automatic printing

WooCommerce Invoice Printer 1.4.0 separates the WordPress host from the printer computer. Install the same production plugin ZIP on a Windows, Linux, macOS or other host that meets the declared WordPress, WooCommerce, PHP, database and PDF-extension requirements. Shared hosting, a VPS and a dedicated server use the same plugin. The WordPress host does not install CUPS, SumatraPDF or Python and does not execute printer commands.

For Windows printer workstations and shared hosting, use the bundled open-source **Print agent**. It runs on an existing computer that can use the intended printer and makes outbound HTTPS requests to WordPress. No inbound shop-network connection, public printer port, VPN, hosting shell access, hosting daemon or commercial printing account is required. A browser tab need not stay open. CUPS direct HTTPS printing remains an optional destination for installations where WordPress can reach a CUPS server. Browser printing remains manual.

## Prerequisites and operating systems

| Component | Requirements |
| --- | --- |
| WordPress host | WordPress 6.6+, WooCommerce 9.0+, PHP 7.4+, production dependencies, GD/mbstring, a writable private temporary directory and database migration privileges. HTTPS REST requests with the Authorization header and Application Password authentication must be permitted. |
| Windows print computer | Python 3.10+ and maintained SumatraPDF, an installed printer queue, its existing platform driver, Internet access and a logged-in account able to use that printer. The agent and SumatraPDF are open-source and have no mandatory service or license fees. |
| Linux or macOS print computer | Python 3.10+, CUPS and its `lp` command, an installed PDF-capable queue and outbound HTTPS access. Use driverless printers or suitable open-source filters. |
| Other printer operating systems | Manual browser printing uses the platform's existing print dialog. Unattended printing needs Python 3.10+ and a tested supported `lp` backend, or a separately implemented open-source backend. Do not assume every operating system supplies a PDF spooler. |

Use the operating system and printer drivers already available in the requested deployment; the plugin does not introduce a paid printing application. Keep the workstation powered on and awake. A hosting policy that blocks plugin installation, required PHP extensions, HTTPS REST or Application Passwords must be changed by its administrator. No plugin can bypass a host's access policy or print to an offline computer. Normal agent polling also runs bounded missed-payment discovery, so this printing path does not depend on a WordPress cron daemon or loopback runner. Existing export workflows still use their documented scheduler.

Official references: [Python on Windows](https://docs.python.org/3/using/windows.html), [SumatraPDF printing commands](https://www.sumatrapdfreader.org/docs/Command-line-arguments), [SumatraPDF open-source license](https://github.com/sumatrapdfreader/sumatrapdf/blob/master/COPYING), [CUPS lp](https://openprinting.github.io/cups/doc/man-lp.html), [WordPress Application Passwords](https://developer.wordpress.org/advanced-administration/security/application-passwords/).

## Pair a workstation

1. Activate the 1.4.0 production plugin ZIP with WooCommerce active. It adds four nullable agent-delivery fields and a queue index to the existing job table; existing financial data, job destinations and confirmations remain intact. Schema and capability versions become 1.4.0; the retired-provider migration stamp remains 1.3.0. Upgrading from 1.3.0 preserves its CUPS configuration and destination. No queued CUPS job is redirected to the local agent.
2. In WordPress Users, create a dedicated user with the **Print agent** role. This role has `read` and `wcip_run_print_agent`, with no invoice creation, settings, order editing, financial, warehouse or paper-confirmation capability. Open this user's profile and generate a revocable Application Password. Keep it private; do not use its interactive login password in the agent.
3. In WooCommerce → Invoice Printer → Printers, save **Agent user ID** and **Agent queue**, for example `Office`. Find the ID in the user's edit URL (`user_id=...`). Copy the displayed REST namespace URL. Queue routes are exact, case-sensitive ASCII identifiers `[A-Za-z0-9][A-Za-z0-9_.-]{0,126}`. The current built-in configuration pairs one user and one route; use one agent process for that pairing.
4. Copy the standalone `print-agent` folder or extract the separate print-agent ZIP on the workstation. Copy `config.example.json` to `config.json`. Set `api_url` to the namespace URL displayed by WordPress, `username` to the dedicated login, `route` to the saved logical queue, and `printer` to the local operating-system printer name. Actual printer names may contain spaces and Unicode. The server sends only the logical route and copies; it cannot supply executable commands or choose another local printer.
5. Select the backend and absolute executable path. On Windows use `sumatra` and the path to `SumatraPDF.exe`. On Linux/macOS use `cups` and the path to `lp`, usually `/usr/bin/lp`. Set a durable, private journal path relative to `config.json` or as an absolute path. Preserve this journal through upgrades and restarts.
6. Set the user's `WCIP_AGENT_PASSWORD` environment variable to the Application Password, or use another environment-variable name in `password_env`. An optional `ca_bundle` references a deployment-owned PEM CA file; TLS verification is always enabled. Redirects are refused so credentials cannot be forwarded to a different destination. Store configuration and the SQLite journal outside web/shared folders and restrict their OS permissions to the printing account.
7. Run the agent once, then choose **Print agent** in an order's printing dialog and submit an intentional test job. Check the spooler and paper, including media, margins, copies and long multilingual text. Run the agent continuously after accepting the results. Finally select **Print agent** in Automatic Printing, choose the invoice template/copies, save, and enable automation. Validate one new confirmed-payment order. Historical orders still require reconciliation preview and explicit selection.

The dedicated agent can fetch only jobs addressed to the configured route and bound user. Protect its Application Password: it grants access to those PDFs, which contain customer information. Password revocation or account/route changes stop access. Check the Printers screen's **Last seen** timestamp and the durable local journal when investigating an interruption.

## Windows operation

Install Python and SumatraPDF from their official projects. Install and test the printer in Windows before starting the agent. Set the user environment variable once through Windows environment settings or a private administrator-controlled setup; avoid placing credentials in command-line arguments or shared scripts.

```powershell
py -3 C:\WCIP\wcip_agent.py --config C:\WCIP\config.json --once
py -3 C:\WCIP\wcip_agent.py --config C:\WCIP\config.json
```

Use Windows Task Scheduler to start the second command at that printing user's logon, using the full Python executable path and the two script/config arguments. Run one instance; set the task to restart after failure and to run only when that user is logged on. This preserves that account's printer access. Turn off sleep during required printing hours. Task Scheduler is an existing Windows facility; no additional commercial scheduler is required. A short-lived one-shot task is also available, but a continuous process reduces polling delay.

The agent uses SumatraPDF's named-printer command, explicit copies and `noscale`, with no interactive print dialog. A successful process exit records spooler submission, not physical completion. Windows queue names, default media, driver permissions and PDF rendering must be accepted on the intended workstation. No Windows binary or printer driver is bundled or purchased by the plugin.

## Linux and macOS operation

```sh
python3 /opt/wcip/wcip_agent.py --config /opt/wcip/config.json --once
python3 /opt/wcip/wcip_agent.py --config /opt/wcip/config.json
```

Use a restricted service account that can print. Start the continuous command through the platform's normal service manager (`systemd` on Linux or `launchd` on macOS), with the Application Password environment variable supplied privately. `lp -d queue -n copies` submits PDFs to the local CUPS spooler. The WordPress host's operating system is independent of this choice.

## Delivery states and recovery

Agent jobs remain **queued** without a connected workstation. Claiming a job atomically records a random token hash, user, preparation phase and ten-minute lease. The returned PDF is capped at 20 MiB and verified locally against its SHA-256 digest. Downloading it alone does not authorize printing. The agent must call `start` with that claim token, and the server rechecks automatic-payment eligibility and lease state immediately before authorizing dispatch.

The local SQLite journal commits before starting the printer process. A zero process exit is reported as **submitted**, still awaiting explicit physical confirmation. A process timeout or nonzero exit is **unknown** because partial or prior output is possible. Only validation/process-start failures are reported **failed**. The agent deletes its private temporary PDF after the process; it retains job identity and receipt evidence, not PDF snapshots, in its journal. System spooler retention is managed separately.

Expired prepared claims can return to queued because their start authorization can no longer succeed. Expired started claims become unknown and are never automatically replayed. A lost receipt is retried idempotently from the journal. Restarting during printing reports unknown without invoking the printer again. A delayed valid receipt can resolve its own unknown job; it cannot authorize a different claim. Preserve the journal and inspect WordPress history, the local spooler and paper before a deliberate manual reprint. Never clear a journal to force automatic replay.

## Developer contracts

The push provider interface remains unchanged for CUPS/extensions. `agent` is a pull-delivery path, rather than a registry provider pretending to have accepted a physical print. `PrintJobService` persists agent jobs without Action Scheduler; `PrintWorker` and `Scheduler` leave them to the paired agent. Settings keys are `automatic_provider`, `agent_queue` and `agent_user_id`. Manual REST `/print` accepts `provider_id: "agent"` and the configured logical queue, alongside `cups`. Browser preparation remains its existing protected workflow.

| Method and route under wc-invoice-printer/v1 | Authentication and behavior |
| --- | --- |
| POST `/agent/claim` | HTTPS, actual WordPress Application Password, `wcip_run_print_agent`, paired user and exact route; returns at most one PDF/claim or `job: null`. |
| POST `/agent/jobs/{id}/start` | Same account, route and claim token; only unexpired prepared jobs start, once. Automatic orders are rechecked. |
| POST `/agent/jobs/{id}/receipt` | Same claim identity; outcome `submitted`, `unknown` or `failed`; duplicate identical receipts are acknowledged without replay or repeated observers. |

Cookie login alone cannot access these routes. Agent polls are rate-limited and discovery uses the existing database lease and bounded native WooCommerce queries. Responses disable caching. Maintain authentication, token ownership, atomic state changes, bounds and the durable pre-dispatch journal when extending the agent. New printer backends must be open-source and require no mandatory service/license/per-job fees.

## Verification boundary

Executed tests, exact release checks and environment limits are recorded in [the remediation record](../REMEDIATION_TRACEABILITY.md). Command construction and recovery are tested locally; the workflow configures Windows/Linux/macOS agent test jobs. Configured CI jobs do not imply that they were run in this session. No Windows host or physical printer is available in the current test environment; accept the native Windows spooler and actual paper before production unattended printing. The code and protocol remain usable on the documented operating systems without introducing a commercial print service.
