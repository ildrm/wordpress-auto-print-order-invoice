=== WooCommerce Invoice Printer ===
Contributors: wc-invoice-printer
Tags: woocommerce, invoice, print, printnode, pdf
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Secure browser and automatic PrintNode invoice printing for WooCommerce.

== Description ==

WooCommerce Invoice Printer creates invoices from WooCommerce's public order APIs and supports HPOS. Administrators and Shop Managers can preview and print individual or bulk invoices. Automatic printing uses a persisted idempotent print job and WooCommerce's Action Scheduler, so payment completion never waits for PDF generation or a remote printer.

PrintNode receives the generated invoice PDF when selected. This may include customer names, addresses, contact details, and purchased items. Configure PrintNode and your privacy disclosures accordingly.

The plugin does not claim jurisdiction-specific fiscal or tax compliance. WooCommerce order numbers are displayed as invoice references.

== Installation ==

1. Run `composer install --no-dev --optimize-autoloader` before packaging, or install a release archive that includes vendor dependencies.
2. Upload and activate the plugin.
3. Open WooCommerce > Invoice Printer.
4. Configure invoice identity and a template.
5. To use PrintNode, save an API key or define `WCIP_PRINTNODE_API_KEY`, refresh printers, and send a test page.

== Data retention ==

Deactivation preserves settings and print history. Uninstall also preserves data unless the `wcip_delete_data_on_uninstall` option is explicitly enabled by an operator. Print jobs retain operational references and sanitized errors, but no duplicated invoice/customer payload.

== Changelog ==

= 1.0.0 =
* Initial release with HPOS-compatible invoice data, three templates, browser printing, PrintNode automation, and print-job history.
