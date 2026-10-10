# WooCommerce Invoice Printer 1.4.0 remediation record

Reviewed 10 October 2026. Baseline: `c894eb05d2120a4bba09aa3500fe2842a0e48d74`, branch `main`, inspected plugin 1.1.2. Evidence applies to the resulting uncommitted working tree. The supplied roles R01–R28 were used as solo review disciplines, not independent human approvals. The supplied prompt defined FR-01–FR-15 and T01–T36. No production store was modified; no commit, push or deployment was performed.

The implementation fixes missed delayed-payment discovery, misleading print states, recipient contact handling and historical replay; adds separate warehouse documents, fulfillment, codes/scanning and signed generic exports; and refreshes all documentation editions. Physical media remains an external acceptance gate. Commercial/proprietary printing and logistics dependencies are excluded by the current user instruction. “Implemented” means executed software behavior within the documented boundaries.

## Cross-platform printing in version 1.4.0

The latest user instruction requires Windows printer computers and operating-system-independent WordPress hosting. Version 1.4.0 adds an outbound HTTPS pull agent, implemented with Python 3.10+ standard-library modules. Windows dispatch uses open-source SumatraPDF; Linux/macOS use CUPS lp. The website needs no Python, shell execution, CUPS daemon or inbound connection to the printer computer. Shared hosting, VPS and dedicated servers use the same plugin when they meet its PHP/WooCommerce/database requirements and permit HTTPS REST/Application Password authentication. A powered, awake printer computer runs the agent. Direct HTTPS CUPS remains optional. No commercial service or mandatory printing license/subscription/per-job fee is introduced.

src/Printing/Agent/AgentQueue.php and src/Rest/AgentController.php implement exact-case route/user pairing, restricted Application Password authentication, atomic claim/start/receipt transitions, 600-second leases, eligibility rechecks and private no-store PDF responses. Claim tokens are hashed in the database. Prepared leases can requeue; started leases become unknown without replay. Late valid receipts can resolve their own unknown claim, and duplicate receipts do not dispatch or notify twice. The agent persists a private SQLite journal before irreversible steps, verifies PDF size/checksum, uses fixed subprocess argument arrays without a shell and retries receipts without reprinting. Agent polls advance bounded missed-payment reconciliation when WP-Cron is disabled. The agent role grants read and wcip_run_print_agent only; no operator printing/settings permissions.

R05/R07 reviewed portable PHP and Python; R08–R11 reviewed pairing, payment rechecks, leases and crash behavior; R13/R16/R17 reviewed the translated setup and RTL/LTR editions; R20/R21 reviewed authentication, no redirects, TLS, bounded PDF/JSON and local credential/journal handling; R24–R28 reviewed additive upgrade, free-software licensing and release packaging. These remain solo review disciplines.

Current checks PASS: composer validate --strict; PHP/JavaScript syntax; **318 PHP tests / 1,160 assertions** on PHP 8.5.8 and PHP 7.4.33; **23 JavaScript tests**; **9 local-agent unit tests**; fourteen **332-message** catalogs with plural/format checks. Real WordPress 6.6.2 / WooCommerce 9.0.2 / PHP 8.3 / MariaDB 10.11 HTTPS agent testing passed **20 assertions**, including real Application Password authentication, route rejection, expired prepared claims, stale tokens, one-use start, duplicate/conflicting receipts, disabled automation, two-copy virtual-printer dispatch and crash recovery without printer replay. **11 real-database assertions** passed for binary route isolation, expired start rejection, safe reclaim, user/token ownership and late unknown-to-submitted receipt resolution.

All fourteen final DOCX editions were rendered and every one of their **149 pages** visually reviewed at original resolution. Each edition includes native-language cross-platform setup and recovery with LTR technical passages; Persian/Arabic retain RTL prose and nine mirrored tables. Final hashes/counts are in system-development/manifest.json. Windows/Linux/macOS CI is configured in .github/workflows/print-agent.yml but was not run remotely. Native Windows printing, a hosting-provider matrix and physical printer/scanner acceptance remain untested. Linux end-to-end testing used a virtual CUPS printer. See docs/cross-platform-printing.md for deployment requirements.

