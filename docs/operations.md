# Shipping documents and operations

Version 1.4.0 retains shipping documents and operational services while retaining the plugin’s namespace, browser printing, the local cross-platform agent and the open-source CUPS provider, old template IDs, job states and extension contracts. Automatic printing, fulfillment automation and exports are off by default. Preparation, payment, provider acceptance and physical confirmation remain independent.

## Configure a store

Open **WooCommerce → Invoice Printer**. Set branding in General. Select a manual template in Templates and an invoice-only automatic template in Automatic Printing. The registered IDs are `classic`, `compact`, `classic-a5`, `thermal`, `thermal58`, `shipping-label` and `packing-list`. Invoice layouts retain saved WooCommerce money/currency/tax/discount data; labels and packing lists omit commercial prices. Thermal PDFs use 58/80 × 297 mm pages rather than guessing roll length. Browser paper is controlled by the driver. Use QR on 58 mm: Code 128 is deliberately omitted at that width.

Documents & shipping controls billing fallback for missing recipient components, recipient phone, payment date visibility, sender visibility and the document timezone (`site` or an IANA zone). Recipient data uses shipping components first, mapped trusted metadata next, and optional billing fallback last. Shipping phone uses shipping, mapped then billing phone. WooCommerce formats country-specific addresses. Only explicitly mapped scalar extra fields print. The mapping is a JSON object with at most 20 entries, for example:

```json
{"phone":"_delivery_phone","unit":"_delivery_unit","address_1":"_delivery_street"}
```

Use your actual saved order-meta keys; the plugin does not create checkout fields. Keys must match the resolver’s safe identifier rules. Malformed JSON is rejected by the admin form. Virtual/non-shipping orders cannot create labels by default; a trusted extension may use `wcip_allow_nonshipping_label` for an actual pickup/delivery requirement. Verify real orders as sample previews do not invoke order-data filters.

## Payment and delayed orders

The shared default policy requires `is_paid()` and `get_date_paid()`, excludes cancelled/failed/refunded/trash/draft orders, and treats native COD/BACS/cheque classes as offline collection. A paid WooCommerce status alone does not establish collection for those methods. No gateway ID is hard-coded. A bank transfer that has actually cleared needs a trusted adapter/collection marker. Unknown gateways follow WooCommerce semantics; split payment, BNPL and authorization/capture distinctions require adapters.

The opt-in fulfillment policy permits WooCommerce-paid workflow states, including collection methods, without asserting money was collected. Payment dates display only for confirmed payment. Do not use print status as evidence of bank settlement. The CUPS worker rechecks eligibility before rendering and immediately before dispatch; the agent checks before PDF delivery and its start authorization; a payment can still change after that last check.

```php
add_filter( 'wcip_payment_confirmed', function ( $confirmed, $order, $semantics ) {
    if ( 'offline' === $semantics ) {
        return 'yes' === $order->get_meta( '_verified_collection', true );
    }
    return $confirmed;
}, 10, 3 );
```

This example is valid only if the store’s trusted payment integration actually writes that collection marker. A matching `wcip_print_filter_query_args` adapter must supply equivalent native query constraints for Orders filtering.

## Discovery and historical printing

Payment-complete and paid-status hooks remain active. A separate reconciler discovers missing automatic invoice jobs using native WooCommerce paid/modified queries, a database lease and a persisted UTC-window/page cursor. It processes at most 50 IDs per run with one query phase, defaults to 600-second cadence (300–3600 configurable), and overlaps completed windows by one hour. Creation date does not limit newly paid orders. A recorded paid date before the enable/upgrade cutoff is excluded even when modified recently. Missing printer configuration holds the cursor and reports a blocked run. Existing automatic rows, including failed/unknown/cancelled ones, prevent a fresh automatic job.

