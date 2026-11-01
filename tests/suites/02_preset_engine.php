<?php
/**
 * Suite 02: Preset Engine, URL Resolution & Services Export
 */

// ----------------------------------------------------
suite('Preset Engine Resolution & Matching');
// ----------------------------------------------------
test('matchPreset() exact match', function() {
    $preset = matchPreset('linuxserver/sonarr:latest');
    assertTrue($preset !== null, 'Preset found');
    assertEqual($preset['name'], 'Sonarr', 'Preset name');
    assertEqual($preset['match_type'], 'exact', 'Match type exact');
    assertEqual($preset['widget']['type'], 'sonarr', 'Widget type');
    return true;
});

test('matchPreset() alias match (hotio/sonarr)', function() {
    $preset = matchPreset('hotio/sonarr:v4');
    assertTrue($preset !== null, 'Preset found');
    assertEqual($preset['name'], 'Sonarr', 'Preset name');
    assertEqual($preset['match_type'], 'alias', 'Match type alias');
    return true;
});

test('matchPreset() keyword match from repo tail', function() {
    $preset = matchPreset('customuser/radarr:develop');
    assertTrue($preset !== null, 'Preset found');
    assertEqual($preset['name'], 'Radarr', 'Preset name');
    assertEqual($preset['match_type'], 'keyword', 'Match type keyword');
    return true;
});

test('matchPreset() returns null for unrecognized repository', function() {
    $preset = matchPreset('some/completely-unheard-of-tool:1.0');
    assertEqual($preset, null, 'Unrecognized repo returns null');
    return true;
});

test('matchPreset() successfully resolves community application presets', function() {
    $expectedApps = [
        'linuxserver/overseerr'                => 'Overseerr',
        'sctx/overseerr'                       => 'Overseerr',
        'fallenbagel/jellyseerr'               => 'Jellyseerr',
        'ghcr.io/tautulli/tautulli'            => 'Tautulli',
        'ghcr.io/advplyr/audiobookshelf'       => 'Audiobookshelf',
        'linuxserver/transmission'             => 'Transmission',
        'ghcr.io/paperless-ngx/paperless-ngx'  => 'Paperless-ngx',
        'alexjustesen/speedtest-tracker'       => 'Speedtest-Tracker',
        'vaultwarden/server'                   => 'Vaultwarden',
        'jvmilazz0/kavita'                     => 'Kavita',
        'gotson/komga'                         => 'Komga',
    ];

    foreach ($expectedApps as $repo => $expectedName) {
        $preset = matchPreset($repo);
        assertTrue($preset !== null, "Preset resolved for {$repo}");
        assertEqual($preset['name'], $expectedName, "Name matches {$expectedName}");
    }
    return true;
});

// ----------------------------------------------------
suite('URL and Internal URL Resolution');
// ----------------------------------------------------
test('resolveUrl() resolves [IP] and [PORT:xxxx] placeholders', function() {
    $res = readTemplate('plex');
    $meta = getContainerMeta($res['doc']);
    $settings = ['BASE_HOST' => '10.0.0.5', 'BASE_SCHEME' => 'http'];
    $url = resolveUrl($meta, $settings);
    assertEqual($url, 'http://10.0.0.5:32400/web', 'Resolved WebUI URL');

    // Test scheme-less WebUI prefixing with BASE_SCHEME
    $metaCustom = ['WebUI' => '[IP]:8080/app', 'Ports' => ['8080' => '8080']];
    $urlHttps = resolveUrl($metaCustom, ['BASE_HOST' => '10.0.0.5', 'BASE_SCHEME' => 'https']);
    assertEqual($urlHttps, 'https://10.0.0.5:8080/app', 'Resolved scheme-less URL with https scheme');

    // Test already-specified https scheme preserved
    $metaHttps = ['WebUI' => 'https://[IP]:8443', 'Ports' => ['8443' => '8443']];
    $urlExplicit = resolveUrl($metaHttps, ['BASE_HOST' => '10.0.0.5', 'BASE_SCHEME' => 'http']);
    assertEqual($urlExplicit, 'https://10.0.0.5:8443', 'Preserves existing https scheme');
    return true;
});

test('resolveUrl() cleans up trailing colon when port placeholder is unmapped', function() {
    $meta = ['WebUI' => 'http://[IP]:[PORT]/', 'Ports' => []];
    $settings = ['BASE_HOST' => '10.0.0.10', 'BASE_SCHEME' => 'http'];
    $url = resolveUrl($meta, $settings);
    assertEqual($url, 'http://10.0.0.10/', 'Dangling colon removed without stripping protocol colon');
    return true;
});

