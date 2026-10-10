# Admin UI specification

All screens live under WooCommerce → Invoice Printer and share compact tab navigation.

| Screen | Goal | Primary action | Secondary actions | Empty/loading/error/success |
|---|---|---|---|---|
| General | Set invoice identity and defaults | Save changes | Choose logo/display fields | Inline save notice and field errors |
| Templates | Compare real invoice layouts | Select template | Preview, RTL preview | Skeleton preview; accessible notice on failure/success |
| Automatic Printing | Configure the paid-order workflow | Enable and save | Select template/copies | Dependencies hidden while disabled; setup summary |
| Printers | Pair the local agent or connect CUPS | Save pairing/printer | Agent last-seen timestamp; CUPS connection, refresh and test | Explicit pairing/configuration/loading/error/submitted states |
| Print Jobs | Resolve operational issues | View details | Safe retry, reprint, cancel queued | Explanatory empty state; paginated/filterable table |
| Order print panel | Print with minimal clicks | Print | Preview, browser/CUPS/agent destination, template/copies | Busy controls, queued versus physical-confirmation wording, inline errors |

The layout uses semantic controls, visible focus, text-bearing statuses, logical CSS properties for RTL, and collapses to one column below the WordPress mobile breakpoint.

## Implemented 1.2 controls

Documents & shipping exposes country-aware recipient fallback, trusted metadata mapping, paid date/phone/sender visibility, timezone and optional fulfillment stage after confirmation. Reconciliation has UTC date inputs, dry-run rows, explicit selection/authorization and interval/policy settings. Barcode & integrations contains keyboard-scanner lookup, a semantic item table, separate stage changes and privileged exports; credentials remain masked. Diagnostics exposes bounded operational JSON for support. Buttons announce pending, success and failure through a polite live region. A failed scan clears previous order actions. Code format guidance recommends QR for 58 mm.

Orders filters and text distinguish ineligible, missing job, queued, printing, submitted/awaiting confirmation, unknown, failed and explicitly printed. A prior confirmed invoice remains printed when a later manual attempt fails. Label and packing-list confirmation are separate. Controls have labels and keyboard support; a formal assistive-technology/WCAG certification has not been performed.
