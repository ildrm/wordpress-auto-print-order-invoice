# PHP compatibility review

This is a historical review of the retired cloud provider. Version 1.4.0 uses the open-source local agent or direct CUPS; the commercial provider was removed in 1.3.0. Provider-specific findings below are historical evidence, not current setup instructions. Current 1.4.0 changes and executed results are recorded in [the remediation report](../REMEDIATION_TRACEABILITY.md). Its earlier counts and archive statements describe that review only.

Review date: October 7, 2026. Target: PHP 7.4 and currently available PHP 8 releases through 8.5.

## Scope and findings

Reviewed all project PHP files: plugin bootstrap, services/controllers, lifecycle code, bundled invoice templates, unit test fixtures, subprocess scripts, and the opt-in integration smoke test. Also reviewed Composer constraints, both dependency sections of the lock file, PHPUnit configuration, plugin metadata, and documentation. The codebase graph was used for structure/callers; current source was read directly because index freshness was uncertain and the mixed PHP/HTML templates were not parsed by the graph.

| Blocker | Change |
| --- | --- |
| PHP 8 constructor property promotion | Explicit property declarations and assignments; retained supported PHP 7.4 typed properties |
| PHP 8.1 `readonly` fields | Private typed value fields with read accessors, write/unset/reinitialization guards, and JSON serialization; service dependencies remain private |
| PHP 8.1 backed enum | `JobStatus` string constants; updated persistence, workers, filters, and admin rendering while retaining all six stored values and failure-hook strings |
| PHP 8 union and `mixed` types | PHP 7.4 signatures with PHPDoc for multi-type production interfaces; existing input validation retained |
| PHP 8 `match` expression | Admin tab dispatch uses `switch` after its existing allowlist validation |
| PHP 8 catch clauses without variables | Added exception variables in template validation and PDF tests |
| `str_starts_with`, `str_contains` | Strict `strpos` comparisons, retaining the empty-prefix handling in metadata fixtures |
| `array_is_list` | Sequential-key validation with an explicit empty-array case; JSON array/object shape checks remain enforced |
| PHP 8-only locked production packages | Resolved for PHP 7.4.33: `psr/log` 1.1.4, mPDF log trait 2.0.0, and DeepCopy 1.13.4; retained mPDF 8.3.1 |
| PHPUnit 10/11 and attribute-only tests | PHPUnit 9.6.38, compatible XML, data-provider/process-isolation annotations, and PHP 7.4-compatible doubles/generated subprocess code |
| PHP 8.1 tentative ArrayAccess return type | A single-line `ReturnTypeWillChange` attribute on the untyped fixture getter is a comment on PHP 7.4 and prevents deprecation on PHP 8.1+ |
| PHP 8.1 advertised minimum | Plugin header, WordPress readme, Composer, and README now declare PHP 7.4 |

Composer's `config.platform.php` is fixed at 7.4.33 so dependency updates performed on newer PHP cannot silently raise the package's minimum. This is dependency resolution configuration, not a replacement for checking the actual deployment runtime and extensions.

The language incompatibilities were checked against the official [PHP 8.0 migration guide](https://www.php.net/manual/en/migration80.new-features.php) and [PHP 8.1 migration guide](https://www.php.net/manual/en/migration81.new-features.php). PDF requirements were checked against the [mPDF requirements](https://mpdf.github.io/about-mpdf/requirements-v7.html) and the installed packages' manifests.

## Verification

The final suite passed **234 tests and 689 assertions on each runtime**, including actual mPDF generation, all bundled layouts in LTR/RTL, protected REST flows, queue/idempotency/retry behavior, schema subprocesses, and lifecycle tests. All **59 project PHP files** passed each runtime's parser.

| Runtime | Environment | PHPUnit / lint |
| --- | --- | --- |
| PHP 7.4.33 | Official WordPress PHP 7.4 container | Passed |
| PHP 8.0.30 | Official WordPress PHP 8.0 container | Passed |
| PHP 8.1.34 | Official WordPress PHP 8.1 container | Passed |
| PHP 8.2.34 | Official WordPress PHP 8.2 container | Passed |
| PHP 8.3.35 | Official WordPress PHP 8.3 container | Passed |
| PHP 8.4.26 | Official WordPress PHP 8.4 container | Passed |
| PHP 8.5.8 | Local CLI | Passed |

Container tests used read-only project mounts, private temporary filesystems, and disabled networking. These were PHP runtime tests using the repository's WordPress/WooCommerce/database doubles; the containers did not run a WordPress site.

Additional checks:

- All development and production platform requirements passed on actual PHP 7.4.33; the local PHP 8.5.8 platform check passed as well.
- A clean `composer install --no-dev --prefer-dist --optimize-autoloader` completed on PHP 7.4.33 in an isolated temporary copy using the package cache. Its production platform check, application/mPDF autoload, and value-object serialization passed; PHPUnit was absent from that installation.
- Strict Composer manifest/lock validation passed; the dependency update reported no published security advisories.
- All 12 JavaScript tests and both production JavaScript syntax checks passed.
- `git diff --check` passed; a token-based comparison confirmed existing PHP string literals were retained except the deliberately removed enum field/data-provider arguments.
- New regressions cover empty printer lists, invalid string statuses, value-field reads/write/unset prevention, read-only reinitialization, array copies, RTL copies, and JSON serialization.

The [CI workflow](../.github/workflows/php-compatibility.yml) runs installation, real platform checks, lint, and the complete PHP suite on PHP 7.4 and 8.0–8.5, plus JavaScript checks in a separate job.

## Extension and deployment notes

`JobStatus::FAILED` and the other constants now return strings. Remove `->value` from extension code, iterate `JobStatus::cases()` directly, and call `JobStatus::is_terminal( $status )` instead of an enum instance method. Value objects keep their constructor parameters and property-read syntax, but their fields are now private; public-property reflection, `get_object_vars()` outside the class, and object-to-array casts have different visibility behavior. Use property reads or JSON serialization for the exposed data.

The committed historical ZIP was not rebuilt. Prepare a fresh production package using the updated lock file before distribution. The existing opt-in real WordPress/WooCommerce smoke test was made PHP 7.4-compatible, but was not rerun against a real site during this compatibility review. Physical delivery, complete WordPress/WooCommerce/gateway matrices, multisite, and future PHP releases remain outside these runtime checks.

The 1.4.0 cross-platform agent runs separately from hosting PHP. Its Windows/Linux/macOS backend setup and Python 3.10+ requirements are in [cross-platform printing](cross-platform-printing.md); executed results and native hardware limits are in [the current remediation record](../REMEDIATION_TRACEABILITY.md).