test('resolveUrl() and resolveInternalUrl() format IPv6 addresses in bracket notation', function() {
    $meta = ['WebUI' => 'http://[IP]:[PORT:8080]/app', 'Ports' => ['8080' => '8080']];
    $settings = ['BASE_HOST' => '2001:db8::1', 'BASE_SCHEME' => 'http', 'INTERNAL_URL_MODE' => 'host_port'];
    
    $url = resolveUrl($meta, $settings);
    assertEqual($url, 'http://[2001:db8::1]:8080/app', 'IPv6 formatted with brackets in resolveUrl');

    $internalUrl = resolveInternalUrl('test-app', 8080, $meta, $settings);
    assertEqual($internalUrl, 'http://[2001:db8::1]:8080', 'IPv6 formatted with brackets in resolveInternalUrl');
    return true;
});

test('resolveInternalUrl() in host_port mode', function() {
    $res = readTemplate('sonarr');
    $meta = getContainerMeta($res['doc']);
    $settings = ['BASE_HOST' => '192.168.1.200', 'BASE_SCHEME' => 'http', 'INTERNAL_URL_MODE' => 'host_port'];
    $url = resolveInternalUrl('sonarr', 8989, $meta, $settings);
    assertEqual($url, 'http://192.168.1.200:8989', 'Internal host_port URL');
    return true;
});

test('resolveInternalUrl() in container_network mode', function() {
    $res = readTemplate('sonarr');
    $meta = getContainerMeta($res['doc']);
    $settings = ['BASE_HOST' => '192.168.1.200', 'BASE_SCHEME' => 'http', 'INTERNAL_URL_MODE' => 'container_network'];
    $url = resolveInternalUrl('sonarr', 8989, $meta, $settings);
    assertEqual($url, 'http://sonarr:8989', 'Internal container_network URL');
    return true;
});

test('getQuickFillUrls() calculates IP, Host, Container and WebUI URLs accurately', function() {
    $meta = [
        'WebUI' => 'http://[IP]:[PORT:8989]/',
        'Ports' => ['8989' => '8989']
    ];
    $settings = [
        'BASE_HOST' => '192.168.1.150',
        'BASE_SCHEME' => 'http',
        'INTERNAL_URL_MODE' => 'host_port'
    ];

    $quickUrls = getQuickFillUrls('Sonarr-PVR', $meta, $settings, 8989);
    assertTrue(is_array($quickUrls), 'Returns array of URLs');
    assertEqual($quickUrls['ipUrl'], 'http://192.168.1.150:8989', 'ipUrl formatted correctly');
    assertEqual($quickUrls['containerUrl'], 'http://Sonarr-PVR:8989', 'containerUrl formatted with internal container name');
    assertEqual($quickUrls['webuiUrl'], 'http://192.168.1.150:8989/', 'webuiUrl resolved from WebUI template');
    return true;
});

// ----------------------------------------------------
suite('Default Label Generation');
// ----------------------------------------------------
test('generateDefaultLabels() creates complete label suite from preset', function() {
    $res = readTemplate('sonarr');
    $meta = getContainerMeta($res['doc']);
    $preset = matchPreset($meta['Repository']);
    $settings = ['BASE_HOST' => '192.168.1.50', 'BASE_SCHEME' => 'http'];
    $labels = generateDefaultLabels('sonarr', $preset, $meta, [], $settings);

    assertTrue(isset($labels['homepage.name']), 'homepage.name generated');
    assertEqual($labels['homepage.name']['value'], 'Sonarr', 'homepage.name value');
    assertTrue(isset($labels['homepage.icon']), 'homepage.icon generated');
    assertEqual($labels['homepage.icon']['value'], 'sonarr.svg', 'homepage.icon value');
    assertTrue(isset($labels['homepage.widget.type']), 'homepage.widget.type generated');
    assertEqual($labels['homepage.widget.type']['value'], 'sonarr', 'homepage.widget.type value');
    assertTrue(isset($labels['homepage.widget.key']), 'homepage.widget.key generated');
    assertEqual($labels['homepage.widget.key']['mask'], 'true', 'API key is masked');
    return true;
});

test('generateDefaultLabels() falls back to "Services" group when unmapped and no existing labels', function() {
    $meta = ['Name' => 'my-unmapped-app', 'Repository' => 'custom/unmapped', 'WebUI' => '', 'Ports' => []];
    $settings = ['BASE_HOST' => '192.168.1.10', 'BASE_SCHEME' => 'http'];
    $labels = generateDefaultLabels('my-unmapped-app', null, $meta, [], $settings);

    assertTrue(isset($labels['homepage.group']), 'homepage.group key is generated');
    assertEqual($labels['homepage.group']['value'], 'Services', 'Unrecognized app defaults to Services group');
    return true;
});

