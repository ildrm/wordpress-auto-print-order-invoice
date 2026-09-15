# Admin UI specification

All screens live under WooCommerce → Invoice Printer and share compact tab navigation.

| Screen | Goal | Primary action | Secondary actions | Empty/loading/error/success |
|---|---|---|---|---|
| General | Set invoice identity and defaults | Save changes | Choose logo/display fields | Inline save notice and field errors |
| Templates | Compare real invoice layouts | Select template | Preview, RTL preview | Skeleton preview; accessible notice on failure/success |
| Automatic Printing | Configure the paid-order workflow | Enable and save | Select template/copies | Dependencies hidden while disabled; setup summary |
| Printers | Connect PrintNode and choose output | Save printer | Test connection, refresh, test print | Explicit unconfigured/no-printers/loading/error/submitted states |
| Print Jobs | Resolve operational issues | View details | Safe retry, reprint, cancel queued | Explanatory empty state; paginated/filterable table |
| Order print panel | Print with minimal clicks | Print | Preview, output/template/copies | Busy controls, clear browser-vs-PrintNode wording, inline errors |

The layout uses semantic controls, visible focus, text-bearing statuses, logical CSS properties for RTL, and collapses to one column below the WordPress mobile breakpoint.
