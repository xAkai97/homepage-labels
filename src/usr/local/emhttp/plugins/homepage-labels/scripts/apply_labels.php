<?php
/**
 * apply_labels.php — AJAX Endpoint for Label Write Operations
 *
 * Handles:
 * - save: Write labels to container XML template (optionally recreate)
 * - disable: Remove all homepage.* labels from a container
 * - preset_fields: Return HTML for a preset's widget fields (AJAX)
 */

header('Content-Type: application/json');

require_once '/usr/local/emhttp/plugins/homepage-labels/include/xml_parser.php';
require_once '/usr/local/emhttp/plugins/homepage-labels/include/preset_engine.php';
require_once '/usr/local/emhttp/plugins/homepage-labels/include/view_components.php';

/**
 * Collect and normalize Homepage labels from form POST data.
 *
 * Shared by 'save' and 'get_save_diff' actions to prevent divergence.
 *
 * @param array $formLabels  Raw labels array from $_POST['labels']
 * @param array $formMasks   Raw masks array from $_POST['masks']
 * @param array $formConfigs Raw configs array from $_POST['configs']
 * @param bool  $enableWidget Whether widget fields should be included
 * @return array Normalized labels [target => ['value', 'mask', ...]]
 */
function collectFormLabels($formLabels, $formMasks, $formConfigs, $enableWidget) {
    $labels = [];

    foreach ($formLabels as $target => $value) {
        $target = trim($target);
        $value = trim($value);

        if (strpos($target, 'homepage.') !== 0) {
            continue;
        }

        if (!$enableWidget && strpos($target, 'homepage.widget.') === 0) {
            continue;
        }

        if ($value === '' || $value === null) {
            continue;
        }

        $mask = 'false';
        if ((isset($formMasks[$target]) && isMaskTrue($formMasks[$target])) ||
            (isset($formConfigs[$target]['mask']) && isMaskTrue($formConfigs[$target]['mask']))) {
            $mask = 'true';
        }

        $labelItem = [
            'value' => $value,
            'mask'  => $mask,
        ];

        if (isset($formConfigs[$target]) && is_array($formConfigs[$target])) {
            $cfg = $formConfigs[$target];
            if (isset($cfg['name']) && trim($cfg['name']) !== '') {
                $labelItem['name'] = trim($cfg['name']);
            }
            if (isset($cfg['default'])) {
                $labelItem['default'] = (string)$cfg['default'];
            }
            if (isset($cfg['description']) && trim($cfg['description']) !== '') {
                $labelItem['description'] = trim($cfg['description']);
            }
            if (isset($cfg['display']) && in_array(trim($cfg['display']), ['always', 'advanced', 'hidden'], true)) {
                $labelItem['display'] = trim($cfg['display']);
            }
            if (isset($cfg['required'])) {
                $labelItem['required'] = ($cfg['required'] === 'true' || $cfg['required'] === true || $cfg['required'] === '1') ? 'true' : 'false';
            }
        }

        $labels[$target] = $labelItem;
    }

    return $labels;
}

// Load settings
$settings = loadPluginSettings();

// Load Unraid emhttp runtime variables if running standalone
if (!isset($var) || !is_array($var)) {
    if (isset($GLOBALS['var']) && is_array($GLOBALS['var'])) {
        $var = &$GLOBALS['var'];
    } elseif (file_exists('/var/local/emhttp/var.ini')) {
        $var = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW) ?: [];
    } else {
        $var = [];
    }
}
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (empty($var['csrf_token'])) {
    $var['csrf_token'] = $GLOBALS['var']['csrf_token']
        ?? $_SESSION['csrf_token']
        ?? (file_exists('/var/local/emhttp/var.ini') ? (@parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW)['csrf_token'] ?? null) : null)
        ?? $_COOKIE['csrf_token']
        ?? '';
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// Validate CSRF token for state-modifying actions if running in authenticated Unraid context
$modifyingActions = ['save', 'disable', 'restore', 'template_clone', 'template_save', 'template_rename', 'template_delete', 'template_apply', 'save_scale', 'batch_enable', 'batch_disable', 'batch_apply_template', 'recreate_single', 'export_templates', 'import_templates', 'test_connection', 'batch_set_group', 'export_services_yaml', 'sync_services_yaml'];
if (in_array($action, $modifyingActions, true)) {
    $sessionToken = $var['csrf_token']
        ?? $GLOBALS['var']['csrf_token']
        ?? $_SESSION['csrf_token']
        ?? (file_exists('/var/local/emhttp/var.ini') ? (@parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW)['csrf_token'] ?? null) : null)
        ?? $_COOKIE['csrf_token']
        ?? null;
    $providedToken = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($sessionToken) || empty($providedToken) || !hash_equals((string)$sessionToken, (string)$providedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
}