The actual 1.4.0 production ZIP passed fresh-prefix activation on that real WordPress/WooCommerce stack: schema/capabilities 1.4.0, historical retirement stamp 1.3.0, CUPS/agent autoload classes, four agent columns and queue index, exactly two agent-role capabilities, seven PDF layouts, default-off automation/exports/fulfillment and nine exact locked production packages without PHPUnit. The populated 1.1.2 fixture passed against the packaged source with unchanged legacy job/confirmation values and nullable agent fields. The populated 1.3.0 CUPS fixture passed with unchanged settings, history, destination and discovery cutoff, restored scoped role and safe rerun. Reproduce these upgrade fixtures only on disposable sites using WCIP_RUN_UPGRADE_TEST=1 with tests/Integration/upgrade.php and tests/Integration/agent-upgrade.php. Neither fixture may be run on a production store.

## Historical open-source printing change in version 1.3.0

The user's open-source instruction superseded the attachment’s instruction to retain the commercial printing provider and pursue proprietary logistics integration. Version 1.3.0 removed the cloud API/client dependency and introduced free browser printing or direct HTTPS IPP to self-hosted OpenPrinting CUPS. No paid account, license, subscription or per-job printing service is required. Existing infrastructure/hardware and setup are prerequisites.

Added src/Printing/Cups/CupsProvider.php and IppCodec.php; removed the old provider implementation. Updated Plugin, settings, REST validation, admin/order/bulk UI, automatic job creation and reconciler. Named queue IDs replace positive numeric cloud IDs. The migration removes retired credentials, disables old automation, cancels old queued jobs and marks processing old jobs unknown, preserving submitted/confirmed history. No legacy job is redirected to CUPS. Stop old workers before upgrading; a PHP request already running old files cannot be recalled.

R05/R07 checked PHP 7.4 API/wire types; R08/R09/R10 checked eligibility and queue boundaries; R11 verified real CUPS TLS/IPP and ambiguity handling; R13/R16/R17 checked all locale catalogs and DOCX direction/layout; R20/R21 reviewed URL credentials, TLS, trusted-host authorization, no redirects, bounded parsing and secret masking; R24/R25 reviewed retirement migration and breaking-client guidance; R27/R28 checked package/free-software policy. Prior FR/T coverage below remains applicable, with the printing boundary replaced by CUPS tests. These are solo review passes, not independent approvals.

Historical 1.3.0 checks: composer validate --strict, PHP lint and JS syntax PASS; full PHP suite PASS 313 tests / 1,134 assertions on PHP 8.5.8 and PHP 7.4.33; JS PASS 21 tests; all fourteen catalogs PASS with 326 messages and correct plural/format checks. Real WordPress 6.6.2 / WooCommerce 9.0.2 / PHP 8.3 / MariaDB 10.11 remediation checks PASS 61 each with HPOS off/on; print queue smoke PASS 32 each. Real HTTPS CUPS 2.4.2 discovery/authentication/real PDF/two-copy acceptance/private-host guard PASS 7 assertions. Populated retired-provider migration PASS 21 assertions, including unchanged confirmed history and no destination remapping.

Authenticated local-printing HTTP checks PASS with HPOS off/on, including nonce protection, saved CUPS password masking, confirmation and unchanged order dates. Localization checks PASS for fourteen catalogs, 98 PDFs and five admin screens, including Japanese background locale versus Persian operator isolation. The clean 1.3.0 production package activated on a fresh database prefix with schema 1.2.0, printing migration 1.3.0, seven PDFs and opt-in defaults; only nine locked production packages were present, with no PHPUnit or stale provider autoload entry. The historical 1.3.0 DOCX editions were rendered and visually reviewed across 135 pages; current 1.4.0 checksums and counts are in system-development/manifest.json. Packaging verifies the binary patch through pristine-baseline replay and byte comparison.

