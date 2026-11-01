<?php
/**
 * Suite 01: XML Parser, Metadata & Label Writing
 */

// ----------------------------------------------------
suite('XML Parser & Template Loading');
// ----------------------------------------------------
test('listAllTemplates() finds all fixture templates', function() {
    $templates = listAllTemplates();
    $names = array_column($templates, 'name');
    sort($names);
    return in_array('plex', $names) && in_array('sonarr', $names) && in_array('autobrr', $names);
});

test('getTemplatePath() returns correct sanitized path', function() use ($templatesDir) {
    $expected = str_replace('\\', '/', $templatesDir) . '/my-plex.xml';
    return str_replace('\\', '/', getTemplatePath('plex')) === $expected;
});

test('getTemplatePath() sanitizes directory traversal attempts', function() use ($templatesDir) {
    $path = getTemplatePath('../../../etc/shadow');
    $expected = str_replace('\\', '/', $templatesDir) . '/my-......etcshadow.xml';
    return str_replace('\\', '/', $path) === $expected;
});

test('isMaskTrue() accurately evaluates boolean and string truthiness', function() {
    assertTrue(isMaskTrue(true), 'bool true');
    assertTrue(isMaskTrue('true'), 'string true');
    assertTrue(isMaskTrue(1), 'int 1');
    assertTrue(isMaskTrue('1'), 'string 1');
    assertFalse(isMaskTrue(false), 'bool false');
    assertFalse(isMaskTrue('false'), 'string false');
    assertFalse(isMaskTrue(0), 'int 0');
    assertFalse(isMaskTrue('0'), 'string 0');
    assertFalse(isMaskTrue(''), 'empty string');
    assertFalse(isMaskTrue(null), 'null');
    return true;
});

test('targetToHumanName() formats targets into readable titles', function() {
    assertEqual(targetToHumanName('homepage.widget.url'), 'Homepage Widget Url', 'Widget URL title');
    assertEqual(targetToHumanName('homepage.group'), 'Homepage Group', 'Group title');
    assertEqual(targetToHumanName('homepage.siteMonitor'), 'Homepage SiteMonitor', 'SiteMonitor title');
    return true;
});

test('readTemplate() loads valid XML successfully', function() {
    $res = readTemplate('plex');
    assertTrue($res['success'], 'readTemplate success');
    assertTrue($res['doc'] instanceof DOMDocument, 'DOMDocument instance');
    $nodes = $res['doc']->getElementsByTagName('Name');
    assertEqual($nodes->item(0)->nodeValue, 'plex', 'Container Name');
    return true;
});

test('readTemplate() returns error for missing template', function() {
    $res = readTemplate('nonexistent-container');
    assertFalse($res['success'], 'Success should be false');
    assertTrue(!empty($res['error']), 'Error message should not be empty');
    return true;
});

test('readTemplate() handles malformed XML gracefully without crashing', function() {
    $res = readTemplate('malformed');
    assertFalse($res['success'], 'Success should be false');
    assertTrue(strpos($res['error'], 'Failed to parse XML') !== false, 'Error mentions parse failure');
    return true;
});

// ----------------------------------------------------
suite('Container Metadata & Label Extraction');
// ----------------------------------------------------
test('getContainerMeta() extracts Name, Repository, WebUI and Ports', function() {
    $res = readTemplate('plex');
    $meta = getContainerMeta($res['doc']);
    assertEqual($meta['Name'], 'plex', 'Name');
    assertEqual($meta['Repository'], 'linuxserver/plex:latest', 'Repository');
    assertEqual($meta['WebUI'], 'http://[IP]:[PORT:32400]/web', 'WebUI');
    assertTrue(isset($meta['Ports']['32400']), 'Port 32400 mapped');
    assertEqual($meta['Ports']['32400'], '32400', 'Port 32400 host value');
    return true;
});

test('getHomepageLabels() extracts empty array when no labels exist', function() {
    $res = readTemplate('plex');
    $labels = getHomepageLabels($res['doc']);
    assertEqual(count($labels), 0, 'No labels count');
    return true;
});

test('getHomepageLabels() correctly extracts discovery labels and mask attributes', function() {
    $res = readTemplate('sonarr');
    $labels = getHomepageLabels($res['doc']);
    assertTrue(isset($labels['homepage.group']), 'homepage.group exists');
    assertEqual($labels['homepage.group']['value'], 'Media Management', 'Group value');
    assertTrue(isset($labels['homepage.widget.key']), 'homepage.widget.key exists');
    assertEqual($labels['homepage.widget.key']['value'], 'secret-api-key-xyz', 'Widget key value');
    assertEqual($labels['homepage.widget.key']['mask'], 'true', 'Widget key is masked');
    assertEqual($labels['homepage.icon']['mask'], 'false', 'Icon is not masked');
    return true;
});

