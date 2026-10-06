# Architecture

## Boundaries and dependency flow

WooCommerce orders are read only through public CRUD objects. `InvoiceFactory` normalizes an order into immutable `InvoiceData`; registered templates consume only that model; `HtmlRenderer` creates markup; `MpdfRenderer` creates private temporary PDF bytes; a `PrintProviderInterface` implementation dispatches them. Print providers never receive an order object.

`PrintJobRepository` owns the operational custom table. `PrintJobService` creates manual or automatic jobs, and `PrintWorker` performs state transitions and dispatch. Admin and REST controllers depend on those services and contain no provider-specific logic.

## Print-job persistence

`{$wpdb->prefix}wc_invoice_print_jobs` stores order/provider references and operational state, never invoice/customer payloads. Its unique `idempotency_key` index makes automatic creation race-safe. Indexes cover status/date, order/date, trigger/date, and action ID.

States are `queued → processing → submitted`; `failed`, `unknown`, and `cancelled` are terminal. `submitted` means PrintNode accepted the request, not that paper was produced. A compare-and-swap update claims queued/retry jobs.

## Idempotency and scheduling

The automatic key is a SHA-256 digest of plugin event version and order ID. Transaction IDs can be assigned or changed after payment callbacks, so they do not define a second automatic event. Existing automatic jobs with legacy keys are also reused by order ID. Manual jobs use a random UUID and are intentionally repeatable. Job insert occurs before `as_enqueue_async_action()`, which receives only the job ID and uses group `wc-invoice-printer`. The scheduler verifies `ActionScheduler::is_initialized()` and reuses pending actions. At initialization a bounded cursor scan repairs queued jobs without a pending/running action, including jobs retained across deactivation. Processing jobs older than ten minutes without a live action become unknown instead of being resubmitted.

## Failure behavior

Authentication, invalid printer, and other definitive rejections fail. HTTP 429 receives at most three total worker attempts with 60/120-second backoff. HTTP 408/5xx, connection errors, malformed successful POST responses, and unexpected errors after dispatch are ambiguous: the job becomes `unknown` and is never automatically retried. Interrupted processing workers also become unknown. Operators inspect PrintNode before intentionally creating a new manual print. Queued-only scheduling failures and processing-only result transitions prevent late errors from overwriting acceptance; after-submission observer exceptions preserve submitted status.

## Extension boundaries

`wcip_invoice_templates` registers validated templates; `wcip_print_providers` registers validated providers; `wcip_invoice_data` filters normalized invoice data; `wcip_enable_paid_status_fallback` controls the fallback; `wcip_automatic_print_eligible` gates all automatic creation. Rendering/submission/failure hooks expose extension boundaries. Submission/failure actions use job IDs rather than customer payloads; the creation-error action includes a throwable, so extension logging must avoid sensitive messages.

## Security and lifecycle

Capabilities separate printing, job visibility, and settings; job mutations require both printing and visibility. Every state-changing form uses a nonce and capability check; every REST route has a permission callback and schema validation. Preview requires authentication, capability, and nonce, and creates no job. Browser readiness is recorded after rendering the entire validated selection. Credentials remain server-side. Activation runs a versioned `dbDelta()` migration and assigns capabilities. Deactivation cancels plugin actions while retaining merchant data for recovery. Uninstall removes capabilities and deletes stored data only with the explicit cleanup option. PDF working directories are private, isolated per render, and cleaned in a finally block; printer caches are credential-scoped.
