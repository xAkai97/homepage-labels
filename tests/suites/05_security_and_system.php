<?php
/**
 * Suite 05: Security, Sanitization, Snapshots & Plugin Settings
 */

// ----------------------------------------------------
suite('Plugin Settings Engine');
// ----------------------------------------------------
test('getDefaultPluginSettings() returns expected default schema', function() {
    $defaults = getDefaultPluginSettings();
    assertTrue(is_array($defaults), 'Defaults is array');
    assertEqual($defaults['UI_FONT_SCALE'], '100', 'Default font scale is 100');
    assertEqual($defaults['HOMEPAGE_CONTAINER_NAME'], 'homepage', 'Default homepage container');
    assertEqual($defaults['BACKUP_BEFORE_WRITE'], 'true', 'Default backup before write');
    return true;
});

test('loadPluginSettings() falls back to defaults when file missing', function() use ($configDir) {
    $nonExistentPath = $configDir . DIRECTORY_SEPARATOR . 'missing_settings.cfg';
    $settings = loadPluginSettings($nonExistentPath);
    assertEqual($settings['UI_FONT_SCALE'], '100', 'Fallback font scale');
    assertEqual($settings['BASE_SCHEME'], 'http', 'Fallback base scheme');
    return true;
});

test('loadPluginSettings() merges saved settings with defaults and handles corrupt files gracefully', function() use ($configDir) {
    $customPath = $configDir . DIRECTORY_SEPARATOR . 'custom_settings.cfg';
    file_put_contents($customPath, "UI_FONT_SCALE=\"125\"\nBASE_HOST=\"10.0.0.5\"\n");
    $loaded = loadPluginSettings($customPath);
    assertEqual($loaded['UI_FONT_SCALE'], '125', 'Custom font scale loaded');
    assertEqual($loaded['BASE_HOST'], '10.0.0.5', 'Custom base host loaded');
    assertEqual($loaded['HOMEPAGE_CONTAINER_NAME'], 'homepage', 'Missing keys populated from defaults');

    // Corrupt file handling
    file_put_contents($customPath, "[corrupt ini file content\n==!!invalid");
    $loadedCorrupt = loadPluginSettings($customPath);
    assertTrue(is_array($loadedCorrupt), 'Corrupt file yields array');
    assertEqual($loadedCorrupt['UI_FONT_SCALE'], '100', 'Falls back to default on corrupt ini');
    @unlink($customPath);
    return true;
});

test('savePluginSettings() safely sanitizes INI keys and values against injection', function() use ($configDir) {
    $testPath = $configDir . DIRECTORY_SEPARATOR . 'safe_settings.cfg';
    $toSave = [
        'UI_FONT_SCALE' => '110',
        'BASE_HOST'     => "example.com\"\nINJECTED_KEY=\"malicious",
        'UNSAFE!@KEY'   => 'valid_val',
    ];
    $saved = savePluginSettings($toSave, $testPath);
    assertTrue($saved, 'savePluginSettings returned true');
    assertTrue(file_exists($testPath), 'Settings file created');

    $loaded = loadPluginSettings($testPath);
    assertEqual($loaded['UI_FONT_SCALE'], '110', 'Font scale persisted');
    // Quotes are escaped and newlines stripped against INI injection
    assertEqual($loaded['BASE_HOST'], 'example.com"INJECTED_KEY="malicious', 'Newlines stripped and quotes escaped safely');
    assertEqual($loaded['UNSAFEKEY'], 'valid_val', 'Unsafe key chars sanitized');
    assertFalse(isset($loaded['INJECTED_KEY']), 'No injected key created');

    @unlink($testPath);
    return true;
});

test('savePluginSettings() escapes quotes and loadPluginSettings() restores them safely', function() use ($configDir) {
    $testCfg = $configDir . DIRECTORY_SEPARATOR . 'quotes_test.cfg';
    $settings = [
        'CUSTOM_ICONS_URL_PREFIX' => '/icons/"custom_folder"/',
        'BASE_HOST'               => '192.168.1.50',
    ];

    $saved = savePluginSettings($settings, $testCfg);
    assertTrue($saved, 'savePluginSettings returned true');

    $fileContent = file_get_contents($testCfg);
    assertTrue(strpos($fileContent, 'CUSTOM_ICONS_URL_PREFIX="/icons/\"custom_folder\"/"') !== false, 'Quotes escaped with backslash in INI');

    $loaded = loadPluginSettings($testCfg);
    assertEqual($loaded['CUSTOM_ICONS_URL_PREFIX'], '/icons/"custom_folder"/', 'Quotes successfully unescaped on load');
    assertEqual($loaded['BASE_HOST'], '192.168.1.50', 'Base host persisted intact');

    @unlink($testCfg);
    return true;
});

test('savePluginSettings() preserves backslashes across saves and loads', function() use ($configDir) {
    $testCfg = $configDir . DIRECTORY_SEPARATOR . 'test_backslashes.cfg';
    $settings = [
        'CUSTOM_ICONS_HOST_DIR' => 'C:\\Unraid\\Icons\\Test',
        'CUSTOM_ICONS_URL_PREFIX' => '/icons/foo\\bar/',
    ];
    savePluginSettings($settings, $testCfg);
    $loaded = loadPluginSettings($testCfg);
    assertEqual($loaded['CUSTOM_ICONS_HOST_DIR'], 'C:\\Unraid\\Icons\\Test', 'Windows backslashes preserved');
    assertEqual($loaded['CUSTOM_ICONS_URL_PREFIX'], '/icons/foo\\bar/', 'URL prefix backslashes preserved');
    @unlink($testCfg);
    return true;
});

