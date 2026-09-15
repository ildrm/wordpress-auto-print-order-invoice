# Architecture

## Boundaries and dependency flow

WooCommerce orders are read only through public CRUD objects. `InvoiceFactory` normalizes an order into immutable `InvoiceData`; registered templates consume only that model; `HtmlRenderer` creates markup; `MpdfRenderer` creates private temporary PDF bytes; a `PrintProviderInterface` implementation dispatches them. Print providers never receive an order object.

`PrintJobRepository` owns the operational custom table. `PrintJobService` creates manual or automatic jobs, and `PrintWorker` performs state transitions and dispatch. Admin and REST controllers depend on those services and contain no provider-specific logic.

## Print-job persistence

`{$wpdb->prefix}wc_invoice_print_jobs` stores order/provider references and operational state, never invoice/customer payloads. Its unique `idempotency_key` index makes automatic creation race-safe. Indexes cover status/date, order/date, trigger/date, and action ID.

States are `queued → processing → submitted`; `failed`, `unknown`, and `cancelled` are terminal. `submitted` means PrintNode accepted the request, not that paper was produced. A compare-and-swap update claims queued/retry jobs.

## Idempotency and scheduling

The automatic key is a SHA-256 digest of plugin event version, order ID, and stable WooCommerce transaction ID (or order ID fallback). Manual jobs use a random UUID and are intentionally repeatable. Job insert occurs before `as_enqueue_async_action()`, which receives only the job ID and uses group `wc-invoice-printer`. A stored action ID avoids repeat scheduling.

## Failure behavior

Authentication, invalid printer, and other definitive 4xx responses fail. HTTP 429 and 5xx responses are definite non-acceptance and receive at most three attempts with exponential backoff. Connection errors and timeouts after dispatch are ambiguous: the job becomes `unknown` and is never automatically retried. Operators may intentionally reprint it as a new manual job.

## Extension boundaries

`wcip_invoice_templates` registers templates; `wcip_print_providers` registers providers; `wcip_invoice_data` filters normalized invoice data; `wcip_automatic_print_eligible` controls the optional paid-status fallback; and submission/failure actions expose job IDs without PII.

## Security and lifecycle

Capabilities separate printing, job visibility, and settings. Every state-changing form uses a nonce and capability check; every REST route has a permission callback and schema validation. Preview output requires authentication, capability, and nonce. API credentials remain server-side and are never returned by REST. Activation runs a versioned `dbDelta()` migration and assigns capabilities. Deactivation retains merchant data. Uninstall deletes data only when the explicit cleanup setting is enabled.