// ----------------------------------------------------
suite('Diff Calculation Engine');
// ----------------------------------------------------
test('compareHomepageLabels() identifies unchanged labels', function() {
    $existing = [
        'homepage.name' => ['value' => 'Sonarr', 'mask' => 'false'],
        'homepage.icon' => ['value' => 'sonarr.svg', 'mask' => 'false'],
    ];
    $incoming = [
        'homepage.name' => ['value' => 'Sonarr', 'mask' => 'false'],
        'homepage.icon' => ['value' => 'sonarr.svg', 'mask' => 'false'],
    ];
    $diff = compareHomepageLabels($existing, $incoming);
    assertFalse($diff['changed'], 'Should have no changes');
    assertEqual($diff['unchanged'], 2, 'Unchanged count');
    assertEqual($diff['added'], 0, 'Added count');
    assertEqual($diff['modified'], 0, 'Modified count');
    assertEqual($diff['removed'], 0, 'Removed count');
    return true;
});

test('compareHomepageLabels() identifies added, modified, and removed labels', function() {
    $existing = [
        'homepage.name' => ['value' => 'Sonarr', 'mask' => 'false'],
        'homepage.icon' => ['value' => 'sonarr.svg', 'mask' => 'false'],
        'homepage.old'  => ['value' => 'to-be-removed', 'mask' => 'false'],
    ];
    $incoming = [
        'homepage.name'  => ['value' => 'Sonarr PVR', 'mask' => 'false'],
        'homepage.icon'  => ['value' => 'sonarr.svg', 'mask' => 'false'],
        'homepage.group' => ['value' => 'Media', 'mask' => 'false'],
    ];
    $diff = compareHomepageLabels($existing, $incoming);
    assertTrue($diff['changed'], 'Should report changes');
    assertEqual($diff['added'], 1, 'Added count');
    assertEqual($diff['modified'], 1, 'Modified count');
    assertEqual($diff['removed'], 1, 'Removed count');
    assertEqual($diff['unchanged'], 1, 'Unchanged count');
    assertEqual($diff['diffs']['homepage.group'], 'added', 'Diff mark added');
    assertEqual($diff['diffs']['homepage.name'], 'modified', 'Diff mark modified');
    assertEqual($diff['diffs']['homepage.old'], 'removed', 'Diff mark removed');
    assertEqual($diff['diffs']['homepage.icon'], 'unchanged', 'Diff mark unchanged');
    return true;
});

test('getAllKnownHomepageFields() includes all extended fields across sections', function() {
    $catalog = getAllKnownHomepageFields();

    assertTrue(isset($catalog['display']['homepage.pod']), 'homepage.pod defined in display section');
    assertTrue(isset($catalog['display']['homepage.tab']), 'homepage.tab defined in display section');
    assertTrue(isset($catalog['health']['homepage.siteMonitor.method']), 'homepage.siteMonitor.method defined in health section');
    assertTrue(isset($catalog['health']['homepage.siteMonitor.status']), 'homepage.siteMonitor.status defined in health section');
    assertTrue(isset($catalog['health']['homepage.siteMonitor.headers']), 'homepage.siteMonitor.headers defined in health section');
    assertTrue(isset($catalog['widget_known']['homepage.widget.fields']), 'homepage.widget.fields defined in widget_known section');
    return true;
});

// ----------------------------------------------------
suite('Connection Testing & Services YAML Export');
// ----------------------------------------------------
test('probeHttpConnection() rejects malformed URLs fail-closed', function() {
    $res = probeHttpConnection('not-a-valid-url');
    assertEqual($res['success'], false, 'Invalid URL reports failure');
    assertEqual($res['http_code'], 0, 'HTTP code is 0');
    assertTrue(strpos($res['message'], 'Invalid URL') !== false, 'Error message mentions invalid URL');

    $fileRes = probeHttpConnection('file:///etc/passwd');
    assertEqual($fileRes['success'], false, 'file:// scheme rejected');
    assertEqual($fileRes['http_code'], 0, 'file:// HTTP code is 0');
    assertTrue(strpos($fileRes['message'], 'Only http and https protocols are supported') !== false, 'Rejects file:// scheme');

    $gopherRes = probeHttpConnection('gopher://127.0.0.1:11211');
    assertEqual($gopherRes['success'], false, 'gopher:// scheme rejected');
    return true;
});

