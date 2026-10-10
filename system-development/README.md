# System development documentation

Development and template-customization guides for **WooCommerce Invoice Printer 1.4.0**, reviewed against the remediation working tree based on commit `c894eb05d2120a4bba09aa3500fe2842a0e48d74` on **10 October 2026**. This folder contains documentation and an optional companion-plugin example; it is not loaded by the main plugin.

The plugin has 13 supported languages and 14 bundled locale catalogs. Every locale has a complete DOCX guide with localized explanations and shared code examples, API references, hook signatures and commands. The English edition gives additional background. Technical identifiers remain unchanged so examples can be copied. Translation wording has not been independently reviewed by native-language editors.

| Language | Locale | Direction | Guide |
| --- | --- | --- | --- |
| English | en_US | LTR | [English](guides/development-guide-en_US.docx) |
| فارسی | fa_IR | RTL | [Persian](guides/development-guide-fa_IR.docx) |
| العربية | ar | RTL | [Arabic](guides/development-guide-ar.docx) |
| Русский | ru_RU | LTR | [Russian](guides/development-guide-ru_RU.docx) |
| 简体中文 | zh_CN | LTR | [Simplified Chinese](guides/development-guide-zh_CN.docx) |
| हिन्दी | hi_IN | LTR | [Hindi](guides/development-guide-hi_IN.docx) |
| Türkçe | tr_TR | LTR | [Turkish](guides/development-guide-tr_TR.docx) |
| 日本語 | ja | LTR | [Japanese](guides/development-guide-ja.docx) |
| Français | fr_FR | LTR | [French](guides/development-guide-fr_FR.docx) |
| Deutsch | de_DE | LTR | [German](guides/development-guide-de_DE.docx) |
| Español | es_ES | LTR | [Spanish](guides/development-guide-es_ES.docx) |
| Português Portugal | pt_PT | LTR | [Portuguese Portugal](guides/development-guide-pt_PT.docx) |
| Português Brasil | pt_BR | LTR | [Portuguese Brazil](guides/development-guide-pt_BR.docx) |
| Հայերեն | hy | LTR | [Armenian](guides/development-guide-hy.docx) |

Each guide explains the development environment, architecture, merchant settings, persistent template customization, invoice fields, hooks, provider limitations, protected REST API, payment triggers, job recovery, translations, tests, security and release preparation.

The 1.4.0 editions also cover confirmed-payment eligibility, bounded reconciliation and historical preview, seven document formats, document-specific confirmation, opt-in warehouse stages, opaque codes, authenticated scanning, signed exports, added hooks/routes and the additive schema. The detailed English operator/receiver contract is in [docs/operations.md](../docs/operations.md); executed tests and remaining external acceptance work are in [the remediation record](../REMEDIATION_TRACEABILITY.md).

[Verification record](VERIFICATION.md) documents the review scope and checks. [Document manifest](manifest.json) records each edition's direction, rendered page count and SHA-256 checksum.

Persian and Arabic use Word paragraph/run language metadata, RTL text and mirrored tables. Logical `start` alignment places prose at the right edge. Code blocks and technical identifiers retain LTR direction. Chinese/Japanese use East Asian fonts and horizontal LTR; Hindi uses a Devanagari font. Word may substitute a font on computers where the selected typeface is unavailable; preserve script coverage and verify rendering after substitution.

## Companion example

Copy [examples/wcip-customization](examples/wcip-customization/) into a disposable site's `wp-content/plugins/wcip-customization/`, activate WooCommerce Invoice Printer, then activate **WCIP Customization**. Select `custom-classic` in Invoice Printer settings. The example copies the classic layout and adds an optional `_purchase_order` order-meta value using WooCommerce CRUD. It does not create or populate that meta field; use the key your store actually saves. Sample previews omit the order-data filter, so test a real order. The example's own text domain is `wcip-customization`; add catalogs if translating its new labels.

## Maintain the guides

Edit the DOCX files directly in LibreOffice. The generation scripts and translation inputs were removed after the finished guides were validated; the documents are self-contained.

The previously requested `source/` cleanup remains complete. No authoring inputs or render intermediates are needed to use these documents.

Keep every edition synchronized after changes to template/data/provider contracts, routes, queue behavior or release requirements. Preserve paragraph and run language metadata, RTL prose and mirrored tables in Persian/Arabic, and LTR code and technical identifiers in every edition.

Export revised documents to PDF and inspect every page for font coverage, text direction, table flow and clipping before distribution. Keep temporary render outputs outside this folder. Update the relevant SHA-256 checksum and rendered page count in [manifest.json](manifest.json), and record new checks in [VERIFICATION.md](VERIFICATION.md).

The Chinese and Japanese editions select **Arial Unicode MS**; their render QA used the locally installed font. Font files are not redistributed here. Keep a compatible CJK font installed when editing or rendering those editions. Source review and document/example validation are distinct from running the plugin's full tests or proving physical delivery.

The guides cover outbound local-agent printing on Windows with SumatraPDF and Linux/macOS with CUPS lp, shared/VPS/dedicated hosting requirements, scoped pairing, Windows startup and crash recovery. WordPress hosting installs no printer software or Python. Direct HTTPS CUPS and manual browser printing remain available. See [cross-platform setup](../docs/cross-platform-printing.md) and [open-source migration](../docs/open-source-printing.md).
