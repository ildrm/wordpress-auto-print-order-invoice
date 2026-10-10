# Languages and translation maintenance

The plugin uses WordPress's `wc-invoice-printer` text domain. Each locale ships an editable PO catalog and a compiled MO catalog in `languages/`; no translation service or runtime compiler is required.

| Language | WordPress locale | Direction |
| --- | --- | --- |
| English | `en_US` (English source also covers other English locales) | LTR |
| Persian — فارسی | `fa_IR` | RTL |
| Turkish — Türkçe | `tr_TR` | LTR |
| Arabic — العربية | `ar` | RTL |
| French — Français | `fr_FR` | LTR |
| German — Deutsch | `de_DE` | LTR |
| Russian — Русский | `ru_RU` | LTR |
| Spanish — Español | `es_ES` | LTR |
| Portuguese — Português | `pt_PT`, `pt_BR` | LTR |
| Armenian — Հայերեն | `hy` | LTR |
| Hindi — हिन्दी | `hi_IN` | LTR |
| Chinese (Simplified) — 简体中文 | `zh_CN` | LTR |
| Japanese — 日本語 | `ja` | LTR |

Choose the site's language in **Settings → General → Site Language**. Install its WordPress language pack if necessary so WordPress/WooCommerce can translate their own interfaces and supply the correct text direction. An administrator's **Users → Profile → Language** controls their admin screens, browser invoice previews, and authenticated plugin REST requests. Browser requests include WordPress's `_locale=user` parameter. Background CUPS and agent jobs use the site's language when the worker runs, including manually queued jobs.

An RTL preview button changes direction without changing the selected language. Ordinary Persian and Arabic previews automatically use RTL. Invoices emit HTML language tags such as `fa-IR` or `zh-CN` and inherit WooCommerce's localized order status, totals, currency, and date formatting. Stored product/customer/business text and existing job error messages retain their original wording. Translation catalogs translate labels and sample data; they do not translate a store's content automatically.

Plugin services initialize at `init` priority 0, after WordPress has established its locale and before Action Scheduler initializes at priority 1. This avoids early just-in-time translation warnings while retaining queue recovery. See [WordPress's i18n initialization guidance](https://make.wordpress.org/core/2024/10/21/i18n-improvements-6-7/) and [Action Scheduler initialization](https://actionscheduler.org/api/).

PDF rendering enables mPDF's `autoScriptToLang` and `autoLangToFont`. Its bundled fonts cover Arabic/Persian shaping, Cyrillic, Armenian, Hindi (FreeSerif with OpenType layout), and Chinese/Japanese (Sun-ExtA). Keep the complete production mPDF dependency, including font data, when packaging. See [mPDF font configuration](https://mpdf.github.io/fonts-languages/fonts-in-mpdf-7-x.html) and [OpenType layout](https://mpdf.github.io/fonts-languages/opentype-layout-otl.html). Browser printing uses the browser's available fonts.

## Updating catalogs

Development tools: WP-CLI with its i18n commands, Python 3.8+, and GNU gettext (`msgmerge`/`msgfmt`). WordPress installations using the packaged plugin do not need these tools.

1. Extract the source messages with `composer i18n:pot`.
2. Merge the new POT into each PO catalog. For example:

   ```sh
   msgmerge --update --backup=none --no-fuzzy-matching \
     languages/wc-invoice-printer-fa_IR.po languages/wc-invoice-printer.pot
   ```

3. Translate new entries in every catalog, preserving formatting placeholders and the language's plural forms. Edit the PO files; their comments identify the source locations.
4. Run `composer i18n:build` to validate and compile all MO files.
5. Run `composer i18n:check` and `composer test:all` before distributing the change.

JavaScript UI messages are translated in PHP and supplied with `wp_localize_script`; separate JavaScript JSON catalogs are unnecessary for the current scripts. Custom templates/providers should wrap their own visible strings in their own text domain and preserve UTF-8 data.

The catalog checker rejects missing or fuzzy translations, mismatched source messages, incorrect printf placeholders, incomplete plural forms, and missing/stale MO files. It exercises singular and plural lookups, including Arabic's six forms and Russian's three. CI also extracts a fresh POT from source and checks every catalog against it.

## Verification

Rendering tests cover all 14 locale catalogs and the original three invoice layouts across 14 locales and all seven registered formats. Hindi and Chinese/Japanese PDF tests assert that the required fonts are embedded. Regression tests cover automatic RTL sample previews, translated printer states without changing device identity, REST requests retaining the user's language, and workers restoring the operator locale after printing or an observer exception.

On an isolated disposable WordPress/WooCommerce site, install the core language packs and run the real WordPress catalog/admin/PDF test:

```sh
wp language core install fa_IR tr_TR ar fr_FR de_DE ru_RU es_ES pt_PT pt_BR hy hi_IN zh_CN ja
WCIP_RUN_INTEGRATION_TESTS=1 wp eval-file /path/to/plugin/tests/Integration/localization.php
```

This test switches between every locale, checks catalog lookup and RTL direction, renders 42 HTML/PDF invoices and 70 admin screens, exercises plural lookups, and verifies the worker's store-language selection and operator-language restoration. It mocks delivery/email and removes its temporary order/job fixtures. WordPress 6.9.4 with WooCommerce 9.0.2 and PHP 8.4 passed these checks; the printing smoke test also passed with HPOS and legacy storage. PDF images were inspected for Hindi shaping, Persian RTL, and Japanese thermal layout. These checks validate completeness and rendering; translation wording has not had a native-speaker editorial review.

The final printing checks also exposed a queue issue in older Action Scheduler stores: their unique-action check uses the hook/group and ignores the job ID. Async actions now use the plugin's per-job pending-action lookup and atomic worker claim, allowing independent invoices to queue while preventing duplicate accepted submissions. Regression coverage includes multiple pending jobs in the real Action Scheduler store.

The 1.4.0 operations, CUPS and agent strings are included in the source POT and every locale catalog. New translations are development translations pending native editorial review. DOCX guides preserve RTL paragraph/run language and mirrored tables for Persian/Arabic and LTR code for every edition. See [the verification record](../system-development/VERIFICATION.md).

Printing uses the outbound local agent (Windows SumatraPDF or Linux/macOS CUPS lp), optional direct HTTPS CUPS, or the manual browser dialog. All locale editions cover cross-platform pairing, startup, recovery, CUPS migration and extensions using open-source tools. See [the printing guide](open-source-printing.md).
