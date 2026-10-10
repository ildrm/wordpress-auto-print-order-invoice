# Development guide verification

Reviewed **10 October 2026** against the **WooCommerce Invoice Printer 1.4.0** working tree, based on commit `c894eb05d2120a4bba09aa3500fe2842a0e48d74`.

## Review scope

The guides were checked against source, Composer requirements, all 14 translation catalogs, release configuration and test entry points. Review includes template/data/provider contracts, protected REST/capabilities, confirmed-payment eligibility, event/reconciliation cutoff, preview tokens, document-specific confirmation, fulfillment revisions, opaque references, scanning, signed export intent/receipt/retention, migration and uninstall. Structural graph exploration was supplemented with source reads for templates and coverage gaps.

Version 1.4.0 separates the WordPress host from the printer computer. Its outbound HTTPS pull agent supports Windows with SumatraPDF and Linux/macOS with CUPS `lp`; the website needs neither printer software nor shell access. Shared hosting, VPS and dedicated hosting use the same PHP plugin, subject to the documented PHP/WooCommerce, database, HTTPS REST and Application Password requirements. A powered, awake printer computer must run the agent for unattended output. Direct HTTPS CUPS and browser printing remain documented alternatives.

Templates are PHP files registered by a companion plugin; the settings screen has no HTML/PHP editor or theme override. Registering a push provider alone does not add it to the built-in UI/REST selection. The built-in agent uses a separate claim/start/receipt protocol. Submitted status indicates spooler acceptance or browser preparation, never proof of paper output.

## Completed checks

- All **14 DOCX editions** match the locale catalogs declared in `scripts/check-translations.py`. They cover **13 languages**, including separate Portuguese editions for Portugal and Brazil.
- Final package/XML checks cover release/date/hook/route/DTO references, nine tables, and Persian/Arabic RTL paragraphs with nine mirrored tables. Technical code passages remain LTR. No authoring source directory was recreated.
- Every final DOCX was rendered through the documents skill's canonical bundled LibreOffice/Poppler renderer. All **149 version 1.4.0 pages** were visually inspected at original resolution for shaping, fonts, direction, table flow, code wrapping and clipping. Table headers repeat, borders remain visible, and rows stay together. All editions include translated cross-platform setup, pairing, operating requirements, recovery and testing details. Persian and Arabic have ten pages each.
- Chinese and Japanese rendering used a locally installed CJK-capable font in an isolated temporary renderer profile. No font, PDF or PNG intermediates are distributed in this folder.
- Both companion-plugin PHP files passed `php -l`.
- A standalone check using the project's test bootstrap helpers exercised template registration, empty sample data, an optional order-meta field, HTML escaping and both LTR and RTL invoice rendering.
- Current PHP suites passed **318 tests / 1,160 assertions** on PHP 8.5.8 and PHP 7.4.33. JavaScript passed **23 tests**; the local agent passed **9 unit tests**. Composer validation, PHP/JavaScript syntax and all fourteen **332-message** catalogs passed, including plural and GNU format checks.
- Real WordPress 6.6.2 / WooCommerce 9.0.2 / PHP 8.3 / MariaDB 10.11 testing passed **20 HTTPS agent assertions** with real Application Password authentication and a Linux virtual CUPS printer. Coverage includes route restrictions, PDF checksum, expired claim recovery, stale-token rejection, one-use start, idempotent/conflicting receipts, automation cancellation, two-copy dispatch and crash recovery without printer replay. Another **11 real-database assertions** verify binary route matching, lease expiry, user/token isolation and valid late receipts.
- Earlier version 1.3.0 evidence includes HPOS/legacy remediation and migration checks, 98 localized PDFs, digital barcode decoding, browser-printing HTTP checks and direct HTTPS CUPS acceptance. Historical counts are distinguished from current results in [REMEDIATION_TRACEABILITY.md](../REMEDIATION_TRACEABILITY.md).
- The actual 1.4.0 production ZIP passed fresh-prefix installation/activation, schema/capability/role/index checks, all seven PDF layouts and exact locked production-dependency checks. Populated 1.1.2 and 1.3.0 CUPS upgrade fixtures passed against the packaged source, preserving historical job/confirmation values and the existing CUPS setup/cutoff.

Rendered page counts and final DOCX checksums are in [manifest.json](manifest.json). Page counts can change with the Word/LibreOffice version or available fonts.

## Practical limits

No native Windows printer, physical printer/scanner, production store, multisite or hosting-provider matrix was tested. Windows command construction is tested; native SumatraPDF/driver/paper acceptance remains a deployment check. The Windows/Linux/macOS Python CI workflow is configured but was not run remotely. Linux end-to-end testing used a virtual printer. HTTPS REST and Application Password authentication must be permitted by the host; a provider that blocks them needs configuration changes before unattended printing can work.

Translations have not had independent native-speaker editorial review. The documents were checked in the headless renderer; rendering in desktop Word can vary with font availability. Before distributing a revised edition, follow the editing and visual-review instructions in [README.md](README.md).

The documentation folder is excluded from the production plugin ZIP; a separate documentation ZIP is provided. The plugin ZIP includes the local-agent source and operator setup guides. See [cross-platform printing](../docs/cross-platform-printing.md), [direct CUPS setup and historical migration](../docs/open-source-printing.md), and the [remediation record](../REMEDIATION_TRACEABILITY.md) for deployment requirements and verification limits.
