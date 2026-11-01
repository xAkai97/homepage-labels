<?php
/**
 * Suite 03: Custom User Templates & Import/Export Engine
 */

// ----------------------------------------------------
suite('User Custom Templates & Presets');
// ----------------------------------------------------
test('saveCustomUserTemplate() and loadUserTemplates() persistence', function() {
    $customFields = [
        'homepage.group' => ['value' => 'Tools', 'mask' => 'false', 'active' => true],
        'homepage.icon'  => ['value' => 'custom.svg', 'mask' => 'false', 'active' => true],
    ];

    $res = saveCustomUserTemplate('My Custom App', $customFields, false);
    assertTrue($res['success'], 'saveCustomUserTemplate succeeded');
    $tplId = $res['id'];

    $templates = loadUserTemplates();
    assertTrue(isset($templates['templates'][$tplId]), 'Template loaded from file');
    assertEqual($templates['templates'][$tplId]['name'], 'My Custom App', 'Template name');

    $renamed = renameUserTemplate($tplId, 'My App V2');
    assertTrue($renamed['success'], 'renameUserTemplate succeeded');

    $templatesRenamed = loadUserTemplates();
    assertEqual($templatesRenamed['templates'][$tplId]['name'], 'My App V2', 'Template renamed');

    $deleted = deleteUserTemplate($tplId);
    assertTrue($deleted['success'], 'deleteUserTemplate succeeded');

    $templatesAfter = loadUserTemplates();
    assertFalse(isset($templatesAfter['templates'][$tplId]), 'Template removed');
    return true;
});

test('saveUserTemplates() performs atomic write staging and preserves templates', function() use ($configDir) {
    $data = [
        'version'   => 1,
        'templates' => [
            'atomic-test' => [
                'id'             => 'atomic-test',
                'name'           => 'Atomic Test Template',
                'created'        => date('c'),
                'fields'         => [
                    'homepage.name' => ['value' => 'Atomic Test', 'mask' => 'false', 'active' => true]
                ],
                'widget_enabled' => false
            ]
        ]
    ];
    $saved = saveUserTemplates($data);
    assertTrue($saved, 'saveUserTemplates succeeded');
    $loaded = loadUserTemplates();
    assertTrue(isset($loaded['templates']['atomic-test']), 'Atomic saved template exists in loaded data');
    assertEqual($loaded['templates']['atomic-test']['name'], 'Atomic Test Template', 'Template name matches');
    return true;
});

test('importUserTemplates() in merge mode adds new templates without overwriting existing IDs', function() {
    // 1. Establish existing template in store
    $baseData = [
        'version' => '1.0',
        'templates' => [
            'existing-tpl' => [
                'id' => 'existing-tpl',
                'name' => 'Original Name',
                'fields' => ['homepage.name' => ['value' => 'Original', 'mask' => 'false']]
            ]
        ]
    ];
    saveUserTemplates($baseData);

    // 2. Import payload with conflicting ID and a new ID
    $importPayload = [
        'templates' => [
            'existing-tpl' => [
                'id' => 'existing-tpl',
                'name' => 'Modified Name In Import',
                'fields' => ['homepage.name' => ['value' => 'Should Not Overwrite In Merge', 'mask' => 'false']]
            ],
            'brand-new-tpl' => [
                'id' => 'brand-new-tpl',
                'name' => 'Brand New App',
                'fields' => ['homepage.name' => ['value' => 'Brand New', 'mask' => 'false']]
            ]
        ]
    ];

    $res = importUserTemplates(json_encode($importPayload), 'merge');
    assertTrue($res['success'], 'Import succeeded in merge mode');
    assertEqual($res['imported_count'], 2, 'Imported templates processed');

    $loaded = loadUserTemplates();
    assertEqual($loaded['templates']['existing-tpl']['name'], 'Original Name', 'Existing template retained original name');
    assertTrue(isset($loaded['templates']['brand-new-tpl']), 'Brand new template added');
    assertTrue(isset($loaded['templates']['existing-tpl-2']), 'Conflicting template given suffixed key');
    return true;
});

test('importUserTemplates() in overwrite mode updates matching IDs and adds new ones', function() {
    $importPayload = [
        'templates' => [
            'existing-tpl' => [
                'id' => 'existing-tpl',
                'name' => 'Overwritten Name',
                'fields' => ['homepage.name' => ['value' => 'Overwritten Value', 'mask' => 'false']]
            ]
        ]
    ];

    $res = importUserTemplates($importPayload, 'overwrite');
    assertTrue($res['success'], 'Import succeeded in overwrite mode');
    assertEqual($res['imported_count'], 1, 'Imported 1 template in overwrite mode');

    $loaded = loadUserTemplates();
    assertEqual($loaded['templates']['existing-tpl']['name'], 'Overwritten Name', 'Existing template overwritten');
    return true;
});

test('importUserTemplates() in replace mode clears store and sets only incoming templates', function() {
    $replacePayload = [
        'templates' => [
            'sole-survivor' => [
                'id' => 'sole-survivor',
                'name' => 'Sole Survivor',
                'fields' => ['homepage.name' => ['value' => 'Sole', 'mask' => 'false']]
            ]
        ]
    ];

    $res = importUserTemplates(json_encode($replacePayload), 'replace');
    assertTrue($res['success'], 'Import succeeded in replace mode');

    $loaded = loadUserTemplates();
    assertEqual(count($loaded['templates']), 1, 'Only 1 template remains');
    assertTrue(isset($loaded['templates']['sole-survivor']), 'Sole survivor exists');
    assertFalse(isset($loaded['templates']['existing-tpl']), 'Previous existing-tpl wiped');
    assertFalse(isset($loaded['templates']['brand-new-tpl']), 'Previous brand-new-tpl wiped');

    // Clean up templates store
    saveUserTemplates(['version' => '1.0', 'templates' => []]);
    return true;
});

test('importUserTemplates() rejects malformed JSON fail-closed', function() {
    $res = importUserTemplates('{"invalid_json": true, not-valid-syntax', 'merge');
    assertFalse($res['success'], 'Invalid JSON import fails');
    assertTrue(strpos($res['error'], 'Invalid JSON') !== false, 'Error message indicates JSON failure');

    $emptyRes = importUserTemplates('{"version": "1.0", "templates": {}}', 'merge');
    assertFalse($emptyRes['success'], 'Empty template payload fails');
    assertTrue(strpos($emptyRes['error'], 'No templates found') !== false, 'Error mentions no templates found');
    return true;
});