Upgrade and enablement establish `wcip_discovery_since` at that time. Reconciliation does not silently backfill older payments. To print history, use Reconciliation: choose a UTC range of at most 31 days, preview, select missing eligible orders, explicitly authorize submission and enqueue. Preview creates no job. Its random token is user-bound, expires after 15 minutes and authorizes only IDs on that page; enqueue accepts at most 50, rechecks permissions/eligibility and refuses unpreviewed IDs. Changing selection pages requires a new preview. Disabling automation prevents new discovery; queued jobs remain reviewable and may be cancelled.

Diagnostics reports cron availability, Action Scheduler initialization, printer setup, cutoff, cursor and last counters. For direct CUPS/exports with WP-Cron disabled, configure an external runner. Agent polling supplies bounded payment discovery and print delivery without a hosting cron runner. Existing queued-job recovery remains separate from paid-order discovery. Unknown print/export outcomes require investigation and are never blindly replayed.

## States and fulfillment

Orders displays a dash for ineligible orders; eligible orders with no invoice job show Not printed. Queued, printing, awaiting confirmation, failed and needs-review are separate text states. Confirm printed is an operator’s declaration after checking paper. Provider acceptance and a browser dialog do not prove physical printing. Prior confirmed invoice evidence persists after a failed reprint. Job document filters distinguish invoices, labels and packing lists; confirming a label never confirms the invoice.

Warehouse stages are `not_started`, `preparing`, `packed`, `ready_to_ship`, `shipped`, `delivered`. They use plugin tables, revisions and audit events, never native WooCommerce order/payment/stock mutations. Automatic transition after invoice confirmation is off by default; select preparing, packed or ready_to_ship explicitly. It applies only to a new confirmation on an eligible invoice and revision zero. Enabling it later does not replay old confirmations. Manual changes require current revision and an event key; conflicting changes return 409. Printing alone never advances a stage. Tracking and measured package data must come from a shipping integration/operator, not a print event.

## Scanner and weights

Enable opaque document codes in Barcode & integrations. Code 128/QR encode a random 96-bit `W1-` reference, stable per order/document/package, with no customer data or credential. References are case-sensitive and provide no authorization. HID scanners submit on Enter; supported AIM prefixes and CR/LF are normalized. Duplicate UI reads are briefly debounced. Authenticated scan returns order/item identifiers, quantities and weight provenance, excludes recipient PII, and leaves physical confirmation unchanged. Failed lookups disable previous order actions.

Unit weight uses frozen `_wcip_unit_weight_kg` if supplied by trusted order capture, otherwise variation/product weight, then optional parent fallback. Catalog weights are labelled as current rather than historical. Units convert through WooCommerce to kg; fractional quantities are retained. Missing weight is null/incomplete; zero is real zero. Export includes purchased/refunded quantities; shipped quantity, measured gross weight and tracking are null when not supplied. Core does not split packages automatically. A package-aware extension may call the code/export services with a validated package identity and provide a payload adapter.

## Generic webhook contract

No proprietary carrier/ERP endpoint is bundled. Enable export, configure an exact public HTTPS host, endpoint and signing secret of at least 32 characters. The WordPress safe HTTP client rejects unsafe URLs, verifies TLS, follows no redirects and limits response bytes. Never allowlist an untrusted host; destination DNS/administrative control remains part of trusted configuration. Configure `WCIP_EXPORT_SIGNING_SECRET` in managed server settings instead of storing it in the database when practical. A nonempty string overrides saved credentials; secrets never appear in API/history output.

POST body is an immutable UTF-8 JSON snapshot, at most 256 KiB, with `schema_version: "1.0"`, `event: "order_export"`, order/number/currency, package/shipping method, stable item/product/variation IDs, SKU and missing flag, name/variation, quantity/refunded quantity, weight values/source/completeness, net item weight, measured gross weight and tracking. Recipient data is omitted unless separately opted in. Review `ExportService::payload()` for exact fields. Payload filtering is trusted code and must preserve the agreed schema and privacy controls.

Headers:

```text
Content-Type: application/json
Idempotency-Key: <64 lowercase hex characters>
X-WCIP-Timestamp: <Unix seconds>
X-WCIP-Signature: sha256=<hex HMAC>
```

HMAC-SHA256 uses the secret and the exact bytes `timestamp + "\n" + idempotency_key + "\n" + body`. The receiver must verify with constant-time comparison, reject stale timestamps (for example ±300 seconds), bound input, validate schema and atomically persist a unique idempotency key with body hash and durable receipt before acknowledging. An identical replay returns the same receipt without another business effect; conflicting content for the same key is rejected. A bare HTTP 2xx is insufficient:

```json
{"accepted":true,"receipt":"receiver-unique-receipt"}
```

Receipt must match `[A-Za-z0-9._:-]{1,191}`. The worker atomically claims an outbox row. Configuration changes freeze queued work as failed before delivery. Only definite 429 rejection allows three total attempts with bounded delays. Timeout, 408, 5xx, malformed receipt or interrupted processing becomes unknown and is not replayed automatically. Receiver acceptance does not prove picking, shipment, delivery or paper output. A demo connection test sends no order payload. Manual, scan and packed-stage triggers have stable independent event identities and are separately authorized/default-off. A manual click retries the same intent during the page session; deliberate new events can create new exports.

Implement `ExportAdapterInterface` (`id`, `capabilities`, `validate_configuration`, `test_connection`, `submit`) and return it through `wcip_export_adapter`. `submit` returns a durable receipt or throws a correctly classified `ProviderException`. Open-source receiver readiness requires actual API specifications, sandbox credentials, field/unit/package mappings, idempotency and replay guarantees, error/status semantics, data-processing requirements and end-to-end acceptance tests.

## REST and extension reference

Namespace remains `wc-invoice-printer/v1`. Use WordPress cookie authentication with `X-WP-Nonce`, or another supported WordPress authentication method. Granular capabilities do not replace object authorization; operations check `edit_shop_order` and `wcip_can_access_order`. New mutation endpoints apply a per-user burst limit. No public scan/lookup exists.

| Route | Body or behavior | Capability |
| --- | --- | --- |
| POST `/reconciliation/preview` | integer UTC `from`, `to`, optional page | settings + print |
| POST `/reconciliation/enqueue` | integer array `order_ids`, token, `confirm:true` | settings + print + order access |
| POST `/scan` | `reference` string | `wcip_scan_orders` + order access |
| POST `/fulfillment/{id}` | stage, integer revision, event_key | `wcip_manage_fulfillment` + order access |
| POST `/exports` | integer order_id, event_key | `wcip_export_orders` + order access |
| GET `/exports/history` | 20 latest metadata rows, no snapshots/secrets | export |
| POST `/exports/test` | demo data only | export + settings |
| GET `/diagnostics` | queue/discovery configuration | settings + print |

Administrators get all new capabilities. Shop Managers get scan and fulfillment alongside existing print/history; export/settings stay administrator-only by default. Manual /print accepts CUPS or the paired local agent. The dedicated wcip_print_agent role has read and wcip_run_print_agent only; HTTPS Application Password authentication must match its configured user and logical queue. Agent claim/start/receipt routes do not grant operator access.

| Hook | Exact arguments |
| --- | --- |
| `wcip_payment_method_semantics` | semantics, WC_Order, gateway → string |
| `wcip_payment_confirmed` | bool, WC_Order, semantics → bool |
| `wcip_fulfillment_eligible` | bool, WC_Order → bool |
| `wcip_recipient_data` | array, WC_Order → array |
| `wcip_weight_parent_fallback` | bool, item → bool |
| `wcip_item_weight` | array, item, quantity → array |
| `wcip_print_filter_query_args` | native query args → array |
| `wcip_can_access_order` | bool, WC_Order, capability → bool |
| `wcip_print_confirmed` | committed job array |
| `wcip_fulfillment_changed` | committed state array, event |
| `wcip_export_adapter` | adapter, SettingsRepository → ExportAdapterInterface |
| `wcip_export_payload` | payload, WC_Order, package_id → array |