The 1.2.0 test counts in the earlier verification table describe the preceding remediation run. They are not the current suite count. No physical printer/scanner, production host, multisite, independent translation reviewer or desktop office renderer was exercised. Current DOCX counts/checksums are in system-development/manifest.json. Current installable ZIP, standalone agent ZIP, documentation ZIP and binary patch are under build/ with version 1.4.0.

## Requirement traceability

Every requirement received R01/R02/R04/R23 review. Payment/queue changes also received R06/R08/R09/R10/R20; documents R12/R13/R16/R17; integrations R18/R19/R20/R21; release R24/R25/R26/R27/R28. R03/R05/R07/R11/R14/R15/R22 covered operational, plugin, PHP, printing, UI and performance implementation. Exceptions are recorded below.

| Requirement | Verdict | Main paths | Tests / limitations |
| --- | --- | --- | --- |
| FR-01 Accurate readable invoices | Partial: software implemented, paper pending | src/Invoice/InvoiceFactory.php; src/Pdf/MpdfRenderer.php; templates/classic, compact, thermal, shared | T18–T22/T31; saved totals, payment time, phone, full-width A5 names, thermal point fonts; no physical print |
| FR-02 Recipient shipping information | Implemented | src/Invoice/RecipientResolver.php; src/Admin/OperationsConsole.php | T18–T20/T26; shipping → mapped → optional billing, country formatting, nonshipping label rejection |
| FR-03 Generic payment handling | Implemented with adapter boundary | src/Automation/PaymentEligibilityPolicy.php | T01/T06–T08/T31; offline collection/partial settlement need authoritative hooks |
| FR-04 Separate payment/print/fulfillment | Implemented | src/PrintJob/DocumentPrintState.php; src/Fulfillment/FulfillmentService.php | T06/T14–T17; plugin warehouse state, unchanged WooCommerce status/payment/stock |
| FR-05 Delayed-payment printing | Implemented | src/Automation/AutomaticPrintHandler.php; src/PrintJob/PrintJobService.php; src/Automation/PrintWorker.php | T01–T05/T08/T09; stable automatic identity, eligibility recheck |
| FR-06 Missed-order reconciliation | Implemented | src/Automation/PaidOrderReconciler.php; src/Infrastructure/DatabaseLease.php; src/Rest/OperationsController.php | T02–T05/T09–T11/T30/T34; cursor/cutoff, bounded windows, selected historical preview |
| FR-07 Printed column/filtering | Implemented | src/Admin/OrderIntegration.php; src/Admin/OrderPrintFilters.php; src/PrintJob/PrintJobRepository.php | T06/T07/T14/T16/T17/T28/T30; native filters, visible batch, prior confirmation retained |
| FR-08 Delivery/recovery/diagnostics | Implemented at provider boundary | src/Automation/PrintWorker.php; src/Automation/Scheduler.php; src/Printing/Agent/AgentQueue.php; src/Rest/AgentController.php; print-agent/wcip_agent.py; src/Admin/OperationsConsole.php | T11–T16/T32–T34; safe retry/unknown, physical confirmation; real HTTPS CUPS and local-agent virtual-printer acceptance tested; native Windows/paper pending |
| FR-09 Independent warehouse documents | Partial: software implemented, paper pending | src/Template/; templates/shipping-label; templates/packing-list | T17/T20/T21/T29; independent identity, seven formats, no warehouse prices |
| FR-10 Configurable fulfillment | Implemented | src/Fulfillment/FulfillmentService.php; src/PrintJob/PrintConfirmationService.php | T15/T17/T32/T36; revisions/events, default-off confirmation transition, no retroactive replay |
| FR-11 Code 128/QR/scanning | Partial: digital path implemented, hardware pending | src/Invoice/DocumentCodeService.php; templates/shared/code.php; assets/js/operations.js | T24–T26; exact digital decoding, authenticated lookup; use QR on 58-mm media |
| FR-12 Logistics integration | Implemented generic framework; commercial adapters excluded by policy | src/Integration/ExportAdapterInterface.php; GenericWebhookAdapter.php; ExportService.php | T12/T25–T27/T36; durable signed intent/receipt; custom open-source receiver mapping requires its contract |
| FR-13 Weight/SKU/package model | Partial: generic model implemented; measured shipment data external | src/Invoice/WeightCalculator.php; src/Integration/ExportService.php | T22/T23/T36; frozen/variation/parent weights, units, refunds, missing vs zero; gross/tracking/shipped quantity remain null |
| FR-14 Security/i18n/resilience/accessibility | Partial validation | src/Rest/OperationsController.php; assets/js/operations.js; languages/; tests/ | T25–T28/T30/T31/T35/T36; permission/nonce/SSRF/schema/RTL checks; independent assistive-technology/editorial/multisite acceptance pending |
| FR-15 Docs/CI/upgrade/release | Implemented; runtime matrix limits below | README.md; readme.txt; docs/; system-development/; scripts/build-release.py; .github/workflows/php-compatibility.yml | T29/T32; populated migration, package installation, DOCX QA, binary patch |

