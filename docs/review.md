# Code review and verification

This is a historical review of the retired cloud provider. Version 1.4.0 uses the open-source local agent or direct CUPS; the commercial provider was removed in 1.3.0. Provider-specific findings below are historical evidence, not current setup instructions. Current 1.4.0 changes and executed results are recorded in [the remediation report](../REMEDIATION_TRACEABILITY.md). Its earlier counts and archive statements describe that review only.

Review date: October 6, 2026. Scope: the complete shipped PHP, invoice templates, admin JavaScript/CSS, lifecycle code, existing tests, and documentation. Automatic printing remains restricted to WooCommerce-confirmed paid orders, as requested.

## Findings fixed

| Area | Original problem and impact | Result / regression coverage |
| --- | --- | --- |
| Queue availability | Checked nonexistent `Action_Scheduler` class; the original test double repeated the mistake, so background printing was unavailable in real WooCommerce | Uses `ActionScheduler`; real WooCommerce/Action Scheduler smoke test verifies a payment creates a pending action and a runner submits it |
| Payment idempotency | Transaction IDs can be assigned/changed after callbacks, generating a second automatic key | Stable order identity, legacy-job reuse, unique-key race handling; `IdempotencyKeyTest`, `PrintJobServiceTest` |
| Persistence | Failed insert could lead to missing job IDs and false success | Explicit persistence failures and accurate request errors; `PrintJobRepositoryTest`, `RestControllerTest` |
| Scheduling races | A failed/duplicate scheduling attempt could overwrite processing/submitted state; a running unique action could block a retry | Pending-action reuse, running-action handling, queued-only failure updates, non-unique delayed retries; `SchedulerTest` and real runner smoke |
| State transitions | Unconditional updates could overwrite accepted/cancelled jobs | Compare-and-swap claims/results/failures, restricted cancellation/retry; repository/worker regression suites |
| Ambiguous delivery | HTTP 5xx and malformed successful submissions could be treated as safely rejected | Transport errors, redirects, missing status, 408/5xx, and invalid successful IDs become unknown; no automatic resubmission; `PrintNodeProviderTest`, `PrintWorkerTest` |
| Observer/result failures | An observer throwing after acceptance could relabel a submitted job failed; result storage failures lacked conservative handling | Acceptance survives observer errors; dispatch/result uncertainty stays unknown; `PrintWorkerTest` |
| Lost workers/queue gaps | Crashes and deactivation left queued or processing rows stranded | Bounded recovery, preserved 429 backoff, interrupted/stale processing becomes unknown; scheduler/worker/lifecycle tests |
| Payment isolation | Auxiliary printing errors could interrupt the payment callback | Creation/error-observer exceptions are contained; paid status is refreshed before submission; `AutomaticPrintHandlerTest`, `PrintWorkerTest` |
| Bulk requests | Earlier orders could be queued before a later invalid order was found | Validate the whole selection first; partial infrastructure failures return created job IDs; REST/preview tests and real REST smoke |
| Permissions | Read-only history capability permitted cancellation/retry | Mutations require print and history capabilities; `RestControllerTest` |
| Request/settings input | Non-scalar settings could fail; removing nondigits could turn an invalid printer ID into another device | Allowlisted normalization, strict positive IDs/copies, safer defaults and error responses; settings/admin/REST suites |
| Secrets/account caches | Empty/non-string external key handling was inconsistent; printer cache could cross credentials | Consistent external override/fallback, scoped/invalidation-aware cache, keys stay server-side; settings/provider tests |
| Provider responses | Boolean/zero/overflow IDs and malformed account/printer lists could be accepted; discovery stopped at the first page | Shape/ID validation, bounded complete pagination; `PrintNodeProviderTest` |
| Browser previews | Preview recorded print submissions before rendering; browser Print did not reliably open the dialog | Read-only one-copy Preview, audited browser readiness after all rendering, explicit print dialog flag; PHP and JavaScript tests |
| Admin JavaScript | Plain permalinks broke API paths; refresh reset printer selection; initial automatic-control toggle did not bubble | URL handling, preserved explicit selection, correct initial visibility/copy validation/queued messaging; 12 Node tests |
| Invoice amounts | Displayed pre-discount line totals and inconsistent tax, empty discount values, truncated fractional quantities | Uses stored discounted totals/tax, explicit tax legend, fractional quantities; `InvoiceFactoryTest` and real order smoke |
| Customer/item text | Addresses lost line breaks/entities; private underscore item metadata was exposed | Preserved plain-text formatting and public metadata only; invoice/template tests and real order smoke |
| PDF layout/storage | Flex layouts were unsupported by mPDF; receipt CSS overrode variable page height; shared temp directory was not private | Table layouts, advertised page dimensions, random private per-render directory with cleanup; actual PDF tests and six visual renders |
| Extension failures | Filter-added providers were not found; invalid templates and thrown templates could leak output buffers | Validated filtered registries and finally-based buffer cleanup; registry/template tests |
| Schema installation | Failed DDL was marked installed, preventing repair attempts | Confirmed table/error check before version stamp, repair of missing tables, admin setup notice; `SchemaInstallationTest` |
| Role/lifecycle cleanup | Shop Manager created after activation missed capabilities; uninstall retained plugin permissions | Deferred role provisioning, capability marker, removal of permissions and opt-in data cleanup; schema/lifecycle tests |

## Verification

- Original baseline: 23 PHPUnit tests, 53 assertions passed.
- Expanded suite: 213 PHPUnit tests, 635 assertions passed; includes real mPDF rendering and schema-boundary subprocess tests.
- JavaScript: 12 Node tests passed; both production scripts pass syntax checks.
- PHP lint, strict Composer manifest validation, and `git diff --check` passed.
- Locked dependency audit returned no published security advisories at review time.
- Integration passed 31 assertions in each of legacy and HPOS storage, using WordPress 6.8.3, WooCommerce 9.0.2, PHP 8.5.8, and MySQL 8.4.11. `tests/Integration/smoke.php` mocks HTTP and email through shutdown.
- Integration assertions cover unpaid eligibility, payment/job scheduling, transaction changes, real order amounts/private metadata, all three RTL PDFs, real Action Scheduler execution, duplicate workers, ambiguous responses, bounded rate-limit retries, history filters/counts, deactivation/recovery, and authenticated/unauthenticated REST requests.
- Six generated Classic/Compact/Thermal LTR/RTL PDFs were visually inspected; physical page dimensions and temporary-file cleanup are also asserted.

## Practical limits

No live PrintNode account/client or physical printer was exercised. There is no claim of complete gateway/browser/printer/version-matrix or multisite coverage. A payment state can still change after the last check and before remote acceptance; network/process uncertainty prevents an end-to-end exactly-once guarantee. Jobs render current order/settings rather than immutable invoice snapshots. The README documents these operational boundaries.

The checked-in historical ZIP was not regenerated. These changes are in source; prepare and verify a fresh archive before release.

The subsequent [PHP compatibility review](php-compatibility.md), dated October 7, 2026, records the PHP 7.4 changes, dependency resolution, and verification on PHP 7.4 and PHP 8.0–8.5.

The 1.4.0 cross-platform agent runs separately from hosting PHP. Its Windows/Linux/macOS backend setup and Python 3.10+ requirements are in [cross-platform printing](cross-platform-printing.md); executed results and native hardware limits are in [the current remediation record](../REMEDIATION_TRACEABILITY.md).
