# WooCommerce Invoice Printer

A production-oriented WooCommerce extension for secure browser invoice printing and asynchronous PrintNode delivery. It supports HPOS, three RTL-aware invoice templates, explicit print-job history, bounded safe retries, and an API-key override through `WCIP_PRINTNODE_API_KEY`.

## Requirements

- WordPress 6.6+
- WooCommerce 9.0+
- PHP 8.1+
- Composer dependencies installed for PDF generation

## Development

```bash
composer install
composer test
composer lint
```

See [docs/architecture.md](docs/architecture.md), [docs/ui-spec.md](docs/ui-spec.md), and [readme.txt](readme.txt) for operational details.
