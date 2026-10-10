=== WooCommerce Invoice Printer ===
Contributors: ildrm
Tags: woocommerce, invoice, print, cups, pdf
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later

Browser and free cross-platform automatic invoice printing for WooCommerce.

== Description ==

WooCommerce Invoice Printer creates invoices from WooCommerce's public order APIs and supports HPOS. Administrators and Shop Managers can preview and print individual or bulk invoices. Automatic printing persists idempotent jobs. The paired local agent polls for work; direct CUPS uses Action Scheduler. Checkout never waits for PDF generation or printing.

Local printers use the browser dialog without accounts or subscriptions. Unattended printing uses the open-source local agent: Python 3.10+ with SumatraPDF on Windows or CUPS lp on Linux/macOS. The printer computer connects outbound to WordPress over HTTPS. Shared hosting, VPS and dedicated servers use the same plugin without hosting-side printer software. A reachable HTTPS CUPS server remains optional. No commercial application, cloud account or paid printing service is required.

The paired workstation or CUPS server receives the generated invoice PDF when selected. This may include customer names, addresses, contact details, and purchased items. Protect workstation/server access, the Application Password and the agent journal, and include them in your data-retention procedures.

Translations are included for English, Persian, Turkish, Arabic, French, German, Russian, Spanish, Portuguese (Portugal and Brazil), Armenian, Hindi, Simplified Chinese, and Japanese. Select the site language in WordPress settings or your admin language in your user profile. Persian/Arabic invoices use RTL layouts; multilingual PDF fonts are bundled with mPDF.

Automatic printing is disabled by default. Confirmed-payment policy requires paid status and a paid date; native offline collection methods require an authoritative collection adapter. Discovery starts at enable/upgrade time; historical printing requires explicit preview and selection.

Local spooler or CUPS acceptance is not confirmation of paper output. The agent requires an awake computer and permitted HTTPS REST/Application Password authentication. Interrupted started jobs are held as unknown; its journal prevents replay after a restart. HTTP 429 rejections receive bounded safe retries. Transport errors, HTTP 408/5xx submission responses, and unreadable successful responses are unknown and require checking CUPS before intentionally reprinting.

The plugin does not claim jurisdiction-specific fiscal or tax compliance. WooCommerce order numbers are displayed as invoice references. See README.md for complete configuration, recovery, API, extension, and testing documentation.

== Installation ==

1. Run `composer install --no-dev --optimize-autoloader` before packaging, or install a release archive that includes vendor dependencies.
2. Upload and activate the plugin.
3. Open WooCommerce > Invoice Printer.
4. Configure invoice identity and a template.
5. For a local printer, open Printers > Open local test page, click Print, and choose your device in the browser dialog. Use Browser print dialog for individual or bulk invoices.
6. For Windows or shared hosting, create a dedicated Print agent user and Application Password, pair its ID/queue in Printers, configure and run the local agent continuously, and test paper before enabling automation. See docs/cross-platform-printing.md for Windows startup and recovery.
7. For optional direct CUPS, save the HTTPS server origin and optional username/password, configure a trusted private host and CA in wp-config.php if needed, refresh queues and test paper output.

== Data retention ==

Deactivation cancels pending plugin actions and preserves settings/history for recovery on reactivation. Stop local agents before uninstall. Uninstall removes operator capabilities and preserves data and the paired-agent role unless the `wcip_delete_data_on_uninstall` option is explicitly enabled by an operator. Print jobs retain operational references and sanitized errors, but no duplicated invoice/customer payload.

== Changelog ==

= 1.4.0 =
* Add an open-source outbound print agent for Windows (SumatraPDF) and Linux/macOS (CUPS lp).
* Support shared hosting, VPS and dedicated WordPress hosts without hosting-side printer software or shell commands.
* Add scoped Application Password pairing, atomic claim/start/receipt, expiring leases and durable local crash protection.
* Preserve CUPS settings, existing job destinations/history, browser printing and explicit paper confirmation.
* Update all fourteen catalogs and translated DOCX development guides.


= 1.3.0 =
* Remove the commercial cloud-printing integration and use direct HTTPS IPP with self-hosted OpenPrinting CUPS.
* Add named CUPS queues, optional Basic authentication, deployment-managed password/host/CA and bounded binary response validation.
* Purge retired credentials, disable legacy automatic setup and cancel old queued cloud jobs without changing confirmed history.
* Preserve uncertain-result protection, browser printing, payment reconciliation and physical confirmation.
* Update all fourteen language catalogs and DOCX guides. No subscription required.


= 1.2.0 =
* Confirmed-payment policy, bounded delayed-payment reconciliation and explicit historical selection.
* Independent shipping labels and packing lists, document-aware print states and warehouse fulfillment.
* Opaque codes, authenticated scanning, weight provenance and signed generic exports.
* Seven formats, additional operational tables and fourteen multilingual development guides.

= 1.1.2 =
* Protected browser confirmation, local test pages, translation and compatibility fixes.
* The former commercial printing backend in this historical release is retired by 1.3.0.
