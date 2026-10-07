=== WooCommerce Invoice Printer ===
Contributors: ildrm
Tags: woocommerce, invoice, print, printnode, pdf
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later

Secure browser and automatic PrintNode invoice printing for WooCommerce.

== Description ==

WooCommerce Invoice Printer creates invoices from WooCommerce's public order APIs and supports HPOS. Administrators and Shop Managers can preview and print individual or bulk invoices. Automatic printing uses a persisted idempotent print job and WooCommerce's Action Scheduler, so payment completion never waits for PDF generation or a remote printer.

Locally connected printers work through the browser print dialog without a PrintNode account or API key. The Printers page includes a local test page. The PrintNode printer list only shows devices shared by its client; local devices are selected in the browser dialog.

PrintNode receives the generated invoice PDF when selected. This may include customer names, addresses, contact details, and purchased items. Configure PrintNode and your privacy disclosures accordingly.

Translations are included for English, Persian, Turkish, Arabic, French, German, Russian, Spanish, Portuguese (Portugal and Brazil), Armenian, Hindi, Simplified Chinese, and Japanese. Select the site language in WordPress settings or your admin language in your user profile. Persian/Arabic invoices use RTL layouts; multilingual PDF fonts are bundled with mPDF.

Automatic printing is disabled by default and follows WooCommerce's paid state. A payment-complete hook and optional paid-status fallback create one automatic job per order. Pending/on-hold orders are not automatically printed. Offline gateways may assign paid statuses before cash collection; extensions can disable the fallback or apply stricter eligibility rules.

PrintNode acceptance is not confirmation of paper output. HTTP 429 rejections receive bounded safe retries. Transport errors, HTTP 408/5xx submission responses, and unreadable successful responses are unknown and require checking PrintNode before intentionally reprinting.

The plugin does not claim jurisdiction-specific fiscal or tax compliance. WooCommerce order numbers are displayed as invoice references. See README.md for complete configuration, recovery, API, extension, and testing documentation.

== Installation ==

1. Run `composer install --no-dev --optimize-autoloader` before packaging, or install a release archive that includes vendor dependencies.
2. Upload and activate the plugin.
3. Open WooCommerce > Invoice Printer.
4. Configure invoice identity and a template.
5. For a local printer, open Printers > Open local test page, click Print, and choose your device in the browser dialog. Use Browser print dialog for individual or bulk invoices.
6. To use PrintNode, save an API key or define `WCIP_PRINTNODE_API_KEY`, refresh printers, and send a test page.

== Data retention ==

Deactivation cancels pending plugin actions and preserves settings/history for recovery on reactivation. Uninstall removes plugin role capabilities and preserves data unless the `wcip_delete_data_on_uninstall` option is explicitly enabled by an operator. Print jobs retain operational references and sanitized errors, but no duplicated invoice/customer payload.

== Changelog ==

= 1.1.2 =
* Printed column on legacy and HPOS order lists, with explicit print confirmation.
* Confirmation adds one private order note per print job without resaving the order or changing its dates.
* Browser, individual, bulk, and PrintNode job confirmation with permission and nonce checks.

= 1.1.1 =
* API-free local-printer setup and test-page access, with optional PrintNode settings.
* Clear separation of local browser printers and PrintNode discovery, translated into every bundled language.
* Prevent PrintNode connection/testing actions before a credential or printer has been selected.

= 1.1.0 =
* Complete catalogs for 13 languages, including Portuguese variants, and translated previews, dialogs, and job labels.
* Automatic RTL previews, Hindi/CJK PDF fonts, and corrected thermal heading spacing.
* User-language REST requests and consistent store-language background invoices with locale restoration.
* Independent jobs queue correctly in Action Scheduler stores that deduplicate by hook/group.

= 1.0.0 =
* Initial release with HPOS-compatible invoice data, three templates, browser printing, PrintNode automation, and print-job history.
* PHP 7.4+ compatible source, production dependencies, and development test suite.
