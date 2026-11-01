<?php
/**
 * xml_parser.php — Unraid Docker Template XML Read/Write Helpers
 *
 * Handles reading, backing up, and modifying Homepage discovery labels
 * in Unraid's dockerMan user template XML files.
 *
 * Template location: /boot/config/plugins/dockerMan/templates-user/my-<container>.xml
 */

// Base path for Unraid's user Docker templates
if (!defined('TEMPLATES_PATH')) {
    define('TEMPLATES_PATH', '/boot/config/plugins/dockerMan/templates-user');
}
if (!defined('BACKUP_PATH')) {
    define('BACKUP_PATH', '/tmp/homepage-labels/backups');
}

/**
 * Check if a value represents boolean true in mask/required context.
 *
 * Handles string 'true', boolean true, string '1', and integer 1.
 * Used for mask, required, and any other boolean-ish XML attributes.
 *
 * @param mixed $value The value to check
 * @return bool
 */
function isMaskTrue($value) {
    return $value === 'true' || $value === true || $value === '1' || $value === 1;
}

/**
 * Resolve Config node attributes from label data and optional existing attributes.
 *
 * @param string $target    Label target key (e.g., "homepage.icon")
 * @param array  $labelData Incoming label data ['value', 'mask', 'name', ...]
 * @param array  $existingAttr Optional existing XML attributes for fallback
 * @return array Resolved attributes ['mask', 'name', 'default', 'mode', 'description', 'display', 'required']
 */
function resolveConfigAttrs($target, $labelData, $existingAttr = []) {
    return [
        'mask'        => isMaskTrue($labelData['mask'] ?? false) ? 'true' : 'false',
        'name'        => !empty($labelData['name']) ? $labelData['name'] : (!empty($existingAttr['name']) ? $existingAttr['name'] : targetToHumanName($target)),
        'default'     => isset($labelData['default']) ? $labelData['default'] : ($existingAttr['default'] ?? ''),
        'mode'        => isset($labelData['mode']) ? $labelData['mode'] : ($existingAttr['mode'] ?? ''),
        'description' => !empty($labelData['description']) ? $labelData['description'] : (!empty($existingAttr['description']) ? $existingAttr['description'] : 'Homepage Discovery Label'),
        'display'     => !empty($labelData['display']) ? $labelData['display'] : (!empty($existingAttr['display']) ? $existingAttr['display'] : 'always'),
        'required'    => isMaskTrue($labelData['required'] ?? ($existingAttr['required'] ?? false)) ? 'true' : 'false',
    ];
}

/**
 * Create a new Config XML node with all standard attributes.
 *
 * @param DOMDocument $doc    The XML document
 * @param string      $target Label target key
 * @param string      $value  Label value
 * @param array       $attrs  Resolved attributes from resolveConfigAttrs()
 * @return DOMElement The created Config node
 */
function buildConfigNode($doc, $target, $value, $attrs) {
    $node = $doc->createElement('Config');
    $node->appendChild($doc->createTextNode($value));
    $node->setAttribute('Name', $attrs['name']);
    $node->setAttribute('Target', $target);
    $node->setAttribute('Default', $attrs['default']);
    $node->setAttribute('Mode', $attrs['mode']);
    $node->setAttribute('Description', $attrs['description']);
    $node->setAttribute('Type', 'Label');
    $node->setAttribute('Display', $attrs['display']);
    $node->setAttribute('Required', $attrs['required']);
    $node->setAttribute('Mask', $attrs['mask']);
    return $node;
}

/**
 * Update an existing Config node's value and attributes in-place.
 *
 * @param DOMElement  $node  The existing Config node
 * @param DOMDocument $doc   The XML document
 * @param string      $value New label value
 * @param array       $attrs Resolved attributes from resolveConfigAttrs()
 */
