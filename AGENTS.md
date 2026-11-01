# Homepage Label Manager for Unraid
Unraid Dynamix plugin (PHP 8 / Bash / vanilla JS) for managing Homepage automatic service discovery Docker labels and user XML templates.

## Commands
- Package Manager: None (zero external dependencies; native Unraid PHP 8 + DOM/cURL extensions only)
- Dev: Sync `src/usr/local/emhttp/plugins/homepage-labels/*` to `/usr/local/emhttp/plugins/homepage-labels/` on an Unraid host
- Build: Single-file plugin manifest via `homepage-labels.plg` (keep `<FILE>` entries synced with `src/`)
- Test All: `powershell -ExecutionPolicy Bypass -File .\tests\run_tests.ps1` (or `php tests/run_tests.php`)
- Test Filter / Single: `php tests/run_tests.php --filter=<keyword>` (or `powershell -ExecutionPolicy Bypass -File .\tests\run_tests.ps1 --filter=<keyword>`)
- Lint: `Get-ChildItem -Path src,tests -Include *.php,*.page -Recurse | ForEach-Object { php -l $_.FullName }`

## Invariants
- Fail-closed CSRF validation (`hash_equals` against `$var['csrf_token']` / `$_SESSION['csrf_token']`) is mandatory on all state-modifying actions in `apply_labels.php` and `HomepageLabelsSettings.page`.
- Never alter or strip non-Homepage `<Config>` nodes (`Port`, `Path`, `Variable`, or non-`homepage.*` labels) when mutating Unraid `dockerMan` XML templates; always snapshot prior labels before writing.
- Guard all runtime path constants (`TEMPLATES_PATH`, `BACKUP_PATH`, `DEFAULT_PRESETS_PATH`, `CUSTOM_PRESETS_PATH`, `USER_TEMPLATES_PATH`, `PLUGIN_SETTINGS_PATH`, `PENDING_RECREATES_PATH`, `LABEL_BACKUPS_DIR`) with `if (!defined(...))` so `tests/run_tests.php` can sandbox them.
- Persist settings strictly through `savePluginSettings()` / `loadPluginSettings()` (`INI_SCANNER_RAW`, stripping newlines and escaping quotes) and write JSON stores atomically via `.tmp` + `rename()`.
- Sensitive fields (`key`, `token`, `password`, `secret`, `apikey`) must sync with XML `Mask="true"` and be redacted from diagnostic bundles and logs.

## Code Standards
- PHP 8+ and Bash (`set -euo pipefail` required in shell scripts); sanitize container keys via `sanitizeContainerKey()` / `basename()` and shell arguments via `escapeshellarg()`.
- Line Endings: Strict Unix LF (`\n`) is enforced via `.gitattributes` for all bash scripts, PHP, and `.page` templates to prevent `/bin/bash^M` execution failures on Unraid.
- UI files (`*.page`) use Unraid Dynamix frontmatter headers with vanilla JS and `--hp-*` CSS theme variables (Unraid 6.12+/7.x); never add Composer, npm, or external build toolchains.
- Container recreation must execute exclusively through `scripts/docker_exec.sh` calling Unraid's native `dockerupdate` script (POST-only for single-container recreate).

## References
- Plugin architecture & user documentation -> `README.md`
- Unraid plugin manifest & release history -> `homepage-labels.plg`
- Standalone sandbox test suite & XML fixtures -> `tests/run_tests.php`, `tests/fixtures/templates-user/`
- Git line ending normalization -> `.gitattributes`
