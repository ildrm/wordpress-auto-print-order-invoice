# Development guide verification

Reviewed **8 October 2026** against **WooCommerce Invoice Printer 1.1.2**, base commit `5f26fa77a9f1fea9acb520a2b5e7ddd5063376a5`.

## Review scope

The guides were checked against the current plugin source, existing architecture/language documentation, Composer requirements, translation catalogs, release configuration and test entry points. Source review covered template registration and rendering, invoice data, printing providers, protected REST routes, capabilities, the print-job lifecycle, payment automation, recovery and uninstall behavior. Graph exploration was supplemented with source reads where index coverage was incomplete or out of date.

The documentation explains several constraints that affect customization: templates are PHP files registered by a companion plugin; the settings screen has no HTML/PHP editor or theme override; registering a provider alone does not enable it in the current PrintNode-oriented UI and REST workflow; and a submitted print job does not prove that paper was printed.

## Completed checks

- All **14 DOCX editions** match the locale catalogs declared in `scripts/check-translations.py`. They cover **13 languages**, including separate Portuguese editions for Portugal and Brazil.
- The authoring-time content validator passed for every edition: localized source schema, required headings and technical references, valid DOCX ZIP/XML, language metadata, LTR code paragraphs, and Persian/Arabic RTL paragraphs and seven mirrored tables. Generation scripts and translation inputs were subsequently removed; the final DOCX files retain their verified checksums.
- Every DOCX was rendered through the documents skill's canonical LibreOffice/Poppler renderer. All **117 pages** were visually inspected for text shaping, fonts, direction, table flow, code wrapping and clipping. Automated character-bound checks found no text outside the checked page bounds. Persian and Arabic were rendered and checked again after the final mixed-direction adjustment.
- Chinese and Japanese rendering used a locally installed CJK-capable font in an isolated temporary renderer profile. No font, PDF or PNG intermediates are distributed in this folder.
- Both companion-plugin PHP files passed `php -l`.
- A standalone check using the project's test bootstrap helpers exercised template registration, empty sample data, an optional order-meta field, HTML escaping and both LTR and RTL invoice rendering.
- `git diff --check` passed.

Rendered page counts and final DOCX checksums are in [manifest.json](manifest.json). Page counts can change with the Word/LibreOffice version or available fonts.

## Practical limits

This was a source-based documentation review and validation of the companion example. The plugin's full PHPUnit/integration suite was not run because Composer dependencies were absent in this checkout. No production store, live PrintNode submission or physical printer was tested. The documentation folder changes no runtime plugin code and is excluded from the plugin distribution archive.

Translations have not had independent native-speaker editorial review. The documents were checked in the headless renderer; rendering in desktop Word can vary with font availability. Before distributing a revised edition, follow the editing and visual-review instructions in [README.md](README.md).