## Decisions and preserved contracts

Confirmed payment normally requires native paid status plus a recorded paid timestamp. Refunded/cancelled/failed/pending/on-hold are excluded. Native COD/BACS/cheque gateway classes require collection evidence; gateway titles never prove funds. Trusted hooks accommodate nonstandard settlement; optional fulfillment eligibility is separately off by default.

No-job ineligible orders show a dash; eligible no-job orders show Not printed. Actual jobs show queued, printing, awaiting confirmation, needs review, failed or cancelled. Prior invoice confirmation preserves Printed through a failed reprint. Labels and packing lists cannot confirm an invoice.

Events and discovery share a durable upgrade/enablement payment-time cutoff. Paid/modified query windows, page size 50, persistent cursor, one-hour overlap and a database lease provide bounded reconciliation. Missing printer/configuration holds progress. Historical preview covers at most 31 days/100 rows per page without printing; enqueue needs selected IDs (maximum 50), the current user's 15-minute token and explicit confirmation. Changed transaction/date/status callbacks reuse a stable automatic invoice identity, including legacy identities. Terminal jobs are not resurrected; manual reprints create new jobs.

Physical confirmation locks the job transactionally, creates one private note, commits, then calls observers. Fulfillment has its own revision/event ledger; automatic preparing/packed/ready_to_ship transition is opt-in and only applies to new eligible invoice confirmation at revision zero. Observer interruption/failure requires warehouse review; it does not erase print evidence or trigger blind replay.

Code payloads are random 96-bit opaque references without PII/secrets. Possession grants no access. Lookup remains case-sensitive after supported AIM/CRLF normalization. HID reads are debounced; failed lookup clears stale actions. Codes are optional, locally generated. On 58-mm documents Code 128's image is omitted to preserve density; QR remains available.

Exports and recipient inclusion default off. Immutable bounded snapshots have a stable event/key, atomic claim and configuration fingerprint. Only valid accepted receipts become accepted; 429 retries are bounded, while timeout/408/5xx/malformed success/crash become unknown without automatic retry. The receiver must implement HMAC validation, replay protection and idempotent receipts. Gross/package/tracking/partial shipment facts are never invented.

## Source and migration

