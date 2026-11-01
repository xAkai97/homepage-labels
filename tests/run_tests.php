#!/usr/bin/env php
<?php
/**
 * Standalone PHP Test Suite for homepage-labels Unraid Plugin
 *
 * Runs without Unraid server dependencies by isolating templates,
 * backups, and presets in a temporary sandbox environment.
 * Supports modular test suites under tests/suites/ and CLI filtering via --filter.
 */

// 1. Parse CLI Options
$filter = null;
for ($i = 1; $i < count($argv ?? []); $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--filter=') === 0) {
        $filter = trim(substr($arg, 9), "'\"");
    } elseif ($arg === '-f' && isset($argv[$i + 1])) {
        $filter = trim($argv[++$i], "'\"");
    } elseif (strpos($arg, '-') !== 0 && $filter === null) {
        $filter = trim($arg, "'\"");
    }
}

// 2. Setup Sandbox Directory
$sandboxDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hp_test_' . uniqid();
$templatesDir = $sandboxDir . DIRECTORY_SEPARATOR . 'templates-user';
$backupsDir = $sandboxDir . DIRECTORY_SEPARATOR . 'backups';
$configDir = $sandboxDir . DIRECTORY_SEPARATOR . 'config';

@mkdir($templatesDir, 0777, true);
@mkdir($backupsDir, 0777, true);
@mkdir($configDir, 0777, true);

// Register cleanup on shutdown
register_shutdown_function(function() use ($sandboxDir) {
    if (is_dir($sandboxDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sandboxDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        unset($files);
        gc_collect_cycles();
        @rmdir($sandboxDir);
    }
});

// Copy fixture templates into sandbox
$fixturesSrc = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'templates-user';
foreach (glob($fixturesSrc . DIRECTORY_SEPARATOR . '*.xml') as $fixtureFile) {
    copy($fixtureFile, $templatesDir . DIRECTORY_SEPARATOR . basename($fixtureFile));
}

// 3. Define Mock Paths BEFORE including plugin files
define('TEMPLATES_PATH', $templatesDir);
define('BACKUP_PATH', $backupsDir);
define('DEFAULT_PRESETS_PATH', dirname(__DIR__) . '/src/usr/local/emhttp/plugins/homepage-labels/default_presets.json');
define('CUSTOM_PRESETS_PATH', $configDir . DIRECTORY_SEPARATOR . 'custom_presets.json');
define('USER_TEMPLATES_PATH', $configDir . DIRECTORY_SEPARATOR . 'user_templates.json');
define('LABEL_BACKUPS_DIR', $backupsDir . DIRECTORY_SEPARATOR . 'label_snapshots');
define('PLUGIN_SETTINGS_PATH', $configDir . DIRECTORY_SEPARATOR . 'settings.cfg');
define('PENDING_RECREATES_PATH', $configDir . DIRECTORY_SEPARATOR . 'pending_recreates.json');

// Mock Unraid globals
$GLOBALS['var'] = ['csrf_token' => 'mock_csrf_token_12345'];
$_SERVER['HTTP_HOST'] = '192.168.1.100:80';

// 4. Load Plugin Files
require_once dirname(__DIR__) . '/src/usr/local/emhttp/plugins/homepage-labels/include/xml_parser.php';
require_once dirname(__DIR__) . '/src/usr/local/emhttp/plugins/homepage-labels/include/preset_engine.php';
require_once dirname(__DIR__) . '/src/usr/local/emhttp/plugins/homepage-labels/scripts/validator.php';

// 5. Test Framework Helpers & State
$startTime = microtime(true);
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$skippedTests = 0;
$currentSuite = '';
$suitePrinted = false;

function suite($name) {
    global $currentSuite, $suitePrinted;
    $currentSuite = $name;
    $suitePrinted = false;
}

function test($description, $assertion) {
    global $totalTests, $passedTests, $failedTests, $skippedTests, $currentSuite, $suitePrinted, $filter;

    // Filter check
    if ($filter !== null && $filter !== '') {
        $haystack = strtolower($currentSuite . ' ' . $description);
        if (strpos($haystack, strtolower($filter)) === false) {
            $skippedTests++;
            return;
        }
    }

    if (!$suitePrinted) {
        echo "\n\033[1;34m=== Suite: {$currentSuite} ===\033[0m\n";
        $suitePrinted = true;
    }

    $totalTests++;
    try {
        $result = is_callable($assertion) ? $assertion() : (bool)$assertion;
        if ($result) {
            $passedTests++;
            echo "  \033[32m✔ PASS:\033[0m {$description}\n";
        } else {
            $failedTests++;
            echo "  \033[31m✘ FAIL:\033[0m {$description}\n";
        }
    } catch (Throwable $e) {
        $failedTests++;
        echo "  \033[31m✘ FAIL (Exception):\033[0m {$description} -> " . $e->getMessage() . "\n";
    }
}

function assertEqual($actual, $expected, $message = '') {
    if ($actual !== $expected) {
        throw new Exception(($message ? "{$message}: " : '') . "Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
    return true;
}

function assertTrue($condition, $message = '') {
    if (!$condition) {
        throw new Exception(($message ? "{$message}: " : '') . "Expected true, got false");
    }
    return true;
}

function assertFalse($condition, $message = '') {
    if ($condition) {
        throw new Exception(($message ? "{$message}: " : '') . "Expected false, got true");
    }
    return true;
}

// 6. Test Harness Banner
echo "\033[1;36m====================================================\033[0m\n";
echo "\033[1;36m  Homepage Labels Plugin - Standalone Test Harness  \033[0m\n";
echo "\033[1;36m====================================================\033[0m\n";
echo "Sandbox:     {$sandboxDir}\n";
echo "PHP Version: " . PHP_VERSION . "\n";
if ($filter !== null && $filter !== '') {
    echo "Filter:      \033[1;33m\"{$filter}\"\033[0m\n";
}

// 7. Load Modular Test Suites
$suiteFiles = glob(__DIR__ . DIRECTORY_SEPARATOR . 'suites' . DIRECTORY_SEPARATOR . '*.php');
sort($suiteFiles);

if (empty($suiteFiles)) {
    echo "\033[31mError: No test suite files found in tests/suites/\033[0m\n";
    exit(1);
}

foreach ($suiteFiles as $suiteFile) {
    require $suiteFile;
}

// 8. Final Results & Diagnostics
$elapsed = round((microtime(true) - $startTime) * 1000, 2);

echo "\n\033[1;36m====================================================\033[0m\n";
echo "Test Execution Finished in {$elapsed} ms.\n";
echo "Total Executed: {$totalTests}\n";
echo "\033[32mPassed:         {$passedTests}\033[0m\n";
if ($skippedTests > 0) {
    echo "\033[33mFiltered Out:   {$skippedTests}\033[0m\n";
}
if ($failedTests > 0) {
    echo "\033[31mFailed:         {$failedTests}\033[0m\n";
    echo "\033[1;31mSTATUS: FAILED\033[0m\n";
    exit(1);
} else {
    echo "\033[1;32mSTATUS: ALL TESTS PASSED (100%)\033[0m\n";
    exit(0);
}
