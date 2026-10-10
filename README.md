# WooCommerce Invoice Printer

Print WooCommerce invoices manually or automatically through the free local print agent on Windows, Linux or macOS, or through a self-hosted CUPS server. The same WordPress plugin installs on supported Windows, Linux or other WordPress hosts, including shared hosting, VPS and dedicated servers. Automatic printing does not delay checkout and is **disabled by default**.

Features include HPOS and legacy order storage, A4/A5 invoices, 58/80 mm receipts, A6 shipping labels and packing lists, translations for 13 languages, RTL and multilingual PDF text, business identity and optional customer notes, individual/bulk printing, and persistent job history with duplicate prevention and conservative retries.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration and first print](#configuration-and-first-print)
- [Payment triggers](#payment-triggers)
- [Manual printing](#manual-printing)
- [Invoices and templates](#invoices-and-templates)
- [Languages](#languages)
- [Jobs, retries, and recovery](#jobs-retries-and-recovery)
- [Permissions and privacy](#permissions-and-privacy)
- [Storage and lifecycle](#storage-and-lifecycle)
- [REST API](#rest-api)
- [Developer extensions](#developer-extensions)
- [Development and testing](#development-and-testing)
- [Packaging](#packaging)
- [Troubleshooting](#troubleshooting)
- [Limitations](#limitations)

## Requirements

| Component | Requirement |
| --- | --- |
| WordPress | 6.6+ |
| WooCommerce | 9.0+, installed and active |
| PHP | 7.4+ |
| PDF output | Packaged Composer dependencies (mPDF), including its `mbstring`/`gd` extension requirements |
| Queue | Direct CUPS/export workflows use Action Scheduler and a cron runner. The local agent polls and runs bounded payment discovery without a hosting daemon or loopback runner. |
| Local printing | Printer installed in the operating system on the computer running your browser; no API key |
| Unattended printing | A paired local agent on an awake printer computer: Python 3.10+, SumatraPDF on Windows or CUPS `lp` on Linux/macOS. Direct HTTPS CUPS remains optional. No paid printing service. |
| Network | Agent makes outbound HTTPS requests to WordPress; HTTPS REST and Application Passwords must be allowed. Direct CUPS needs a WordPress-to-CUPS route on port 443/631. |
| Filesystem | Writable PHP temporary directory for private PDF working files |

Browser printing remains available without mPDF; PDF and automatic output need it. The WordPress host installs no Python, SumatraPDF, CUPS or printer driver. The printer computer may be on a different network. Composer itself is only needed to prepare dependencies, not on a correctly packaged production installation. Hosting policies and required PHP/extensions still apply.

Production and development dependencies support PHP 7.4. Composer resolves against PHP 7.4.33 through `config.platform.php`, so preparing a package on PHP 8 does not select dependencies that require PHP 8. Keep this platform setting when updating the lock file, and run `composer check-platform-reqs --no-dev` on the deployment runtime to verify its actual PHP/extensions.

## Installation

For a release archive, verify it includes `wc-invoice-printer.php`, `src/`, `templates/`, `assets/`, `languages/`, and `vendor/autoload.php`. Upload it through **Plugins → Add New Plugin → Upload Plugin**, or extract its folder into `wp-content/plugins/`. Activate WooCommerce, activate this plugin, and open **WooCommerce → Invoice Printer**.

For a source checkout, install production dependencies before uploading:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

Copy the whole folder, including `vendor/`, to `wp-content/plugins/wc-invoice-printer/`. Activation creates the job table and assigns capabilities. Back up the production database before upgrades.

## Configuration and first print

1. In **General**, set business name, contact information, logo URL, business/tax details, and whether customer notes should appear. The store address comes from WooCommerce store settings.
2. In **Templates**, choose the manual default and inspect normal/RTL sample previews.
For a locally connected USB or network printer, open **Printers → Local browser printing → Open local test page**. Click **Print** on that page and select the printer in your browser's dialog. For real orders, use **Print invoice → Browser print dialog**, or the same destination in bulk printing. Local printing is available immediately; no credential or printer settings need to be saved.

The CUPS list contains queues on your own CUPS server. Browser printer choice belongs to the operating system; refreshing CUPS does not discover USB devices on the browser computer.

For Windows or shared hosting, use **Print agent**. Create a dedicated WordPress user with that role and an Application Password; pair its user ID and logical queue in Printers. On the workstation, configure the bundled open-source agent with the displayed REST URL, that login/queue and the local printer name. Windows uses SumatraPDF; Linux/macOS use `lp`. Start the agent continuously, test one intentional job, then choose Print agent in Automatic Printing and enable it after checking paper. See [cross-platform setup, startup and recovery](docs/cross-platform-printing.md). Keep the workstation awake and preserve its private SQLite journal. No inbound printer port, VPN, hosting shell, paid account or open browser is required.

For a deployment with a reachable CUPS server, choose **CUPS open-source automatic printing**:

3. Install OpenPrinting CUPS on an existing Linux or Unix-like print host. Configure a PDF-capable queue, share it to the WordPress host and test local output. No commercial service or subscription is required.
4. Make CUPS reachable through a private network or an open-source VPN such as WireGuard. Use HTTPS with a certificate trusted by WordPress. Do not expose an unrestricted print server to the public internet.
5. In **Printers**, save the server origin (for example `https://cups.example.org:631`) and a dedicated CUPS username/password. An anonymous server is supported only when its private network and CUPS policy already restrict access. There is no cloud account or API key.
6. Test connection, refresh queues, select the exact queue name, and save. Send a test page and inspect actual paper, copies, media and margins.
7. Enable Automatic Printing only after the staging payment, queue and physical confirmation checks pass. Discovery is cached for ten minutes per server and credential configuration. A configured address does not prove connectivity.

See [the direct CUPS setup and migration guide](docs/open-source-printing.md). This optional path uses binary IPP over HTTPS without a commercial client or hosting shell execution. If WordPress cannot reach CUPS, use the outbound local agent.

### Server-managed password and network trust

In `wp-config.php`, before WordPress loads:

```php
define( 'WCIP_CUPS_PASSWORD', 'deployment-managed-password' );
define( 'WCIP_CUPS_TRUSTED_HOSTS', array( 'cups.internal.example' ) );
define( 'WCIP_CUPS_CA_BUNDLE', '/absolute/path/to/cups-ca.pem' );
```

The password constant is optional and overrides the stored password. Password fields remain blank; leave blank to retain the value. Remove a saved password through `SettingsRepository::update( array( 'cups_password' => '' ) )`. Stored passwords are not encrypted by the plugin; use deployment secrets and protected backups.

The exact, lowercase trusted-host allowlist is optional for a private/VPN host or port 631. It is deployment-owned and bypasses WordPress's public-address restriction only for that configured host. HTTPS, port/path validation, certificate verification, zero redirects and response bounds remain enforced. A custom PEM CA bundle is optional; its absence uses WordPress's CA trust. Never disable TLS validation.

## Payment triggers

Automation follows **payment confirmation**, not every order creation:

- `woocommerce_payment_complete` creates a job for an eligible paid order.
- A paid-status fallback handles gateways that mark an order paid without firing that hook.
- Default eligibility requires automatic printing enabled, a paid status with a recorded paid date, an invoice template and a configured agent pairing or CUPS destination. Native COD, bank transfer and cheque gateway classes are excluded from confirmed-payment semantics unless an adapter supplies an authoritative collection marker. The optional fulfillment policy is configured separately.
- One automatic job is permitted per order. Repeated/concurrent payment events and transaction IDs added/changed later reuse it; failed, unknown, or cancelled jobs are not silently replaced.
- A CUPS worker or an agent claim generates the PDF outside checkout. Automatic eligibility is checked again immediately before dispatch; agent downloads need an additional start authorization.
- Manual printing creates independent jobs and is intentionally repeatable.

WooCommerce generally considers `processing` and `completed` paid statuses. Offline gateways such as cash on delivery can assign `processing` before cash is collected. This plugin follows WooCommerce's state; it does not independently verify bank settlement or cash collection. For a payment-complete-hook-only workflow, disable the status fallback:

```php
add_filter( 'wcip_enable_paid_status_fallback', '__return_false' );
```

Use `wcip_payment_confirmed` with an authoritative collection marker and `wcip_payment_method_semantics` for gateway semantics; `wcip_automatic_print_eligible` remains a final veto. Verify how that gateway uses the payment-complete hook. Pending/on-hold orders remain ineligible until marked paid.

Enabling automation sets a discovery cutoff at that time. Historical payments require the explicit Reconciliation preview and selected-order confirmation, or manual printing. Disabling it stops new automatic jobs; the agent also refuses to start old automatic jobs while disabled. Already queued direct CUPS jobs follow their existing worker policy. Cancel unwanted queued jobs in history.

## Manual printing

In an order's **Invoice printing** panel, choose template, destination, and copies. **Preview** shows one copy without submitting a print job. **Print** opens the browser dialog or queues a CUPS/agent job. Bulk printing supports the same destinations. An order-list action also opens browser output.

On either the legacy or HPOS order list, select orders and choose **Print invoices** for bulk printing. Requests support up to 50 distinct orders and 1–20 copies per order. Missing, trashed, or checkout-draft orders are rejected before jobs are created. Browser output separates invoices/copies with page breaks; CUPS queues one job per selected order.

Manual printing can include unpaid orders and does not mutate payment or native order status. Optional fulfillment automation runs only after explicit invoice confirmation. Browser `submitted` means output was prepared; the browser cannot confirm whether the operator printed or cancelled. Allow popups and match the browser/printer paper settings.

After checking the paper output, choose **Confirm printed** on the browser invoice, in the order's Invoice printing box, or under the job's **View** details in **Print Jobs**. A combined browser document confirms all its selected orders together. CUPS jobs also require confirmation after checking the printer, since acceptance alone does not prove paper output. An uncertain submission can be confirmed when you have verified the paper; its delivery status remains `unknown`.

Confirmation adds a private order note with the job, destination, and copy count. It records the confirmation time, operator ID, and note ID in the plugin's job table. Repeating confirmation of the same job does not add another note. A later deliberate reprint has its own job and can add its own confirmation note.

The WooCommerce **Orders** list includes a **Printed** column on both legacy storage and HPOS. **Not printed** means an eligible order has no invoice job. Ineligible orders show a dash with an explanation. Queued, printing, awaiting confirmation, failed and unknown states are distinct; submitted jobs require explicit paper confirmation. A confirmed order stays marked printed even if a later reprint fails. The same confirmation status appears in **Print Jobs**.

Printing and confirmation do not save the order, change its creation/modification/payment dates, or replace the Orders list's sorting. The private note uses WooCommerce's note API without an order save. Print-job timestamps remain separate from order timestamps. Opening a preview records no print; using its **Print** link prepares a tracked browser job.

Bulk input is prevalidated, but a database/queue failure during creation may leave earlier valid jobs created. REST errors include created `job_ids`. Review history before repeating a whole selection to avoid duplicates.

## Invoices and templates

| ID | Layout | Paper |
| --- | --- | --- |
| `classic` | Detailed invoice and item amounts | A4 portrait |
| `compact` | Dense office-printer layout | A4 portrait |
| `thermal` | Narrow receipt | 80 mm PDF width; paginated 297 mm page height |
| `classic-a5` | Small invoice | A5 portrait |
| `thermal58` | Narrow receipt | 58 × 297 mm pages; choose QR for codes |
| `shipping-label` | Recipient label without prices | A6 portrait |
| `packing-list` | Items, quantities, recipient; no prices | A4 portrait |

Invoices use the WooCommerce order number as their reference. Data includes dates/status/currency, business and customer details, public item metadata, SKU where the product still exists, payment/shipping methods, order totals, and optional notes. Private item metadata is excluded. Amounts use the order's currency.

Item unit price/subtotal exclude tax and precede discounts. Discount equals stored subtotal minus discounted total; payable line total includes stored line tax after discount. WooCommerce order totals retain shipping, fees, discounts, and tax. Classic exposes detailed amounts; smaller layouts show a subset.

RTL follows the WordPress locale, with an explicit RTL sample option. mPDF selects bundled fonts for the invoice language. Large receipts can span pages; browser receipt paper size depends on the selected printer/driver. Test long names, addresses, and notes on the physical device.

Logo URLs use WordPress's safe HTTP API. PNG/JPEG/GIF/WebP data is validated and embedded, with limits of 2 MiB and 5,000 pixels per dimension. Successful logos are cached for one day; inaccessible or invalid images are omitted.

## Languages

Bundled translations cover English, Persian, Turkish, Arabic, French, German, Russian, Spanish, Portuguese (Portugal and Brazil), Armenian, Hindi, Simplified Chinese, and Japanese. Admin screens, print dialogs, messages, invoice labels, and sample previews use the selected WordPress language.

Choose **Settings → General → Site Language** for the store and background invoices. Administrators can choose a different **Users → Profile → Language** for their admin screens and browser previews. Background CUPS and agent jobs use the site's language when the worker runs. Persian and Arabic automatically use RTL layout, including ordinary sample previews.

PDF output selects bundled fonts for Arabic/Persian, Armenian, Cyrillic, Hindi, Chinese, and Japanese; retain mPDF's font files in the release. WooCommerce supplies real order totals, currency/date formatting, payment/shipping names, and order status translations. Business details, product names, customer details, and notes keep the text saved in the store.

See [language locales, translation maintenance, and verification](docs/languages.md).

## Jobs, retries, and recovery

**Print Jobs** provides status/source/order filters, pagination, attempt count, provider, external ID, and sanitized error details. UTC timestamps are displayed in the site's timezone.

| Status | Meaning | Next step |
| --- | --- | --- |
| `queued` | Awaiting background work or a safe delayed retry | Wait/check queue or cancel |
| `processing` | Atomically claimed by a worker or paired agent | Wait; avoid duplicate submission |
| `submitted` | CUPS/local spooler accepted the PDF, or browser output was prepared | Inspect the local spooler and paper |
| `failed` | Generation failure or definitive rejection | Correct cause; use safe Retry when offered, otherwise intentionally reprint |
| `unknown` | Possibly accepted, or worker stopped before recording a result | Inspect the local spooler/physical output before reprinting |
| `cancelled` | Operator cancelled a queued job | Create a new manual job if needed |

Submitted does **not** prove paper output; the plugin does not poll CUPS delivery status.

Agent delivery uses a 600-second claim/start lease and a private durable SQLite journal. Expired downloads that never received print authorization may be reclaimed; started jobs become unknown and are never replayed automatically. A valid late receipt may resolve its own unknown claim. The agent can retry receipt delivery without running the printer again. See [the agent protocol](docs/cross-platform-printing.md).

### Direct CUPS retry policy

- A definite HTTP 429 rejection is retried up to three total worker attempts, after 60 then 120 seconds.
- Credentials/printer errors, generation errors, and other definitive rejections fail without automatic resubmission.
- Transport errors, submission redirects/missing status/HTTP 408/5xx responses, and malformed successful submission responses become `unknown`, because remote acceptance cannot safely be ruled out.
- Safe **Retry** is limited to pre-submission queue failures or HTTP 429 within the attempt limit. Unknown jobs require an intentional new manual print.
- Only queued jobs may be cancelled. Once submission starts, this plugin cannot reliably recall it.

Atomic status transitions prevent late scheduling errors/cancellation/other workers from overwriting an accepted job. After-submission observer exceptions preserve submitted status. Action Scheduler timeout/failure notifications mark interrupted processing jobs unknown.

### Direct CUPS queue maintenance

Actions use hook `wcip_process_print_job`, group `wc-invoice-printer`, and only a `job_id` argument. Look under **WooCommerce → Status → Scheduled Actions**, or the equivalent Action Scheduler admin screen.

At Action Scheduler initialization, recovery examines up to 100 queued/processing jobs and advances a persisted cursor between requests. Queued CUPS jobs lacking a pending/running action are rescheduled, preserving safe retry backoff. Processing jobs older than ten minutes with no active action become unknown and are never resubmitted. This repairs gaps between insertion and scheduling and restores pending jobs after reactivation; large queues need multiple requests to scan.

Keep WP-Cron/loopback requests working, or configure a server-managed runner when traffic-driven cron is disabled. See the [Action Scheduler API](https://actionscheduler.org/api/) and [usage documentation](https://actionscheduler.org/usage/).

## Permissions and privacy

| Capability | Administrator | Shop Manager | Purpose |
| --- | --- | --- | --- |
| `wcip_print_invoices` | Yes | Yes | Preview/manual printing |
| `wcip_view_print_jobs` | Yes | Yes | View history |
| `wcip_manage_settings` | Yes | No | Configure/test connection/printers |

Job cancel/retry requires both print and history permissions. The dedicated `wcip_print_agent` role receives only `read` and `wcip_run_print_agent`. Its Application Password can access the paired queue handshake, not operator printing/settings/history routes. Other roles receive none automatically. Print permission grants access to customer invoices throughout the store. There is no public customer invoice endpoint.

Admin forms/previews require capabilities and nonces; REST routes enforce permissions/schema validation, with REST nonces for browser cookie authentication. Passwords remain on the server, but a saved password is accessible to database administrators/backups and is not encrypted by this plugin. Prefer the constant when your deployment has secret management.

Your paired local agent or configured CUPS server receives the generated PDF and any included customer names, addresses, contact details, items, and notes. Review [CUPS server security](https://openprinting.github.io/cups/doc/security.html) and the store's privacy disclosures. Test pages contain sample data.

Job rows store operational references/errors and agent token hashes rather than PDF/customer snapshots. The local agent deletes temporary PDFs after each attempt; its private SQLite journal retains job IDs, tokens and delivery outcomes. Protect the workstation, journal and Application Password; use environment-based credentials and restrict filesystem access. Trusted extensions should avoid sensitive exception text or logs. PDF rendering uses a random private temporary directory per render and cleans it on completion/failure; uncatchable termination can leave files for the host to clean up. Logos and configuration-scoped printer lists use WordPress transients.

## Storage and lifecycle

- Table: `{$wpdb->prefix}wc_invoice_print_jobs`; unique idempotency keys and indexes for status/date, order/date, source/date, and action ID.
- Options: `wcip_settings`, `wcip_db_version`, `wcip_capabilities_version`, `wcip_recovery_cursor`; explicit cleanup uses `wcip_delete_data_on_uninstall`.
- Activation provisions the schema/capabilities; boot performs versioned schema checks. Failed DDL is not marked installed; setup failure shows an administrator notice and printing remains unavailable until repaired.
- Deactivation cancels plugin actions and clears queued action references, retaining settings/history for reactivation recovery.
- Uninstall cancels available plugin actions and removes operator role capabilities. Data and the paired-agent role remain unless cleanup was explicitly enabled. Opt-in deletion removes the agent role but never user accounts. Stop local agents before uninstalling.
- Job history is retained indefinitely by default. Removing an order does not remove its historical jobs.

To opt into deletion before uninstall, on the intended site:

```sh
wp option update wcip_delete_data_on_uninstall 1
```

Cleanup drops the plugin table and deletes its settings/version/cursor/cleanup options, preserving WooCommerce orders and shared Action Scheduler tables/history. Cached transients expire separately. Reactivating a reinstallation with retained data restores default capabilities.

Multisite network activation/uninstall and new-site provisioning are not covered by the test suite. Use individual-site installation and verify each site's schema/roles before network deployment.

## REST API

Namespace: `/wp-json/wc-invoice-printer/v1`. Authenticate as a WordPress user with the relevant capabilities; cookie-authenticated admin requests supply `X-WP-Nonce` from `wp_create_nonce( 'wp_rest' )`.

| Method | Route | Permission | Result |
| --- | --- | --- | --- |
| POST | `/connection/test` | Manage settings | Account/connection summary |
| GET | `/printers?refresh=true` | Manage settings | Printer IDs/names/states |
| POST | `/test-print` | Manage settings | Submit sample PDF; body contains `printer_id` |
| POST | `/print` | Print invoices | Queue jobs; returns `queued`, `job_ids` |
| POST | `/printed` | Print + view jobs | Confirm checked paper output and add private notes; body contains `job_ids` |
| POST | `/jobs/{id}/cancel` | Print + view jobs | Cancel queued job |
| POST | `/jobs/{id}/retry` | Print + view jobs | Queue eligible safe retry |

Example `/print` body:

```json
{
  "order_ids": [1042, 1043],
  "template_id": "classic",
  "provider_id": "cups",
  "printer_id": "Office_A4",
  "copies": 1
}
```

Order IDs are positive integers (1–50 orders), templates must be registered, printer IDs are exact named CUPS queues or the paired logical agent queue, and copies range from 1 to 20. The protected print route supports `cups` and `agent`. HTTP 201 means queued, not printed; repeated manual requests create additional jobs. Inspect returned error `job_ids` before repeating a bulk operation. No public history/list route is implemented.

## Developer extensions

Extensions run as trusted WordPress code. Return the expected types, escape custom-template output, and keep eligibility hooks fast because they run in the payment flow.

| Hook | Type | Arguments/result |
| --- | --- | --- |
| `wcip_invoice_templates` | Filter | Map of `TemplateDefinition` by matching ID |
| `wcip_print_providers` | Filter | Map of `PrintProviderInterface` by matching provider ID |
| `wcip_invoice_data` | Filter | `InvoiceData`, order; return `InvoiceData` |
| `wcip_enable_paid_status_fallback` | Filter | Boolean, order, previous/new status |
| `wcip_automatic_print_eligible` | Filter | Boolean, order; false vetoes printing |
| `wcip_rendered_invoice_html` | Filter | HTML, invoice data, template |
| `wcip_before_invoice_render` | Action | Invoice data, template |
| `wcip_after_invoice_render` | Action | HTML, invoice data, template |
| `wcip_before_print_submission` | Action | Job ID, provider ID |
| `wcip_after_print_submission` | Action | Job ID, external job ID |
| `wcip_print_failure` | Action | Job ID, error code, status |
| `wcip_automatic_print_error` | Action | Order ID, throwable from creation failure |

Example template registration in a companion plugin:

```php
add_filter( 'wcip_invoice_templates', static function ( array $templates ): array {
    $templates['warehouse'] = new \WCInvoicePrinter\Template\TemplateDefinition(
        'warehouse', 'Warehouse', 'Warehouse invoice layout.',
        'A4', 'portrait', true, __DIR__ . '/templates/warehouse.php'
    );
    return $templates;
} );
```

The readable PHP file receives `$invoice` (`InvoiceData`) and `$template` (`TemplateDefinition`). Follow a bundled template's complete HTML/body/style structure. IDs use lowercase letters, digits, `_`, and `-`; orientation is `portrait`/`landscape`. Templates execute PHP and must come from trusted code.

`InvoiceData` exposes read-only `order`, `store`, `customer`, `items`, `totals`, `fulfillment` arrays and an `rtl` boolean. PHP 7.4-compatible private fields and magic accessors preserve property reads and prevent replacement/unsetting. Array reads return values, so modify a copy and return a new instance to change fields. `TemplateDefinition` and `SubmissionResult` use the same approach. Match bundled escaping of plain text and restricted currency HTML.

`JobStatus` uses string constants on every PHP version: pass `JobStatus::FAILED` directly rather than reading an enum's `->value`. `JobStatus::cases()` returns the status strings, `JobStatus::is_terminal( $status )` identifies terminal states, and `JobStatus::label( $status )` returns translated display labels. Stored database values and failure-hook arguments remain the same strings.

Providers receive PDF bytes, printer ID, copies, and title, and return `SubmissionResult`. Use `ProviderException` to distinguish definitive rejection from ambiguous submission; never mark possibly accepted delivery retryable. Additional registered push providers are available internally; adding a selectable destination also requires settings/UI/REST integration. The built-in manual route accepts CUPS or the paired agent. Agent delivery uses `AgentQueue` and `AgentController` with claim/start/receipt, rather than `PrintProviderInterface`.

## Development and testing

```bash
composer install --prefer-dist
composer test
composer test:js
composer lint
composer lint:js
composer i18n:check
```

Unit tests use controlled WordPress/WooCommerce/HTTP/database doubles for settings, permissions, requests, queue failures, idempotency, and state transitions. PDF unit tests cover the original three layouts across all locales and the seven registered formats with long mixed RTL text. The opt-in localization integration test generates all seven formats in every bundled locale, including the required Hindi/CJK fonts. Translation checks require Python 3.8+ and GNU gettext; extraction additionally uses WP-CLI. Unit tests use mocks and do not physically print. The opt-in real agent integration separately exercises HTTPS WordPress authentication and a Linux CUPS virtual PDF queue. Doubles do not establish MySQL behavior or browser/gateway compatibility.

PHPUnit 9.6 and its configuration/annotations run on PHP 7.4 and PHP 8. The [PHP compatibility workflow](.github/workflows/php-compatibility.yml) installs the committed lock file and runs platform checks, PHP lint, and the full suite on PHP 7.4 and 8.0–8.5. Compatibility review details are in [docs/php-compatibility.md](docs/php-compatibility.md).

JavaScript tests use Node's built-in test runner (Node 18+); no npm dependencies are needed. `composer test:all` runs both PHP and JavaScript suites.

The opt-in [integration smoke test](tests/Integration/smoke.php) uses real WordPress/WooCommerce order CRUD, plugin hooks, Action Scheduler, database persistence, REST permissions, and PDF generation. Run only on an isolated disposable site with WooCommerce and this plugin active:

```sh
WCIP_RUN_INTEGRATION_TESTS=1 wp --path=/path/to/disposable-wordpress eval-file \
  /path/to/wc-invoice-printer/tests/Integration/smoke.php
```

It creates test orders/products, mocks outbound HTTP, restores settings, and removes its test data. Test with both legacy storage and HPOS; physical delivery requires a deliberate staging test with a real printer.

The [local-printing HTTP test](tests/Integration/local-printing.php) additionally checks the actual authenticated settings, preview, and confirmation routes without an API key. It verifies the thermal sample, no sample job creation, nonce rejection, two-copy browser output, Printed columns, a single private note after repeated confirmation, unchanged persisted creation/modification dates, and unchanged order sorting by either date. Run it with legacy storage and HPOS on a disposable site whose admin URL is reachable from its own PHP process:

```sh
WCIP_RUN_INTEGRATION_TESTS=1 wp eval-file /path/to/plugin/tests/Integration/local-printing.php
```

The HTTP test fetches documents without executing browser scripts, so it does not print paper. It restores settings and deletes its order, job, and login-session fixtures.

The [remediation test](tests/Integration/remediation.php) exercises discovery, states, native Orders filters, fulfillment, scan permissions, outbox deduplication and all document formats against real WordPress/WooCommerce. The [upgrade test](tests/Integration/upgrade.php) requires an empty plugin job table and reconstructs the 1.1.2 schema; run it only in an expendable database. The [concurrency test](tests/Integration/concurrency.php) provides prepare, event, reconcile, confirmation, verification and cleanup phases for separate PHP workers. Commands and the executed evidence matrix are in [REMEDIATION_TRACEABILITY.md](REMEDIATION_TRACEABILITY.md).

| Directory/file | Responsibility |
| --- | --- |
| `wc-invoice-printer.php`, `src/Plugin.php` | Bootstrap, HPOS declaration, service/hook wiring |
| `src/Admin/`, `src/Rest/`, `src/Settings/` | UI, protected API, normalized configuration |
| `src/Invoice/`, `src/Template/`, `src/Pdf/` | Order data → HTML → PDF |
| `src/Printing/`, `print-agent/` | CUPS push provider, agent handshake, cross-platform local app |
| `src/PrintJob/`, `src/Automation/` | Persistence, states, payment hooks, queue, worker |
| `src/Infrastructure/`, `uninstall.php` | Schema/capabilities and cleanup |
| `templates/`, `assets/`, `tests/` | Invoice layouts, admin assets, verification |

See [architecture](docs/architecture.md), [UI specification](docs/ui-spec.md), and [WordPress plugin readme](readme.txt).

Localized DOCX development and template-customization guides and a companion-plugin example are in [system-development](system-development/README.md).

[Review findings and verification](docs/review.md) records the corrected issues, regression coverage, tested environment, and practical limits.

## Packaging

Prepare a clean staging copy, install dependencies with `--no-dev --prefer-dist --optimize-autoloader`, check production platform requirements, and archive the folder as `wc-invoice-printer/`. Include production `vendor/`. Exclude `.git/`, tests/cache, build output, and local configuration; `.distignore` documents exclusions but does not build an archive itself.

The release builder creates a clean temporary staging directory, installs locked production dependencies, checks platform requirements, verifies the package allowlist, and writes a ZIP plus SHA-256 file:

```sh
python3 scripts/build-release.py
```

For an offline rebuild, `--production-vendor /absolute/path/to/clean/vendor` accepts only an exact locked production package set. Do not pass the development checkout's vendor directory. The plugin ZIP includes cross-platform setup, CUPS migration, operations instructions and the agent source/config example. The standalone agent ZIP contains the same local app and setup guide. Install Python and SumatraPDF or CUPS only on the printer computer. Developer DOCX guides, review evidence and companion examples are in the separate documentation bundle and source.

`build/` contains local staging files and generated release ZIPs and is ignored by Git. Source changes and tests do not regenerate archives. Build a fresh archive before distributing reviewed changes, then verify its installation/activation and a test print. Distribute release ZIPs separately from the source repository.

`build/wc-invoice-printer-1.4.0.zip` is the production-dependency package. Schema/capability version 1.4.0 adds agent lease fields/index and a scoped role while preserving prior jobs, confirmations and CUPS settings. The separate commercial-provider retirement stamp remains 1.3.0. Back up the database before upgrade; review [upgrade and operations instructions](docs/operations.md).

## Troubleshooting

| Symptom | Check/action |
| --- | --- |
| No automatic job | Automation enabled, WooCommerce paid state, valid template and paired agent or CUPS connection/queue, eligibility filters, existing automatic history |
| Offline gateway does not print | Its paid-status/payment-hook policy; pending/on-hold is ineligible |
| Queue stays pending | Agent: awake workstation, last seen, HTTPS/authentication, exact logical queue and private journal. CUPS: Scheduled Actions, WP-Cron, loopback and queue logs |
| Queue failure | Restore Action Scheduler/storage, then safe Retry for `scheduler_unavailable`/`schedule_failed` |
| Database setup notice | Check database CREATE/ALTER permissions and storage; fix the cause and reactivate. Other WooCommerce operations can continue while printing is unavailable. |
| Generation failure | Composer dependencies/extensions, memory/temp permissions, template/filter code; correct then reprint |
| HTTP 401 | Replace/test credential and check whether the server constant overrides it |
| Local printer missing from plugin list | Use the agent’s local printer name or Browser print dialog; the refreshed list contains CUPS queues |
| Local printer missing from browser dialog | Install the printer/driver in the operating system and verify printing from another application |
| No CUPS printers | Check CUPS sharing, HTTPS trust, access policy and queue names, then refresh |
| Missing printer after refresh/configuration change | Explicitly select/save a printer from the intended server; refresh does not silently choose another |
| HTTP 429 | Wait for bounded retries; inspect server rate limits if exhausted |
| Unknown job | Inspect the local spooler, journal and physical output first; an intentional reprint may duplicate accepted work |
| Submitted without paper | Local spooler/CUPS history, printer queue/state, copies/media and paper settings |
| Missing logo | Safe HTTP(S) access, real supported format, size/dimensions, one-day cache |
| Browser dialog blocked | Popup settings; use the output page's Print button |
| Missing permissions | Reactivate to provision default role caps, or grant custom-role caps explicitly |

## Limitations

- Order invoices/receipts are not a fiscal-accounting system: no separate legal invoice sequence, tax-authority reporting, credit notes, or jurisdiction-specific compliance claim.
- Workers use current order/business data when they run. Jobs freeze template/provider/printer/copies, not an immutable invoice snapshot.
- Automatic output uses one configured destination: a paired logical agent queue or CUPS queue. Browser printing needs operator interaction. The agent requires an awake printer computer and outbound HTTPS; hosting must allow REST Application Password authentication.
- There is no remote status polling or unknown-job automatic retry. Uncertain network/process failures prevent a guarantee of exactly-once physical delivery.
- Multisite network workflows, every payment gateway/printer driver, and a complete PHP/WordPress matrix require environment-specific verification.

Licensed under [GPL-2.0-or-later](LICENSE).

## Shipping workflow and integrations

Open Documents & shipping for recipient fallback, mapped fields, timezone and optional fulfillment settings. Barcode & integrations provides authenticated lookup, independent warehouse stages and the default-off signed webhook outbox. Reconciliation provides a dry-run preview and explicit selection for historical orders. Diagnostics reports queue configuration and discovery progress.

See [operations, API contracts and extension examples](docs/operations.md) and the [executed remediation report](REMEDIATION_TRACEABILITY.md). Automatic output uses one configured agent or CUPS destination; manual labels can use another explicit CUPS queue or the paired agent. Package splitting, measured gross weight and tracking are adapter-provided data, never inferred.

Version 1.3.0 retires the commercial provider, clears its saved connection data and stops its pending jobs. Configure a paired agent or CUPS. REST clients use provider_id=agent with the logical queue, or provider_id=cups with a CUPS queue; old cloud identifiers are rejected. Read [the migration guide](docs/open-source-printing.md) before upgrading. Only open-source, fee-free provider/receiver extensions are within the project policy.

Version 1.4.0 adds outbound local-agent printing for Windows, Linux and macOS without installing printer software on WordPress hosting. See [deployment and Windows startup](docs/cross-platform-printing.md). Run `python3 -m unittest discover -s print-agent -p "test_*.py"`; the configured agent CI matrix covers Windows, Linux and macOS, but local execution does not establish native Windows printer compatibility.