test('getContainerMeta() falls back to Networking/Publish/Port when Config Type=Port is missing', function() use ($sandboxDir) {
    $xml = <<<XML
<?xml version="1.0"?>
<Container version="2">
  <Name>fallback-port-app</Name>
  <Repository>test/app:latest</Repository>
  <Networking>
    <Mode>bridge</Mode>
    <Publish>
      <Port>
        <HostPort>9999</HostPort>
        <ContainerPort>8080/tcp</ContainerPort>
        <Protocol>tcp</Protocol>
      </Port>
    </Publish>
  </Networking>
</Container>
XML;
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $meta = getContainerMeta($doc);
    assertTrue(isset($meta['Ports']['8080']), 'Extracted port 8080 from Networking block');
    assertEqual($meta['Ports']['8080'], '9999', 'Mapped host port 9999');
    return true;
});

// ----------------------------------------------------
suite('Label Writing & XML Manipulation');
// ----------------------------------------------------
test('writeHomepageLabels() writes labels to template and preserves existing Config nodes', function() {
    $newLabels = [
        'homepage.group' => ['value' => 'Streaming', 'mask' => 'false'],
        'homepage.name'  => ['value' => 'Plex Server', 'mask' => 'false'],
        'homepage.icon'  => ['value' => 'plex.png', 'mask' => 'false'],
        'homepage.href'  => ['value' => 'http://192.168.1.10:32400/web', 'mask' => 'false'],
    ];

    $writeRes = writeHomepageLabels('plex', $newLabels, true, true);
    assertTrue($writeRes['success'], 'Write successful');
    assertTrue($writeRes['changed'], 'Changes committed');
    assertEqual($writeRes['added'], 4, 'Added 4 labels');

    // Verify written XML contains labels
    $readRes = readTemplate('plex');
    $readLabels = getHomepageLabels($readRes['doc']);
    assertEqual(count($readLabels), 4, 'Read back 4 labels');
    assertEqual($readLabels['homepage.name']['value'], 'Plex Server', 'homepage.name read back');

    // Verify non-Homepage configs (Port and Path) are preserved
    $meta = getContainerMeta($readRes['doc']);
    assertTrue(isset($meta['Ports']['32400']), 'Port 32400 preserved');

    $configs = $readRes['doc']->getElementsByTagName('Config');
    $hasPortConfig = false;
    $hasPathConfig = false;
    foreach ($configs as $config) {
        if ($config->getAttribute('Type') === 'Port') $hasPortConfig = true;
        if ($config->getAttribute('Type') === 'Path') $hasPathConfig = true;
    }
    assertTrue($hasPortConfig, 'Config Type=Port preserved');
    assertTrue($hasPathConfig, 'Config Type=Path preserved');
    return true;
});

test('writeHomepageLabels() idempotency check (writing same labels reports changed=false)', function() {
    $labels = [
        'homepage.group' => ['value' => 'Streaming', 'mask' => 'false'],
        'homepage.name'  => ['value' => 'Plex Server', 'mask' => 'false'],
        'homepage.icon'  => ['value' => 'plex.png', 'mask' => 'false'],
        'homepage.href'  => ['value' => 'http://192.168.1.10:32400/web', 'mask' => 'false'],
    ];

    $writeRes = writeHomepageLabels('plex', $labels, true, true);
    assertTrue($writeRes['success'], 'Write successful');
    assertFalse($writeRes['changed'], 'Should report changed=false');
    assertEqual($writeRes['unchanged'], 4, 'All 4 unchanged');
    return true;
});

test('writeHomepageLabels() in-place update mode (groupAtBottom=false)', function() use ($fixturesSrc, $templatesDir) {
    $modifiedLabels = [
        'homepage.group' => ['value' => 'Media & TV', 'mask' => 'false'],
        'homepage.name'  => ['value' => 'Sonarr PVR', 'mask' => 'false'],
    ];

    $writeRes = writeHomepageLabels('sonarr', $modifiedLabels, false, false);
    assertTrue($writeRes['success'], 'In-place write successful');
    assertTrue($writeRes['changed'], 'Changes detected and written');

    $readRes = readTemplate('sonarr');
    $readLabels = getHomepageLabels($readRes['doc']);
    assertEqual($readLabels['homepage.name']['value'], 'Sonarr PVR', 'Updated name in place');
    assertEqual($readLabels['homepage.group']['value'], 'Media & TV', 'Updated group in place');
    assertFalse(isset($readLabels['homepage.widget.key']), 'Omitted labels cleaned up');

    // Reset sonarr fixture so subsequent dashboard tests have pristine template
    copy($fixturesSrc . DIRECTORY_SEPARATOR . 'my-sonarr.xml', $templatesDir . DIRECTORY_SEPARATOR . 'my-sonarr.xml');
    return true;
});

