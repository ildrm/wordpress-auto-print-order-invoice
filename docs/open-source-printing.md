# Open source printing and migration

WooCommerce Invoice Printer 1.4.0 uses free browser printing, a local open-source print agent and optional direct HTTPS IPP through self-hosted OpenPrinting CUPS. Use the [cross-platform agent setup](cross-platform-printing.md) for Windows printer workstations and shared hosting. WordPress itself installs on any supported host operating system and needs no printer software or shell execution. No commercial cloud service, paid API or subscription is required. Existing hardware, WordPress hosting and administration remain deployment prerequisites.

CUPS is open-source under Apache 2.0 with a GPL linking exception. Its IPP implementation supports printer discovery and PDF Print-Job submission. Sources: [OpenPrinting CUPS](https://openprinting.github.io/cups/), [CUPS IPP operations](https://openprinting.github.io/cups/doc/spec-ipp.html), [RFC 8010 encoding](https://www.rfc-editor.org/info/rfc8010/), [RFC 8011 operations](https://www.rfc-editor.org/info/rfc8011/).

## Browser printing

Open an invoice or the local test page and choose Print. The browser/operating system selects the printer. No CUPS server settings are necessary. The browser dialog requires user interaction; PDF preparation and opening that dialog do not confirm paper output. After inspecting the paper, explicitly confirm the plugin job.

## CUPS setup

1. Use a maintained OpenPrinting CUPS installation on an existing Linux or Unix-like print host. Add a PDF-capable printer queue and test locally with CUPS. Prefer driverless IPP Everywhere printers and open-source drivers/filters. A printer requiring a proprietary driver is outside the supported free-software deployment policy.
2. Connect WordPress to the print host over a private network or a self-hosted open-source VPN. WordPress sends the PDF to CUPS; no browser or printer computer cloud client runs. A server with no network route to CUPS cannot perform unattended printing.
3. Enable HTTPS and configure a server certificate matching the hostname. Trust its CA in WordPress or configure the optional deployment-owned PEM bundle. Keep TLS verification enabled. Restrict CUPS access to the WordPress host and a dedicated printing user; do not expose public anonymous printing. See [CUPS encryption](https://openprinting.github.io/cups/doc/encryption.html) and [server security](https://openprinting.github.io/cups/doc/security.html).
4. Open WooCommerce → Invoice Printer → Printers. Save the CUPS server origin, such as `https://cups.internal.example:631`, and username/password. Only ports 443/631 are accepted. Paths other than `/`, URL credentials, query strings and fragments are rejected. A free HTTPS reverse proxy on port 443 may forward the same origin paths to CUPS.
5. For a private host or port 631, explicitly allow that exact lowercase hostname in `WCIP_CUPS_TRUSTED_HOSTS` in wp-config.php. This deployment-owned authorization permits WordPress to call that host using its HTTP API. Ordinary database settings cannot bypass the public-address SSRF checks. The origin must resolve to your intended print server; control its DNS and network access.
6. Test connection, refresh printers, select the exact queue name and save. A name is case-sensitive ASCII: `[A-Za-z0-9][A-Za-z0-9_.-]{0,126}`. The plugin does not accept shell commands, arbitrary printer URLs or paths. Nonmatching queues must be renamed in CUPS. Queue discovery is bounded at 1,000 IPP attribute groups/10,000 attributes/2 MiB and cached for ten minutes by server and credentials.
7. Send a test page, then inspect paper size, margins, copies, density and offline behavior on the intended printer. Enable automation only after these checks and an eligible staging payment. WooCommerce Action Scheduler and a working cron runner remain necessary.

```php
// Optional deployment-owned configuration; keep secrets out of version control.
define( 'WCIP_CUPS_PASSWORD', 'deployment-managed-password' );
define( 'WCIP_CUPS_TRUSTED_HOSTS', array( 'cups.internal.example' ) );
define( 'WCIP_CUPS_CA_BUNDLE', '/absolute/path/to/cups-ca.pem' );
```

A nonempty string password constant overrides the saved password. The UI never echoes either password. A blank password field retains the saved value; use `SettingsRepository::update( array( 'cups_password' => '' ) )` for deliberate removal. Changing the server/username/password invalidates printer caches. Saved passwords are not encrypted by the plugin and may be present in database backups. An anonymous CUPS server works when its existing private-network policy restricts access; use authenticated printing for normal deployments. Authentication is HTTP Basic over verified TLS; Digest-only deployments need a dedicated Basic-over-TLS printing policy.

## Delivery and recovery

The plugin sends binary IPP/1.1 CUPS-Get-Printers and Print-Job messages directly through the WordPress HTTP API. Print-Job includes PDF bytes, exact queue URI, requested copies (1–20), title and attribute fidelity. No local shell command executes. PDF output is capped at 20 MiB. Response limits, Content-Type, lengths, request ID, status, group tags and positive job ID are validated. Provider errors never expose raw bodies or passwords.

Successful CUPS acceptance records its integer job ID and the plugin's `submitted` state. Acceptance does not prove physical output, nor does CUPS job completion necessarily prove the paper was collected. Explicit physical confirmation still controls `printed_at`, the private order note, printed filters and optional warehouse transitions. The plugin does not poll or cancel an already accepted CUPS job. Inspect/cancel that job in CUPS itself.

An HTTP 429 rejection can receive at most three total worker attempts. Definitive authentication/client/IPP client errors fail. Transport errors, 408/5xx, redirects, malformed success, missing job IDs or accepted-but-substituted print options become `unknown` and are never automatically replayed. Interrupted workers remain uncertain. Inspect the CUPS queue and paper before creating a deliberate reprint. Preserve the plugin's existing duplicate-payment, eligibility recheck, order history and cross-document confirmation controls.

## Upgrade from the retired cloud provider

Back up files/database, disable automation and stop its queue runner before changing plugin files. Deploy the current 1.4.0 release and allow activation/update migration to finish before resuming workers. PHP requests already running the old files cannot be recalled by a new release; stopping them is part of the upgrade.

Schema/capability versions are now 1.4.0; the 1.3.0 retirement migration remains separate. The independent `wcip_printing_migration_version=1.3.0` removes saved `printnode_api_key`, printer ID/name and their database printer transients, disables the legacy automatic setup, cancels queued `printnode` jobs and marks processing legacy jobs unknown. Submitted/confirmed/history/reference/order financial rows remain unchanged. No old job is translated into a CUPS destination or replayed. Remove the obsolete `WCIP_PRINTNODE_API_KEY` from deployment configuration and invalidate its old credential with its issuer. Persistent object caches may retain old transients until expiration; flush those through your own cache administration.

Configure the local agent or CUPS afresh when upgrading from the retired service. A 1.3.0 CUPS setup is preserved in 1.4.0. Preview and select historical orders explicitly using reconciliation; the normal enable/upgrade cutoff prevents mass printing. A cancelled legacy automatic job remains the order's automatic identity, so use an intentional manual CUPS print when needed. REST `/print` accepts `provider_id: "cups"` or `provider_id: "agent"`; old cloud identifiers are rejected. Existing browser, document, fulfillment and export routes/hooks are preserved. Custom clients must update provider/configuration fields.

Rollback means stop queues and agents, retain current job/journal evidence, and restore matching files and database backups. Do not restore and resume the removed commercial integration under the current project policy. If WordPress cannot reach CUPS, use the outbound local agent or manual browser workflow.

## Extension and document policy

Provider and export interfaces remain extensible. New adapters must use open-source software and execute without mandatory license, service, subscription or per-job fees. Keep physical confirmation, bounded inputs, protected routes and ambiguity handling. The built-in UI supports browser, CUPS and the local agent; `/print` queues CUPS or agent jobs, while browser preparation uses its existing protected route. Agent delivery has a separate claim/start/receipt protocol rather than the push provider interface. Registration alone does not add a selectable third-party destination. Use LibreOffice to edit the DOCX guides and preserve RTL/LTR paragraph/run/table metadata. The 14 catalogs and 14 guides cover every bundled locale.

## Verification evidence

Current executed tests and release checks are recorded in the remediation report. Real CUPS interoperability is verified with an isolated virtual IPP printer rather than physical hardware. No paper-output guarantee is implied. Run the staging hardware acceptance in step 7 before production unattended printing.