test('yamlEscape() correctly handles scalars and strings with special characters', function() {
    assertEqual(yamlEscape(123), '123', 'Number unchanged');
    assertEqual(yamlEscape(true), 'true', 'Boolean true formatted');
    assertEqual(yamlEscape(false), 'false', 'Boolean false formatted');
    assertEqual(yamlEscape('SimpleText'), 'SimpleText', 'Simple text unquoted');
    assertEqual(yamlEscape('Text: With Colon'), '"Text: With Colon"', 'Special colon character quoted');
    assertEqual(yamlEscape('Text "with" quotes'), '"Text \\"with\\" quotes"', 'Quotes escaped');
    return true;
});

test('generateServicesYaml() compiles active containers into valid Homepage services YAML', function() {
    $containers = [
        [
            'name'  => 'sonarr',
            'group' => 'Media',
            'labels'=> [
                'homepage.name'        => ['value' => 'Sonarr', 'mask' => 'false'],
                'homepage.group'       => ['value' => 'Media', 'mask' => 'false'],
                'homepage.icon'        => ['value' => 'sonarr.png', 'mask' => 'false'],
                'homepage.href'        => ['value' => 'http://192.168.1.100:8989', 'mask' => 'false'],
                'homepage.widget.type' => ['value' => 'sonarr', 'mask' => 'false'],
                'homepage.widget.url'  => ['value' => 'http://192.168.1.100:8989', 'mask' => 'false'],
                'homepage.widget.key'  => ['value' => '123456789', 'mask' => 'true'],
            ]
        ],
        [
            'name'  => 'qbittorrent',
            'group' => 'Downloads',
            'labels'=> [
                'homepage.name'  => ['value' => 'qBittorrent', 'mask' => 'false'],
                'homepage.group' => ['value' => 'Downloads', 'mask' => 'false'],
                'homepage.icon'  => ['value' => 'qbittorrent.png', 'mask' => 'false'],
            ]
        ]
    ];

    $yaml = generateServicesYaml($containers);
    assertTrue(is_string($yaml), 'YAML output is string');
    assertTrue(strpos($yaml, '- Media:') !== false, 'Media group header exists');
    assertTrue(strpos($yaml, '- Sonarr:') !== false, 'Sonarr service entry exists');
    assertTrue(strpos($yaml, 'widget:') !== false, 'Widget section exists');
    assertTrue(strpos($yaml, 'type: sonarr') !== false, 'Widget type exists');
    assertTrue(strpos($yaml, 'key: "123456789"') !== false || strpos($yaml, 'key: 123456789') !== false, 'Widget key preserved');
    assertTrue(strpos($yaml, '- Downloads:') !== false, 'Downloads group header exists');
    assertTrue(strpos($yaml, '- qBittorrent:') !== false, 'qBittorrent service entry exists');
    return true;
});

test('renderBulkGroupModal() generates markup containing existing groups and input', function() {
    if (!function_exists('renderBulkGroupModal')) {
        require_once dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/homepage-labels/include/view_components.php';
    }
    $html = renderBulkGroupModal(['Media', 'Downloads', 'Services']);
    assertTrue(strpos($html, 'id="homepage-bulk-group-modal"') !== false, 'Modal ID exists');
    assertTrue(strpos($html, 'id="homepage-bulk-group-input"') !== false, 'Input field exists');
    assertTrue(strpos($html, '<option value="Media">Media</option>') !== false, 'Media group option exists');
    return true;
});

test('batch_set_group writes updated group across fixture templates', function() use ($fixturesSrc, $templatesDir) {
    $cName = 'sonarr';
    $readRes = readTemplate($cName);
    assertTrue($readRes['success'], 'Fixture template read');

    $existing = getHomepageLabels($readRes['doc']);
    $existing['homepage.group'] = ['value' => 'NewGroupTesting', 'mask' => 'false'];

    $writeRes = writeHomepageLabels($cName, $existing, false, true);
    assertTrue($writeRes['success'], 'Write success');

    $checkRes = readTemplate($cName);
    $updatedLabels = getHomepageLabels($checkRes['doc']);
    assertEqual($updatedLabels['homepage.group']['value'], 'NewGroupTesting', 'Group successfully updated in template');

    // Reset sonarr fixture so subsequent tests have pristine template
    copy($fixturesSrc . DIRECTORY_SEPARATOR . 'my-sonarr.xml', $templatesDir . DIRECTORY_SEPARATOR . 'my-sonarr.xml');
    return true;
});