test('writeHomepageLabels() cleans up labels when value is empty string', function() {
    $labels = [
        'homepage.group' => ['value' => 'Streaming', 'mask' => 'false'],
        'homepage.name'  => ['value' => 'Plex Server', 'mask' => 'false'],
        'homepage.icon'  => ['value' => '', 'mask' => 'false'],
    ];

    $writeRes = writeHomepageLabels('plex', $labels, false, true);
    assertTrue($writeRes['success'], 'Write successful');

    $readRes = readTemplate('plex');
    $readLabels = getHomepageLabels($readRes['doc']);
    assertFalse(isset($readLabels['homepage.icon']), 'Icon label removed when value is empty');
    assertTrue(isset($readLabels['homepage.name']), 'Name label retained');
    return true;
});

test('writeHomepageLabels() idempotency with empty-value entries in incoming array', function() {
    $readRes = readTemplate('plex');
    $existing = getHomepageLabels($readRes['doc']);
    $labelsWithEmpty = $existing;
    $labelsWithEmpty['homepage.nonexistent'] = ['value' => '', 'mask' => 'false'];

    $writeRes = writeHomepageLabels('plex', $labelsWithEmpty, false, true);
    assertTrue($writeRes['success'], 'Write call was successful');
    assertFalse($writeRes['changed'], 'Should report changed=false when extra labels only have empty values');
    return true;
});

test('parseTemplateConfigNodes() parses labels and ports in a single DOM pass', function() {
    $xml = <<<XML
<?xml version="1.0"?>
<Container version="2">
  <Config Type="Label" Target="homepage.group" Mask="false">Media</Config>
  <Config Type="Label" Target="homepage.name" Mask="false">Sonarr</Config>
  <Config Type="Port" Target="8989/tcp">8989</Config>
  <Config Type="Variable" Target="API_KEY">secret123</Config>
</Container>
XML;
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $parsed = parseTemplateConfigNodes($doc);
    assertEqual(count($parsed['labels']), 2, 'Parsed 2 homepage labels');
    assertEqual($parsed['labels']['homepage.group']['value'], 'Media', 'Group label value');
    assertEqual($parsed['labels']['homepage.name']['value'], 'Sonarr', 'Name label value');
    assertEqual(count($parsed['ports']), 1, 'Parsed 1 port');
    assertEqual($parsed['ports']['8989'], '8989', 'Port mapping');
    return true;
});

test('Sensitive fields sync Mask="true" and non-sensitive fields sync Mask="false"', function() use ($fixturesSrc, $templatesDir) {
    $labels = [
        'homepage.name'        => ['value' => 'Plex Security Test', 'mask' => 'false'],
        'homepage.widget.key'  => ['value' => 'super-secret-token-12345', 'mask' => 'true'],
        'homepage.widget.password' => ['value' => 'my-admin-password', 'mask' => 'true'],
        'homepage.widget.url'  => ['value' => 'http://192.168.1.100:32400', 'mask' => 'false'],
    ];

    $writeRes = writeHomepageLabels('plex', $labels, true, true);
    assertTrue($writeRes['success'], 'Wrote sensitive labels successfully');

    $readRes = readTemplate('plex');
    $extracted = getHomepageLabels($readRes['doc']);

    // Check sensitive fields have mask='true'
    assertEqual($extracted['homepage.widget.key']['mask'], 'true', 'API key has Mask="true"');
    assertEqual($extracted['homepage.widget.password']['mask'], 'true', 'Password has Mask="true"');

    // Check non-sensitive fields have mask='false'
    assertEqual($extracted['homepage.name']['mask'], 'false', 'Name has Mask="false"');
    assertEqual($extracted['homepage.widget.url']['mask'], 'false', 'URL has Mask="false"');

    // Verify raw XML attributes on <Config> nodes directly
    $xpath = new DOMXPath($readRes['doc']);
    $keyNode = $xpath->query('//Config[@Type="Label" and @Target="homepage.widget.key"]')->item(0);
    assertTrue($keyNode !== null, 'Found homepage.widget.key node');
    assertEqual($keyNode->getAttribute('Mask'), 'true', 'XML Mask attribute is "true" for widget key');

    $nameNode = $xpath->query('//Config[@Type="Label" and @Target="homepage.name"]')->item(0);
    assertTrue($nameNode !== null, 'Found homepage.name node');
    assertEqual($nameNode->getAttribute('Mask'), 'false', 'XML Mask attribute is "false" for name');

    // Reset fixture
    copy($fixturesSrc . DIRECTORY_SEPARATOR . 'my-plex.xml', $templatesDir . DIRECTORY_SEPARATOR . 'my-plex.xml');
    return true;
});

