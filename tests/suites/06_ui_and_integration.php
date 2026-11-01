<?php
/**
 * Suite 06: Unraid UI, Overlay Editor, Hooks & Dynamix Integration
 */

// ----------------------------------------------------
suite('Unraid System & Docker Integration');
// ----------------------------------------------------
test('findHomepageContainer() detects container from templates or preferred name', function() {
    $found = findHomepageContainer('sonarr');
    assertEqual($found, 'sonarr', 'Finds existing template');

    $fallback = findHomepageContainer('non-existent-homepage-container-12345');
    assertTrue(!empty($fallback), 'Returns a valid fallback name');
    return true;
});

test('validateDockerSocket() detects Docker Socket Proxy configuration', function() {
    $socketCheck = validateDockerSocket('sonarr');
    assertTrue(isset($socketCheck['socketMounted']), 'socketMounted key exists');
    assertTrue(isset($socketCheck['connectionType']), 'connectionType key exists');
    return true;
});

test('Hook and Event files exist and are valid scripts', function() {
    $hookFile = dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/HomepageLabelsHook.page';
    $eventFile = dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/event/docker_container_created';

    assertTrue(file_exists($hookFile), 'HomepageLabelsHook.page exists');
    assertTrue(file_exists($eventFile), 'docker_container_created event file exists');

    $hookContent = file_get_contents($hookFile);
    assertTrue(strpos($hookContent, 'Menu="Buttons:99"') !== false, 'Hook uses Buttons:99 menu');
    assertTrue(strpos($hookContent, 'Disabled:Label') !== false, 'Hook contains Disabled:Label logic');
    assertTrue(strpos($hookContent, 'Add Homepage Labels') !== false, 'Hook renders Add Homepage Labels bottom link');
    assertTrue(strpos($hookContent, 'Edit Homepage Labels') !== false, 'Hook renders dynamic Edit Homepage Labels when labels exist');
    assertTrue(strpos($hookContent, 'Add another Path, Port, Variable, Label or Device') !== false, 'Hook anchors directly below Unraid Add Config link');
    assertTrue(strpos($hookContent, 'hpOpenOverlayEditor') !== false, 'Hook contains in-page Overlay Editor modal handler');
    assertTrue(strpos($hookContent, 'hpResolveContainerName') !== false, 'Hook resolves container name from xmlTemplate param and inputs');
    assertTrue(strpos($hookContent, 'hpNativeSyncFormLabels') !== false, 'Hook syncs saved overlay labels back to Unraid form DOM');

    $eventContent = file_get_contents($eventFile);
    assertTrue(strpos($eventContent, '#!/bin/bash') === 0, 'Event script is bash');
    assertTrue(strpos($eventContent, 'notify') !== false, 'Event script calls notify');
    return true;
});