Plugin, schema and capability versions are 1.4.0. The separate historical commercial-provider retirement stamp remains 1.3.0. Four agent claim columns and a provider/route/status/id queue index are added and verified before the 1.4.0 schema stamp is written. Minimums remain PHP 7.4, WordPress 6.6 and WooCommerce 9.0. The job table adds default-invoice document type and indexes. Five operational tables hold leases, opaque references (binary ASCII comparison), fulfillment, fulfillment events and export outbox. Migration verifies installation before stamping versions. The exact populated 1.1.2 job fixture retained every prior value and confirmation in MariaDB 10.11; migration rerun also passed.

Major changes span bootstrap/wiring, activator/uninstall, settings, payment/worker/jobs/confirmation, invoice/PDF/templates, Orders UI, operations UI/REST/JS and translations. New unit/integration fixtures exercise these services. Locked mpdf/qrcode 1.2.2 adds local QR generation (LGPL-2.1-or-later); dependency licenses are included, with no independent legal review claimed.

Namespace, provider interface and hook signatures remain compatible. The printing API deliberately changes: /print accepts cups or agent and named queues instead of the retired cloud provider; agent destinations must match the configured pairing. Restricted agent claim/start/receipt routes require HTTPS and real WordPress Application Password authentication. Its settings/credential methods are replaced by CUPS origin/user/password fields; update custom clients. TemplateDefinition's optional eighth document_type argument defaults to invoice, retaining old seven-argument registrations. CUPS/agent/browser provider selection and custom-provider UI restrictions remain documented. Custom templates should copy shared detail/code behavior as needed; updates do not overwrite companion plugins.

Deactivation unschedules four plugin hooks and preserves history/queue intent. Uninstall preserves data unless wcip_delete_data_on_uninstall is explicitly enabled; removal affects plugin-owned tables/options only. Rollback cannot recall paper or receiver acceptance.

## Historical executed evidence for version 1 2

Baseline: 270 PHP tests / 1,022 assertions, 18 JavaScript tests, 14 catalogs / 239 messages. The preceding 1.2.0 remediation finished with 321 PHP tests / 1,173 assertions on PHP 8.5.8 and 7.4.33; 21 JavaScript tests. The table below preserves that earlier evidence. Current 1.4.0 results and historical 1.3.0 results appear above. Real integrations used WordPress 6.6.2 / WooCommerce 9.0.2 / PHP 8.3 / MariaDB 10.11, isolated test data, disabled cron and intercepted provider HTTP.

| Command / check | Result | Evidence boundary |
| --- | --- | --- |
| composer validate --strict; composer lint; composer lint:js | PASS | Lock/configuration and source/test/example syntax |
| composer test | PASS: 321 tests / 1,173 assertions | PHP 8.5.8, real PDFs and controlled WP/HTTP/DB doubles |
| docker run --rm --network none -v "$PWD:/app:ro" -w /app wordpress:php7.4-apache php vendor/bin/phpunit | PASS: same 321 / 1,173 | PHP 7.4.33; read-only PHPUnit cache warning harmless |
| composer test:js | PASS: 21 tests | Operations nonce URLs/scans/stale actions plus existing admin/confirmation |
| composer i18n:check and gettext MO compilation | PASS | All 14 catalogs / 325 messages; plural, placeholders, nonempty and nonfuzzy |
| composer audit --locked --no-dev | PASS: no advisories found | Successful network retry following sandbox DNS failure |
| remediation.php, WCIP_RUN_INTEGRATION_TESTS=1 | PASS: 61 each, HPOS on/off | Real DB/native filters/discovery/fulfillment/export, unauthorized/subscriber denial, DST, seven PDFs |
| smoke.php, WCIP_RUN_INTEGRATION_TESTS=1 | PASS: 32 assertions each, HPOS on/off | Real Action Scheduler, bounded retries, unknown outcome, REST, lifecycle |
| local-printing.php, WCIP_RUN_INTEGRATION_TESTS=1 | PASS, HPOS on/off | Real authenticated HTTP/nonces, awaiting confirmation → Printed, one note, unchanged saved dates/sorting; no browser script execution |
| localization.php, WCIP_RUN_INTEGRATION_TESTS=1 | PASS: 14 × 7 = 98 PDFs | Actual WP locale/direction/plural/five admin screens; Japanese background versus Persian operator isolation |
| upgrade.php, WCIP_RUN_UPGRADE_TEST=1 | PASS | Exact populated 1.1.2 job preservation, rerunnable schema/cutoff |
| concurrency.php phases, WCIP_RUN_CONCURRENCY_TEST=1 | PASS | Three event plus two reconcile PHP processes → one automatic job/action; two confirmations → one note/fulfillment event |
| Digital code raster/decode | PASS | 68-mm Code 128 and 25-mm QR at 203 DPI decoded to exact reference with zxing-cpp; hardware absent |
| Canonical DOCX render/every-page visual review | PASS: 14 editions / 134 pages | RTL/run language, nine mirrored tables, LTR identifiers and Indic/CJK fonts; final hashes/page counts in manifest |
| ZIP CRC/allowlist/exact production packages/clean activation | PASS | Nine production packages; no PHPUnit; fresh DB prefix; 1.2.0 schema/default-off options and seven PDFs |
| Binary patch replay/byte comparison | PASS | Includes untracked source plus MO/DOCX binaries; baseline reproduced in a temporary directory |