// ----------------------------------------------------
suite('Backup and Snapshot Engine');
// ----------------------------------------------------
test('backupTemplate() generates timestamped XML copy', function() use ($backupsDir) {
    $res = backupTemplate('plex');
    assertTrue($res['success'], 'Backup created');
    assertTrue(file_exists($res['path']), 'Backup file exists on disk');
    assertTrue(filesize($res['path']) > 0, 'Backup file is non-empty');
    return true;
});

test('saveLabelBackup() and getLabelBackup() snapshot cycle', function() {
    $labelsToBackup = [
        'homepage.name' => 'Sonarr Backup',
        'homepage.icon' => 'sonarr.png',
    ];

    $saved = saveLabelBackup('sonarr', $labelsToBackup, 'pre-test');
    assertTrue($saved, 'saveLabelBackup returned true');
    assertTrue(hasLabelBackup('sonarr'), 'hasLabelBackup returns true');

    $snapshot = getLabelBackup('sonarr');
    assertTrue($snapshot !== null, 'Retrieved snapshot');
    assertEqual($snapshot['labels']['homepage.name'], 'Sonarr Backup', 'Snapshot label value');

    $deleted = deleteLabelBackup('sonarr');
    assertTrue($deleted, 'deleteLabelBackup returned true');
    assertFalse(hasLabelBackup('sonarr'), 'hasLabelBackup returns false after delete');
    return true;
});

test('getLabelBackupPath() sanitizes container name into safe json file path', function() {
    $path = getLabelBackupPath('my-app/special:name..test');
    assertTrue(str_ends_with($path, 'my-app_special_name..test.json'), 'Path sanitized unsafe chars to underscore');
    return true;
});

test('purgeExpiredBackups() purges based on file modification time', function() {
    $testFile = LABEL_BACKUPS_DIR . DIRECTORY_SEPARATOR . 'test_expired_backup.json';
    @mkdir(LABEL_BACKUPS_DIR, 0777, true);
    file_put_contents($testFile, json_encode(['timestamp' => time() - 100000, 'labels' => []]));
    touch($testFile, time() - 100000);
    $purged = purgeExpiredBackups(24);
    assertTrue($purged >= 1, 'Purged expired backup file');
    assertFalse(file_exists($testFile), 'Expired file removed from disk');
    return true;
});

// ----------------------------------------------------
suite('Security Hardening & Input Sanitization');
// ----------------------------------------------------
test('apply_labels.php enforces fail-closed CSRF and POST-only recreate_single', function() {
    $script = file_get_contents(dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/scripts/apply_labels.php');
    assertTrue(strpos($script, 'empty($sessionToken) || empty($providedToken)') !== false, 'CSRF check is fail-closed');
    assertTrue(strpos($script, '$_POST[\'container\']') !== false, 'recreate_single reads container from $_POST');
    assertFalse(strpos($script, '$_REQUEST[\'container\']') !== false, 'recreate_single does not read from $_REQUEST');

    $settingsPage = file_get_contents(dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/HomepageLabelsSettings.page');
    assertTrue(strpos($settingsPage, 'empty($sessionToken) || empty($providedToken)') !== false, 'Settings page CSRF check is fail-closed');
    return true;
});

test('sanitizeContainerKey() neutralizes command injection and path traversal attempts', function() {
    assertEqual(sanitizeContainerKey('my-plex-server'), 'my-plex-server', 'Standard dashed name');
    assertEqual(sanitizeContainerKey('plex.app:custom/v1'), 'plex.app_custom_v1', 'Colon and slash sanitized');
    assertEqual(sanitizeContainerKey('../../../etc/passwd'), '.._.._.._etc_passwd', 'Path traversal sanitized');

    // Command injection vectors
    assertEqual(sanitizeContainerKey('plex; rm -rf /'), 'plex__rm_-rf__', 'Semicolon, spaces and slash sanitized');
    assertEqual(sanitizeContainerKey('plex && whoami'), 'plex____whoami', 'Ampersands stripped');
    assertEqual(sanitizeContainerKey('plex | cat /etc/shadow'), 'plex___cat__etc_shadow', 'Pipes stripped');
    assertEqual(sanitizeContainerKey('plex`id`'), 'plex_id_', 'Backticks stripped');
    assertEqual(sanitizeContainerKey('plex$(reboot)'), 'plex__reboot_', 'Subshell expansion stripped');
    assertEqual(sanitizeContainerKey("plex\nmalicious"), 'plex_malicious', 'Newlines stripped');
    return true;
});

test('docker_exec.sh has set -euo pipefail configured', function() {
    $script = file_get_contents(dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/scripts/docker_exec.sh');
    assertTrue(strpos($script, 'set -euo pipefail') !== false, 'docker_exec.sh includes set -euo pipefail');
    return true;
});

test('docker_container_created has set -euo pipefail configured and safely passes arguments', function() {
    $script = file_get_contents(dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/event/docker_container_created');
    assertTrue(strpos($script, 'set -euo pipefail') !== false, 'docker_container_created includes set -euo pipefail');
    assertTrue(strpos($script, '$argv[1]') !== false, 'Arguments passed safely to php CLI via $argv');
    return true;
});
