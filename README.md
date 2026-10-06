# WooCommerce Invoice Printer

Print WooCommerce invoices manually or send them to a physical printer through PrintNode after WooCommerce confirms payment. Automatic printing runs in the background so checkout does not wait for PDF generation or delivery. It is **disabled by default**.

Features include HPOS and legacy order storage, Classic/Compact/80 mm Thermal templates, RTL and Persian/Arabic PDF text, business identity and optional customer notes, individual/bulk printing, and persistent job history with duplicate prevention and conservative retries.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration and first print](#configuration-and-first-print)
- [Payment triggers](#payment-triggers)
- [Manual printing](#manual-printing)
- [Invoices and templates](#invoices-and-templates)
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
| PHP | 8.1+ for production |
| PDF output | Packaged Composer dependencies (mPDF), including its `mbstring`/`gd` extension requirements |
| Queue | WooCommerce's initialized Action Scheduler and a working WP-Cron/loopback or server-managed runner |
| Physical printing | PrintNode account/API key and an online PrintNode client on a computer connected to the printer |
| Network | Outbound HTTPS to `https://api.printnode.com`; logo loading also needs access to the logo host |
| Filesystem | Writable PHP temporary directory for private PDF working files |

Browser printing remains available without mPDF; PrintNode output/test pages need it. The WordPress server and printer computer may be on different networks. Composer itself is only needed to prepare dependencies, not on a correctly packaged production installation.

The production PHP minimum differs from development tooling: the locked PHPUnit 11 dependencies require PHP 8.3+. Use that or newer to install the exact development lock file.

## Installation

For a release archive, verify it includes `wc-invoice-printer.php`, `src/`, `templates/`, `assets/`, and `vendor/autoload.php`. Upload it through **Plugins → Add New Plugin → Upload Plugin**, or extract its folder into `wp-content/plugins/`. Activate WooCommerce, activate this plugin, and open **WooCommerce → Invoice Printer**.

For a source checkout, install production dependencies before uploading:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

Copy the whole folder, including `vendor/`, to `wp-content/plugins/wc-invoice-printer/`. Activation creates the job table and assigns capabilities. Back up the production database before upgrades.

## Configuration and first print

1. In **General**, set business name, contact information, logo URL, business/tax details, and whether customer notes should appear. The store address comes from WooCommerce store settings.
2. In **Templates**, choose the manual default and inspect normal/RTL sample previews.
3. Install/sign in to the PrintNode client on the printer computer. First verify that the operating system can print to the device.
4. In **Printers**, enter and **save** the API key before testing; alternatively configure the server constant below.
5. **Test connection**, **Refresh printers**, select the intended printer, and save printer settings.
6. **Print test page** sends a real sample page. Check physical output, paper, margins, and driver settings.
7. In **Automatic Printing**, select a template and 1–20 copies, enable automation, and save. A credential and printer must already be configured.
8. Complete payment on a staging order. Inspect **Print Jobs**, then verify a repeated payment event does not create another automatic job.

Saving a credential does not validate it. The connection test verifies the account; a physical test also checks client connectivity and the printer setup. Discovery is cached for ten minutes per credential; **Refresh printers** bypasses that cache.

Printer discovery fetches pages of 100, capped at 10,000 printers; an incomplete/malformed listing fails rather than being cached as a complete list.

### Server-managed credential

In `wp-config.php`, before WordPress loads:

```php
define( 'WCIP_PRINTNODE_API_KEY', 'your-printnode-api-key' );
```

Supply the value through your deployment's secret management. A non-empty string constant overrides the saved key and disables editing it in the admin UI. Empty/non-string constants fall back to the stored key. The password input is always blank; leave it blank to retain a stored key, or enter a replacement and save. Remove a stored key programmatically with `SettingsRepository::update( array( 'printnode_api_key' => '' ) )`. Plugin REST responses never include the credential.

## Payment triggers

Automation follows **payment confirmation**, not every order creation:

- `woocommerce_payment_complete` creates a job for an eligible paid order.
- A paid-status fallback handles gateways that mark an order paid without firing that hook.
- Eligibility requires automatic printing enabled, `WC_Order::is_paid()` true, a registered template, a configured credential, and a positive printer ID. Extensions may veto eligibility.
- One automatic job is permitted per order. Repeated/concurrent payment events and transaction IDs added/changed later reuse it; failed, unknown, or cancelled jobs are not silently replaced.
- A worker generates the PDF asynchronously and rechecks that the order exists and is still paid before submitting.
- Manual printing creates independent jobs and is intentionally repeatable.

WooCommerce generally considers `processing` and `completed` paid statuses. Offline gateways such as cash on delivery can assign `processing` before cash is collected. This plugin follows WooCommerce's state; it does not independently verify bank settlement or cash collection. For a payment-complete-hook-only workflow, disable the status fallback:

```php
add_filter( 'wcip_enable_paid_status_fallback', '__return_false' );
```

Use `wcip_automatic_print_eligible` with an authoritative gateway payment marker for stricter rules. Verify how that gateway uses the payment-complete hook. Pending/on-hold orders remain ineligible until marked paid.

Enabling automation does not backfill previously paid orders; print those manually. Disabling it stops creation of new jobs, while already queued jobs remain scheduled. Cancel unwanted queued jobs in history.

## Manual printing

In an order's **Invoice printing** panel, choose template, destination, and copies. **Preview** shows one copy without submitting a print job. **Print** opens the browser dialog or queues a PrintNode job. An order-list action also opens browser output.

On either the legacy or HPOS order list, select orders and choose **Print invoices** for bulk printing. Requests support up to 50 distinct orders and 1–20 copies per order. Missing, trashed, or checkout-draft orders are rejected before jobs are created. Browser output separates invoices/copies with page breaks; PrintNode queues one job per selected order.

Manual printing can include unpaid orders and never changes payment/fulfillment state. Browser `submitted` means output was prepared; the browser cannot confirm whether the operator printed or cancelled. Allow popups and match the browser/printer paper settings.

Bulk input is prevalidated, but a database/queue failure during creation may leave earlier valid jobs created. REST errors include created `job_ids`. Review history before repeating a whole selection to avoid duplicates.

## Invoices and templates

| ID | Layout | Paper |
| --- | --- | --- |
| `classic` | Detailed invoice and item amounts | A4 portrait |
| `compact` | Dense office-printer layout | A4 portrait |
| `thermal` | Narrow receipt | 80 mm PDF width; height grows with item count, up to 1,000 mm per page |

Invoices use the WooCommerce order number as their reference. Data includes dates/status/currency, business and customer details, public item metadata, SKU where the product still exists, payment/shipping methods, order totals, and optional notes. Private item metadata is excluded. Amounts use the order's currency.

Item unit price/subtotal exclude tax and precede discounts. Discount equals stored subtotal minus discounted total; payable line total includes stored line tax after discount. WooCommerce order totals retain shipping, fees, discounts, and tax. Classic exposes detailed amounts; smaller layouts show a subset.

RTL follows the WordPress locale, with an explicit RTL sample option. mPDF uses DejaVu Sans for Latin/Persian/Arabic. Large receipts can span pages; browser receipt paper size depends on the selected printer/driver. Test long names, addresses, and notes on the physical device.

Logo URLs use WordPress's safe HTTP API. PNG/JPEG/GIF/WebP data is validated and embedded, with limits of 2 MiB and 5,000 pixels per dimension. Successful logos are cached for one day; inaccessible or invalid images are omitted.

## Jobs, retries, and recovery

**Print Jobs** provides status/source/order filters, pagination, attempt count, provider, external ID, and sanitized error details. UTC timestamps are displayed in the site's timezone.

| Status | Meaning | Next step |
| --- | --- | --- |
| `queued` | Awaiting background work or a safe delayed retry | Wait/check queue or cancel |
| `processing` | Atomically claimed by a worker | Wait; avoid duplicate submission |
| `submitted` | PrintNode returned a valid ID, or browser output was prepared | Check client/printer if paper is missing |
| `failed` | Generation failure or definitive rejection | Correct cause; use safe Retry when offered, otherwise intentionally reprint |
| `unknown` | Possibly accepted, or worker stopped before recording a result | Inspect PrintNode/physical output before reprinting |
| `cancelled` | Operator cancelled a queued job | Create a new manual job if needed |

Submitted does **not** prove paper output; the plugin does not poll PrintNode delivery status.

### Retry policy

- A definite HTTP 429 rejection is retried up to three total worker attempts, after 60 then 120 seconds.
- Credentials/printer errors, generation errors, and other definitive rejections fail without automatic resubmission.
- Transport errors, submission redirects/missing status/HTTP 408/5xx responses, and malformed successful submission responses become `unknown`, because remote acceptance cannot safely be ruled out.
- Safe **Retry** is limited to pre-submission queue failures or HTTP 429 within the attempt limit. Unknown jobs require an intentional new manual print.
- Only queued jobs may be cancelled. Once submission starts, this plugin cannot reliably recall it.

Atomic status transitions prevent late scheduling errors/cancellation/other workers from overwriting an accepted job. After-submission observer exceptions preserve submitted status. Action Scheduler timeout/failure notifications mark interrupted processing jobs unknown.

### Queue maintenance

Actions use hook `wcip_process_print_job`, group `wc-invoice-printer`, and only a `job_id` argument. Look under **WooCommerce → Status → Scheduled Actions**, or the equivalent Action Scheduler admin screen.

At Action Scheduler initialization, recovery examines up to 100 queued/processing jobs and advances a persisted cursor between requests. Queued jobs lacking a pending/running action are rescheduled, preserving safe retry backoff. Processing jobs older than ten minutes with no active action become unknown and are never resubmitted. This repairs gaps between insertion and scheduling and restores pending jobs after reactivation; large queues need multiple requests to scan.

Keep WP-Cron/loopback requests working, or configure a server-managed runner when traffic-driven cron is disabled. See the [Action Scheduler API](https://actionscheduler.org/api/) and [usage documentation](https://actionscheduler.org/usage/).

## Permissions and privacy

| Capability | Administrator | Shop Manager | Purpose |
| --- | --- | --- | --- |
| `wcip_print_invoices` | Yes | Yes | Preview/manual printing |
| `wcip_view_print_jobs` | Yes | Yes | View history |
| `wcip_manage_settings` | Yes | No | Configure/test connection/printers |

Job cancel/retry requires both print and history permissions. Other roles receive none automatically. Print permission grants access to customer invoices throughout the store. There is no public customer invoice endpoint.

Admin forms/previews require capabilities and nonces; REST routes enforce permissions/schema validation, with REST nonces for browser cookie authentication. Keys remain on the server, but a saved key is accessible to database administrators/backups and is not encrypted by this plugin. Prefer the constant when your deployment has secret management.

PrintNode receives the generated PDF and any included customer names, addresses, contact details, items, and notes. Review [PrintNode's service information](https://www.printnode.com/) and the store's privacy disclosures. Test pages contain sample data.

Job rows store operational references/errors rather than PDF/customer snapshots. Trusted extensions should avoid sensitive exception text or logs. PDF rendering uses a random private temporary directory per render and cleans it on completion/failure; uncatchable termination can leave files for the host to clean up. Logos and account-scoped printer lists use WordPress transients.

## Storage and lifecycle

- Table: `{$wpdb->prefix}wc_invoice_print_jobs`; unique idempotency keys and indexes for status/date, order/date, source/date, and action ID.
- Options: `wcip_settings`, `wcip_db_version`, `wcip_capabilities_version`, `wcip_recovery_cursor`; explicit cleanup uses `wcip_delete_data_on_uninstall`.
- Activation provisions the schema/capabilities; boot performs versioned schema checks. Failed DDL is not marked installed; setup failure shows an administrator notice and printing remains unavailable until repaired.
- Deactivation cancels plugin actions and clears queued action references, retaining settings/history for reactivation recovery.
- Uninstall cancels available plugin actions and removes role capabilities. Data remains unless cleanup was explicitly enabled.
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
| POST | `/jobs/{id}/cancel` | Print + view jobs | Cancel queued job |
| POST | `/jobs/{id}/retry` | Print + view jobs | Queue eligible safe retry |

Example `/print` body:

```json
{
  "order_ids": [1042, 1043],
  "template_id": "classic",
  "provider_id": "printnode",
  "printer_id": "12345",
  "copies": 1
}
```

Order IDs are positive integers (1–50 orders), templates must be registered, printer IDs are positive decimal strings, and copies range from 1 to 20. The public print route supports PrintNode only. HTTP 201 means queued, not printed; repeated manual requests create additional jobs. Inspect returned error `job_ids` before repeating a bulk operation. No public history/list route is implemented.

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

`InvoiceData` has readonly `order`, `store`, `customer`, `items`, `totals`, `fulfillment` arrays and an `rtl` boolean. Return a new instance to change fields. Match bundled escaping of plain text and restricted currency HTML.

Providers receive PDF bytes, printer ID, copies, and title, and return `SubmissionResult`. Use `ProviderException` to distinguish definitive rejection from ambiguous submission; never mark possibly accepted delivery retryable. Additional registered providers are available internally; the built-in configuration/public manual route remains PrintNode-specific.

## Development and testing

```bash
composer install --prefer-dist
composer test
composer test:js
composer lint
composer lint:js
```

Unit tests use controlled WordPress/WooCommerce/HTTP/database doubles for settings, permissions, requests, queue failures, idempotency, and state transitions. PDF tests generate real mPDF output, including RTL text. Tests do not contact PrintNode or physically print. Doubles do not establish MySQL behavior or browser/gateway compatibility.

JavaScript tests use Node's built-in test runner (Node 18+); no npm dependencies are needed. `composer test:all` runs both PHP and JavaScript suites.

The opt-in [integration smoke test](tests/Integration/smoke.php) uses real WordPress/WooCommerce order CRUD, plugin hooks, Action Scheduler, database persistence, REST permissions, and PDF generation. Run only on an isolated disposable site with WooCommerce and this plugin active:

```sh
WCIP_RUN_INTEGRATION_TESTS=1 wp --path=/path/to/disposable-wordpress eval-file \
  /path/to/wc-invoice-printer/tests/Integration/smoke.php
```

It creates test orders/products, mocks outbound HTTP, restores settings, and removes its test data. Test with both legacy storage and HPOS; physical delivery requires a deliberate staging test with a real printer.

| Directory/file | Responsibility |
| --- | --- |
| `wc-invoice-printer.php`, `src/Plugin.php` | Bootstrap, HPOS declaration, service/hook wiring |
| `src/Admin/`, `src/Rest/`, `src/Settings/` | UI, protected API, normalized configuration |
| `src/Invoice/`, `src/Template/`, `src/Pdf/` | Order data → HTML → PDF |
| `src/Printing/` | Provider registry, PrintNode, retry policy |
| `src/PrintJob/`, `src/Automation/` | Persistence, states, payment hooks, queue, worker |
| `src/Infrastructure/`, `uninstall.php` | Schema/capabilities and cleanup |
| `templates/`, `assets/`, `tests/` | Invoice layouts, admin assets, verification |

See [architecture](docs/architecture.md), [UI specification](docs/ui-spec.md), and [WordPress plugin readme](readme.txt).

[Review findings and verification](docs/review.md) records the corrected issues, regression coverage, tested environment, and practical limits.

## Packaging

Prepare a clean staging copy, install dependencies with `--no-dev --prefer-dist --optimize-autoloader`, check production platform requirements, and archive the folder as `wc-invoice-printer/`. Include production `vendor/`. Exclude `.git/`, tests/cache, build output, and local configuration; `.distignore` documents exclusions but does not build an archive itself.

The checked-in `build/wc-invoice-printer-1.0.0.zip` is a historical artifact, **not regenerated by source changes/tests**. Build a fresh archive before distributing reviewed changes, then verify its installation/activation and a test print.

## Troubleshooting

| Symptom | Check/action |
| --- | --- |
| No automatic job | Automation enabled, WooCommerce paid state, valid template/key/printer, eligibility filters, existing automatic history |
| Offline gateway does not print | Its paid-status/payment-hook policy; pending/on-hold is ineligible |
| Queue stays pending | Scheduled Actions group, WP-Cron, loopback HTTP, queue logs; allow recovery requests to scan |
| Queue failure | Restore Action Scheduler/storage, then safe Retry for `scheduler_unavailable`/`schedule_failed` |
| Database setup notice | Check database CREATE/ALTER permissions and storage; fix the cause and reactivate. Other WooCommerce operations can continue while printing is unavailable. |
| Generation failure | Composer dependencies/extensions, memory/temp permissions, template/filter code; correct then reprint |
| HTTP 401 | Replace/test credential and check whether the server constant overrides it |
| No printers | Correct account, online client, refresh discovery |
| Missing printer after refresh/key change | Explicitly select/save a printer from the intended account; refresh does not silently choose another |
| HTTP 429 | Wait for bounded retries; inspect service/account limits if exhausted |
| Unknown job | Inspect PrintNode/physical output first; a new manual print may duplicate accepted work |
| Submitted without paper | PrintNode history, client connectivity, printer queue/driver/state, paper settings |
| Missing logo | Safe HTTP(S) access, real supported format, size/dimensions, one-day cache |
| Browser dialog blocked | Popup settings; use the output page's Print button |
| Missing permissions | Reactivate to provision default role caps, or grant custom-role caps explicitly |

## Limitations

- Order invoices/receipts are not a fiscal-accounting system: no separate legal invoice sequence, tax-authority reporting, credit notes, or jurisdiction-specific compliance claim.
- Workers use current order/business data when they run. Jobs freeze template/provider/printer/copies, not an immutable invoice snapshot.
- Automatic output uses one configured PrintNode printer. Browser printing needs operator interaction.
- There is no remote status polling or unknown-job automatic retry. Uncertain network/process failures prevent a guarantee of exactly-once physical delivery.
- Multisite network workflows, every payment gateway/printer driver, and a complete PHP/WordPress matrix require environment-specific verification.

Licensed under [GPL-2.0-or-later](LICENSE).
