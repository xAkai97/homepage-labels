<?php
/**
 * Suite 04: Dashboard Summaries, Validator & Pending Recreates
 */

// ----------------------------------------------------
suite('Dashboard Summaries');
// ----------------------------------------------------
test('getContainerSummary() calculates correct dashboard status', function() {
    $sonarrSummary = getContainerSummary('sonarr');
    assertEqual($sonarrSummary['name'], 'sonarr', 'Container name');
    assertTrue($sonarrSummary['discoveryActive'], 'Discovery active for sonarr');
    assertEqual($sonarrSummary['group'], 'Media Management', 'Group');
    assertEqual($sonarrSummary['widgetType'], 'sonarr', 'Widget type');
    assertTrue($sonarrSummary['widgetConfigured'], 'Widget configured');
    assertEqual($sonarrSummary['href'], 'http://192.168.1.100:8989/', 'Href label extracted');

    $autobrrSummary = getContainerSummary('autobrr');
    assertEqual($autobrrSummary['name'], 'autobrr', 'Container name');
    assertFalse($autobrrSummary['discoveryActive'], 'Autobrr has no labels');
    assertFalse($autobrrSummary['widgetConfigured'], 'Autobrr widget not configured');
    assertEqual($autobrrSummary['href'], '', 'Autobrr href is empty');
    return true;
});

test('getContainerSummaries() batch processor', function() {
    $templates = listAllTemplates();
    $summaries = getContainerSummaries($templates);
    assertTrue(is_array($summaries), 'Returns array');
    assertTrue(count($summaries) >= 3, 'Contains summaries for all valid templates');
    return true;
});

// ----------------------------------------------------
suite('Container Label Validator');
// ----------------------------------------------------
test('validateContainerLabels() returns valid=true for containers with no labels', function() {
    $val = validateContainerLabels('autobrr');
    assertTrue($val['valid'], 'Autobrr without labels should be valid');
    assertEqual(count($val['warnings']), 0, 'No warnings for unlabeled container');
    return true;
});

test('validateContainerLabels() validates formatted template labels', function() {
    $val = validateContainerLabels('plex');
    assertTrue($val['valid'], 'Plex with standard labels should be valid');
    assertEqual(count($val['warnings']), 0, 'No warnings for valid Plex labels');
    return true;
});

test('validateContainerLabels() detects invalid URLs and non-numeric fields', function() use ($fixturesSrc, $templatesDir) {
    // Write an invalid set of labels to sonarr
    $invalidLabels = [
        'homepage.group'  => ['value' => 'Media', 'mask' => 'false'],
        'homepage.href'   => ['value' => 'ht!tp://invalid url with spaces', 'mask' => 'false'],
        'homepage.weight' => ['value' => 'not-a-number', 'mask' => 'false'],
        'homepage.widget.type' => ['value' => 'sonarr', 'mask' => 'false'],
    ];
    writeHomepageLabels('sonarr', $invalidLabels, false, true);

    $val = validateContainerLabels('sonarr');
    assertFalse($val['valid'], 'Should be invalid due to validation errors');
    assertTrue(count($val['warnings']) >= 3, 'Should produce at least 3 warnings');

    $allWarnings = implode(' ', $val['warnings']);
    assertTrue(strpos($allWarnings, 'homepage.name is not set') !== false, 'Warns about missing name');
    assertTrue(strpos($allWarnings, 'does not appear to be a valid URL') !== false, 'Warns about invalid URL');
    assertTrue(strpos($allWarnings, 'must be a valid numeric value') !== false, 'Warns about non-numeric weight');
    assertTrue(strpos($allWarnings, 'homepage.widget.url is missing') !== false, 'Warns about missing widget url');

    // Reset sonarr fixture
    copy($fixturesSrc . DIRECTORY_SEPARATOR . 'my-sonarr.xml', $templatesDir . DIRECTORY_SEPARATOR . 'my-sonarr.xml');
    return true;
});

test('validateContainerLabels() accepts valid URLs with [IP] and [PORT:xxxx] placeholders', function() use ($fixturesSrc, $templatesDir) {
    $placeholderLabels = [
        'homepage.group'  => ['value' => 'Media', 'mask' => 'false'],
        'homepage.name'   => ['value' => 'Sonarr', 'mask' => 'false'],
        'homepage.href'   => ['value' => 'http://[IP]:[PORT:8989]/', 'mask' => 'false'],
        'homepage.siteMonitor' => ['value' => 'http://[IP]:8989/ping', 'mask' => 'false'],
    ];
    writeHomepageLabels('sonarr', $placeholderLabels, false, true);

    $val = validateContainerLabels('sonarr');
    assertTrue($val['valid'], 'Valid template with placeholder URLs should not produce warnings');
    assertEqual(count($val['warnings']), 0, 'No warnings for valid [IP] placeholder URLs');

    // Reset sonarr fixture
    copy($fixturesSrc . DIRECTORY_SEPARATOR . 'my-sonarr.xml', $templatesDir . DIRECTORY_SEPARATOR . 'my-sonarr.xml');
    return true;
});

// ----------------------------------------------------
suite('Pending Recreates State Machine');
// ----------------------------------------------------
test('loadPendingRecreates() returns empty array when file does not exist', function() {
    $list = loadPendingRecreates();
    assertTrue(is_array($list), 'Returns array');
    return true;
});

test('addPendingRecreate(), isPendingRecreate(), and removePendingRecreate() full cycle', function() {
    assertFalse(isPendingRecreate('test-recreate-app'), 'Initially not pending');

    $added = addPendingRecreate('test-recreate-app');
    assertTrue($added, 'addPendingRecreate succeeded');
    assertTrue(isPendingRecreate('test-recreate-app'), 'isPendingRecreate returns true after add');

    $list = loadPendingRecreates();
    assertTrue(isset($list['test-recreate-app']), 'Container in pending list');
    assertTrue($list['test-recreate-app'] > 0, 'Timestamp recorded');

    $removed = removePendingRecreate('test-recreate-app');
    assertTrue($removed, 'removePendingRecreate succeeded');
    assertFalse(isPendingRecreate('test-recreate-app'), 'isPendingRecreate returns false after remove');
    return true;
});

test('getContainerSummary() calculates pendingRecreate boolean flag accurately', function() {
    // Before adding pending recreate
    $summaryBefore = getContainerSummary('sonarr');
    assertFalse($summaryBefore['pendingRecreate'] ?? false, 'pendingRecreate is false before queueing');

    // Add sonarr to pending recreate
    addPendingRecreate('sonarr');

    // After adding pending recreate
    $summaryAfter = getContainerSummary('sonarr');
    assertTrue($summaryAfter['pendingRecreate'] ?? false, 'pendingRecreate is true when in pending list');

    // Cleanup
    removePendingRecreate('sonarr');
    $summaryCleaned = getContainerSummary('sonarr');
    assertFalse($summaryCleaned['pendingRecreate'] ?? false, 'pendingRecreate is false after removing');
    return true;
});

