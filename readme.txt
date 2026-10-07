=== WooCommerce Invoice Printer ===
Contributors: wc-invoice-printer
Tags: woocommerce, invoice, print, printnode, pdf
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Secure browser and automatic PrintNode invoice printing for WooCommerce.

== Description ==

WooCommerce Invoice Printer creates invoices from WooCommerce's public order APIs and supports HPOS. Administrators and Shop Managers can preview and print individual or bulk invoices. Automatic printing uses a persisted idempotent print job and WooCommerce's Action Scheduler, so payment completion never waits for PDF generation or a remote printer.

PrintNode receives the generated invoice PDF when selected. This may include customer names, addresses, contact details, and purchased items. Configure PrintNode and your privacy disclosures accordingly.

Automatic printing is disabled by default and follows WooCommerce's paid state. A payment-complete hook and optional paid-status fallback create one automatic job per order. Pending/on-hold orders are not automatically printed. Offline gateways may assign paid statuses before cash collection; extensions can disable the fallback or apply stricter eligibility rules.

PrintNode acceptance is not confirmation of paper output. HTTP 429 rejections receive bounded safe retries. Transport errors, HTTP 408/5xx submission responses, and unreadable successful responses are unknown and require checking PrintNode before intentionally reprinting.

The plugin does not claim jurisdiction-specific fiscal or tax compliance. WooCommerce order numbers are displayed as invoice references. See README.md for complete configuration, recovery, API, extension, and testing documentation.

== Installation ==

1. Run `composer install --no-dev --optimize-autoloader` before packaging, or install a release archive that includes vendor dependencies.
2. Upload and activate the plugin.
3. Open WooCommerce > Invoice Printer.
4. Configure invoice identity and a template.
5. To use PrintNode, save an API key or define `WCIP_PRINTNODE_API_KEY`, refresh printers, and send a test page.

== Data retention ==

Deactivation cancels pending plugin actions and preserves settings/history for recovery on reactivation. Uninstall removes plugin role capabilities and preserves data unless the `wcip_delete_data_on_uninstall` option is explicitly enabled by an operator. Print jobs retain operational references and sanitized errors, but no duplicated invoice/customer payload.

== Changelog ==

= 1.0.0 =
* Initial release with HPOS-compatible invoice data, three templates, browser printing, PrintNode automation, and print-job history.
* PHP 7.4+ compatible source, production dependencies, and development test suite.