switch ($action) {

    // -------------------------------------------------------
    // GET_LOCAL_ICONS: Get list of icons from custom folder
    // -------------------------------------------------------
    case 'get_local_icons':
        $hostDir = !empty($settings['CUSTOM_ICONS_HOST_DIR']) ? $settings['CUSTOM_ICONS_HOST_DIR'] : '';
        $urlPrefix = !empty($settings['CUSTOM_ICONS_URL_PREFIX']) ? $settings['CUSTOM_ICONS_URL_PREFIX'] : '/icons/';
        $icons = getLocalCustomIcons($hostDir, $urlPrefix);
        echo json_encode([
            'success'    => true,
            'configured' => !empty($hostDir) && is_dir($hostDir),
            'hostDir'    => $hostDir,
            'urlPrefix'  => $urlPrefix,
            'icons'      => $icons,
        ]);
        break;


    // -------------------------------------------------------
    // GET_ICON_THUMB: Safely stream icon thumbnail image
    // -------------------------------------------------------
    case 'get_icon_thumb':
        $hostDir = !empty($settings['CUSTOM_ICONS_HOST_DIR']) ? $settings['CUSTOM_ICONS_HOST_DIR'] : '';
        $iconName = isset($_GET['name']) ? trim($_GET['name']) : '';

        if (empty($hostDir) || !is_dir($hostDir) || empty($iconName)) {
            http_response_code(404);
            exit;
        }

        // Sanitize icon name and extract basename
        $iconName = basename($iconName);
        $filePath = rtrim($hostDir, '/') . '/' . $iconName;

        $ext = strtolower(pathinfo($iconName, PATHINFO_EXTENSION));
        $contentTypes = [
            'png'  => 'image/png',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'ico'  => 'image/x-icon',
        ];
        if (!isset($contentTypes[$ext])) {
            http_response_code(404);
            exit;
        }

        // Prevent path traversal and enforce directory boundary
        $realHostDir = realpath($hostDir);
        $realFilePath = realpath($filePath);
        if (!$realHostDir || !$realFilePath || !file_exists($realFilePath)) {
            http_response_code(404);
            exit;
        }

        $safePrefix = rtrim($realHostDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($realFilePath, $safePrefix) !== 0) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $contentTypes[$ext]);
        header('Cache-Control: public, max-age=86400');
        if ($ext === 'svg') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
            header('X-Content-Type-Options: nosniff');
        }
        readfile($realFilePath);
        exit;

    // -------------------------------------------------------
    // SAVE_SCALE: Update UI Font Readability Scale
    // -------------------------------------------------------
    case 'save_scale':
        $scale = isset($_POST['scale']) ? trim($_POST['scale']) : '100';
        if (!in_array($scale, ['100', '110', '125', '140'], true)) {
            $scale = '100';
        }
        $settingsPath = defined('PLUGIN_SETTINGS_PATH') ? PLUGIN_SETTINGS_PATH : '/boot/config/plugins/homepage-labels/settings.cfg';
        $curSettings = loadPluginSettings($settingsPath);
        $curSettings['UI_FONT_SCALE'] = $scale;
        $res = savePluginSettings($curSettings, $settingsPath);
        echo json_encode(['success' => $res, 'scale' => $scale]);
        break;

    // -------------------------------------------------------
    // RESTORE: Restore labels from previous snapshot backup
    // -------------------------------------------------------
    case 'restore':
        $containerName = isset($_POST['container']) ? trim($_POST['container']) : '';

        if (empty($containerName)) {
            echo json_encode(['success' => false, 'error' => 'Container name is required.']);
            exit;
        }

        $containerName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $containerName);
        $retention = isset($settings['BACKUP_RETENTION_HOURS']) ? intval($settings['BACKUP_RETENTION_HOURS']) : 24;
        $backup = getLabelBackup($containerName, $retention);

        if (!$backup || empty($backup['labels'])) {
            echo json_encode(['success' => false, 'error' => 'No recent label backup found for "' . $containerName . '".']);
            exit;
        }

        $doBackup = isset($settings['BACKUP_BEFORE_WRITE']) ? ($settings['BACKUP_BEFORE_WRITE'] === 'true') : true;
        $result = writeHomepageLabels($containerName, $backup['labels'], $doBackup);
        if ($result['success']) {
            if ($result['changed'] && function_exists('addPendingRecreate')) {
                addPendingRecreate($containerName);
            }
            $result['restored_count'] = count($backup['labels']);
            $result['backup_date'] = $backup['date'] ?? '';
            $result['message'] = 'Successfully restored ' . count($backup['labels']) . ' Homepage labels for "' . $containerName . '".';
        }

        echo json_encode($result);
        break;

    // -------------------------------------------------------
    // GET_SAVE_DIFF: Compute pre-save diff against template XML
    // -------------------------------------------------------
    case 'get_save_diff':
        $containerName = isset($_POST['container']) ? trim($_POST['container']) : '';

        if (empty($containerName)) {
            echo json_encode(['success' => false, 'error' => 'Container name is required.']);
            exit;
        }

        $containerName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $containerName);

        // Collect incoming labels using shared helper (identical to 'save')
        $formLabels = isset($_POST['labels']) ? $_POST['labels'] : [];
        $formMasks = isset($_POST['masks']) ? $_POST['masks'] : [];
        $formConfigs = isset($_POST['configs']) && is_array($_POST['configs']) ? $_POST['configs'] : [];
        $enableWidget = isset($_POST['enable_widget']) && $_POST['enable_widget'] === '1';

        $incomingLabels = collectFormLabels($formLabels, $formMasks, $formConfigs, $enableWidget);

        // Read current template
        $readRes = readTemplate($containerName);
        if (!$readRes['success']) {
            echo json_encode([
                'success'   => false,
                'container' => $containerName,
                'error'     => 'Could not read template XML: ' . ($readRes['error'] ?? 'File not found'),
            ]);
            exit;
        }

        $existingLabels = getHomepageLabels($readRes['doc']);
        $diff = compareHomepageLabels($existingLabels, $incomingLabels);

        $detailedDiffs = [];
        $secretKeywords = '/(key|token|secret|password|passwd|pass|auth|api)/i';

        foreach ($diff['diffs'] as $target => $status) {
            if ($status === 'unchanged') {
                continue;
            }

            $isMasked = false;
            $oldVal = '';
            $newVal = '';

            if (isset($existingLabels[$target])) {
                $oldVal = (string)($existingLabels[$target]['value'] ?? '');
                if (isMaskTrue($existingLabels[$target]['mask'] ?? false)) {
                    $isMasked = true;
                }
            }

            if (isset($incomingLabels[$target])) {
                $newVal = (string)($incomingLabels[$target]['value'] ?? '');
                if (isMaskTrue($incomingLabels[$target]['mask'] ?? false)) {
                    $isMasked = true;
                }
            }

            if (preg_match($secretKeywords, $target)) {
                $isMasked = true;
            }

            $name = !empty($incomingLabels[$target]['name']) ? $incomingLabels[$target]['name'] : (!empty($existingLabels[$target]['name']) ? $existingLabels[$target]['name'] : targetToHumanName($target));

            $detailedDiffs[] = [
                'target'   => $target,
                'name'     => $name,
                'status'   => $status,
                'old_val'  => $isMasked ? ($oldVal !== '' ? '••••••••' : '') : $oldVal,
                'new_val'  => $isMasked ? ($newVal !== '' ? '••••••••' : '') : $newVal,
                'masked'   => $isMasked,
            ];
        }

        // Sort diffs: added first, then modified, then removed, sorted by target
        usort($detailedDiffs, function($a, $b) {
            $order = ['added' => 1, 'modified' => 2, 'removed' => 3];
            $ordA = $order[$a['status']] ?? 9;
            $ordB = $order[$b['status']] ?? 9;
            if ($ordA !== $ordB) return $ordA - $ordB;
            return strcasecmp($a['target'], $b['target']);
        });

        echo json_encode([
            'success'   => true,
            'container' => $containerName,
            'changed'   => $diff['changed'],
            'added'     => $diff['added'],
            'modified'  => $diff['modified'],
            'removed'   => $diff['removed'],
            'unchanged' => $diff['unchanged'],
            'diffs'     => $detailedDiffs,
        ]);
        break;

    // -------------------------------------------------------
    // SAVE: Write labels to XML template
    // -------------------------------------------------------
    case 'save':
        $containerName = isset($_POST['container']) ? trim($_POST['container']) : '';
        $recreate = isset($_POST['recreate']) && $_POST['recreate'] === '1';

        if (empty($containerName)) {
            echo json_encode(['success' => false, 'error' => 'Container name is required.']);
            exit;
        }

        // Sanitize container name
        $containerName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $containerName);

        // Collect labels and configs from form using shared helper
        $formLabels = isset($_POST['labels']) ? $_POST['labels'] : [];
        $formMasks = isset($_POST['masks']) ? $_POST['masks'] : [];
        $formConfigs = isset($_POST['configs']) && is_array($_POST['configs']) ? $_POST['configs'] : [];
        $enableWidget = isset($_POST['enable_widget']) && $_POST['enable_widget'] === '1';

        $labels = collectFormLabels($formLabels, $formMasks, $formConfigs, $enableWidget);

        // Check if backup should be created
        $doBackup = isset($settings['BACKUP_BEFORE_WRITE']) ? ($settings['BACKUP_BEFORE_WRITE'] === 'true') : true;
        $groupAtBottom = isset($settings['GROUP_LABELS_AT_BOTTOM']) ? ($settings['GROUP_LABELS_AT_BOTTOM'] === 'true') : true;

        // Capture existing labels before write for undo / restore backup
        $priorLabels = [];
        $readRes = readTemplate($containerName);
        if ($readRes['success']) {
            $priorLabels = getHomepageLabels($readRes['doc']);
        }

        // Write labels with differential updating (bottom-grouped or in-place per setting)
        $result = writeHomepageLabels($containerName, $labels, $doBackup, $groupAtBottom);

        if (!$result['success']) {
            echo json_encode($result);
            exit;
        }

        // Save backup snapshot of prior labels only if changes were made and prior labels existed
        if ($result['changed'] && !empty($priorLabels)) {
            saveLabelBackup($containerName, $priorLabels, 'edited');
        }

        // Handle container recreation
        if ($recreate) {
            if (!$result['changed']) {
                $result['recreated'] = false;
                $result['recreate_skipped'] = true;
                $result['message'] = 'No label changes detected. Container recreation skipped.';
            } else {
                $dockerExecScript = '/usr/local/emhttp/plugins/homepage-labels/scripts/docker_exec.sh';
                if (file_exists($dockerExecScript)) {
                    $output = [];
                    $returnCode = 0;
                    exec("bash " . escapeshellarg($dockerExecScript) . " recreate " . escapeshellarg($containerName) . " 2>&1", $output, $returnCode);

                    $result['recreated'] = ($returnCode === 0);
                    $result['recreate_output'] = implode("\n", $output);
                    $result['recreate_code'] = $returnCode;

                    if ($returnCode !== 0) {
                        $result['warning'] = 'Labels saved but container recreation failed. You may need to recreate manually.';
                    } else {
                        if (function_exists('removePendingRecreate')) removePendingRecreate($containerName);
                    }
                } else {
                    $result['warning'] = 'docker_exec.sh not found. Container was not recreated.';
                }
            }
        } elseif ($result['changed']) {
            if (function_exists('addPendingRecreate')) addPendingRecreate($containerName);
        }

        echo json_encode($result);
        break;

    // -------------------------------------------------------
    // DISABLE: Remove all Homepage labels from a container
    // -------------------------------------------------------
    case 'disable':
        $containerName = isset($_POST['container']) ? trim($_POST['container']) : '';

        if (empty($containerName)) {
            echo json_encode(['success' => false, 'error' => 'Container name is required.']);
            exit;
        }

        $containerName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $containerName);

        // Save backup snapshot of current labels before removing
        $readRes = readTemplate($containerName);
        if ($readRes['success']) {
            $existingLabels = getHomepageLabels($readRes['doc']);
            if (!empty($existingLabels)) {
                saveLabelBackup($containerName, $existingLabels, 'disabled');
            }
        }

        // Write with empty labels array = removes all existing labels
        $doBackup = isset($settings['BACKUP_BEFORE_WRITE']) ? ($settings['BACKUP_BEFORE_WRITE'] === 'true') : true;
        $result = writeHomepageLabels($containerName, [], $doBackup);
        if ($result['success'] && function_exists('removePendingRecreate')) {
            removePendingRecreate($containerName);
        }

        echo json_encode($result);
        break;

    // -------------------------------------------------------
    // PRESET_FIELDS: Return HTML for a preset's widget fields
    // -------------------------------------------------------
    case 'preset_fields':
        $presetKey = isset($_GET['preset']) ? $_GET['preset'] : '';
        $containerName = isset($_GET['container']) ? $_GET['container'] : '';

        if (empty($presetKey)) {
            echo json_encode(['success' => false, 'error' => 'Preset key required.']);
            exit;
        }

        $allPresetsData = loadAllPresets();
        $presets = $allPresetsData['presets'];

        if (!isset($presets[$presetKey])) {
            echo json_encode(['success' => false, 'error' => 'Preset not found.']);
            exit;
        }

        $preset = $presets[$presetKey];

        // Generate HTML for the widget fields
        $html = '<dl>';
        $html .= '<dt><label for="field-homepage-widget-type">Widget Type:</label></dt>';
        $html .= '<dd><input type="text" id="field-homepage-widget-type" name="labels[homepage.widget.type]" value="' . htmlspecialchars($preset['widget']['type'] ?? '') . '" readonly class="homepage-readonly"></dd>';

        if (isset($preset['widget']['field_metadata'])) {
            foreach ($preset['widget']['field_metadata'] as $fieldTarget => $fieldMeta) {
                $html .= renderFieldRow($fieldTarget, '', $fieldMeta);
            }
        }
        $html .= '</dl>';

        echo json_encode(['success' => true, 'html' => $html, 'icon' => $preset['icon'] ?? '', 'name' => $preset['name'] ?? '', 'group' => $preset['default_group'] ?? '']);
        break;

    // -------------------------------------------------------
    // TEMPLATE_LIST: Return list of all default & custom templates
    // -------------------------------------------------------
    case 'template_list':
        $allPresetsData = loadAllPresets();
        $userTemplatesData = loadUserTemplates();

        $defaults = [];
        foreach ($allPresetsData['presets'] as $key => $p) {
            $defaults[] = [
                'id'       => $key,
                'name'     => $p['name'] ?? $key,
                'group'    => $p['default_group'] ?? '',
                'icon'     => $p['icon'] ?? '',
                'has_widget' => isset($p['widget']),
            ];
        }

        $customs = [];
        foreach ($userTemplatesData['templates'] as $id => $t) {
            $customs[] = [
                'id'             => $id,
                'name'           => $t['name'] ?? $id,
                'cloned_from'    => $t['cloned_from'] ?? '',
                'widget_enabled' => !empty($t['widget_enabled']),
                'modified'       => $t['modified'] ?? '',
            ];
        }

        echo json_encode(['success' => true, 'defaults' => $defaults, 'customs' => $customs]);
        break;

    // -------------------------------------------------------
    // TEMPLATE_CLONE: Clone a default preset into a custom template
    // -------------------------------------------------------
    case 'template_clone':
        $presetKey = isset($_POST['preset_key']) ? trim($_POST['preset_key']) : '';
        $customName = isset($_POST['name']) ? trim($_POST['name']) : '';

        if (empty($presetKey) || empty($customName)) {
            echo json_encode(['success' => false, 'error' => 'Preset key and template name are required.']);
            exit;
        }

        $result = cloneDefaultAsUserTemplate($presetKey, $customName);
        echo json_encode($result);
        break;

    // -------------------------------------------------------
    // TEMPLATE_SAVE: Save active form fields as a new custom template
    // -------------------------------------------------------
    case 'template_save':
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $fields = isset($_POST['fields']) && is_array($_POST['fields']) ? $_POST['fields'] : [];
        $widgetEnabled = isset($_POST['widget_enabled']) && $_POST['widget_enabled'] === '1';
        $templateId = isset($_POST['template_id']) ? trim($_POST['template_id']) : '';

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Template name is required.']);
            exit;
        }

        $result = saveCustomUserTemplate($name, $fields, $widgetEnabled, $templateId);
        echo json_encode($result);
        break;

    // -------------------------------------------------------
    // TEMPLATE_RENAME: Rename an existing custom template
    // -------------------------------------------------------
    case 'template_rename':
        $templateId = isset($_POST['template_id']) ? trim($_POST['template_id']) : '';
        $newName = isset($_POST['name']) ? trim($_POST['name']) : '';

        if (empty($templateId) || empty($newName)) {
            echo json_encode(['success' => false, 'error' => 'Template ID and new name are required.']);
            exit;
        }

        $result = renameUserTemplate($templateId, $newName);
        echo json_encode($result);
        break;

    // -------------------------------------------------------
    // TEMPLATE_DELETE: Delete a custom template
    // -------------------------------------------------------
    case 'template_delete':
        $templateId = isset($_POST['template_id']) ? trim($_POST['template_id']) : '';

        if (empty($templateId)) {
            echo json_encode(['success' => false, 'error' => 'Template ID is required.']);
            exit;
        }

        $result = deleteUserTemplate($templateId);
        echo json_encode($result);
        break;

    // -------------------------------------------------------
    // TEMPLATE_APPLY: Return field definitions and values for a template
    // -------------------------------------------------------
    case 'template_apply':
        $templateId = isset($_REQUEST['template_id']) ? trim($_REQUEST['template_id']) : '';
        $type = isset($_REQUEST['type']) ? trim($_REQUEST['type']) : 'custom'; // 'default' or 'custom'

        if (empty($templateId)) {
            echo json_encode(['success' => false, 'error' => 'Template ID is required.']);
            exit;
        }

        if ($type === 'default') {
            $allPresetsData = loadAllPresets();
            if (!isset($allPresetsData['presets'][$templateId])) {
                echo json_encode(['success' => false, 'error' => 'Default preset not found: ' . $templateId]);
                exit;
            }
            $preset = $allPresetsData['presets'][$templateId];
            $fields = [
                'homepage.group' => ['value' => $preset['default_group'] ?? '', 'mask' => 'false', 'active' => true],
                'homepage.name'  => ['value' => $preset['name'] ?? '', 'mask' => 'false', 'active' => true],
                'homepage.icon'  => ['value' => $preset['icon'] ?? '', 'mask' => 'false', 'active' => true],
            ];
            $widgetEnabled = isset($preset['widget']);
            if ($widgetEnabled) {
                $fields['homepage.widget.type'] = ['value' => $preset['widget']['type'] ?? '', 'mask' => 'false', 'active' => true];
                if (isset($preset['widget']['field_metadata'])) {
                    foreach ($preset['widget']['field_metadata'] as $t => $m) {
                        $fields[$t] = ['value' => $m['default'] ?? '', 'mask' => (!empty($m['mask'])) ? 'true' : 'false', 'active' => true];
                    }
                }
            }
            echo json_encode(['success' => true, 'fields' => $fields, 'widget_enabled' => $widgetEnabled, 'name' => $preset['name'] ?? $templateId]);
        } else {
            $userTemplatesData = loadUserTemplates();
            if (!isset($userTemplatesData['templates'][$templateId])) {
                echo json_encode(['success' => false, 'error' => 'Custom template not found: ' . $templateId]);
                exit;
            }
            $t = $userTemplatesData['templates'][$templateId];
            echo json_encode(['success' => true, 'fields' => $t['fields'] ?? [], 'widget_enabled' => !empty($t['widget_enabled']), 'name' => $t['name'] ?? $templateId]);
        }
        break;

    // -------------------------------------------------------
    // BATCH_ENABLE: Generate default discovery labels for multiple containers
    // -------------------------------------------------------
    case 'batch_enable':
        @set_time_limit(120);
        $containers = isset($_POST['containers']) ? (array)$_POST['containers'] : [];
        if (empty($containers)) {
            echo json_encode(['success' => false, 'error' => 'No containers specified.']);
            exit;
        }
        $doBackup = isset($settings['BACKUP_BEFORE_WRITE']) ? ($settings['BACKUP_BEFORE_WRITE'] === 'true') : true;
        $groupAtBottom = isset($settings['GROUP_LABELS_AT_BOTTOM']) ? ($settings['GROUP_LABELS_AT_BOTTOM'] === 'true') : true;

        // Load presets once for the entire batch (B-1 fix: was undefined)
        $allPresetsData = loadAllPresets();
        $presets = $allPresetsData['presets'];

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($containers as $cName) {
            $cName = trim($cName);
            if (empty($cName)) continue;

            $readRes = readTemplate($cName);
            if (!$readRes['success']) {
                $results[$cName] = ['success' => false, 'error' => $readRes['error']];
                $failCount++;
                continue;
            }

            $doc = $readRes['doc'];
            $meta = getContainerMeta($doc);
            $existingLabels = getHomepageLabels($doc);
            $matchedPreset = matchPreset($meta['Repository'] ?? '', $presets);
            $suggested = generateDefaultLabels($cName, $matchedPreset, $meta, $existingLabels, $settings);
            $merged = $suggested;
            foreach ($existingLabels as $k => $v) {
                if (is_array($v) ? (isset($v['value']) && $v['value'] !== '') : !empty($v)) {
                    $merged[$k] = $v;
                }
            }

            $writeRes = writeHomepageLabels($cName, $merged, $doBackup, $groupAtBottom);
            if ($writeRes['success']) {
                if ($writeRes['changed']) {
                    if (!empty($existingLabels)) {
                        saveLabelBackup($cName, $existingLabels, 'batch_enable');
                    }
                    if (function_exists('addPendingRecreate')) {
                        addPendingRecreate($cName);
                    }
                }
                $results[$cName] = ['success' => true, 'changed' => $writeRes['changed']];
                $successCount++;
            } else {
                $results[$cName] = ['success' => false, 'error' => $writeRes['error']];
                $failCount++;
            }
        }

        echo json_encode([
            'success'      => ($failCount === 0),
            'updated'      => $successCount,
            'failed'       => $failCount,
            'results'      => $results,
            'message'      => "Enabled discovery for {$successCount} container(s)." . ($failCount > 0 ? " ({$failCount} failed)" : '')
        ]);
        break;

    // -------------------------------------------------------
    // BATCH_DISABLE: Remove Homepage labels for multiple containers
    // -------------------------------------------------------
    case 'batch_disable':
        @set_time_limit(120);
        $containers = isset($_POST['containers']) ? (array)$_POST['containers'] : [];
        if (empty($containers)) {
            echo json_encode(['success' => false, 'error' => 'No containers specified.']);
            exit;
        }
        $doBackup = isset($settings['BACKUP_BEFORE_WRITE']) ? ($settings['BACKUP_BEFORE_WRITE'] === 'true') : true;
        $groupAtBottom = isset($settings['GROUP_LABELS_AT_BOTTOM']) ? ($settings['GROUP_LABELS_AT_BOTTOM'] === 'true') : true;

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($containers as $cName) {
            $cName = trim($cName);
            if (empty($cName)) continue;

            $readRes = readTemplate($cName);
            if (!$readRes['success']) {
                $results[$cName] = ['success' => false, 'error' => $readRes['error']];
                $failCount++;
                continue;
            }

            $existing = getHomepageLabels($readRes['doc']);
            if (!empty($existing)) {
                saveLabelBackup($cName, $existing, 'batch_disable');
            }

            $writeRes = writeHomepageLabels($cName, [], $doBackup, $groupAtBottom);
            if ($writeRes['success']) {
                if ($writeRes['changed'] && function_exists('addPendingRecreate')) {
                    addPendingRecreate($cName);
                }
                $results[$cName] = ['success' => true, 'removed' => $writeRes['removed']];
                $successCount++;
            } else {
                $results[$cName] = ['success' => false, 'error' => $writeRes['error']];
                $failCount++;
            }
        }

        echo json_encode([
            'success'      => ($failCount === 0),
            'updated'      => $successCount,
            'failed'       => $failCount,
            'results'      => $results,
            'message'      => "Disabled discovery for {$successCount} container(s)." . ($failCount > 0 ? " ({$failCount} failed)" : '')
        ]);
        break;

    // -------------------------------------------------------
    // BATCH_APPLY_TEMPLATE: Apply a preset or template to multiple containers
    // -------------------------------------------------------
    case 'batch_apply_template':
        @set_time_limit(120);
        $containers = isset($_POST['containers']) ? (array)$_POST['containers'] : [];
        $templateId = isset($_POST['template_id']) ? trim($_POST['template_id']) : '';
        $type = isset($_POST['type']) ? trim($_POST['type']) : 'custom';

        if (empty($containers)) {
            echo json_encode(['success' => false, 'error' => 'No containers specified.']);
            exit;
        }
        if (empty($templateId)) {
            echo json_encode(['success' => false, 'error' => 'Template ID is required.']);
            exit;
        }

        $templateFields = [];
        if ($type === 'default') {
            $allPresetsData = loadAllPresets();
            if (!isset($allPresetsData['presets'][$templateId])) {
                echo json_encode(['success' => false, 'error' => 'Default preset not found: ' . $templateId]);
                exit;
            }
            $preset = $allPresetsData['presets'][$templateId];
            $templateFields = [
                'homepage.group' => ['value' => $preset['default_group'] ?? '', 'mask' => 'false'],
                'homepage.name'  => ['value' => $preset['name'] ?? '', 'mask' => 'false'],
                'homepage.icon'  => ['value' => $preset['icon'] ?? '', 'mask' => 'false'],
            ];
            if (!empty($preset['widget'])) {
                $templateFields['homepage.widget.type'] = ['value' => $preset['widget']['type'] ?? '', 'mask' => 'false'];
            }
        } else {
            $userTemplatesData = loadUserTemplates();
            if (!isset($userTemplatesData['templates'][$templateId])) {
                echo json_encode(['success' => false, 'error' => 'Custom template not found: ' . $templateId]);
                exit;
            }
            $t = $userTemplatesData['templates'][$templateId];
            if (!empty($t['fields'])) {
                foreach ($t['fields'] as $tgt => $fData) {
                    if (isset($fData['active']) && !$fData['active']) {
                        continue;
                    }
                    $val = isset($fData['value']) ? trim((string)$fData['value']) : '';
                    if ($val === '' && $tgt !== 'homepage.name') {
                        continue;
                    }
                    $templateFields[$tgt] = [
                        'value' => $val,
                        'mask'  => $fData['mask'] ?? 'false',
                        'name'  => $fData['name'] ?? null,
                    ];
                }
            }
        }

        $doBackup = isset($settings['BACKUP_BEFORE_WRITE']) ? ($settings['BACKUP_BEFORE_WRITE'] === 'true') : true;
        $groupAtBottom = isset($settings['GROUP_LABELS_AT_BOTTOM']) ? ($settings['GROUP_LABELS_AT_BOTTOM'] === 'true') : true;

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($containers as $cName) {
            $cName = trim($cName);
            if (empty($cName)) continue;

            $readRes = readTemplate($cName);
            if (!$readRes['success']) {
                $results[$cName] = ['success' => false, 'error' => $readRes['error']];
                $failCount++;
                continue;
            }

            $doc = $readRes['doc'];
            $existing = getHomepageLabels($doc);
            $meta = getContainerMeta($doc);
            $merged = $existing;
            foreach ($templateFields as $tgt => $fData) {
                if ($tgt === 'homepage.name' && ($type === 'default' || empty($fData['value']))) {
                    $merged[$tgt] = ['value' => ucfirst($cName), 'mask' => 'false'];
                } else {
                    $merged[$tgt] = $fData;
                }
            }

            // Auto-populate widget.url if default preset defines a widget and widget.url is not yet set
            if ($type === 'default' && !empty($preset['widget']) && (!isset($merged['homepage.widget.url']) || empty($merged['homepage.widget.url']['value']))) {
                $port = $preset['default_port'] ?? 0;
                $widgetUrl = ($port > 0) ? resolveInternalUrl($cName, $port, $meta, $settings) : resolveUrl($meta, $settings);
                if (!empty($widgetUrl)) {
                    $merged['homepage.widget.url'] = ['value' => $widgetUrl, 'mask' => 'false'];
                }
            }

            $writeRes = writeHomepageLabels($cName, $merged, $doBackup, $groupAtBottom);
            if ($writeRes['success']) {
                if ($writeRes['changed']) {
                    if (!empty($existing)) {
                        saveLabelBackup($cName, $existing, 'batch_template');
                    }
                    if (function_exists('addPendingRecreate')) {
                        addPendingRecreate($cName);
                    }
                }
                $results[$cName] = ['success' => true, 'changed' => $writeRes['changed']];
                $successCount++;
            } else {
                $results[$cName] = ['success' => false, 'error' => $writeRes['error']];
                $failCount++;
            }
        }

        echo json_encode([
            'success'      => ($failCount === 0),
            'updated'      => $successCount,
            'failed'       => $failCount,
            'results'      => $results,
            'message'      => "Applied template to {$successCount} container(s)." . ($failCount > 0 ? " ({$failCount} failed)" : '')
        ]);
        break;

    // -------------------------------------------------------
    // RECREATE_SINGLE: Recreate a single container via docker_exec.sh
    // -------------------------------------------------------
    case 'recreate_single':
        $containerName = isset($_POST['container']) ? trim($_POST['container']) : '';
        if (empty($containerName) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $containerName)) {
            echo json_encode(['success' => false, 'error' => 'Valid container name is required.']);
            exit;
        }
        $dockerExecScript = '/usr/local/emhttp/plugins/homepage-labels/scripts/docker_exec.sh';
        if (file_exists($dockerExecScript)) {
            $output = [];
            $returnCode = 0;
            exec("bash " . escapeshellarg($dockerExecScript) . " recreate " . escapeshellarg($containerName) . " 2>&1", $output, $returnCode);
            if ($returnCode === 0 && function_exists('removePendingRecreate')) {
                removePendingRecreate($containerName);
            }
            echo json_encode([
                'success'   => ($returnCode === 0),
                'container' => $containerName,
                'output'    => implode("\n", $output),
                'code'      => $returnCode,
                'error'     => ($returnCode !== 0) ? 'Container recreation failed with exit code ' . $returnCode : null,
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'docker_exec.sh not found.']);
        }
        break;

    // -------------------------------------------------------
    // EXPORT_TEMPLATES: Export custom templates as JSON
    // -------------------------------------------------------
    case 'export_templates':
        $templateId = isset($_REQUEST['template_id']) ? trim($_REQUEST['template_id']) : null;
        $export = exportUserTemplates($templateId);

        if (!$export['success']) {
            echo json_encode($export);
            exit;
        }

        $asDownload = isset($_REQUEST['download']) && ($_REQUEST['download'] === '1' || $_REQUEST['download'] === 'true');
        if ($asDownload) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
            echo json_encode($export['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode($export);
        break;

    // -------------------------------------------------------
    // IMPORT_TEMPLATES: Import custom templates from file or JSON
    // -------------------------------------------------------
    case 'import_templates':
        $mode = isset($_REQUEST['mode']) ? $_REQUEST['mode'] : (isset($_REQUEST['import_mode']) ? $_REQUEST['import_mode'] : 'merge');
        if (!in_array($mode, ['merge', 'overwrite', 'replace'], true)) {
            $mode = 'merge';
        }
        $jsonContent = '';

        if (isset($_FILES['template_file']) && is_uploaded_file($_FILES['template_file']['tmp_name'])) {
            $jsonContent = @file_get_contents($_FILES['template_file']['tmp_name']);
        } elseif (isset($_POST['template_json'])) {
            $jsonContent = trim($_POST['template_json']);
        } elseif (isset($_POST['import_data'])) {
            $jsonContent = trim($_POST['import_data']);
        }

        if (empty($jsonContent)) {
            echo json_encode(['success' => false, 'error' => 'No template file uploaded or JSON provided.']);
            exit;
        }

        $importResult = importUserTemplates($jsonContent, $mode);
        echo json_encode($importResult);
        break;

    // -------------------------------------------------------
    // TEST_CONNECTION: Live HTTP & API endpoint probe
    // -------------------------------------------------------
    case 'test_connection':
        $url = isset($_REQUEST['url']) ? trim($_REQUEST['url']) : '';
        $options = [
            'method'      => isset($_REQUEST['method']) ? trim($_REQUEST['method']) : 'GET',
            'timeout'     => isset($_REQUEST['timeout']) ? (int)$_REQUEST['timeout'] : 4,
            'key'         => isset($_REQUEST['key']) ? trim($_REQUEST['key']) : '',
            'username'    => isset($_REQUEST['username']) ? trim($_REQUEST['username']) : '',
            'password'    => isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '',
            'widget_type' => isset($_REQUEST['widget_type']) ? trim($_REQUEST['widget_type']) : '',
        ];
        $probe = probeHttpConnection($url, $options);
        echo json_encode($probe);
        break;

    // -------------------------------------------------------
    // BATCH_SET_GROUP: Assign a Homepage group across multiple containers
    // -------------------------------------------------------
    case 'batch_set_group':
        $containers = isset($_POST['containers']) ? (array)$_POST['containers'] : [];
        $groupName = isset($_POST['group']) ? trim($_POST['group']) : '';
        if (empty($containers)) {
            echo json_encode(['success' => false, 'error' => 'No containers selected.']);
            exit;
        }

        $doBackup = isset($settings['BACKUP_BEFORE_WRITE']) ? ($settings['BACKUP_BEFORE_WRITE'] === 'true') : true;
        $groupAtBottom = isset($settings['GROUP_LABELS_AT_BOTTOM']) ? ($settings['GROUP_LABELS_AT_BOTTOM'] === 'true') : true;

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($containers as $cName) {
            $cName = trim($cName);
            if (empty($cName)) continue;

            $readRes = readTemplate($cName);
            if (!$readRes['success']) {
                $results[$cName] = ['success' => false, 'error' => $readRes['error']];
                $failCount++;
                continue;
            }

            $doc = $readRes['doc'];
            $existing = getHomepageLabels($doc);

            $updatedLabels = $existing;
            if ($groupName === '') {
                unset($updatedLabels['homepage.group']);
            } else {
                $updatedLabels['homepage.group'] = [
                    'value' => $groupName,
                    'mask'  => 'false',
                ];
            }

            $writeRes = writeHomepageLabels($cName, $updatedLabels, $doBackup, $groupAtBottom);
            if ($writeRes['success']) {
                if ($writeRes['changed']) {
                    if (!empty($existing)) {
                        saveLabelBackup($cName, $existing, 'batch_set_group');
                    }
                    if (function_exists('addPendingRecreate')) {
                        addPendingRecreate($cName);
                    }
                }
                $results[$cName] = ['success' => true, 'changed' => $writeRes['changed']];
                $successCount++;
            } else {
                $results[$cName] = ['success' => false, 'error' => $writeRes['error']];
                $failCount++;
            }
        }

        echo json_encode([
            'success' => ($failCount === 0),
            'updated' => $successCount,
            'failed'  => $failCount,
            'results' => $results,
            'message' => "Updated group to '{$groupName}' for {$successCount} container(s)." . ($failCount > 0 ? " ({$failCount} failed)" : '')
        ]);
        break;

    // -------------------------------------------------------
    // EXPORT_SERVICES_YAML: Compile and download services.yaml
    // -------------------------------------------------------
    case 'export_services_yaml':
        $templates = listAllTemplates();
        $containersList = [];
        foreach ($templates as $t) {
            $tName = is_array($t) ? ($t['name'] ?? '') : $t;
            if (empty($tName)) continue;
            $readRes = readTemplate($tName);
            if (!$readRes['success']) continue;
            $doc = $readRes['doc'];
            $labels = getHomepageLabels($doc);
            if (empty($labels)) continue;
            $containersList[] = [
                'name'   => $tName,
                'group'  => $labels['homepage.group']['value'] ?? 'Services',
                'labels' => $labels,
            ];
        }

        $yaml = generateServicesYaml($containersList);
        $asDownload = isset($_REQUEST['download']) && ($_REQUEST['download'] === '1' || $_REQUEST['download'] === 'true');
        if ($asDownload) {
            header('Content-Type: text/yaml; charset=utf-8');
            header('Content-Disposition: attachment; filename="services.yaml"');
            echo $yaml;
            exit;
        }

        echo json_encode([
            'success' => true,
            'yaml'    => $yaml,
            'count'   => count($containersList),
        ]);
        break;

    // -------------------------------------------------------
    // SYNC_SERVICES_YAML: Atomically sync to appdata services.yaml
    // -------------------------------------------------------
    case 'sync_services_yaml':
        $filePath = !empty($settings['HOMEPAGE_SERVICES_FILE_PATH']) ? $settings['HOMEPAGE_SERVICES_FILE_PATH'] : '/mnt/user/appdata/homepage/config/services.yaml';
        $targetDir = dirname($filePath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $templates = listAllTemplates();
        $containersList = [];
        foreach ($templates as $t) {
            $tName = is_array($t) ? ($t['name'] ?? '') : $t;
            if (empty($tName)) continue;
            $readRes = readTemplate($tName);
            if (!$readRes['success']) continue;
            $doc = $readRes['doc'];
            $labels = getHomepageLabels($doc);
            if (empty($labels)) continue;
            $containersList[] = [
                'name'   => $tName,
                'group'  => $labels['homepage.group']['value'] ?? 'Services',
                'labels' => $labels,
            ];
        }

        $yaml = generateServicesYaml($containersList);

        if (file_exists($filePath)) {
            @copy($filePath, $filePath . '.bak');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'hp_yaml_');
        if ($tmp === false || file_put_contents($tmp, $yaml) === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to write temporary services.yaml file.']);
            exit;
        }

        if (!@rename($tmp, $filePath)) {
            if (!@copy($tmp, $filePath)) {
                @unlink($tmp);
                echo json_encode(['success' => false, 'error' => "Failed to write {$filePath}. Please check path and permissions."]);
                exit;
            }
            @unlink($tmp);
        }

        echo json_encode([
            'success' => true,
            'path'    => $filePath,
            'bytes'   => strlen($yaml),
            'count'   => count($containersList),
            'message' => "Successfully synced {$filePath} (" . count($containersList) . " services).",
        ]);
        break;

    // -------------------------------------------------------
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        break;
}
