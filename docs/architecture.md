# Architecture

## Boundaries and dependency flow

WooCommerce orders are read only through public CRUD objects. `InvoiceFactory` normalizes an order into immutable `InvoiceData`; registered templates consume only that model; `HtmlRenderer` creates markup; `MpdfRenderer` creates private temporary PDF bytes; a `PrintProviderInterface` implementation dispatches them. Print providers never receive an order object.

The minimum PHP version is 7.4. Service dependencies use explicit typed properties and constructor assignments. Value objects expose private fields through read-only accessors; job statuses are string constants with the same database values on PHP 7.4 and PHP 8. Composer's platform target keeps the complete dependency lock installable on PHP 7.4.33.

`PrintJobRepository` owns the operational custom table. `PrintJobService` creates manual or automatic jobs; `PrintWorker` dispatches push-provider jobs. `AgentQueue` atomically coordinates pull claims, start authorization and receipts. `AgentController` uses a paired, restricted WordPress Application Password account and renders PDFs in the store locale. Agent jobs need no hosting daemon, printer software or Action Scheduler action.

## Print-job persistence

`{$wpdb->prefix}wc_invoice_print_jobs` stores order/provider references and operational state, never invoice/customer payloads. Its unique `idempotency_key` index makes automatic creation race-safe. Indexes cover status/date, order/date, trigger/date, and action ID.

States are `queued → processing → submitted`; `failed`, `unknown`, and `cancelled` are not automatically replayed. `submitted` means CUPS/local spooler acceptance or browser preparation, without paper confirmation. A compare-and-swap update claims queued/retry jobs. Agent fields store token hashes, the paired user, phase and lease time; no PDF snapshot is retained. Expired prepared leases safely requeue; expired started leases become unknown. A valid late receipt may resolve its own unknown claim. The local durable journal prevents printer replay after a crash.

## Idempotency and scheduling

The automatic key is a SHA-256 digest of plugin event version and order ID. Transaction IDs can be assigned or changed after payment callbacks, so they do not define a second automatic event. Existing automatic jobs with legacy keys are also reused by order ID. Manual jobs use a random UUID and are intentionally repeatable. Agent jobs stay queued for the paired computer's outbound HTTPS poll. A throttled poll also advances bounded paid-order reconciliation, even when WP-Cron is disabled. Before printing, a separate one-use start request rechecks payment eligibility and automation enablement.

For direct CUPS jobs, insertion occurs before `as_enqueue_async_action()`, which receives only the job ID and uses group `wc-invoice-printer`. The scheduler verifies `ActionScheduler::is_initialized()` and reuses pending actions. At initialization a bounded cursor scan repairs queued CUPS jobs without a pending/running action, including jobs retained across deactivation. Processing CUPS jobs older than ten minutes without a live action become unknown instead of being resubmitted. Agent claims have their own prepared/started lease recovery and are never dispatched by this worker.

## Failure behavior

For direct CUPS, authentication, invalid printer, and other definitive rejections fail. HTTP 429 receives at most three total worker attempts with 60/120-second backoff. HTTP 408/5xx, connection errors, malformed successful POST responses, and unexpected errors after dispatch are ambiguous: the job becomes `unknown` and is never automatically retried. Interrupted processing workers also become unknown. Operators inspect the relevant local spooler and paper before intentionally creating a new manual print. Queued-only scheduling failures and processing-only result transitions prevent late errors from overwriting acceptance; after-submission observer exceptions preserve submitted status.

For the local agent, failures before printer process launch are definite failures. Process timeout/nonzero exit or a crash after dispatch becomes unknown. The private SQLite journal records the phase before each irreversible step, then retries only receipts without replaying printer output. A prepared claim that expires can be reclaimed; a started claim that expires becomes unknown. Receipts are user/token-bound and idempotent, and a late valid receipt can resolve its own unknown claim. Neither spooler acceptance nor a receipt confirms paper output.

## Extension boundaries

`wcip_invoice_templates` registers validated templates; `wcip_print_providers` registers validated providers; `wcip_invoice_data` filters normalized invoice data; `wcip_enable_paid_status_fallback` controls the fallback; `wcip_automatic_print_eligible` gates all automatic creation. Rendering/submission/failure hooks expose extension boundaries. Submission/failure actions use job IDs rather than customer payloads; the creation-error action includes a throwable, so extension logging must avoid sensitive messages.

## Security and lifecycle

Capabilities separate printing, job visibility, and settings; job mutations require both printing and visibility. Every state-changing form uses a nonce and capability check; every REST route has a permission callback and schema validation. Preview requires authentication, capability, and nonce, and creates no job. Browser readiness is recorded after rendering the entire validated selection. Credentials remain server-side. Activation runs a versioned `dbDelta()` migration and assigns capabilities. Deactivation cancels plugin actions while retaining merchant data for recovery. Uninstall removes capabilities and deletes stored data only with the explicit cleanup option. PDF working directories are private, isolated per render, and cleaned in a finally block; printer caches are credential-scoped.

## Version 1.2 operations

PaymentEligibilityPolicy is shared by creation, discovery, order display and both worker checks. RecipientResolver uses WooCommerce country address formatting and trusted metadata mappings. WeightCalculator distinguishes frozen order weight from current catalog weight and missing from zero. DocumentCodeService persists a random reference per order/document/package; knowledge of a reference provides no authorization.

Jobs carry document_type; existing rows migrate to invoice. Latest and confirmed history queries default to invoice, preventing label confirmation from marking invoices printed. Visible order pages prime history in two bounded queries. Native WooCommerce/WP query hooks add indexed predicates against plugin history; custom payment adapters supply equivalent native query constraints.

PaidOrderReconciler is distinct from existing-job recovery. It uses an expiring database coordinator lease, paid/modified phases, UTC windows, 50 IDs per run and a persisted cursor. Recorded paid timestamps before the enable/upgrade cutoff are excluded even when recently modified. Invalid printer configuration holds the cursor. Explicit backfill uses a user-bound 15-minute preview token and selected IDs.

FulfillmentService writes independent stages/revisions and unique events in InnoDB tables; compare-and-swap and row locks protect transitions. It never changes WooCommerce status, payment or dates. Committed invoice confirmation observers may opt in to a configured initial warehouse stage; repeated confirmations have no new effect.

ExportService persists immutable JSON intent before scheduling. Atomic claims, configuration fingerprints and stable event keys prevent unintended replay. Accepted receipt, failed rejection and unknown outcome are separate. Only a definite 429 permits three bounded attempts. Interrupted processing becomes unknown. Old accepted/failed payloads are cleared in bounded recovery after 30 days while receipts/keys remain. Unknown payloads remain for investigation. See [the contract and privacy policy](operations.md).
