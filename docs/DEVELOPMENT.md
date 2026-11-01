# Development and Testing

This document details the development environment, coding standards, line ending requirements, and automated test suite for the Homepage Label Manager plugin.

## Line Endings

All bash scripts, PHP files, and templates must use Unix line endings (`LF`). 

Windows default line endings (`CRLF`) cause `/bin/bash^M: bad interpreter` execution failures on Unraid. Line ending normalization is strictly enforced via `.gitattributes`:

```gitattributes
* text=auto eol=lf
*.sh text eol=lf
*.page text eol=lf
*.php text eol=lf
*.json text eol=lf
*.xml text eol=lf
*.cfg text eol=lf
*.md text eol=lf
```

If editing on Windows, ensure your editor is configured to use LF line endings.

## Test Suite

The plugin includes a zero-dependency standalone PHP sandbox test suite covering XML parsing, preset resolution, URL generation, security boundaries, and template serialization.

Tests execute inside isolated temporary directories without modifying host templates or live Docker services.

### Running All Tests

From PowerShell:
```powershell
powershell -ExecutionPolicy Bypass -File .\tests\run_tests.ps1
```

From bash or standard PHP CLI:
```bash
php tests/run_tests.php
```

### Running Targeted Tests (Filter)

Filter test suites or individual tests by keyword using the `--filter` flag:

```bash
php tests/run_tests.php --filter=xml
php tests/run_tests.php --filter=preset
php tests/run_tests.php --filter=security
php tests/run_tests.php --filter=csrf
```

### Syntax Linting

Verify PHP and Dynamix template syntax across all files:

```powershell
Get-ChildItem -Path src,tests -Include *.php,*.page -Recurse | ForEach-Object { php -l $_.FullName }
```

## Test Suite Structure

Test suites are modularized under `tests/suites/`:

| Suite File | Coverage Domain |
|---|---|
| `01_xml_parser.php` | XML loading, container metadata, label diffing, writing, and sensitive field masking |
| `02_preset_engine.php` | Shipped presets, URL placeholder resolution, IPv6 formatting, and services.yaml export |
| `03_user_templates.php` | Custom template persistence, atomic JSON staging, and conflict resolution (merge/overwrite/replace) |
| `04_dashboard_and_recreates.php` | Container summaries, label validation, and pending recreate state tracking |
| `05_security_and_system.php` | INI configuration serialization, snapshot retention, CSRF enforcement, and argument sanitization |
| `06_ui_and_integration.php` | Tabbed navigation routing, native Docker edit hook, and overlay modal integration |

## Key Invariants

When contributing code, the following architectural rules must be preserved:

1. **CSRF Validation**: State-modifying actions in `apply_labels.php` and `HomepageLabelsSettings.page` must validate CSRF tokens using `hash_equals()` and fail closed.
2. **Template Preservation**: Never alter or remove non-Homepage `<Config>` nodes (ports, volumes, variables, or non-`homepage.*` labels) when updating templates.
3. **Path Sandboxing**: Guard all runtime file path constants with `if (!defined(...))` so test harnesses can safely override them with temporary sandbox paths.
4. **Atomic Writes**: JSON and INI configuration files must be written atomically using temporary files and atomic rename operations.
5. **Secret Masking**: Sensitive fields matching secret naming conventions (`key`, `token`, `password`, `secret`, `apikey`) must synchronize with XML `Mask="true"` and be redacted from diagnostic exports.