PHP 8.0/8.1/8.2/8.4 are configured in CI but not executed for this remediation. PHP 8.3 received real integrations, rather than the full unit suite. WPCS/static analysis, native editorial, desktop Word, independent screen-reader/contrast, multisite, physical paper/scanner and native Windows/physical CUPS acceptance are not claimed.

The local HTTP fixture initially used localhost inside a CLI container; its isolated URL was corrected. Obsolete width/status expectations were aligned with receipt dimensions and awaiting-confirmation behavior. Visual review found thermal pixel fonts and cramped A5 names; fixed point sizes/receipt widths/full-width descriptions were rechecked.

Run the integration files with WP-CLI eval-file on a disposable activated site, repeating legacy and HPOS storage. Upgrade testing requires an empty job table and reconstructs the old schema. Concurrency phases are prepare → concurrent event/reconcile → verify → concurrent confirm → verify_confirmation → cleanup. Each fixture's header documents its explicit opt-in environment guard. Never run upgrade/concurrency fixtures in production.

## Acceptance matrix

Unit names below refer to tests/Unit/; real fixtures to tests/Integration/. PASS describes executed checks, with additional acceptance scope explicit.

| IDs | Verdict | Tests |
| --- | --- | --- |
| T01–T04 | PASS | AutomaticPrintHandlerTest, PaidOrderReconcilerTest, PrintJobServiceTest, PrintWorkerTest, remediation.php: immediate/delayed/missed/duplicate events |
| T05 | PASS | concurrency.php separate PHP workers; DatabaseLease and unique DB keys |
| T06–T08 | PASS | PaymentEligibilityPolicyTest, PrintWorkerTest, remediation.php: unpaid/offline/reversed/date and worker checks |
| T09–T10 | PASS | upgrade.php, remediation.php, PaidOrderReconcilerTest: cutoff, historical payment, dry-run/token/selection |
| T11–T13 | PASS at simulated provider boundary | PrintWorkerTest, CupsProviderTest, RetryPolicyTest, GenericWebhookAdapterTest, SchedulerTest, smoke.php |
| T14–T17 | PASS | RemediationTest, PrintConfirmationTest, concurrency.php, local HTTP/remediation: awaiting/one confirmation/prior evidence/document isolation |
| T18–T20 | PASS | RecipientResolverTest, RemediationTest, real fixture: phone/mapping/fallback/nonshipping rejection |
| T21 | PARTIAL | MpdfRendererTest: 120 long mixed-RTL items/notes; first/middle/last representative pages reviewed in seven formats; no paper |
| T22 | PASS within fixtures | InvoiceFactoryTest, ReadOnlyDataTest, smoke.php: saved currency/tax/discount/refund/deleted-product handling |
| T23 | PASS | RecipientResolverTest and real export: frozen/current/variation/parent, zero/missing/fractional and unit conversion |
| T24 | PARTIAL | Digital PDF decode plus REST/JS scan parsing pass; printer/scanner density proof pending |
| T25–T26 | PASS within negative-test scope | REST/settings/logo/provider/adapter unit tests, real anonymous/subscriber/object/schema/burst denial, HTTP nonce denial |
| T27 | PASS with intercepted receiver | GenericWebhookAdapterTest, remediation.php: immutable intent/key/receipt; production receiver replay behavior still needs acceptance |
| T28–T29 | PASS | Real HPOS on/off and populated old schema; existing constructor/provider/hook regression suites |
| T30 | PASS representative synthetic fixture | Virtual 100,000-order catalog pages 50/50, cursor advances, allocation under 16 MiB; visible batch/indexes. No 100,000-row SQL benchmark |
| T31–T34 | PASS within fixtures | Real DST/locale isolation; LifecycleTest, REST bulk, scheduler recovery/diagnostics and actual queue checks |
| T35 | PARTIAL | Text statuses, labels, semantic tables, live regions/keyboard controls and RTL implemented; assistive-technology audit pending |
| T36 | PASS | Fresh package defaults and disabled-export fixture; recipient excluded without explicit opt-in |