`TemplateDefinition` adds an optional eighth `document_type` argument, default `invoice`; existing seven-argument registrations remain valid. Accepted types are invoice, shipping_label, packing_list. Automatic templates must be invoices. Existing public namespace, route contracts, hook signatures and stored statuses remain intact. Reference lengths/protocol are new in this release.

## Storage, privacy and upgrade

Schema/capability version is 1.4.0. The job table includes document indexes plus agent token hashes, paired user, phase, claim time and a destination/status queue index. No PDF snapshot is stored. Five plugin tables hold leases, opaque references, fulfillment, audit events and the export outbox. Migration is additive and checks installation success before stamping a version. Repeated migration and populated 1.1.2 preservation were executed against MariaDB. Keep database backups; do not downgrade after using new features without disabling automation and reviewing queued actions.

PDF working directories are private and removed after rendering. PDF HTML is bounded at 4 MiB and output at 20 MiB. Export snapshots may contain order item text and opted-in recipient data. Recovery clears accepted/failed snapshot bodies older than 30 days, at most 50 per run, retaining receipts/idempotency history. Unknown payloads remain for investigation. If recovery is not scheduled (exports disabled), administrators must perform retention cleanup separately. History/references/warehouse audits are not automatically erased; use an authorized store privacy process and coordinate deletion with receivers/CUPS. No claim of regulatory compliance is made. Logs/errors avoid raw payloads and credentials.

Deactivate unschedules plugin actions and retains rows. Reactivation recovers eligible queued work. Uninstall removes capabilities but retains data unless `wcip_delete_data_on_uninstall` is explicitly enabled; that opt-in removes only plugin tables/options, not WooCommerce orders. Multisite requires separate validation.

Upgrade on staging: back up DB/files, disable automation/exports, update the production ZIP, activate, verify version/schema, preview a real order and all media, inspect old confirmations, configure payment policy and verify cutoff, pair and test the local agent or CUPS server/queue/paper, then enable selected automation. Rollback: disable features and pending actions, restore the previous files and the matching database backup together. Preserve current job evidence before restoring so uncertain physical submissions are investigated rather than repeated.

## Ten minute merchant acceptance

1. Confirm branding, shipping phone/address, mapped fields, paid date/timezone and saved totals on an ordinary and a delayed-payment order.
2. Print one invoice, label and packing list using the actual driver/media; inspect every page, RTL mixed phone/SKU and long notes.
3. Scan Code 128/QR using the actual HID device; verify the correct order, missing weight and package behavior. Select QR for 58 mm.
4. Check submitted remains awaiting confirmation, then confirm checked paper once; repeat confirmation and verify one private note/stage effect.
5. Test an unpaid and COD order against the chosen policy; repeat a payment callback and check one logical auto job.
6. Preview history without printing, select one authorized order and check enqueue. Review queued/unknown recovery instructions and cron health.
7. If using export, test the receiver’s signature/replay/idempotency behavior with sandbox data before opting into recipient sharing.

Physical printers/scanners, proprietary services, assistive-technology review and real production-volume profiling require the merchant’s environment. Executed software evidence and outstanding checks are in [the remediation report](../REMEDIATION_TRACEABILITY.md).

## Cross platform open source printing

Use the outbound agent for Windows workstations and shared hosting; use direct CUPS when WordPress can reach a print server. WordPress hosting needs no printer applications, Python or shell commands. See [cross-platform setup and agent protocol](cross-platform-printing.md) and [CUPS migration](open-source-printing.md). Schema/capability versions are 1.4.0; the independent retirement stamp remains 1.3.0. REST /print accepts provider_id=agent with the paired logical queue or provider_id=cups with exact CUPS queue names. Historical jobs retain their original destinations and confirmations.

Commercial and proprietary adapters are outside the current authorized scope. Custom adapters must run through open-source receivers without mandatory service, subscription or per-job fees.