function applyConfigAttrs($node, $doc, $value, $attrs) {
    $node->nodeValue = '';
    $node->appendChild($doc->createTextNode($value));
    $node->setAttribute('Name', $attrs['name']);
    $node->setAttribute('Default', $attrs['default']);
    $node->setAttribute('Mode', $attrs['mode']);
    $node->setAttribute('Description', $attrs['description']);
    $node->setAttribute('Display', $attrs['display']);
    $node->setAttribute('Required', $attrs['required']);
    $node->setAttribute('Mask', $attrs['mask']);
}

/**
 * Get the template file path for a given container name.
 *
 * @param string $containerName The container name (e.g., "sonarr")
 * @return string Full path to the XML template file
 */
function getTemplatePath($containerName) {
    if (is_array($containerName)) {
        $containerName = $containerName['name'] ?? '';
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', (string)$containerName);
    return TEMPLATES_PATH . "/my-{$safeName}.xml";
}

/**
 * Sanitize a container name into a safe identifier key for filesystem cache/backups.
 *
 * @param string $containerName
 * @return string
 */
function sanitizeContainerKey($containerName) {
    return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', (string)$containerName);
}

/**
 * List all user template XML files.
 *
 * @return array Array of ['name' => string, 'path' => string] for each template
 */
function listAllTemplates() {
    $templates = [];
    $pattern = TEMPLATES_PATH . '/my-*.xml';
    $files = glob($pattern);

    if ($files === false) {
        return $templates;
    }

    foreach ($files as $file) {
        $basename = basename($file, '.xml');
        // Strip the "my-" prefix to get container name
        $containerName = preg_replace('/^my-/', '', $basename);
        $templates[] = [
            'name' => $containerName,
            'path' => $file,
        ];
    }

    return $templates;
}

/**
 * Read and parse a container's XML template into a DOMDocument.
 *
 * @param string $containerName The container name
 * @return array ['success' => bool, 'doc' => DOMDocument|null, 'error' => string|null, 'path' => string]
 */
function readTemplate($containerName) {
    $path = getTemplatePath($containerName);

    if (!file_exists($path)) {
        return [
            'success' => false,
            'doc'     => null,
            'error'   => "Template file not found: {$path}",
            'path'    => $path,
        ];
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;

    // Suppress XML warnings for malformed templates
    $previousErrors = libxml_use_internal_errors(true);
    $loaded = $doc->load($path);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    if (!$loaded) {
        $errorMsg = !empty($errors) ? $errors[0]->message : 'Unknown XML parse error';
        return [
            'success' => false,
            'doc'     => null,
            'error'   => "Failed to parse XML: {$errorMsg}",
            'path'    => $path,
        ];
    }

    return [
        'success' => true,
        'doc'     => $doc,
        'error'   => null,
        'path'    => $path,
    ];
}

/**
 * Parse all <Config> nodes from an XML template in a single pass.
 *
 * @param DOMDocument $doc Parsed XML document
 * @return array ['labels' => array, 'ports' => array]
 */
function parseTemplateConfigNodes($doc) {
    $labels = [];
    $ports = [];
    $configs = $doc->getElementsByTagName('Config');

    foreach ($configs as $config) {
        $type = strtolower($config->getAttribute('Type'));
        $target = $config->getAttribute('Target');
        $val = trim($config->nodeValue);

        if ($type === 'label' && strpos($target, 'homepage.') === 0) {
            $labels[$target] = [
                'value'       => $config->nodeValue,
                'mask'        => $config->getAttribute('Mask') ?: 'false',
                'name'        => $config->getAttribute('Name') ?: targetToHumanName($target),
                'default'     => $config->getAttribute('Default') ?: '',
                'mode'        => $config->getAttribute('Mode') ?: '',
                'description' => $config->getAttribute('Description') ?: 'Homepage Discovery Label',
                'display'     => $config->getAttribute('Display') ?: 'always',
                'required'    => $config->getAttribute('Required') ?: 'false',
            ];
        } elseif ($type === 'port') {
            $containerPort = preg_replace('#/(tcp|udp)$#i', '', $target);
            if ($containerPort && $val) {
                $ports[$containerPort] = $val;
            }
        }
    }

    return ['labels' => $labels, 'ports' => $ports];
}

/**
 * Extract all existing Homepage discovery labels from a parsed template.
 *
 * @param DOMDocument $doc Parsed XML document
 * @return array Associative array of [target => ['value' => string, 'mask' => string, 'name' => string]]
 */
function getHomepageLabels($doc) {
    $parsed = parseTemplateConfigNodes($doc);
    return $parsed['labels'];
}

/**
 * Extract key container metadata from the template XML.
 *
 * @param DOMDocument $doc Parsed XML document
 * @return array Container metadata (Name, Repository, Icon, WebUI, ports, etc.)
 */
function getContainerMeta($doc) {
    $meta = [
        'Name'       => '',
        'Repository' => '',
        'Icon'       => '',
        'WebUI'      => '',
        'Ports'      => [],
    ];

    $root = $doc->documentElement;
    if (!$root) {
        return $meta;
    }

    // Extract direct child text nodes
    foreach (['Name', 'Repository', 'Icon', 'WebUI'] as $tag) {
        $nodes = $root->getElementsByTagName($tag);
        if ($nodes->length > 0) {
            $meta[$tag] = trim($nodes->item(0)->nodeValue);
        }
    }

    // Extract port mappings via unified Config node parser
    $parsed = parseTemplateConfigNodes($doc);
    $meta['Ports'] = $parsed['ports'];

    // Fallback: check <Networking><Publish><Port> if no ports were found from Config nodes
    if (empty($meta['Ports'])) {
        $xpath = new DOMXPath($doc);
        $portNodes = $xpath->query('//Networking/Publish/Port');
        if ($portNodes) {
            foreach ($portNodes as $pNode) {
                $hostPortNode = $xpath->query('HostPort', $pNode);
                $containerPortNode = $xpath->query('ContainerPort', $pNode);
                if ($hostPortNode->length > 0 && $containerPortNode->length > 0) {
                    $hp = trim($hostPortNode->item(0)->nodeValue);
                    $cp = preg_replace('#/(tcp|udp)$#i', '', trim($containerPortNode->item(0)->nodeValue));
                    if ($cp && $hp) {
                        $meta['Ports'][$cp] = $hp;
                    }
                }
            }
        }
    }

    return $meta;
}

/**
 * Create a timestamped backup of a container's template XML.
 *
 * @param string $containerName The container name
 * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
 */
function backupTemplate($containerName) {
    $sourcePath = getTemplatePath($containerName);

    if (!file_exists($sourcePath)) {
        return [
            'success' => false,
            'path'    => null,
            'error'   => "Source template not found: {$sourcePath}",
        ];
    }

    // Ensure backup directory exists
    if (!is_dir(BACKUP_PATH)) {
        if (!mkdir(BACKUP_PATH, 0755, true)) {
            return [
                'success' => false,
                'path'    => null,
                'error'   => "Failed to create backup directory: " . BACKUP_PATH,
            ];
        }
    }

    $timestamp = date('Y-m-d_His');
    $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $containerName);
    $backupFile = BACKUP_PATH . "/my-{$safeName}_{$timestamp}.xml.bak";

    if (!copy($sourcePath, $backupFile)) {
        return [
            'success' => false,
            'path'    => null,
            'error'   => "Failed to copy template to backup location",
        ];
    }

    return [
        'success' => true,
        'path'    => $backupFile,
        'error'   => null,
    ];
}

/**
 * Logical sorting order weight for Homepage labels.
 *
 * Lower numbers appear higher in the XML template.
 *
 * @param string $target Label target key
 * @return int Sort weight
 */
function getHomepageLabelSortWeight($target) {
    static $weights = null;
    if ($weights === null) {
        $weights = [
            'homepage.group'           => 10,
            'homepage.name'            => 11,
            'homepage.icon'            => 12,
            'homepage.href'            => 13,
            'homepage.description'     => 14,
            'homepage.weight'          => 20,
            'homepage.siteMonitor'     => 30,
            'homepage.ping'            => 31,
            'homepage.widget.type'     => 40,
            'homepage.widget.url'      => 41,
            'homepage.widget.key'      => 42,
            'homepage.widget.token'    => 43,
            'homepage.widget.username' => 44,
            'homepage.widget.password' => 45,
            'homepage.widget.version'  => 46,
        ];
    }
    if (isset($weights[$target])) {
        return $weights[$target];
    }
    if (strpos($target, 'homepage.widget.') === 0) {
        return 50;
    }
    return 100;
}

/**
 * Sort an associative array of Homepage labels in logical order.
 *
 * @param array $labels Array of [target => data]
 * @return array Sorted array
 */
function sortHomepageLabels($labels) {
    uksort($labels, function($a, $b) {
        $wA = getHomepageLabelSortWeight($a);
        $wB = getHomepageLabelSortWeight($b);
        if ($wA !== $wB) {
            return $wA <=> $wB;
        }
        return strcasecmp($a, $b);
    });
    return $labels;
}

/**
 * Compare existing template labels with incoming form labels to produce granular diff metrics.
 *
 * @param array $existing Array of [target => ['value' => ..., 'mask' => ..., 'name' => ...]] from XML
 * @param array $incoming Array of [target => ['value' => ..., 'mask' => ..., 'name' => ...]] from Form
 * @return array Diff metrics ['changed' => bool, 'added' => int, 'modified' => int, 'removed' => int, 'unchanged' => int, 'diffs' => array]
 */
function compareHomepageLabels($existing, $incoming) {
    $cleanIncoming = [];
    foreach ($incoming as $k => $v) {
        $val = isset($v['value']) ? trim((string)$v['value']) : '';
        if ($val !== '') {
            $cleanIncoming[$k] = [
                'value'       => $val,
                'mask'        => isMaskTrue($v['mask'] ?? false) ? 'true' : 'false',
                'name'        => isset($v['name']) ? trim((string)$v['name']) : null,
                'default'     => isset($v['default']) ? (string)$v['default'] : null,
                'description' => isset($v['description']) ? trim((string)$v['description']) : null,
                'display'     => isset($v['display']) ? trim((string)$v['display']) : null,
                'required'    => isset($v['required']) ? (isMaskTrue($v['required']) ? 'true' : 'false') : null,
            ];
        }
    }

    $added = 0;
    $modified = 0;
    $removed = 0;
    $unchanged = 0;
    $diffs = [];

    // Check existing labels against incoming
    foreach ($existing as $target => $oldData) {
        $oldVal = trim((string)($oldData['value'] ?? ''));
        $oldMask = isMaskTrue($oldData['mask'] ?? false) ? 'true' : 'false';

        if (!isset($cleanIncoming[$target])) {
            $removed++;
            $diffs[$target] = 'removed';
        } else {
            $newVal = $cleanIncoming[$target]['value'];
            $newMask = $cleanIncoming[$target]['mask'];
            $newName = $cleanIncoming[$target]['name'];
            $newDefault = $cleanIncoming[$target]['default'];
            $newDesc = $cleanIncoming[$target]['description'];
            $newDisplay = $cleanIncoming[$target]['display'];
            $newReq = $cleanIncoming[$target]['required'];

            $isModified = ($oldVal !== $newVal || $oldMask !== $newMask);
            if (!$isModified && $newName !== null && $newName !== ($oldData['name'] ?? '')) {
                $isModified = true;
            }
            if (!$isModified && $newDefault !== null && $newDefault !== ($oldData['default'] ?? '')) {
                $isModified = true;
            }
            if (!$isModified && $newDesc !== null && $newDesc !== ($oldData['description'] ?? 'Homepage Discovery Label')) {
                $isModified = true;
            }
            if (!$isModified && $newDisplay !== null && $newDisplay !== ($oldData['display'] ?? 'always')) {
                $isModified = true;
            }
            if (!$isModified && $newReq !== null && $newReq !== ($oldData['required'] ?? 'false')) {
                $isModified = true;
            }

            if ($isModified) {
                $modified++;
                $diffs[$target] = 'modified';
            } else {
                $unchanged++;
                $diffs[$target] = 'unchanged';
            }
        }
    }

    // Check for newly added labels
    foreach ($cleanIncoming as $target => $newData) {
        if (!isset($existing[$target])) {
            $added++;
            $diffs[$target] = 'added';
        }
    }

    $hasChanges = ($added > 0 || $modified > 0 || $removed > 0);

    return [
        'changed'   => $hasChanges,
        'added'     => $added,
        'modified'  => $modified,
        'removed'   => $removed,
        'unchanged' => $unchanged,
        'diffs'     => $diffs,
    ];
}

/**
 * Remove all existing Homepage labels from the document and clean whitespace.
 *
 * @param DOMDocument $doc The XML document to modify
 * @return int Number of labels removed
 */
function removeHomepageLabels($doc) {
    $toRemove = [];
    $configs = $doc->getElementsByTagName('Config');

    for ($i = 0; $i < $configs->length; $i++) {
        $config = $configs->item($i);
        $type = $config->getAttribute('Type');
        $target = $config->getAttribute('Target');

        if (strtolower($type) === 'label' && strpos($target, 'homepage.') === 0) {
            $toRemove[] = $config;
        }
    }

    foreach ($toRemove as $node) {
        // Clean adjacent whitespace text nodes
        $prev = $node->previousSibling;
        if ($prev && $prev->nodeType === XML_TEXT_NODE && trim($prev->nodeValue) === '') {
            $prev->parentNode->removeChild($prev);
        }
        $node->parentNode->removeChild($node);
    }

    return count($toRemove);
}

/**
 * Generate a human-readable label name from a Homepage target key.
 *
 * @param string $target The Homepage label target (e.g., "homepage.widget.url")
 * @return string Human-readable name (e.g., "Homepage Widget URL")
 */
function targetToHumanName($target) {
    // Remove "homepage." prefix
    $rest = preg_replace('/^homepage\./', '', $target);
    // Convert dots to spaces and capitalize each word
    $words = explode('.', $rest);
    $words = array_map('ucfirst', $words);
    return 'Homepage ' . implode(' ', $words);
}

/**
 * Write Homepage discovery labels to a container's template XML using
 * bottom-grouped differential updates and atomic file writing.
 *
 * 1. Analyzes diffs against existing labels
 * 2. If changes exist (or relocations needed), creates a timestamped backup
 * 3. Preserves all non-Homepage configs in-place
 * 4. Consolidates and logically sorts all homepage.* labels at the bottom of <Container>
 * 5. Performs atomic write via /tmp staging with XML syntax validation
 *
 * @param string $containerName The container name
 * @param array  $labels Associative array of [target => ['value' => string, 'mask' => string]]
 * @param bool   $doBackup Whether to create a backup before writing (default: true)
 * @param bool   $groupAtBottom Whether to consolidate labels at template bottom (default: true)
 * @return array ['success' => bool, 'changed' => bool, 'added' => int, 'modified' => int, 'removed' => int, 'unchanged' => int, 'error' => string|null, 'backup' => string|null]
 */
function writeHomepageLabels($containerName, $labels, $doBackup = true, $groupAtBottom = true) {
    // Step 1: Read current template
    $result = readTemplate($containerName);
    if (!$result['success']) {
        return [
            'success'   => false,
            'changed'   => false,
            'added'     => 0,
            'modified'  => 0,
            'removed'   => 0,
            'unchanged' => 0,
            'error'     => $result['error'],
            'backup'    => null,
        ];
    }

    $doc = $result['doc'];
    $path = $result['path'];

    // Step 2: Extract existing labels and calculate diffs
    $existingLabels = getHomepageLabels($doc);
    $diff = compareHomepageLabels($existingLabels, $labels);

    // If no changes exist and labels are already present, verify whether a write is even needed
    $cleanCount = count(array_filter($labels, function($l) {
        return isset($l['value']) && trim((string)$l['value']) !== '';
    }));
    if (!$diff['changed'] && count($existingLabels) === $cleanCount) {
        return [
            'success'   => true,
            'changed'   => false,
            'added'     => 0,
            'modified'  => 0,
            'removed'   => 0,
            'unchanged' => $diff['unchanged'],
            'error'     => null,
            'backup'    => null,
        ];
    }

    // Step 3: Backup (only if changes exist)
    $backupPath = null;
    if ($doBackup && $diff['changed']) {
        $backupResult = backupTemplate($containerName);
        if (!$backupResult['success']) {
            return [
                'success'   => false,
                'changed'   => false,
                'added'     => 0,
                'modified'  => 0,
                'removed'   => 0,
                'unchanged' => 0,
                'error'     => 'Backup failed: ' . $backupResult['error'],
                'backup'    => null,
            ];
        }
        $backupPath = $backupResult['path'];
    }

    $root = $doc->documentElement;

    if ($groupAtBottom) {
        // Step 4: Remove existing homepage.* labels from anywhere in the document
        removeHomepageLabels($doc);

        // Step 5: Sort incoming labels in clean logical order (Core -> Display -> Health -> Widget -> Custom)
        $sortedLabels = sortHomepageLabels($labels);

        // Step 6: Append sorted label nodes at the bottom of <Container>
        foreach ($sortedLabels as $target => $labelData) {
            $value = isset($labelData['value']) ? trim((string)$labelData['value']) : '';
            if ($value === '') {
                continue;
            }

            $existingAttr = $existingLabels[$target] ?? [];
            $attrs = resolveConfigAttrs($target, $labelData, $existingAttr);
            $root->appendChild(buildConfigNode($doc, $target, $value, $attrs));
        }
    } else {
        // Step 4b: In-place update preserving existing label positions
        $configs = $doc->getElementsByTagName('Config');
        $existingNodes = [];
        for ($i = 0; $i < $configs->length; $i++) {
            $config = $configs->item($i);
            $type = $config->getAttribute('Type');
            $target = $config->getAttribute('Target');
            if (strtolower($type) === 'label' && strpos($target, 'homepage.') === 0) {
                $existingNodes[$target] = $config;
            }
        }

        $handledTargets = [];
        foreach ($existingNodes as $target => $node) {
            if (isset($labels[$target])) {
                $labelData = $labels[$target];
                $value = isset($labelData['value']) ? trim((string)$labelData['value']) : '';
                if ($value === '') {
                    $prev = $node->previousSibling;
                    if ($prev && $prev->nodeType === XML_TEXT_NODE && trim($prev->nodeValue) === '') {
                        $prev->parentNode->removeChild($prev);
                    }
                    $node->parentNode->removeChild($node);
                } else {
                    $existingAttr = $existingLabels[$target] ?? [];
                    $attrs = resolveConfigAttrs($target, $labelData, $existingAttr);
                    applyConfigAttrs($node, $doc, $value, $attrs);
                }
                $handledTargets[$target] = true;
            } else {
                $prev = $node->previousSibling;
                if ($prev && $prev->nodeType === XML_TEXT_NODE && trim($prev->nodeValue) === '') {
                    $prev->parentNode->removeChild($prev);
                }
                $node->parentNode->removeChild($node);
            }
        }

        // Any new labels not yet in XML: append to root
        foreach ($labels as $target => $labelData) {
            if (isset($handledTargets[$target])) {
                continue;
            }
            $value = isset($labelData['value']) ? trim((string)$labelData['value']) : '';
            if ($value === '') {
                continue;
            }

            $attrs = resolveConfigAttrs($target, $labelData);
            $root->appendChild(buildConfigNode($doc, $target, $value, $attrs));
        }
    }

    // Step 7: Format XML output with clean indentation
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    $xmlContent = $doc->saveXML();

    if ($xmlContent === false) {
        return [
            'success'   => false,
            'changed'   => false,
            'added'     => 0,
            'modified'  => 0,
            'removed'   => 0,
            'unchanged' => 0,
            'error'     => "Failed to format XML document.",
            'backup'    => $backupPath,
        ];
    }

    // Step 8: Atomic write via temp staging with XML syntax validation
    $stagingDir = is_dir('/tmp') ? '/tmp' : sys_get_temp_dir();
    $tmpFile = tempnam($stagingDir, 'hp_xml_');
    if ($tmpFile === false) {
        $tmpFile = $stagingDir . DIRECTORY_SEPARATOR . 'hp_xml_' . uniqid() . '.xml';
    }

    $written = file_put_contents($tmpFile, $xmlContent, LOCK_EX);
    if ($written === false) {
        return [
            'success'   => false,
            'changed'   => false,
            'added'     => 0,
            'modified'  => 0,
            'removed'   => 0,
            'unchanged' => 0,
            'error'     => "Failed to write staging XML file.",
            'backup'    => $backupPath,
        ];
    }

    // Validate staging XML syntax before touching flash storage
    $testDoc = new DOMDocument();
    $testDoc->preserveWhiteSpace = false;
    $loaded = @$testDoc->load($tmpFile);
    if (!$loaded) {
        @unlink($tmpFile);
        return [
            'success'   => false,
            'changed'   => false,
            'added'     => 0,
            'modified'  => 0,
            'removed'   => 0,
            'unchanged' => 0,
            'error'     => "Generated XML failed syntax validation; write aborted.",
            'backup'    => $backupPath,
        ];
    }

    // Copy validated XML to destination path
    $copied = @copy($tmpFile, $path);
    @unlink($tmpFile);

    if (!$copied) {
        return [
            'success'   => false,
            'changed'   => false,
            'added'     => 0,
            'modified'  => 0,
            'removed'   => 0,
            'unchanged' => 0,
            'error'     => "Failed to write XML to: {$path}",
            'backup'    => $backupPath,
        ];
    }

    return [
        'success'   => true,
        'changed'   => $diff['changed'],
        'added'     => $diff['added'],
        'modified'  => $diff['modified'],
        'removed'   => $diff['removed'],
        'unchanged' => $diff['unchanged'],
        'error'     => null,
        'backup'    => $backupPath,
    ];
}


/**
 * Get a summary of a container's Homepage label status for dashboard display.
 *
 * @param string $containerName The container name
 * @return array Dashboard summary info
 */
function getContainerSummary($containerName) {
    $result = readTemplate($containerName);
    if (!$result['success']) {
        return [
            'name'             => $containerName,
            'discoveryActive'  => false,
            'group'            => '',
            'icon'             => '',
            'widgetConfigured' => false,
            'error'            => $result['error'],
        ];
    }

    $doc = $result['doc'];
    $labels = getHomepageLabels($doc);
    $meta = getContainerMeta($doc);

    $group = isset($labels['homepage.group']) ? $labels['homepage.group']['value'] : '';
    $icon = isset($labels['homepage.icon']) ? $labels['homepage.icon']['value'] : '';
    $href = isset($labels['homepage.href']) ? $labels['homepage.href']['value'] : '';
    $widgetType = isset($labels['homepage.widget.type']) ? $labels['homepage.widget.type']['value'] : '';
    $hasHealth = (isset($labels['homepage.siteMonitor']) && !empty($labels['homepage.siteMonitor']['value'])) ||
                 (isset($labels['homepage.ping']) && !empty($labels['homepage.ping']['value']));
    $hasBackup = function_exists('hasLabelBackup') ? hasLabelBackup($containerName) : false;
    $isPending = function_exists('isPendingRecreate') ? isPendingRecreate($containerName) : false;

    return [
        'name'             => $containerName,
        'displayName'      => $meta['Name'] ?: $containerName,
        'repository'       => $meta['Repository'],
        'templateIcon'     => $meta['Icon'],
        'discoveryActive'  => !empty($labels),
        'group'            => $group,
        'icon'             => $icon,
        'href'             => $href,
        'widgetType'       => $widgetType,
        'widgetConfigured' => !empty($widgetType),
        'hasHealth'        => $hasHealth,
        'hasBackup'        => $hasBackup,
        'pendingRecreate'  => $isPending,
        'labelCount'       => count($labels),
        'error'            => null,
    ];
}

/**
 * Get summaries for all containers in batch with optimized backup checking.
 *
 * Pre-scans the backup directory once with glob() instead of N individual
 * file_exists + json_decode calls. Each XML template is still parsed
 * individually (unavoidable), but filesystem overhead is minimized.
 *
 * @param array $templates Array of ['name' => string, 'path' => string] from listAllTemplates()
 * @param int   $retentionHours Backup retention window (default: 24)
 * @return array Array of container summary arrays
 */
function getContainerSummaries($templates, $retentionHours = 24) {
    // Pre-scan backup directory once for all containers
    $backupSet = [];
    if (defined('LABEL_BACKUPS_DIR') && is_dir(LABEL_BACKUPS_DIR)) {
        $backupFiles = glob(LABEL_BACKUPS_DIR . '/*.json') ?: [];
        $now = time();
        $maxAge = $retentionHours * 3600;
        foreach ($backupFiles as $f) {
            $basename = pathinfo($f, PATHINFO_FILENAME);
            // Quick expiry check using filemtime stat to eliminate redundant JSON parsing
            if ($retentionHours > 0) {
                $mtime = @filemtime($f);
                if ($mtime !== false && ($now - $mtime) <= $maxAge) {
                    $backupSet[$basename] = true;
                }
            } else {
                $backupSet[$basename] = true;
            }
        }
    }

    $pendingSet = function_exists('loadPendingRecreates') ? loadPendingRecreates() : [];

    $summaries = [];
    foreach ($templates as $template) {
        $containerName = $template['name'];
        $result = readTemplate($containerName);

        if (!$result['success']) {
            $summaries[] = [
                'name'             => $containerName,
                'discoveryActive'  => false,
                'group'            => '',
                'icon'             => '',
                'widgetConfigured' => false,
                'error'            => $result['error'],
            ];
            continue;
        }

        $doc = $result['doc'];
        $labels = getHomepageLabels($doc);
        $meta = getContainerMeta($doc);

        $group = isset($labels['homepage.group']) ? $labels['homepage.group']['value'] : '';
        $icon = isset($labels['homepage.icon']) ? $labels['homepage.icon']['value'] : '';
        $href = isset($labels['homepage.href']) ? $labels['homepage.href']['value'] : '';
        $widgetType = isset($labels['homepage.widget.type']) ? $labels['homepage.widget.type']['value'] : '';
        $hasHealth = (isset($labels['homepage.siteMonitor']) && !empty($labels['homepage.siteMonitor']['value'])) ||
                     (isset($labels['homepage.ping']) && !empty($labels['homepage.ping']['value']));

        // Lookup backup from pre-scanned set
        $safeKey = sanitizeContainerKey($containerName);
        $hasBackup = isset($backupSet[$safeKey]);

        $summaries[] = [
            'name'             => $containerName,
            'displayName'      => $meta['Name'] ?: $containerName,
            'repository'       => $meta['Repository'],
            'templateIcon'     => $meta['Icon'],
            'discoveryActive'  => !empty($labels),
            'group'            => $group,
            'icon'             => $icon,
            'href'             => $href,
            'widgetType'       => $widgetType,
            'widgetConfigured' => !empty($widgetType),
            'hasHealth'        => $hasHealth,
            'hasBackup'        => $hasBackup,
            'pendingRecreate'  => (!empty($pendingSet[$containerName]) || !empty($pendingSet[$safeKey])),
            'labelCount'       => count($labels),
            'error'            => null,
        ];
    }

    return $summaries;
}