## Safety and compatibility audit

Saved financial/order semantics are preserved. Production order changes use public CRUD and only add a private confirmation note; tests verify unchanged order dates/sorting. Native payment/status/stock are never changed by warehouse or print workflows. Discovery uses wc_get_orders/native hooks, without reading WooCommerce order SQL. Custom settlement filters need equivalent native query adapters for Orders filtering.

New routes enforce scalar/collection schemas, granular capabilities, per-order access, cookie REST nonce, rate limit and bounded requests. Existing whole-store print/history capability scope is preserved. Outputs use escaping/textContent; scanning returns minimal fields; raw codes contain no PII. Webhooks use public HTTPS exact-host allowlists, safe HTTP validation, TLS, no redirects/private IPs/credentials/fragments/non-443 ports, bounded time/body and HMAC. PDF private temp files are cleaned on success/failure. Secret constants take precedence; DB secrets remain plaintext in backups but are not echoed in forms/errors.

Enabled export recovery clears accepted/failed bodies older than 30 days in batches of 50, retaining keys/receipts; unknown bodies remain for investigation. Disabled recovery needs authorized manual retention cleanup. No WordPress personal-data erasure callback is supplied: coordinate store/receiver/CUPS deletion through the store privacy process. Immutable export intent can differ after later order edits. Catalog weights are explicitly marked unless checkout froze item weights. No regulatory certification is claimed.

Physical exactly-once printing and remote exactly-once side effects cannot be guaranteed. Acceptance followed by interruption becomes unknown; inspect history/paper/receiver before repeating. Postcommit observer interruption requires manual warehouse review.