test('Unified Tabbed Navigation (Option 2) hides duplicate Settings icon and supports tab routing', function() {
    $mainPage = file_get_contents(dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/HomepageLabels.page');
    $settingsPage = file_get_contents(dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/HomepageLabelsSettings.page');

    assertTrue(strpos($mainPage, 'Menu="Utilities"') !== false, 'Main page is registered in Utilities menu');
    assertTrue(strpos($settingsPage, 'Menu="Hidden"') !== false, 'Settings page uses Menu="Hidden" to avoid duplicate User Utilities icon');
    assertTrue(strpos($mainPage, '/Settings/HomepageLabels?tab=settings') !== false, 'Main page links to ?tab=settings');
    assertTrue(strpos($settingsPage, 'DOCKER_EDIT_OVERLAY') !== false, 'Settings page includes DOCKER_EDIT_OVERLAY configuration');
    return true;
});

test('Embedded Overlay Editor mode (?embed=1) renders Close Overlay and Save & Sync controls', function() {
    if (!function_exists('renderEditorForm')) {
        require_once dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/include/view_components.php';
    }
    $defaults = getDefaultPluginSettings();
    assertEqual($defaults['DOCKER_EDIT_OVERLAY'] ?? '', 'true', 'DOCKER_EDIT_OVERLAY defaults to true');

    $_GET['embed'] = '1';
    $html = renderEditorForm('Docker-Socket-Proxy', [], null, [], [], [], $defaults);
    unset($_GET['embed']);

    assertTrue(strpos($html, 'Close Overlay') !== false, 'Embedded editor renders Close Overlay button');
    assertTrue(strpos($html, 'Save &amp; Sync to Form') !== false || strpos($html, 'Save & Sync to Form') !== false, 'Embedded editor renders Save & Sync to Form button');
    return true;
});

test('UI Usability Improvements (Unified Toolbar, Inactive Catalog Drawer, Dimmed Hints)', function() {
    if (!function_exists('renderTemplateToolbar')) {
        require_once dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/include/view_components.php';
    }
    $defaults = getDefaultPluginSettings();
    $presets = loadAllPresets();
    $userTemplates = loadUserTemplates();

    // 1. Unified Toolbar
    $toolbarHtml = renderTemplateToolbar($presets, $userTemplates, $defaults);
    assertTrue(strpos($toolbarHtml, 'id="unified-template-select"') !== false, 'Toolbar has unified template select');
    assertTrue(strpos($toolbarHtml, 'homepageApplyUnifiedTemplate()') !== false, 'Toolbar has unified Apply button');
    assertTrue(strpos($toolbarHtml, 'id="default-template-select"') !== false, 'Toolbar retains backward-compatible default-template-select');
    assertTrue(strpos($toolbarHtml, 'id="custom-template-select"') !== false, 'Toolbar retains backward-compatible custom-template-select');

    // 2. Dimmed Field Row
    $dimmedRowHtml = renderInteractiveFieldRow('homepage.widget.key', '', ['label' => 'API Key', 'type' => 'password', 'key_hint' => 'Settings -> Security'], false, false);
    assertTrue(strpos($dimmedRowHtml, 'homepage-dimmed-hint') !== false, 'Dimmed row renders click to enable hint');
    assertTrue(strpos($dimmedRowHtml, 'homepage-key-hint') !== false, 'API Key field renders where to find key hint');
    assertTrue(strpos($dimmedRowHtml, 'homepage-btn--remove-field" title="Deactivate and clear field" onclick="homepageDeactivateField(this)" style="display:none;"') !== false, 'Remove button hidden on dimmed row');

    // 3. Editor Form & Inactive Widget Catalog Drawer
    $formHtml = renderEditorForm('Sonarr', [], null, $presets, ['Media'], [], $defaults);
    assertTrue(strpos($formHtml, 'id="btn-toggle-other-widgets"') !== false, 'Editor has button to toggle other catalog fields');
    assertTrue(strpos($formHtml, 'id="widget-other-fields"') !== false, 'Editor retains widget-other-fields container');

    // 4. Dynamic Hook Link
    $hookFile = file_get_contents(dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/HomepageLabelsHook.page');
    assertTrue(strpos($hookFile, 'Edit Homepage Labels') !== false, 'Hook page supports dynamic Edit Homepage Labels');
    assertTrue(strpos($hookFile, 'Add Homepage Labels') !== false, 'Hook page supports Add Homepage Labels');
    return true;
});

test('Multi-source icon resolution prefixes (si-, sh-, mdi-) in view_components.php', function() {
    if (!function_exists('renderDashboardTable')) {
        require_once dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/include/view_components.php';
    }
    $summaries = [
        [
            'name'             => 'test-simpleicons',
            'displayName'      => 'Test SimpleIcons',
            'repository'       => 'test/repo',
            'templateIcon'     => '',
            'discoveryActive'  => true,
            'group'            => 'Services',
            'icon'             => 'si-docker',
            'href'             => 'http://127.0.0.1:8080',
            'widgetType'       => '',
            'widgetConfigured' => false,
            'hasHealth'        => false,
            'hasBackup'        => false,
            'pendingRecreate'  => false,
            'labelCount'       => 2,
            'error'            => null,
        ],
        [
            'name'             => 'test-selfhst',
            'displayName'      => 'Test Selfhst',
            'repository'       => 'test/repo',
            'templateIcon'     => '',
            'discoveryActive'  => true,
            'group'            => 'Services',
            'icon'             => 'sh-sonarr',
            'href'             => 'http://127.0.0.1:8989',
            'widgetType'       => '',
            'widgetConfigured' => false,
            'hasHealth'        => false,
            'hasBackup'        => false,
            'pendingRecreate'  => false,
            'labelCount'       => 2,
            'error'            => null,
        ],
        [
            'name'             => 'test-mdi',
            'displayName'      => 'Test MDI',
            'repository'       => 'test/repo',
            'templateIcon'     => '',
            'discoveryActive'  => true,
            'group'            => 'Services',
            'icon'             => 'mdi-server',
            'href'             => 'http://127.0.0.1:9000',
            'widgetType'       => '',
            'widgetConfigured' => false,
            'hasHealth'        => false,
            'hasBackup'        => false,
            'pendingRecreate'  => false,
            'labelCount'       => 2,
            'error'            => null,
        ],
    ];

    $html = renderDashboardTable($summaries);
    assertTrue(strpos($html, 'cdn.jsdelivr.net/npm/simple-icons@latest/icons/docker.svg') !== false, 'Resolves si- prefix to Simple Icons CDN');
    assertTrue(strpos($html, 'cdn.jsdelivr.net/gh/selfhst/icons/svg/sonarr.svg') !== false, 'Resolves sh- prefix to Selfh.st CDN');
    assertTrue(strpos($html, 'cdn.jsdelivr.net/npm/@mdi/svg@latest/svg/server.svg') !== false, 'Resolves mdi- prefix to MDI CDN');
    return true;
});