Official references verified on the review date: [order queries](https://developer.woocommerce.com/docs/features/orders/wc-get-orders/), [HPOS recipe](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/), [Action Scheduler API](https://actionscheduler.org/api/), [CUPS IPP operations](https://openprinting.github.io/cups/doc/spec-ipp.html). Installed minimum-version classes/tests govern behavior; new documentation does not backport APIs.

## Installation, upgrade and rollback

1. Back up database, plugin files/options, companion templates and queue history. Use staging first and disable printing/exports while comparing pending work.
2. Upload build/wc-invoice-printer-1.4.0.zip through WordPress Plugins with WooCommerce active. Activation/update applies additive schema 1.4.0. Check Diagnostics and wcip_db_version.
3. Configure WooCommerce → Invoice Printer → Documents & shipping. Test intended media/phone/payment time/template; pair the local agent using docs/cross-platform-printing.md or configure direct self-hosted CUPS using docs/open-source-printing.md. Enable automation only after one newly paid order, queue and paper confirmation are verified. Review cutoff/cursor in Diagnostics.
4. Reconciliation preview never prints. Explicitly select historical orders before enqueue. Keep export/automatic fulfillment off until receiver/payment/defaults are accepted. Submitted remains awaiting paper confirmation.
5. Rollback: disable printing/exports, deactivate to stop actions, preserve history and investigate unknown/accepted outcomes. Restore prior files plus a consistent DB backup if schema restoration is needed; reconcile legitimate postbackup store transactions with an administrator. Files alone cannot reverse remote/physical output. Do not use opt-in uninstall deletion as rollback.

Detailed setup, hooks/routes, receiver schema/signatures, retention and physical acceptance procedure: [docs/operations.md](docs/operations.md). All 14 DOCX guides and companion example: [system-development](system-development/README.md). Its removed source/ folder stays absent; edit DOCX directly.

## Integration readiness and release artifacts

Generic integration is wired to authenticated operator/scan actions, durable outbox/history, scheduler/recovery and persisted receipts. An optional open-source receiver adapter needs the real API/version/endpoints, sandbox credentials, authentication, SKU/package mappings, measured weights, partial shipment rules, idempotency/status/receipt/error contracts and vendor acceptance. No proprietary endpoint is implemented or required. Commercial adapters are excluded from the current scope.

Deliverables: build/wc-invoice-printer-1.4.0.zip and .sha256; build/wcip-1.4.0-print-agent.zip and .sha256; build/wcip-1.4.0-remediation.patch and .sha256; build/wcip-1.4.0-development-documents.zip and .sha256. Patch includes new source and binary DOCX/MO changes; source remains the working tree. Production ZIP includes curated local-agent source/configuration/license, cross-platform setup, CUPS migration and operations instructions, and excludes tests, development dependencies and the DOCX folder. Its builder regenerates optimized autoload metadata against the staged source even when reusing verified production dependencies. Documentation ZIP includes fourteen final editions, index/manifest/verification, companion example, root readmes, the remediation record and all Markdown guides under docs/, plus the curated agent source/configuration/license. The standalone agent ZIP preserves print-agent/ and docs/ paths so its setup links resolve. External hash files avoid self-referential patch checksums in this record.

## Open acceptance work

| Severity | Reason and impact | Remediation | Roles |
| --- | --- | --- | --- |
| High before unattended printing rollout | No native Windows or physical printer/scanner; Linux virtual CUPS acceptance only; native drivers/media/density/offline delivery unproven | Execute documented paper, Code 128/QR/HID, multi-copy and offline acceptance per printer | R03/R11/R12/R18/R23 |
| Medium / custom receiver deployment | Generic receipt is insufficient proof for a particular open-source WMS/ERP | Obtain its contract/mappings and implement/test an open-source, fee-free adapter | R17/R19/R20/R21/R24 |
| Medium / store data | Historical checkout weight, measured packaging/tracking and nonstandard settlement cannot be inferred | Trusted checkout/payment/package adapters; match native filter semantics | R06/R09/R17/R19 |
| Medium / deployment validation | Remaining PHP CI versions, SQL-scale, multisite and independent assistive-technology checks not executed | Run configured CI and target-store load/accessibility/multisite acceptance | R05/R16/R22/R24/R25 |
| Medium / privacy operations | No automatic erasure hook; retained/unknown payloads and receiver copies need review; DB secrets plaintext | External secret constant, restricted backups, documented authorized retention/erasure workflow | R20/R21/R27 |
| Low / editorial tooling | Independent native proofreading, desktop Word and WPCS/static analysis not performed | Native-editor/font review and coding-standards audit | R05/R13/R16/R26 |

No known failing P0 software check remains. External/validation limits above prevent universal production-acceptance claims. Release is prepared for staging evaluation within these limits.
