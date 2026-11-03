<?php
/**
 * view_components.php — Dynamix HTML Form Generators
 *
 * Helper functions to generate Unraid Dynamix-styled HTML components
 * for the Homepage Label Manager plugin UI.
 */

/**
 * Render the main dashboard overview table.
 *
 * @param array $containers Array of container summaries (from getContainerSummary)
 * @return string HTML table markup
 */
function renderDashboardTable($containers) {
    $html = '<div class="homepage-table-wrap">' . "\n";
    $html .= '<table class="tablesorter shift ups homepage-table" id="homepage-labels-dashboard">' . "\n";
    $html .= '<thead>';
    $html .= '<tr>';
    $html .= '<th style="width:36px;text-align:center;" data-sorter="false"><input type="checkbox" id="homepage-select-all" class="homepage-select-all-cb" onclick="homepageToggleSelectAll(this)" title="Select all visible containers"></th>';
    $html .= '<th data-col="container" class="homepage-th-resizable" style="min-width:180px;"><span class="homepage-th-title">Container</span><div class="homepage-col-resizer" title="Drag to resize, double-click to auto-fit"></div></th>';
    $html .= '<th data-col="group" class="homepage-th-resizable" style="width:130px;"><span class="homepage-th-title">Group</span><div class="homepage-col-resizer" title="Drag to resize, double-click to auto-fit"></div></th>';
    $html .= '<th data-col="icon" class="homepage-th-resizable" style="min-width:140px;"><span class="homepage-th-title">Homepage Icon</span><div class="homepage-col-resizer" title="Drag to resize, double-click to auto-fit"></div></th>';
    $html .= '<th data-col="discovery" class="homepage-th-resizable" style="width:110px;text-align:center;"><span class="homepage-th-title">Discovery</span><div class="homepage-col-resizer" title="Drag to resize, double-click to auto-fit"></div></th>';
    $html .= '<th data-col="widget" class="homepage-th-resizable" style="width:130px;text-align:center;"><span class="homepage-th-title">Widget</span><div class="homepage-col-resizer" title="Drag to resize, double-click to auto-fit"></div></th>';
    $html .= '<th data-col="labels" class="homepage-th-resizable" style="width:75px;text-align:center;"><span class="homepage-th-title">Labels</span><div class="homepage-col-resizer" title="Drag to resize, double-click to auto-fit"></div></th>';
    $html .= '<th data-col="actions" style="min-width:240px;text-align:right;"><span class="homepage-th-title">Actions</span></th>';
    $html .= '</tr>';
    $html .= '</thead>' . "\n";
    $html .= '<tbody>' . "\n";

    if (empty($containers)) {
        $html .= '<tr><td colspan="8" style="text-align:center;padding:30px;">';
        $html .= '<i class="fa fa-cubes" style="font-size:2em;opacity:0.3;display:block;margin-bottom:8px;"></i>';
        $html .= '<em>No Docker container templates found.</em>';
        $html .= '</td></tr>';
        $html .= '</tbody></table></div>';
        return $html;
    }

    $activeContainers = [];
    $inactiveContainers = [];
    foreach ($containers as $c) {
        if (!empty($c['discoveryActive'])) {
            $activeContainers[] = $c;
        } else {
            $inactiveContainers[] = $c;
        }
    }

    // Sort each group alphabetically by container name
    usort($activeContainers, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
    usort($inactiveContainers, function($a, $b) { return strcasecmp($a['name'], $b['name']); });

    $renderRow = function($container) {
        $name = htmlspecialchars($container['name']);
        $displayName = htmlspecialchars($container['displayName'] ?? $container['name']);
        $repository = htmlspecialchars($container['repository'] ?? '');
        $templateIcon = htmlspecialchars($container['templateIcon'] ?? '');
        $group = htmlspecialchars($container['group'] ?? '');
        $icon = htmlspecialchars($container['icon'] ?? '');
        $active = !empty($container['discoveryActive']);
        $pending = !empty($container['pendingRecreate']);
        $widgetType = htmlspecialchars($container['widgetType'] ?? '');
        $labelCount = intval($container['labelCount'] ?? 0);

        // Discovery badge
        if ($pending) {
            $statusBadge = '<span class="homepage-pill homepage-pill--pending" title="Labels are saved in XML template, but container needs recreation to apply them"><i class="fa fa-refresh"></i> Pending Apply</span>';
        } elseif ($active) {
            $statusBadge = '<span class="homepage-pill homepage-pill--active"><i class="fa fa-check-circle"></i> Active</span>';
        } else {
            $statusBadge = '<span class="homepage-pill homepage-pill--inactive"><i class="fa fa-minus-circle"></i> Inactive</span>';
        }

        // Widget badge
        $widgetBadge = !empty($widgetType)
            ? '<span class="homepage-pill homepage-pill--widget"><i class="fa fa-puzzle-piece"></i> ' . $widgetType . '</span>'
            : '<span class="homepage-muted">—</span>';

        // Icon preview
        $iconPreview = '<span class="homepage-muted">—</span>';
        if (!empty($icon)) {
            $iconSrc = '';
            if (filter_var($icon, FILTER_VALIDATE_URL) || str_starts_with($icon, '/')) {
                $iconSrc = $icon;
            } elseif (str_starts_with($icon, 'si-')) {
                $iconSrc = 'https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/' . substr($icon, 3) . '.svg';
            } elseif (str_starts_with($icon, 'sh-')) {
                $iconSrc = 'https://cdn.jsdelivr.net/gh/selfhst/icons/svg/' . substr($icon, 3) . '.svg';
            } elseif (str_starts_with($icon, 'mdi-')) {
                $iconSrc = 'https://cdn.jsdelivr.net/npm/@mdi/svg@latest/svg/' . substr($icon, 4) . '.svg';
            } elseif (preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $icon)) {
                $cleanIcon = ltrim($icon, '/');
                $ext = strtolower(pathinfo($cleanIcon, PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'svg', 'webp', 'jpg', 'jpeg'], true)) {
                    $cleanIcon .= '.svg';
                    $ext = 'svg';
                }
                $folder = ($ext === 'png') ? 'png' : 'svg';
                $baseIcon = basename($cleanIcon);
                $iconSrc = "https://cdn.jsdelivr.net/gh/walkxcode/dashboard-icons/{$folder}/{$baseIcon}";
            }

            if (!empty($iconSrc)) {
                $safeSrc = htmlspecialchars($iconSrc);
                $safeIcon = htmlspecialchars($icon);
                $iconPreview = '<span class="homepage-pill homepage-pill--icon" title="' . $safeIcon . '"><img src="' . $safeSrc . '" class="homepage-icon-thumb" alt="" onerror="this.style.display=\'none\';if(this.nextElementSibling)this.nextElementSibling.style.display=\'inline\';"><i class="fa fa-picture-o" style="display:none;"></i> ' . $safeIcon . '</span>';
            } else {
                $iconPreview = '<span class="homepage-pill homepage-pill--icon" title="' . htmlspecialchars($icon) . '"><i class="fa fa-picture-o"></i> ' . htmlspecialchars($icon) . '</span>';
            }
        }

        // App Icon
        $appIconHtml = '';
        if (!empty($templateIcon)) {
            $appIconHtml = '<img src="' . $templateIcon . '" class="homepage-app-icon" onerror="this.src=\'/plugins/dynamix.docker.manager/images/docker.png\';this.onerror=null;">';
        } else {
            $appIconHtml = '<i class="fa fa-cube homepage-app-icon-fallback"></i>';
        }

        // Row search tags for realtime filtering
        $searchData = strtolower("{$name} {$displayName} {$group} {$widgetType} {$repository} " . ($active ? 'active' : 'inactive'));

        $rowHtml = '<tr class="homepage-row" data-name="' . htmlspecialchars(strtolower($displayName)) . '" data-container="' . $name . '" data-label-count="' . $labelCount . '" data-active="' . ($active ? '1' : '0') . '" data-widget="' . (!empty($widgetType) ? '1' : '0') . '" data-health="' . (!empty($container['hasHealth']) ? '1' : '0') . '" data-group-name="' . htmlspecialchars(strtolower($group)) . '" data-search="' . htmlspecialchars($searchData) . '">';
        $rowHtml .= '<td style="text-align:center;"><input type="checkbox" class="homepage-row-select" data-container="' . $name . '" data-active="' . ($active ? '1' : '0') . '" onchange="homepageOnRowSelect()"></td>';

        // Container column
        $rowHtml .= '<td>';
        $rowHtml .= '<div class="homepage-container-col">';
        $rowHtml .= '<a href="javascript:void(0)" onclick="homepageEditContainer(\'' . $name . '\')" title="Edit labels for ' . $name . '" style="display:inline-flex;align-items:center;text-decoration:none;color:inherit;cursor:pointer;">';
        $rowHtml .= $appIconHtml;
        $rowHtml .= '</a>';
        $rowHtml .= '<div class="homepage-container-info">';
        $rowHtml .= '<a href="javascript:void(0)" onclick="homepageEditContainer(\'' . $name . '\')" class="homepage-container-name" style="text-decoration:none;color:inherit;cursor:pointer;" title="Click to edit labels">' . $displayName . '</a>';
        if (!empty($repository)) {
            $rowHtml .= '<span class="homepage-container-repo">' . $repository . '</span>';
        }
        $rowHtml .= '</div>';
        $rowHtml .= '</div>';
        $rowHtml .= '</td>';

        // Group
        $rowHtml .= '<td>' . ($group ? '<span class="homepage-pill homepage-pill--group"><i class="fa fa-folder-o"></i> ' . $group . '</span>' : '<span class="homepage-muted">—</span>') . '</td>';

        // Homepage Icon
        $rowHtml .= '<td>' . $iconPreview . '</td>';

        // Discovery
        $rowHtml .= '<td style="text-align:center;">' . $statusBadge . '</td>';

        // Widget
        $rowHtml .= '<td style="text-align:center;">' . $widgetBadge . '</td>';

        // Label Count
        $rowHtml .= '<td style="text-align:center;">' . ($labelCount > 0 ? '<span class="homepage-count-badge">' . $labelCount . '</span>' : '<span class="homepage-muted">0</span>') . '</td>';

        // Actions
        $rowHtml .= '<td style="text-align:right;white-space:nowrap;">';
        if (!empty($container['href']) && filter_var($container['href'], FILTER_VALIDATE_URL)) {
            $rowHtml .= '<a href="' . htmlspecialchars($container['href']) . '" target="_blank" rel="noopener noreferrer" class="homepage-btn homepage-btn--config" title="Open WebUI (' . htmlspecialchars($container['href']) . ')"><i class="fa fa-external-link"></i></a> ';
        }
        if ($pending) {
            $rowHtml .= '<button type="button" class="homepage-btn homepage-btn--apply" onclick="homepageRecreateSingle(\'' . $name . '\')" title="Recreate container now to apply pending labels"><i class="fa fa-refresh"></i> Apply</button> ';
        }
        $rowHtml .= '<button type="button" class="homepage-btn homepage-btn--edit" onclick="homepageEditContainer(\'' . $name . '\')">';
        $rowHtml .= '<i class="fa fa-tags"></i> Edit Labels</button> ';
        if (!$active && !empty($container['hasBackup'])) {
            $rowHtml .= '<button type="button" class="homepage-btn homepage-btn--restore" onclick="homepageRestoreContainer(\'' . $name . '\')" title="Restore backed-up Homepage labels"><i class="fa fa-undo"></i> Restore</button> ';
        }
        $rowHtml .= '<a href="/Docker/UpdateContainer?xmlTemplate=edit:/boot/config/plugins/dockerMan/templates-user/my-' . $name . '.xml" class="homepage-btn homepage-btn--config" title="Edit Unraid container template (ports, volumes, paths)"><i class="fa fa-sliders"></i> Config</a> ';
        if ($active) {
            $rowHtml .= '<button type="button" class="homepage-btn homepage-btn--disable" onclick="homepageDisableContainer(\'' . $name . '\')" title="Disable Homepage Discovery (Removes discovery labels without stopping container)">';
            $rowHtml .= '<i class="fa fa-power-off"></i></button>';
        }
        $rowHtml .= '</td>';

        $rowHtml .= '</tr>' . "\n";
        return $rowHtml;
    };

    // 1. Active Discovery Group
    if (!empty($activeContainers)) {
        $html .= '<tr class="homepage-group-header-row homepage-group-header-row--active" data-group="active">';
        $html .= '<td colspan="8" class="homepage-group-header-cell">';
        $html .= '<div class="homepage-group-header-content">';
        $html .= '<i class="fa fa-check-circle" style="color:#27ae60;"></i> ';
        $html .= '<span class="homepage-group-header-title">Active Homepage Discovery</span> ';
        $html .= '<span class="homepage-badge homepage-badge--count">' . count($activeContainers) . ' Containers</span>';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '</tr>' . "\n";

        foreach ($activeContainers as $container) {
            $html .= $renderRow($container);
        }
    }

    // 2. Inactive / Unconfigured Group
    if (!empty($inactiveContainers)) {
        $html .= '<tr class="homepage-group-header-row homepage-group-header-row--inactive" data-group="inactive">';
        $html .= '<td colspan="8" class="homepage-group-header-cell">';
        $html .= '<div class="homepage-group-header-content">';
        $html .= '<i class="fa fa-minus-circle" style="opacity:0.6;"></i> ';
        $html .= '<span class="homepage-group-header-title">Inactive / Unconfigured Containers</span> ';
        $html .= '<span class="homepage-badge homepage-badge--count">' . count($inactiveContainers) . ' Containers</span>';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '</tr>' . "\n";

        foreach ($inactiveContainers as $container) {
            $html .= $renderRow($container);
        }
    }

    $html .= '</tbody>' . "\n";
    $html .= '</table>' . "\n";
    $html .= '</div>'; // .homepage-table-wrap

    return $html;
}

/**
 * Render a collapsible accordion section card with header, live badge, and toggle controls.
 *
 * @param string $id Section identifier
 * @param string $icon FontAwesome icon class (e.g., "fa-compass")
 * @param string $title Section title
 * @param int    $activeCount Number of active fields in section
 * @param int    $totalCount Total fields in section
 * @param bool   $isExpanded Whether section is expanded by default
 * @param string $contentHtml Inner HTML markup
 * @param string $extraHeaderHtml Optional controls to render in header
 * @return string HTML accordion markup
 */
function renderCollapsibleSection($id, $icon, $title, $activeCount, $totalCount, $isExpanded, $contentHtml, $extraHeaderHtml = '') {
    $chevronClass = $isExpanded ? 'fa-chevron-down' : 'fa-chevron-right';
    $bodyStyle = $isExpanded ? '' : 'display:none;';
    $expandedAttr = $isExpanded ? 'true' : 'false';

    $html = '<div class="homepage-section" id="section-' . htmlspecialchars($id) . '" data-expanded="' . $expandedAttr . '">' . "\n";
    $html .= '<div class="homepage-section-header" onclick="homepageToggleCategory(\'' . htmlspecialchars($id) . '\')">' . "\n";
    $html .= '<div class="homepage-section-header-left">' . "\n";
    $html .= '<i class="fa ' . $chevronClass . ' homepage-chevron" id="chevron-' . htmlspecialchars($id) . '"></i> ';
    $html .= '<i class="fa ' . htmlspecialchars($icon) . ' homepage-section-icon"></i> ';
    $html .= '<span class="homepage-section-title">' . htmlspecialchars($title) . '</span> ';
    if ($id === 'custom') {
        $html .= '<span class="homepage-badge homepage-badge--count" id="badge-custom"><span class="active-num">' . intval($activeCount) . '</span> Active</span>';
    } elseif ($totalCount > 0) {
        $html .= '<span class="homepage-badge homepage-badge--count" id="badge-' . htmlspecialchars($id) . '"><span class="active-num">' . intval($activeCount) . '</span> / ' . intval($totalCount) . ' Active</span>';
    }
    $html .= '</div>' . "\n";

    if ($extraHeaderHtml) {
        $html .= '<div class="homepage-section-header-right" onclick="event.stopPropagation()">' . $extraHeaderHtml . '</div>' . "\n";
    }

    $html .= '</div>' . "\n"; // .homepage-section-header
    $html .= '<div class="homepage-section-body" id="body-' . htmlspecialchars($id) . '" style="' . $bodyStyle . '">' . "\n";
    $html .= $contentHtml;
    $html .= '</div>' . "\n"; // .homepage-section-body
    $html .= '</div>' . "\n"; // .homepage-section

    return $html;
}

/**
 * Render a single interactive field row with active/dimmed states, red remove button, and optional URL quick-fill chips.
 *
 * @param string      $target Label target key (e.g., "homepage.icon")
 * @param string      $value Current value
 * @param array       $metadata Field definition metadata
 * @param bool        $isActive Whether field is currently active
 * @param bool        $isRequired Whether field is mandatory (cannot be deactivated)
 * @param array|null  $quickFillUrls Associative array of quick-fill URLs ['hostUrl', 'containerUrl', 'webuiUrl']
 * @param string|null $customSectionId Section ID for live badge updates
 * @return string HTML row markup
 */
function renderInteractiveFieldRow($target, $value, $metadata, $isActive, $isRequired, $quickFillUrls = null, $customSectionId = '', $groupSuggestions = [], $localIcons = [], $localIconMatch = '', $configAttrs = []) {
    $fieldLabel = htmlspecialchars($metadata['label'] ?? $target);
    $fieldType = $metadata['type'] ?? 'text';
    $placeholder = htmlspecialchars($metadata['placeholder'] ?? '');
    $helpText = isset($metadata['help']) ? htmlspecialchars($metadata['help']) : '';
    $mask = isset($metadata['mask']) && $metadata['mask'] ? true : false;
    $defaultVal = htmlspecialchars($metadata['default'] ?? '');

    $rowClass = 'homepage-field-row';
    if ($isRequired) {
        $rowClass .= ' homepage-field--required homepage-field--active';
    } elseif ($isActive) {
        $rowClass .= ' homepage-field--active';
    } else {
        $rowClass .= ' homepage-field--dimmed';
    }

    $safeTarget = htmlspecialchars($target);
    $inputId = ($target === 'homepage.group') ? 'homepage-group-input' : ('field-' . preg_replace('/[^a-zA-Z0-9_\-]/', '-', $target));
    $rowId = 'row-field-' . preg_replace('/[^a-zA-Z0-9_\-]/', '-', $target);

    $html = '<div class="' . $rowClass . '" id="' . $rowId . '" data-target="' . $safeTarget . '" data-required="' . ($isRequired ? '1' : '0') . '" data-section="' . htmlspecialchars($customSectionId) . '" data-default="' . $defaultVal . '" onclick="homepageActivateField(this)">' . "\n";
    $html .= '<div class="homepage-field-label-col">' . "\n";
    $html .= '<label for="' . $inputId . '">' . $fieldLabel . ($isRequired ? ' <span class="homepage-required">*</span>' : '') . '</label>' . "\n";
    if ($helpText) {
        $html .= '<span class="homepage-field-help">' . $helpText . '</span>' . "\n";
    }
    $html .= '</div>' . "\n"; // .homepage-field-label-col

    $html .= '<div class="homepage-field-input-col" onclick="event.stopPropagation()">' . "\n";
    $html .= '<div class="homepage-field-input-wrap">' . "\n";

    // Disabled attribute for dimmed fields (prevents inclusion in form serialization)
    $disabledAttr = (!$isActive && !$isRequired) ? ' disabled' : '';

    $isMaskChecked = (isset($configAttrs['mask'])) ?
        isMaskTrue($configAttrs['mask']) :
        ($fieldType === 'password' || $mask);
    $isSecret = ($fieldType === 'password' || $mask || $isMaskChecked);

    if ($target === 'homepage.group') {
        // Special combobox for group
        $html .= '<div class="homepage-combobox-wrap">' . "\n";
        $html .= '<input type="text" name="labels[' . $safeTarget . ']" id="homepage-group-input" class="homepage-combobox-input" value="' . htmlspecialchars($value) . '" placeholder="' . $placeholder . '" data-default="' . $defaultVal . '" autocomplete="off"' . $disabledAttr . '>' . "\n";
        $html .= '<button type="button" class="homepage-combobox-toggle" tabindex="-1" onclick="homepageToggleCombobox(event)" title="Choose existing group"><i class="fa fa-chevron-down"></i></button>' . "\n";
        $html .= '<div class="homepage-combobox-menu" id="homepage-group-menu">' . "\n";
        foreach ($groupSuggestions as $grp) {
            $html .= '<div class="homepage-combobox-item" data-value="' . htmlspecialchars($grp) . '">';
            $html .= '<i class="fa fa-folder-o"></i> ' . htmlspecialchars($grp) . '</div>' . "\n";
        }
        $html .= '</div>' . "\n";
        $html .= '</div>' . "\n";
    } elseif ($fieldType === 'select' && isset($metadata['options'])) {
        $html .= '<select name="labels[' . $safeTarget . ']" id="' . $inputId . '" data-default="' . $defaultVal . '" onchange="homepageOnFieldChange()"' . $disabledAttr . '>' . "\n";
        foreach ($metadata['options'] as $opt) {
            $selected = ($value === $opt) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($opt) . '"' . $selected . '>' . htmlspecialchars($opt) . '</option>' . "\n";
        }
        $html .= '</select>' . "\n";
    } else {
        $htmlType = $isSecret ? 'password' : (($fieldType === 'number') ? 'number' : 'text');
        $secretClass = $isSecret ? ' homepage-secret-input' : '';
        $onInput = ($target === 'homepage.icon') ? ' oninput="homepageUpdateIconPreview(this.value); homepageOnFieldChange();"' : ' oninput="homepageOnFieldChange();"';
        $listAttr = ($target === 'homepage.icon' && !empty($localIcons)) ? ' list="homepage-local-icons-list"' : '';
        $html .= '<input type="' . $htmlType . '" name="labels[' . $safeTarget . ']" id="' . $inputId . '" class="' . trim($secretClass) . '" value="' . htmlspecialchars($value) . '" placeholder="' . $placeholder . '" data-default="' . $defaultVal . '"' . $listAttr . $onInput . $disabledAttr . '>' . "\n";
        
        // Datalist of available custom local icons
        if ($target === 'homepage.icon' && !empty($localIcons)) {
            $html .= '<datalist id="homepage-local-icons-list">' . "\n";
            foreach ($localIcons as $icon) {
                $html .= '<option value="' . htmlspecialchars($icon['url_path']) . '">' . htmlspecialchars($icon['filename']) . ' (Local Custom Icon)</option>' . "\n";
            }
            $html .= '</datalist>' . "\n";
        }
    }

    // Icon Preview Badge & Homepage Icon Guide Link
    if ($target === 'homepage.icon') {
        $html .= ' <span id="icon-preview" class="homepage-icon-preview"></span>' . "\n";
        $html .= ' <a href="https://gethomepage.dev/configs/service-widgets/icons/" target="_blank" rel="noopener noreferrer" class="homepage-btn homepage-btn--secondary" title="View official Homepage icon reference guide"><i class="fa fa-book"></i> Icon Guide <i class="fa fa-external-link" style="font-size:0.8em;opacity:0.7;"></i></a>' . "\n";
    }

    // Secret Reveal / Conceal Toggle ([👁])
    if ($isSecret) {
        $secretBtnStyle = $isActive ? '' : ' style="display:none;"';
        $html .= ' <button type="button" class="homepage-btn homepage-btn--secret-toggle" title="Show secret" onclick="homepageToggleSecretVisibility(this)"' . $secretBtnStyle . '><i class="fa fa-eye"></i></button>' . "\n";
    }

    // Mask secret XML toggle
    if ($isSecret) {
        $maskInputId = 'mask-' . $inputId;
        $checkedAttr = $isMaskChecked ? ' checked' : '';
        $maskStyle = $isActive ? '' : ' style="display:none;"';
        $html .= ' <label class="homepage-mask-toggle" for="' . $maskInputId . '" title="Mask value in Unraid template XML"' . $maskStyle . '>' . "\n";
        $html .= '<input type="checkbox" id="' . $maskInputId . '" name="masks[' . $safeTarget . ']" value="true"' . $checkedAttr . $disabledAttr . ' onchange="homepageSyncMask(this)"> <i class="fa fa-eye-slash"></i></label>' . "\n";
    }

    // Advanced Config Drawer Toggle Button
    $configBtnStyle = $isActive ? '' : ' style="display:none;"';
    $html .= ' <button type="button" class="homepage-btn homepage-btn--field-config" title="Advanced Unraid XML &lt;Config&gt; settings" onclick="homepageToggleFieldConfig(this)"' . $configBtnStyle . '><i class="fa fa-cog"></i> Config</button>' . "\n";

    // Test Connection Button for URL fields
    if ($target === 'homepage.widget.url') {
        $html .= ' <button type="button" class="homepage-btn homepage-btn--test-conn" title="Test widget connection" onclick="homepageTestConnection(\'widget\')"><i class="fa fa-bolt"></i> Test</button>' . "\n";
        $html .= ' <span id="test-status-widget" class="homepage-test-status"></span>' . "\n";
    } elseif ($target === 'homepage.siteMonitor') {
        $html .= ' <button type="button" class="homepage-btn homepage-btn--test-conn" title="Test site monitor health probe" onclick="homepageTestConnection(\'health\')"><i class="fa fa-bolt"></i> Test</button>' . "\n";
        $html .= ' <span id="test-status-health" class="homepage-test-status"></span>' . "\n";
    }

    // Red Remove Button for optional active fields
    if (!$isRequired) {
        $removeBtnStyle = $isActive ? '' : ' style="display:none;"';
        $html .= ' <button type="button" class="homepage-btn homepage-btn--remove-field" title="Deactivate and clear field" onclick="homepageDeactivateField(this)"' . $removeBtnStyle . '><i class="fa fa-times"></i></button>' . "\n";
    }

    // Dimmed hint
    $dimmedHintStyle = $isActive ? ' style="display:none;"' : '';
    $html .= '<span class="homepage-dimmed-hint"' . $dimmedHintStyle . '><i class="fa fa-plus-circle"></i> Click to enable</span>' . "\n";

    $html .= '</div>' . "\n"; // .homepage-field-input-wrap

    // Inline "Where to Find" Hint (for API key / token)
    if ($target === 'homepage.widget.key' || !empty($metadata['key_hint'])) {
        $keyHint = $metadata['key_hint'] ?? ($metadata['help'] ?? '');
        if ($keyHint) {
            $html .= '<div class="homepage-key-hint"><i class="fa fa-key"></i> <strong>Where to find:</strong> ' . htmlspecialchars($keyHint) . '</div>' . "\n";
        }
    }

    // Matched Local Custom Icon Chip
    if ($target === 'homepage.icon' && !empty($localIconMatch)) {
        $html .= '<div class="homepage-quickfill-row" style="margin-top:6px;">' . "\n";
        $html .= '<span class="homepage-quickfill-label"><i class="fa fa-picture-o"></i> Matched Local:</span> ' . "\n";
        $html .= '<button type="button" class="homepage-quickfill-chip" data-input-id="' . htmlspecialchars($inputId) . '" data-url="' . htmlspecialchars($localIconMatch) . '" title="Click to use matched custom local icon"><i class="fa fa-folder-open"></i> <span>Local Icon:</span> <code class="homepage-quickfill-val">' . htmlspecialchars($localIconMatch) . '</code></button> ';
        $html .= '</div>' . "\n";
    }

    // URL Quick-Fill Chips
    if (!empty($metadata['quickfill']) && $quickFillUrls) {
        $html .= '<div class="homepage-quickfill-row">' . "\n";
        $html .= '<span class="homepage-quickfill-label"><i class="fa fa-bolt"></i> Quick-Fill:</span> ' . "\n";

        $ipUrl = $quickFillUrls['ipUrl'] ?? '';
        $hostUrl = $quickFillUrls['hostUrl'] ?? '';
        $containerUrl = $quickFillUrls['containerUrl'] ?? '';
        $webuiUrl = $quickFillUrls['webuiUrl'] ?? '';

        $isHostDistinct = !empty($hostUrl) && strcasecmp(rtrim($hostUrl, '/'), rtrim($ipUrl, '/')) !== 0;
        $isWebuiDistinct = !empty($webuiUrl) &&
                           strcasecmp(rtrim($webuiUrl, '/'), rtrim($ipUrl, '/')) !== 0 &&
                           strcasecmp(rtrim($webuiUrl, '/'), rtrim($hostUrl, '/')) !== 0;

        if (!empty($ipUrl)) {
            $html .= '<button type="button" class="homepage-quickfill-chip" data-input-id="' . htmlspecialchars($inputId) . '" data-url="' . htmlspecialchars($ipUrl) . '" title="Click to fill server IP address and mapped port"><i class="fa fa-server"></i> <span>IP:</span> <code class="homepage-quickfill-val">' . htmlspecialchars($ipUrl) . '</code></button> ';
        }
        if ($isHostDistinct) {
            $html .= '<button type="button" class="homepage-quickfill-chip" data-input-id="' . htmlspecialchars($inputId) . '" data-url="' . htmlspecialchars($hostUrl) . '" title="Click to fill server Hostname/FQDN and mapped port"><i class="fa fa-desktop"></i> <span>Host:</span> <code class="homepage-quickfill-val">' . htmlspecialchars($hostUrl) . '</code></button> ';
        }
        if (!empty($containerUrl)) {
            $html .= '<button type="button" class="homepage-quickfill-chip" data-input-id="' . htmlspecialchars($inputId) . '" data-url="' . htmlspecialchars($containerUrl) . '" title="Click to fill container name URL (for shared Docker networks)"><i class="fa fa-cube"></i> <span>Container:</span> <code class="homepage-quickfill-val">' . htmlspecialchars($containerUrl) . '</code></button> ';
        }
        if ($isWebuiDistinct) {
            $html .= '<button type="button" class="homepage-quickfill-chip" data-input-id="' . htmlspecialchars($inputId) . '" data-url="' . htmlspecialchars($webuiUrl) . '" title="Click to fill custom Unraid WebUI template URL"><i class="fa fa-external-link"></i> <span>WebUI:</span> <code class="homepage-quickfill-val">' . htmlspecialchars($webuiUrl) . '</code></button> ';
        }
        $html .= '</div>' . "\n";
    }

    // Advanced XML Config Drawer
    $cfgNameVal = htmlspecialchars($configAttrs['name'] ?? ($metadata['label'] ?? targetToHumanName($target)));
    $cfgDefaultVal = htmlspecialchars($configAttrs['default'] ?? ($metadata['default'] ?? ''));
    $cfgDescVal = htmlspecialchars($configAttrs['description'] ?? ($metadata['help'] ?? 'Homepage Discovery Label'));
    $cfgDisplayVal = strtolower($configAttrs['display'] ?? 'always');
    $cfgReqVal = isMaskTrue($configAttrs['required'] ?? false) ? 'true' : ($isRequired ? 'true' : 'false');
    $cfgMaskVal = isMaskTrue($configAttrs['mask'] ?? false) ? 'true' : (($mask || $fieldType === 'password') ? 'true' : 'false');

    $html .= '<div class="homepage-field-config-drawer" style="display:none;">' . "\n";
    $html .= '<div class="homepage-drawer-header"><i class="fa fa-sliders"></i> Advanced Unraid Template &lt;Config&gt; Attributes</div>' . "\n";
    $html .= '<div class="homepage-drawer-grid">' . "\n";

    // Attribute: Name
    $html .= '<div class="homepage-drawer-item">' . "\n";
    $html .= '<label>Config Name</label>' . "\n";
    $html .= '<input type="text" class="cfg-attr-name" name="configs[' . $safeTarget . '][name]" value="' . $cfgNameVal . '" placeholder="' . htmlspecialchars(targetToHumanName($target)) . '" oninput="homepageOnFieldChange()"' . $disabledAttr . '>' . "\n";
    $html .= '</div>' . "\n";

    // Attribute: Default
    $html .= '<div class="homepage-drawer-item">' . "\n";
    $html .= '<label>Default Value</label>' . "\n";
    $html .= '<input type="text" class="cfg-attr-default" name="configs[' . $safeTarget . '][default]" value="' . $cfgDefaultVal . '" placeholder="Default Value" oninput="homepageOnFieldChange()"' . $disabledAttr . '>' . "\n";
    $html .= '</div>' . "\n";

    // Attribute: Description
    $html .= '<div class="homepage-drawer-item">' . "\n";
    $html .= '<label>Description / Help</label>' . "\n";
    $html .= '<input type="text" class="cfg-attr-description" name="configs[' . $safeTarget . '][description]" value="' . $cfgDescVal . '" placeholder="Help description" oninput="homepageOnFieldChange()"' . $disabledAttr . '>' . "\n";
    $html .= '</div>' . "\n";

    // Attribute: Display
    $html .= '<div class="homepage-drawer-item">' . "\n";
    $html .= '<label>Display Mode</label>' . "\n";
    $html .= '<select class="cfg-attr-display" name="configs[' . $safeTarget . '][display]" onchange="homepageOnFieldChange()"' . $disabledAttr . '>' . "\n";
    $html .= '<option value="always"' . ($cfgDisplayVal === 'always' ? ' selected' : '') . '>Always</option>' . "\n";
    $html .= '<option value="advanced"' . ($cfgDisplayVal === 'advanced' ? ' selected' : '') . '>Advanced</option>' . "\n";
    $html .= '<option value="hidden"' . ($cfgDisplayVal === 'hidden' ? ' selected' : '') . '>Hidden</option>' . "\n";
    $html .= '</select>' . "\n";
    $html .= '</div>' . "\n";

    // Attribute: Required
    $html .= '<div class="homepage-drawer-item">' . "\n";
    $html .= '<label>Required</label>' . "\n";
    $html .= '<select class="cfg-attr-required" name="configs[' . $safeTarget . '][required]" onchange="homepageOnFieldChange()"' . $disabledAttr . '>' . "\n";
    $html .= '<option value="false"' . ($cfgReqVal === 'false' ? ' selected' : '') . '>No</option>' . "\n";
    $html .= '<option value="true"' . ($cfgReqVal === 'true' ? ' selected' : '') . '>Yes</option>' . "\n";
    $html .= '</select>' . "\n";
    $html .= '</div>' . "\n";

    // Attribute: Mask
    $html .= '<div class="homepage-drawer-item">' . "\n";
    $html .= '<label>Password Mask</label>' . "\n";
    $html .= '<select class="cfg-attr-mask" name="configs[' . $safeTarget . '][mask]" onchange="homepageSyncDrawerMask(this); homepageOnFieldChange()"' . $disabledAttr . '>' . "\n";
    $html .= '<option value="false"' . ($cfgMaskVal === 'false' ? ' selected' : '') . '>No</option>' . "\n";
    $html .= '<option value="true"' . ($cfgMaskVal === 'true' ? ' selected' : '') . '>Yes</option>' . "\n";
    $html .= '</select>' . "\n";
    $html .= '</div>' . "\n";

    $html .= '</div>' . "\n"; // .homepage-drawer-grid
    $html .= '</div>' . "\n"; // .homepage-field-config-drawer

    $html .= '</div>' . "\n"; // .homepage-field-input-col
    $html .= '</div>' . "\n"; // .homepage-field-row

    return $html;
}

/**
 * Render the Template Toolbar with Default (Read-Only) and Custom Template actions.
 *
 * @param array $presetList Shipped default presets
 * @param array $userTemplates User custom templates
 * @return string HTML toolbar markup
 */
function renderTemplateToolbar($presetList, $userTemplates, $settings = []) {
    $fontScale = $settings['UI_FONT_SCALE'] ?? '100';
    $html = '<div class="homepage-template-toolbar">' . "\n";

    // Group 1: Global View Controls (Expand, Collapse, Advanced XML Mode, Readability Scale)
    $html .= '<div class="homepage-template-group">' . "\n";
    $html .= '<button type="button" class="homepage-btn homepage-btn--config" onclick="homepageExpandAll()"><i class="fa fa-folder-open-o"></i> Expand All</button> ';
    $html .= '<button type="button" class="homepage-btn homepage-btn--config" onclick="homepageCollapseAll()"><i class="fa fa-folder-o"></i> Collapse All</button> ';
    $html .= '<button type="button" class="homepage-btn homepage-btn--config" id="btn-toggle-advanced-xml" onclick="homepageToggleAdvancedXmlMode()" title="Toggle advanced Unraid XML &lt;Config&gt; attributes on all fields"><i class="fa fa-sliders"></i> Advanced XML Mode</button> ';
    $html .= '<div class="homepage-scale-switcher" title="Adjust text size & UI readability">' .
        '<i class="fa fa-text-height"></i> <select id="homepage-scale-select-editor" name="homepage_scale" onchange="homepageChangeFontScale(this.value)">' .
        '<option value="100"' . ($fontScale === '100' ? ' selected' : '') . '>100% (Default Unraid)</option>' .
        '<option value="110"' . ($fontScale === '110' ? ' selected' : '') . '>110% (+10%)</option>' .
        '<option value="125"' . ($fontScale === '125' ? ' selected' : '') . '>125% (+25%)</option>' .
        '<option value="140"' . ($fontScale === '140' ? ' selected' : '') . '>140% (+40%)</option>' .
        '</select></div>';
    $html .= '</div>' . "\n";

    // Group 2: Unified Presets & Custom Templates Selector + Actions
    $html .= '<div class="homepage-template-group homepage-template-group--unified">' . "\n";
    $html .= '<select id="unified-template-select" class="homepage-template-select" onchange="homepageOnUnifiedTemplateChange(this.value)" title="Choose a default preset or custom template to apply">' . "\n";
    $html .= '<option value="">-- Choose Preset or Template --</option>' . "\n";

    $html .= '<optgroup id="optgroup-default-presets" label="🔒 Verified Default Presets">' . "\n";
    foreach ($presetList as $key => $p) {
        $pName = htmlspecialchars($p['name'] ?? $key);
        $html .= '<option value="default:' . htmlspecialchars($key) . '">🔒 ' . $pName . '</option>' . "\n";
    }
    $html .= '</optgroup>' . "\n";

    $html .= '<optgroup id="optgroup-custom-templates" label="⭐ My Custom Templates">' . "\n";
    if (!empty($userTemplates['templates'])) {
        foreach ($userTemplates['templates'] as $id => $t) {
            $tName = htmlspecialchars($t['name'] ?? $id);
            $html .= '<option value="custom:' . htmlspecialchars($id) . '">⭐ ' . $tName . '</option>' . "\n";
        }
    }
    $html .= '</optgroup>' . "\n";
    $html .= '</select> ';

    $html .= '<button type="button" class="homepage-btn homepage-btn--apply-tpl" onclick="homepageApplyUnifiedTemplate()" title="Apply selected preset or custom template to form"><i class="fa fa-check"></i> Apply</button> ';
    $html .= '<button type="button" class="homepage-btn homepage-btn--config" onclick="homepageSaveCurrentAsTemplate()" title="Save current form values as a new custom template"><i class="fa fa-floppy-o"></i> Save As</button> ';

    // Manage Dropdown
    $html .= '<div class="homepage-dropdown-wrap" id="homepage-template-manage-wrap" style="display:inline-block;">' . "\n";
    $html .= '<button type="button" class="homepage-btn homepage-btn--config" onclick="homepageToggleDropdown(\'template-manage\', event)" title="Preset & template management options"><i class="fa fa-ellipsis-h"></i> Manage ▾</button>' . "\n";
    $html .= '<div class="homepage-dropdown-menu" id="homepage-template-manage-menu" style="min-width:210px;">' . "\n";
    $html .= '<div class="homepage-dropdown-section-title">Presets &amp; Templates</div>' . "\n";
    $html .= '<div class="homepage-dropdown-item" onclick="homepageCloneUnifiedSelected()"><i class="fa fa-clone" style="color:#29b6f6;"></i> Clone Selected to Custom</div>' . "\n";
    $html .= '<div class="homepage-dropdown-item" onclick="homepageRenameUnifiedSelected()"><i class="fa fa-pencil" style="color:#ffb74d;"></i> Rename Selected Custom</div>' . "\n";
    $html .= '<div class="homepage-dropdown-item" onclick="homepageDeleteUnifiedSelected()" style="color:#e53935;"><i class="fa fa-trash"></i> Delete Selected Custom</div>' . "\n";
    $html .= '<div style="border-top:1px solid var(--line-color, rgba(120,120,120,0.2));margin:0.35em 0;"></div>' . "\n";
    $html .= '<div class="homepage-dropdown-item" onclick="homepageExportTemplates()"><i class="fa fa-download"></i> Export Templates (JSON)</div>' . "\n";
    $html .= '<div class="homepage-dropdown-item" onclick="homepageOpenImportModal()"><i class="fa fa-upload"></i> Import Templates (JSON)</div>' . "\n";
    $html .= '</div>' . "\n"; // #homepage-template-manage-menu
    $html .= '</div> '; // #homepage-template-manage-wrap

    // Retain hidden legacy selects for backward compatibility and test harness
    $html .= '<select id="default-template-select" style="display:none;">' . "\n";
    $html .= '<option value="">-- Default Presets (Read-Only) --</option>' . "\n";
    foreach ($presetList as $key => $p) {
        $pName = htmlspecialchars($p['name'] ?? $key);
        $html .= '<option value="' . htmlspecialchars($key) . '">🔒 ' . $pName . '</option>' . "\n";
    }
    $html .= '</select>' . "\n";

    $html .= '<select id="custom-template-select" style="display:none;">' . "\n";
    $html .= '<option value="">-- Custom Templates --</option>' . "\n";
    if (!empty($userTemplates['templates'])) {
        foreach ($userTemplates['templates'] as $id => $t) {
            $tName = htmlspecialchars($t['name'] ?? $id);
            $html .= '<option value="' . htmlspecialchars($id) . '">⭐ ' . $tName . '</option>' . "\n";
        }
    }
    $html .= '</select>' . "\n";

    $html .= '</div>' . "\n";

    $html .= '</div>' . "\n"; // .homepage-template-toolbar

    return $html;
}

/**
 * Render the full container label editor form with 6 categorized collapsible accordions.
 *
 * @param string     $containerName Container name
 * @param array      $labels Current label values [target => ['value','mask','name']]
 * @param array|null $preset Matched preset data
 * @param array      $presetList All available presets for dropdown
 * @param array      $groupSuggestions List of existing group names for autocomplete
 * @param array      $containerMeta Container metadata (Ports, WebUI, etc.)
 * @param array      $settings Plugin settings
 * @return string HTML form markup
 */
function renderEditorForm($containerName, $labels, $preset, $presetList, $groupSuggestions, $containerMeta = [], $settings = []) {
    $safeName = htmlspecialchars($containerName);
    $presetName = ($preset && isset($preset['name'])) ? htmlspecialchars($preset['name']) : 'None detected';
    $matchType = ($preset && isset($preset['match_type'])) ? htmlspecialchars($preset['match_type']) : '';
    $userTemplates = loadUserTemplates();
    $knownFields = getAllKnownHomepageFields();

    // Quick-Fill URLs calculation
    $quickFillUrls = getQuickFillUrls($containerName, $containerMeta, $settings, $preset['default_port'] ?? 0);

    // Custom local icons discovery
    $hostDir = !empty($settings['CUSTOM_ICONS_HOST_DIR']) ? $settings['CUSTOM_ICONS_HOST_DIR'] : '';
    $urlPrefix = !empty($settings['CUSTOM_ICONS_URL_PREFIX']) ? $settings['CUSTOM_ICONS_URL_PREFIX'] : '/icons/';
    $localCustomIcons = getLocalCustomIcons($hostDir, $urlPrefix);
    $localIconMatch = matchLocalCustomIcon($containerName, $hostDir, $urlPrefix);

    $html = '';

    // --- Header ---
    $isEmbed = !empty($_GET['embed']) && $_GET['embed'] === '1';
    $html .= '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;padding:4px 0;">';
    $html .= '<div>';
    $html .= '<span style="font-size:1.25em;font-weight:600;"><i class="fa fa-tags"></i> Edit Labels: ' . $safeName . '</span>';
    $html .= ' <span class="homepage-badge homepage-badge--preset" style="margin-left:10px;">';
    $html .= 'Preset: ' . $presetName;
    if ($matchType) {
        $html .= ' <small>(' . $matchType . ' match)</small>';
    }
    $html .= '</span>';
    $html .= '</div>';
    $html .= '<div style="display:flex;align-items:center;gap:10px;">';
    if (!empty($labels['homepage.href']['value']) && filter_var($labels['homepage.href']['value'], FILTER_VALIDATE_URL)) {
        $html .= '<a href="' . htmlspecialchars($labels['homepage.href']['value']) . '" target="_blank" rel="noopener noreferrer" class="homepage-btn homepage-btn--config" title="Open WebUI (' . htmlspecialchars($labels['homepage.href']['value']) . ')"><i class="fa fa-external-link"></i> WebUI</a> ';
    }
    if ($isEmbed) {
        $html .= '<button type="button" class="homepage-btn homepage-btn--config" onclick="homepageCancelEdit()" title="Close Overlay Editor"><i class="fa fa-times"></i> Close Overlay</button>';
    } else {
        $html .= '<a href="/Settings/HomepageLabels?tab=settings" class="homepage-btn homepage-btn--config" title="Homepage Labels Settings"><i class="fa fa-cog"></i> Settings</a> ';
        $html .= '<a href="/Docker/UpdateContainer?xmlTemplate=edit:/boot/config/plugins/dockerMan/templates-user/my-' . $safeName . '.xml" class="homepage-btn homepage-btn--config" title="Edit Unraid container paths, ports, and environment variables"><i class="fa fa-sliders"></i> Edit Unraid Template</a> ';
        $html .= '<a href="/Settings/HomepageLabels" class="homepage-btn homepage-btn--config"><i class="fa fa-arrow-left"></i> Dashboard</a>';
    }
    $html .= '</div>';
    $html .= '</div>' . "\n";

    // --- Template Toolbar ---
    $html .= renderTemplateToolbar($presetList, $userTemplates, $settings);

    $csrfToken = htmlspecialchars($GLOBALS['var']['csrf_token'] ?? $var['csrf_token'] ?? $_SESSION['csrf_token'] ?? $_COOKIE['csrf_token'] ?? '');
    $html .= '<form id="homepage-editor-form" method="POST">' . "\n";
    $html .= '<input type="hidden" id="homepage-container-name" name="container" value="' . $safeName . '">' . "\n";
    $html .= '<input type="hidden" id="homepage-csrf-token" name="csrf_token" value="' . $csrfToken . '">' . "\n";

    // --- Core Discovery ---
    $coreHtml = '<div class="homepage-fields-grid">' . "\n";
    $coreActiveCount = 0;
    foreach ($knownFields['core'] as $target => $meta) {
        $val = isset($labels[$target]) ? $labels[$target]['value'] : '';
        $isRequired = !empty($meta['required']);
        $isActive = $isRequired || !empty($val);
        if ($isActive) $coreActiveCount++;
        $cfgAttrs = $labels[$target] ?? [];
        $coreHtml .= renderInteractiveFieldRow($target, $val, $meta, $isActive, $isRequired, $quickFillUrls, 'core', $groupSuggestions, $localCustomIcons, $localIconMatch, $cfgAttrs);
    }
    $coreHtml .= '</div>' . "\n";
    $html .= renderCollapsibleSection('core', 'fa-compass', 'Core Discovery (Required)', $coreActiveCount, count($knownFields['core']), true, $coreHtml);

    // --- Display & Styling ---
    $displayHtml = '<div class="homepage-fields-grid">' . "\n";
    $displayActiveCount = 0;
    foreach ($knownFields['display'] as $target => $meta) {
        $val = isset($labels[$target]) ? $labels[$target]['value'] : '';
        $isActive = !empty($val);
        if ($isActive) $displayActiveCount++;
        $cfgAttrs = $labels[$target] ?? [];
        $displayHtml .= renderInteractiveFieldRow($target, $val, $meta, $isActive, false, null, 'display', [], [], '', $cfgAttrs);
    }
    $displayHtml .= '</div>' . "\n";
    $html .= renderCollapsibleSection('display', 'fa-paint-brush', 'Display & Styling', $displayActiveCount, count($knownFields['display']), ($displayActiveCount > 0), $displayHtml);

    // --- Health & Monitoring ---
    $healthExtraHeader = '<div style="display:flex;align-items:center;gap:10px;">' .
        '<button type="button" class="homepage-btn homepage-btn--test-conn" onclick="homepageTestConnection(\'health\')" title="Test HTTP health check probe"><i class="fa fa-bolt"></i> Test Health Probe</button>' .
        '<span id="test-status-health-header" class="homepage-test-status"></span>' .
        '</div>';
    $healthHtml = '<div class="homepage-fields-grid">' . "\n";
    $healthActiveCount = 0;
    foreach ($knownFields['health'] as $target => $meta) {
        $val = isset($labels[$target]) ? $labels[$target]['value'] : '';
        $isActive = !empty($val);
        if ($isActive) $healthActiveCount++;
        $cfgAttrs = $labels[$target] ?? [];
        $healthHtml .= renderInteractiveFieldRow($target, $val, $meta, $isActive, false, $quickFillUrls, 'health', [], [], '', $cfgAttrs);
    }
    $healthHtml .= '</div>' . "\n";
    $html .= renderCollapsibleSection('health', 'fa-heartbeat', 'Health & Monitoring', $healthActiveCount, count($knownFields['health']), ($healthActiveCount > 0), $healthHtml, $healthExtraHeader);

    // --- Widget Configuration ---
    $widgetType = isset($labels['homepage.widget.type']) ? $labels['homepage.widget.type']['value'] : ($preset['widget']['type'] ?? '');
    $hasWidget = !empty($widgetType) || (isset($labels['homepage.widget.type']) && !empty($labels['homepage.widget.type']['value']));

    $widgetCheckboxHtml = '<label for="enable-widget" style="cursor:pointer;font-weight:600;display:flex;align-items:center;gap:6px;">' .
        '<input type="checkbox" id="enable-widget" name="enable_widget" value="1"' . ($hasWidget ? ' checked' : '') . ' onchange="homepageToggleWidgetSection(this.checked)"> ' .
        '<i class="fa fa-puzzle-piece"></i> Enable Widget</label>';
    $widgetDocsUrl = !empty($widgetType)
        ? 'https://gethomepage.dev/widgets/services/' . htmlspecialchars(strtolower($widgetType)) . '/'
        : 'https://gethomepage.dev/widgets/services/';
    $widgetExtraHeader = '<div style="display:flex;align-items:center;gap:10px;">' .
        '<a href="' . $widgetDocsUrl . '" target="_blank" rel="noopener noreferrer" class="homepage-btn homepage-btn--config" style="text-decoration:none;" title="Official Homepage documentation for ' . htmlspecialchars($widgetType ?: 'widgets') . '"><i class="fa fa-external-link"></i> Docs</a>' .
        '<button type="button" class="homepage-btn homepage-btn--test-conn" onclick="homepageTestConnection(\'widget\')" title="Test widget endpoint connectivity and authentication"><i class="fa fa-bolt"></i> Test Connection</button>' .
        '<span id="test-status-widget-header" class="homepage-test-status"></span>' .
        $widgetCheckboxHtml .
        '</div>';

    $widgetBodyHtml = '<div id="widget-fields-container">' . "\n";

    // Recommended Fields for Preset
    $widgetBodyHtml .= '<div class="homepage-subsection-title"><i class="fa fa-star"></i> Recommended Widget Fields</div>' . "\n";
    $widgetBodyHtml .= '<div class="homepage-fields-grid" id="widget-recommended-fields">' . "\n";

    $widgetActiveCount = 0;
    $widgetTotalCount = 0;
    $renderedWidgetKeys = [];

    // Always render widget.type and widget.url in recommended
    foreach (['homepage.widget.type', 'homepage.widget.url'] as $wTarget) {
        $meta = $knownFields['widget_known'][$wTarget] ?? ['label' => $wTarget, 'type' => 'text'];
        if ($wTarget === 'homepage.widget.type' && empty($meta['default'])) {
            $meta['default'] = $widgetType;
        }
        if ($wTarget === 'homepage.widget.url' && empty($meta['default'])) {
            $meta['default'] = $quickFillUrls['webuiUrl'] ?: ($quickFillUrls['containerUrl'] ?: $quickFillUrls['hostUrl'] ?: '');
        }
        $val = isset($labels[$wTarget]) ? $labels[$wTarget]['value'] : (($wTarget === 'homepage.widget.type' && $hasWidget) ? $widgetType : '');
        $isActive = !empty($val);
        if ($isActive) $widgetActiveCount++;
        $widgetTotalCount++;
        $renderedWidgetKeys[] = $wTarget;
        $cfgAttrs = $labels[$wTarget] ?? [];
        $widgetBodyHtml .= renderInteractiveFieldRow($wTarget, $val, $meta, $isActive, false, $quickFillUrls, 'widget', [], [], '', $cfgAttrs);
    }

    // Add preset specific metadata fields to recommended
    if ($preset && isset($preset['widget']['field_metadata'])) {
        foreach ($preset['widget']['field_metadata'] as $wTarget => $fieldMeta) {
            if (in_array($wTarget, $renderedWidgetKeys)) continue;
            $meta = array_merge($knownFields['widget_known'][$wTarget] ?? [], $fieldMeta);
            $val = isset($labels[$wTarget]) ? $labels[$wTarget]['value'] : ($meta['default'] ?? '');
            $isRequired = isset($preset['widget']['required_fields']) && in_array($wTarget, $preset['widget']['required_fields']);
            $isActive = $isRequired || !empty($val);
            if ($isActive) $widgetActiveCount++;
            $widgetTotalCount++;
            $renderedWidgetKeys[] = $wTarget;
            $cfgAttrs = $labels[$wTarget] ?? [];
            $widgetBodyHtml .= renderInteractiveFieldRow($wTarget, $val, $meta, $isActive, $isRequired, $quickFillUrls, 'widget', [], [], '', $cfgAttrs);
        }
    } else {
        // Fallback default API Key field
        foreach (['homepage.widget.key', 'homepage.widget.username', 'homepage.widget.password'] as $wTarget) {
            if (in_array($wTarget, $renderedWidgetKeys)) continue;
            $meta = $knownFields['widget_known'][$wTarget];
            $val = isset($labels[$wTarget]) ? $labels[$wTarget]['value'] : '';
            $isActive = !empty($val);
            if ($isActive) $widgetActiveCount++;
            $widgetTotalCount++;
            $renderedWidgetKeys[] = $wTarget;
            $cfgAttrs = $labels[$wTarget] ?? [];
            $widgetBodyHtml .= renderInteractiveFieldRow($wTarget, $val, $meta, $isActive, false, null, 'widget', [], [], '', $cfgAttrs);
        }
    }
    $widgetBodyHtml .= '</div>' . "\n"; // #widget-recommended-fields

    // Other Known Widget Fields Catalog (Active fields rendered first, inactive fields inside collapsible drawer)
    $otherActiveRows = '';
    $otherInactiveRows = '';
    $otherInactiveCount = 0;
    foreach ($knownFields['widget_known'] as $wTarget => $meta) {
        if (in_array($wTarget, $renderedWidgetKeys)) continue;
        $val = isset($labels[$wTarget]) ? $labels[$wTarget]['value'] : '';
        $isActive = !empty($val);
        if ($isActive) {
            $widgetActiveCount++;
        } else {
            $otherInactiveCount++;
        }
        $widgetTotalCount++;
        $cfgAttrs = $labels[$wTarget] ?? [];
        $rowHtml = renderInteractiveFieldRow($wTarget, $val, $meta, $isActive, false, null, 'widget', [], [], '', $cfgAttrs);
        if ($isActive) {
            $otherActiveRows .= $rowHtml;
        } else {
            $otherInactiveRows .= $rowHtml;
        }
    }

    if (!empty($otherActiveRows)) {
        $widgetBodyHtml .= '<div class="homepage-subsection-title" style="margin-top:16px;"><i class="fa fa-th-list"></i> Additional Active Widget Fields</div>' . "\n";
        $widgetBodyHtml .= '<div class="homepage-fields-grid">' . "\n";
        $widgetBodyHtml .= $otherActiveRows;
        $widgetBodyHtml .= '</div>' . "\n";
    }

    if ($otherInactiveCount > 0) {
        $widgetBodyHtml .= '<div class="homepage-other-widgets-toggle-bar" style="margin-top:16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">' . "\n";
        $widgetBodyHtml .= '<button type="button" class="homepage-btn homepage-btn--secondary" id="btn-toggle-other-widgets" onclick="homepageToggleOtherWidgets()"><i class="fa fa-chevron-down"></i> Show Other Catalog Fields (' . $otherInactiveCount . ')</button>' . "\n";
        $widgetBodyHtml .= '<input type="text" id="widget-other-filter" class="homepage-input" style="display:none; max-width:220px; font-size:12px; padding:4px 8px; border-radius:4px;" placeholder="Filter catalog fields..." oninput="homepageFilterOtherWidgets(this.value)">' . "\n";
        $widgetBodyHtml .= '</div>' . "\n";
        $widgetBodyHtml .= '<div class="homepage-fields-grid" id="widget-other-fields" style="display:none; margin-top:10px;">' . "\n";
        $widgetBodyHtml .= $otherInactiveRows;
        $widgetBodyHtml .= '</div>' . "\n";
    } else {
        $widgetBodyHtml .= '<div class="homepage-fields-grid" id="widget-other-fields" style="display:none;"></div>' . "\n";
    }

    $widgetBodyHtml .= '</div>' . "\n"; // #widget-fields-container
    $html .= renderCollapsibleSection('widget', 'fa-puzzle-piece', 'Widget Configuration', $widgetActiveCount, $widgetTotalCount, $hasWidget, $widgetBodyHtml, $widgetExtraHeader);

    // --- Custom Key/Value Pairs ---
    $allKnownFlatKeys = array_merge(
        array_keys($knownFields['core']),
        array_keys($knownFields['display']),
        array_keys($knownFields['health']),
        array_keys($knownFields['widget_known'])
    );

    $customLabels = [];
    foreach ($labels as $target => $data) {
        if (strpos($target, 'homepage.') === 0 && !in_array($target, $allKnownFlatKeys)) {
            $customLabels[$target] = $data;
        }
    }

    $customHtml = '<div id="custom-rows" style="margin: 12px 0;">' . "\n";
    $cIdx = 0;
    foreach ($customLabels as $target => $data) {
        $cIdx++;
        $customValue = htmlspecialchars($data['value'] ?? '');
        $customMasked = isMaskTrue($data['mask'] ?? false);
        $customName = htmlspecialchars($data['name'] ?? targetToHumanName($target));
        $customDefault = htmlspecialchars($data['default'] ?? '');
        $customDesc = htmlspecialchars($data['description'] ?? 'Homepage Discovery Label');
        $customDisplay = strtolower($data['display'] ?? 'always');
        $customReq = isMaskTrue($data['required'] ?? false) ? 'true' : 'false';

        $customHtml .= '<div class="homepage-custom-row">' . "\n";
        $customHtml .= '<div class="homepage-custom-main-wrap">' . "\n";
        $customHtml .= '<input type="text" class="custom-key" id="custom-key-' . $cIdx . '" name="custom_label_keys[]" value="' . htmlspecialchars($target) . '" placeholder="homepage.custom.key" oninput="homepageOnCustomKeyInput(this); homepageOnFieldChange();">' . "\n";
        $customValType = $customMasked ? 'password' : 'text';
        $customHtml .= '<input type="' . $customValType . '" class="custom-val" id="custom-val-' . $cIdx . '" name="custom_label_vals[]" value="' . $customValue . '" placeholder="value" oninput="homepageOnFieldChange()">' . "\n";
        $customHtml .= '<button type="button" class="homepage-btn homepage-btn--secret-toggle custom-secret-toggle" title="Show secret" onclick="homepageToggleSecretVisibility(this)"' . ($customMasked ? '' : ' style="display:none;"') . '><i class="fa fa-eye"></i></button>' . "\n";
        $customHtml .= '<label class="homepage-mask-toggle" for="custom-mask-' . $cIdx . '" title="Mask value in Unraid template XML"><input type="checkbox" class="custom-mask" id="custom-mask-' . $cIdx . '" name="custom_label_masks[]" value="true"' . ($customMasked ? ' checked' : '') . ' onchange="homepageOnCustomMaskChange(this)"> <i class="fa fa-eye-slash"></i></label>' . "\n";
        $customHtml .= '<button type="button" class="homepage-btn homepage-btn--field-config" title="Advanced Unraid XML &lt;Config&gt; attributes" onclick="homepageToggleCustomConfig(this)"><i class="fa fa-cog"></i> Config</button>' . "\n";
        $customHtml .= '<button type="button" class="homepage-btn homepage-btn--remove" onclick="$(this).closest(\'.homepage-custom-row\').remove(); homepageUpdateBadge(\'custom\'); homepageOnFieldChange();"><i class="fa fa-trash"></i></button>' . "\n";
        $customHtml .= '</div>' . "\n"; // .homepage-custom-main-wrap

        // Custom config drawer
        $customHtml .= '<div class="homepage-custom-config-drawer" style="display:none;">' . "\n";
        $customHtml .= '<div class="homepage-drawer-header"><i class="fa fa-sliders"></i> Advanced Unraid Template &lt;Config&gt; Attributes</div>' . "\n";
        $customHtml .= '<div class="homepage-drawer-grid">' . "\n";
        $customHtml .= '<div class="homepage-drawer-item"><label>Config Name</label><input type="text" class="custom-cfg-name" value="' . $customName . '" placeholder="Config Name" oninput="homepageOnFieldChange()"></div>' . "\n";
        $customHtml .= '<div class="homepage-drawer-item"><label>Default Value</label><input type="text" class="custom-cfg-default" value="' . $customDefault . '" placeholder="Default Value" oninput="homepageOnFieldChange()"></div>' . "\n";
        $customHtml .= '<div class="homepage-drawer-item"><label>Description</label><input type="text" class="custom-cfg-desc" value="' . $customDesc . '" placeholder="Description" oninput="homepageOnFieldChange()"></div>' . "\n";
        $customHtml .= '<div class="homepage-drawer-item"><label>Display Mode</label><select class="custom-cfg-display" onchange="homepageOnFieldChange()"><option value="always"' . ($customDisplay === 'always' ? ' selected' : '') . '>Always</option><option value="advanced"' . ($customDisplay === 'advanced' ? ' selected' : '') . '>Advanced</option><option value="hidden"' . ($customDisplay === 'hidden' ? ' selected' : '') . '>Hidden</option></select></div>' . "\n";
        $customHtml .= '<div class="homepage-drawer-item"><label>Required</label><select class="custom-cfg-required" onchange="homepageOnFieldChange()"><option value="false"' . ($customReq === 'false' ? ' selected' : '') . '>No</option><option value="true"' . ($customReq === 'true' ? ' selected' : '') . '>Yes</option></select></div>' . "\n";
        $customHtml .= '<div class="homepage-drawer-item"><label>Password Mask</label><select class="custom-cfg-mask" onchange="homepageOnCustomDrawerMaskChange(this); homepageOnFieldChange();"><option value="false"' . (!$customMasked ? ' selected' : '') . '>No</option><option value="true"' . ($customMasked ? ' selected' : '') . '>Yes</option></select></div>' . "\n";
        $customHtml .= '</div>' . "\n"; // .homepage-drawer-grid
        $customHtml .= '</div>' . "\n"; // .homepage-custom-config-drawer
        $customHtml .= '</div>' . "\n"; // .homepage-custom-row
    }
    $customHtml .= '</div>' . "\n";
    $customHtml .= '<button type="button" class="homepage-btn homepage-btn--add" onclick="homepageAddCustomRow()"><i class="fa fa-plus"></i> Add Unlisted Custom Row</button>' . "\n";
    $html .= renderCollapsibleSection('custom', 'fa-code', 'Custom Key / Value Pairs', count($customLabels), null, (!empty($customLabels)), $customHtml);

    // --- Live Discovery Preview & Code Exporter ---
    $previewHtml = '<div class="homepage-preview-container">' . "\n";

    // Visual Homepage Service Card Mockup
    $previewHtml .= '<div class="homepage-mockup-wrapper">' . "\n";
    $previewHtml .= '<div class="homepage-mockup-header-label"><i class="fa fa-desktop"></i> Homepage Dashboard Card Mockup <span style="font-size:0.85em;opacity:0.75;font-weight:400;margin-left:6px;">(Live preview of how this service card renders on Homepage)</span></div>' . "\n";
    $previewHtml .= '<div class="homepage-mockup-board">' . "\n";
    $previewHtml .= '<div class="homepage-mockup-group-title"><i class="fa fa-folder-open-o"></i> <span id="mockup-group-text">Services</span></div>' . "\n";
    $previewHtml .= '<div class="homepage-mockup-card">' . "\n";
    $previewHtml .= '<div class="homepage-mockup-card-body">' . "\n";
    $previewHtml .= '<div class="homepage-mockup-icon-wrap" id="mockup-icon-wrap">' . "\n";
    $previewHtml .= '<img id="mockup-icon-img" src="" class="homepage-mockup-icon" alt="" style="display:none;" onerror="this.style.display=\'none\';document.getElementById(\'mockup-icon-fallback\').style.display=\'flex\';">' . "\n";
    $previewHtml .= '<span id="mockup-icon-fallback" class="homepage-mockup-icon-fallback"><i class="fa fa-cube"></i></span>' . "\n";
    $previewHtml .= '</div>' . "\n";
    $previewHtml .= '<div class="homepage-mockup-info">' . "\n";
    $previewHtml .= '<div class="homepage-mockup-title-row">' . "\n";
    $previewHtml .= '<span class="homepage-mockup-title" id="mockup-title-text">' . $safeName . '</span>' . "\n";
    $previewHtml .= '<span class="homepage-mockup-status-dot" id="mockup-status-dot" style="display:none;" title="SiteMonitor / Ping Health Indicator"></span>' . "\n";
    $previewHtml .= '</div>' . "\n";
    $previewHtml .= '<div class="homepage-mockup-desc" id="mockup-desc-text" style="display:none;"></div>' . "\n";
    $previewHtml .= '</div>' . "\n";
    $previewHtml .= '</div>' . "\n"; // .homepage-mockup-card-body
    $previewHtml .= '<div class="homepage-mockup-widget" id="mockup-widget-wrap" style="display:none;">' . "\n";
    $previewHtml .= '<div class="homepage-mockup-widget-header">' . "\n";
    $previewHtml .= '<span class="homepage-mockup-widget-type" id="mockup-widget-type-text"><i class="fa fa-puzzle-piece"></i> Widget</span>' . "\n";
    $previewHtml .= '<span class="homepage-mockup-widget-live-dot" title="Widget Active"></span>' . "\n";
    $previewHtml .= '</div>' . "\n";
    $previewHtml .= '<div class="homepage-mockup-widget-stats" id="mockup-widget-stats">' . "\n";
    $previewHtml .= '<span class="homepage-mockup-stat-pill"><span class="stat-lbl">Status</span><span class="stat-val">Active</span></span>' . "\n";
    $previewHtml .= '<span class="homepage-mockup-stat-pill"><span class="stat-lbl">Service</span><span class="stat-val">Online</span></span>' . "\n";
    $previewHtml .= '</div>' . "\n";
    $previewHtml .= '</div>' . "\n"; // .homepage-mockup-widget
    $previewHtml .= '</div>' . "\n"; // .homepage-mockup-card
    $previewHtml .= '</div>' . "\n"; // .homepage-mockup-board
    $previewHtml .= '</div>' . "\n"; // .homepage-mockup-wrapper

    $previewHtml .= '<div class="homepage-preview-toolbar">' . "\n";
    $previewHtml .= '<div class="homepage-filters">' . "\n";
    $previewHtml .= '<button type="button" class="homepage-tab homepage-tab--active" id="preview-tab-translated" onclick="homepageSwitchPreviewTab(\'translated\')"><i class="fa fa-file-code-o"></i> Translated services.yaml</button>';
    $previewHtml .= '<button type="button" class="homepage-tab" id="preview-tab-compose" onclick="homepageSwitchPreviewTab(\'compose\')"><i class="fa fa-cubes"></i> Raw Compose YAML</button>';
    $previewHtml .= '<button type="button" class="homepage-tab" id="preview-tab-cli" onclick="homepageSwitchPreviewTab(\'cli\')"><i class="fa fa-terminal"></i> Docker CLI Flags</button>';
    $previewHtml .= '<button type="button" class="homepage-tab" id="preview-tab-xml" onclick="homepageSwitchPreviewTab(\'xml\')"><i class="fa fa-code"></i> Unraid XML Template</button>';
    $previewHtml .= '</div>' . "\n";
    $previewHtml .= '<div class="homepage-preview-actions">' . "\n";
    $previewHtml .= '<button type="button" class="homepage-btn homepage-btn--config" id="btn-copy-preview" onclick="homepageCopyCurrentPreview()"><i class="fa fa-clipboard"></i> Copy</button>' . "\n";
    $previewHtml .= '</div>' . "\n";
    $previewHtml .= '</div>' . "\n";
    $previewHtml .= '<pre id="homepage-live-yaml" class="homepage-code-block"></pre>' . "\n";
    $previewHtml .= '</div>' . "\n";
    $html .= renderCollapsibleSection('preview', 'fa-eye', 'Live Discovery YAML & Label Preview', 0, 0, true, $previewHtml);

    // --- Form Action Buttons ---
    $cancelBtnLabel = $isEmbed ? 'Close Overlay' : 'Done';
    $saveXmlBtnLabel = $isEmbed ? 'Save & Sync to Form' : 'Save XML Only';
    $html .= '<br>' . "\n";
    $html .= '<div class="homepage-actions-bar">' . "\n";
    $html .= '<input type="button" id="btn-cancel-edit" name="btn_cancel_edit" value="' . htmlspecialchars($cancelBtnLabel) . '" onclick="homepageCancelEdit()">' . "\n";
    $html .= '<input type="button" id="btn-save-xml" name="btn_save_xml" value="' . htmlspecialchars($saveXmlBtnLabel) . '" onclick="homepageSaveLabels(false)">' . "\n";
    $html .= '<input type="button" id="btn-save-apply" name="btn_save_apply" value="Save & Apply (Recreate Container)" class="homepage-btn-apply" onclick="homepageSaveLabels(true)">' . "\n";
    $html .= '</div>' . "\n";

    $html .= '</form>' . "\n";
    $html .= renderPreSaveDiffModal();
    $html .= renderTemplateImportModal();

    return $html;
}

/**
 * Render the Homepage container health check card.
 *
 * @param array $healthData Health check results from validator
 * @return string HTML markup for the health card
 */
function renderHealthCheck($healthData) {
    $isRunning = !empty($healthData['running']);
    $hasSocket = !empty($healthData['socketMounted']);
    $containerName = htmlspecialchars($healthData['containerName'] ?? 'homepage');

    $html = '<div class="homepage-health-card">';
    $html .= '<div class="title"><span class="left"><i class="fa fa-stethoscope"></i> Homepage Health Check</span></div>';
    $html .= '<dl>';

    // Container status
    $html .= '<dt>Homepage Container (' . $containerName . '):</dt>';
    if ($isRunning) {
        $html .= '<dd><span class="homepage-badge homepage-badge--active"><i class="fa fa-check-circle"></i> Running</span></dd>';
    } else {
        $html .= '<dd><span class="homepage-badge homepage-badge--error"><i class="fa fa-exclamation-circle"></i> Not Running</span></dd>';
    }

    // Docker socket
    $html .= '<dt>Docker Socket Mounted:</dt>';
    if ($hasSocket) {
        $html .= '<dd><span class="homepage-badge homepage-badge--active"><i class="fa fa-check-circle"></i> /var/run/docker.sock</span></dd>';
    } else {
        $html .= '<dd><span class="homepage-badge homepage-badge--error"><i class="fa fa-exclamation-triangle"></i> Not mounted — labels won\'t be discovered</span></dd>';
    }

    $html .= '</dl>';
    $html .= '</div>';

    return $html;
}

/**
 * Render a single label field row for the editor (used for dynamic AJAX-added fields).
 *
 * @param string $target Label target key
 * @param string $value Current value
 * @param array  $metadata Field metadata from preset
 * @return string HTML row markup
 */
function renderFieldRow($target, $value, $metadata) {
    return renderInteractiveFieldRow($target, $value, $metadata, true, false, null, 'widget');
}



/**
 * Render the Floating/Docked Bulk Operations Toolbar.
 *
 * @param array $presets Available presets
 * @param array $userTemplates Available custom templates
 * @return string HTML markup for bulk action bar
 */
function renderBulkActionBar($presets = [], $userTemplates = []) {
    $html = '<div id="homepage-bulk-toolbar" class="homepage-bulk-toolbar" style="display:none;">' . "\n";
    $html .= '<div class="homepage-bulk-toolbar-inner">' . "\n";
    
    // Selection counter
    $html .= '<div class="homepage-bulk-info">' . "\n";
    $html .= '<i class="fa fa-check-square-o" style="color:#e65100;font-size:1.15em;"></i> ';
    $html .= '<span id="homepage-bulk-count" class="homepage-bulk-count">0</span>';
    $html .= '<span class="homepage-bulk-label"> selected</span>' . "\n";
    $html .= '</div>' . "\n";

    // Actions
    $html .= '<div class="homepage-bulk-actions">' . "\n";

    // Apply Template Dropdown
    $html .= '<div class="homepage-dropdown-wrap" id="homepage-bulk-template-dropdown-wrap">' . "\n";
    $html .= '<button type="button" class="homepage-btn homepage-btn--bulk" onclick="homepageToggleDropdown(\'bulk-template\', event)">' . "\n";
    $html .= '<i class="fa fa-clone"></i> Apply Template <i class="fa fa-caret-down" style="font-size:0.85em;margin-left:0.2em;"></i>' . "\n";
    $html .= '</button>' . "\n";
    $html .= '<div class="homepage-dropdown-menu" id="homepage-bulk-template-menu" onclick="event.stopPropagation()">' . "\n";
    $html .= '<div class="homepage-dropdown-header"><span>Select Template to Apply</span></div>' . "\n";

    // Default Presets
    if (!empty($presets)) {
        $html .= '<div class="homepage-dropdown-section-title">Default Presets</div>' . "\n";
        foreach ($presets as $pKey => $p) {
            $pName = htmlspecialchars($p['name'] ?? $pKey);
            $html .= '<div class="homepage-dropdown-item homepage-batch-template-item" data-template-id="' . htmlspecialchars($pKey) . '" data-source="default" data-name="' . htmlspecialchars($p['name'] ?? $pKey) . '">' . "\n";
            $html .= '<i class="fa fa-star-o" style="color:#ffb300;"></i> ' . $pName . '</div>' . "\n";
        }
    }

    // User Templates
    if (!empty($userTemplates['templates'])) {
        $html .= '<div class="homepage-dropdown-section-title">Custom Templates</div>' . "\n";
        foreach ($userTemplates['templates'] as $tId => $t) {
            $tName = htmlspecialchars($t['name'] ?? $tId);
            $html .= '<div class="homepage-dropdown-item homepage-batch-template-item" data-template-id="' . htmlspecialchars($tId) . '" data-source="custom" data-name="' . htmlspecialchars($t['name'] ?? $tId) . '">' . "\n";
            $html .= '<i class="fa fa-bookmark-o" style="color:#29b6f6;"></i> ' . $tName . '</div>' . "\n";
        }
    }

    $html .= '</div>' . "\n"; // .homepage-dropdown-menu
    $html .= '</div>' . "\n"; // .homepage-dropdown-wrap

    // Set Group Button
    $html .= '<button type="button" class="homepage-btn homepage-btn--bulk homepage-btn--bulk-group" onclick="homepageOpenBulkGroupModal()" title="Assign a Homepage group to selected containers">' . "\n";
    $html .= '<i class="fa fa-folder"></i> Set Group' . "\n";
    $html .= '</button>' . "\n";

    // Enable Discovery Button
    $html .= '<button type="button" class="homepage-btn homepage-btn--bulk homepage-btn--bulk-enable" onclick="homepageBatchEnable()" title="Generate default Homepage labels for selected containers">' . "\n";
    $html .= '<i class="fa fa-check-circle"></i> Enable Discovery' . "\n";
    $html .= '</button>' . "\n";

    // Disable Discovery Button
    $html .= '<button type="button" class="homepage-btn homepage-btn--bulk homepage-btn--bulk-disable" onclick="homepageBatchDisable()" title="Remove Homepage discovery labels from selected containers">' . "\n";
    $html .= '<i class="fa fa-minus-circle"></i> Disable Discovery' . "\n";
    $html .= '</button>' . "\n";

    // Recreate Containers Button
    $html .= '<button type="button" class="homepage-btn homepage-btn--bulk homepage-btn--bulk-recreate" onclick="homepageBatchRecreate()" title="Safely recreate selected containers sequentially via dockerupdate">' . "\n";
    $html .= '<i class="fa fa-refresh"></i> Recreate Containers' . "\n";
    $html .= '</button>' . "\n";

    // Deselect All
    $html .= '<button type="button" class="homepage-btn homepage-btn--bulk-clear" onclick="homepageClearSelection()" title="Clear selection">' . "\n";
    $html .= '<i class="fa fa-times"></i> Deselect' . "\n";
    $html .= '</button>' . "\n";

    $html .= '</div>' . "\n"; // .homepage-bulk-actions
    $html .= '</div>' . "\n"; // .homepage-bulk-toolbar-inner
    $html .= '</div>' . "\n"; // #homepage-bulk-toolbar

    return $html;
}

/**
 * Render the Batch Progress Modal Dialog markup.
 *
 * @return string HTML markup for progress modal
 */
function renderBatchProgressModal() {
    $html = <<<HTML
<div id="homepage-batch-modal" class="homepage-modal-backdrop" style="display:none;">
    <div class="homepage-modal-dialog" style="max-width:38em;height:auto;max-height:85vh;">
        <div class="homepage-modal-header">
            <div class="homepage-modal-title-wrap">
                <i class="fa fa-tasks homepage-modal-icon"></i>
                <h3 class="homepage-modal-title" id="homepage-batch-modal-title">Batch Container Operation</h3>
            </div>
            <button type="button" id="homepage-batch-modal-close" class="homepage-modal-close" style="display:none;" onclick="homepageCloseBatchModal()">&times;</button>
        </div>

        <div class="homepage-modal-body" style="padding:1.4em;">
            <div id="homepage-batch-status-text" style="font-weight:600;font-size:1.05em;margin-bottom:0.7em;">
                Processing containers...
            </div>

            <!-- Progress Bar -->
            <div class="homepage-progress-wrap" style="background:rgba(120,120,120,0.15);border-radius:0.5em;height:1.2em;overflow:hidden;margin-bottom:1em;border:1px solid var(--line-color,rgba(120,120,120,0.25));">
                <div id="homepage-batch-progress-bar" style="width:0%;height:100%;background:linear-gradient(90deg,#e65100,#ff9800);transition:width 0.25s ease;"></div>
            </div>

            <div style="display:flex;justify-content:space-between;font-size:0.88em;opacity:0.8;margin-bottom:1em;">
                <span id="homepage-batch-counter">0 of 0 completed</span>
                <span id="homepage-batch-percent">0%</span>
            </div>

            <!-- Activity Log -->
            <div id="homepage-batch-log" style="background:rgba(0,0,0,0.25);border:1px solid var(--line-color,rgba(120,120,120,0.2));border-radius:0.4em;padding:0.75em 1em;max-height:14em;overflow-y:auto;font-family:Consolas,monospace;font-size:0.88em;line-height:1.6;">
            </div>
        </div>

        <div class="homepage-modal-footer">
            <div id="homepage-batch-footer-msg" style="font-size:0.88em;opacity:0.75;">
                Please do not close this window while containers are being recreated.
            </div>
            <div class="homepage-modal-actions">
                <button type="button" id="homepage-batch-btn-cancel" class="homepage-btn" onclick="homepageCancelBatch()">Stop</button>
                <button type="button" id="homepage-batch-btn-done" class="homepage-btn homepage-btn--apply" style="display:none;" onclick="homepageCloseBatchModal(true)">Done &amp; Refresh</button>
            </div>
        </div>
    </div>
</div>
HTML;
    return $html;
}

/**
 * Render Pre-Save Diff & Recreate Warning Modal markup.
 *
 * @return string HTML markup
 */
function renderPreSaveDiffModal() {
    $html = <<<HTML
<div id="homepage-diff-modal" class="homepage-modal-backdrop" style="display:none;">
    <div class="homepage-modal-dialog" style="max-width:46em;height:auto;max-height:88vh;">
        <div class="homepage-modal-header">
            <div class="homepage-modal-title-wrap">
                <i class="fa fa-exclamation-triangle homepage-modal-icon" style="color:#e67e22;"></i>
                <h3 class="homepage-modal-title">Confirm Container Recreate: <span id="homepage-diff-container-title"></span></h3>
            </div>
            <button type="button" class="homepage-modal-close" onclick="homepageCloseDiffModal()">&times;</button>
        </div>

        <div class="homepage-modal-body" style="padding:1.4em;">
            <!-- Warning Notice -->
            <div class="homepage-diff-warning-banner">
                <i class="fa fa-info-circle"></i>
                <div>
                    Applying changes will restart and recreate Docker container <strong id="homepage-diff-container-highlight"></strong>.
                    Active connections, streams, and downloads running inside this container will be briefly interrupted.
                </div>
            </div>

            <!-- Metric Badges -->
            <div class="homepage-diff-metrics-bar">
                <span class="homepage-diff-pill homepage-diff-pill--added" id="diff-pill-added"><i class="fa fa-plus-circle"></i> <strong id="diff-badge-added">0</strong> Added</span>
                <span class="homepage-diff-pill homepage-diff-pill--modified" id="diff-pill-modified"><i class="fa fa-pencil-square"></i> <strong id="diff-badge-modified">0</strong> Modified</span>
                <span class="homepage-diff-pill homepage-diff-pill--removed" id="diff-pill-removed"><i class="fa fa-minus-circle"></i> <strong id="diff-badge-removed">0</strong> Removed</span>
            </div>

            <!-- Diff Content Table -->
            <div class="homepage-diff-list-wrap">
                <table class="homepage-diff-table" id="homepage-diff-table">
                    <thead>
                        <tr>
                            <th style="width:28%;">Label</th>
                            <th style="width:36%;">Before (Template XML)</th>
                            <th style="width:36%;">After (New Form Value)</th>
                        </tr>
                    </thead>
                    <tbody id="homepage-diff-table-body">
                    </tbody>
                </table>
            </div>

            <!-- Remember Choice Checkbox -->
            <div style="margin-top:1em;display:flex;align-items:center;gap:0.5em;font-size:0.9em;opacity:0.85;">
                <label style="cursor:pointer;display:inline-flex;align-items:center;gap:0.5em;user-select:none;">
                    <input type="checkbox" id="homepage-diff-skip-session" value="1">
                    Don't show this recreation warning again during this session
                </label>
            </div>
        </div>

        <div class="homepage-modal-footer">
            <div class="homepage-modal-actions" style="width:100%;display:flex;justify-content:space-between;align-items:center;">
                <button type="button" class="homepage-btn" onclick="homepageCloseDiffModal()">Cancel</button>
                <div style="display:flex;gap:0.7em;">
                    <button type="button" class="homepage-btn homepage-btn--config" onclick="homepageConfirmSaveXmlOnly()" title="Save changes to Unraid XML template without restarting the Docker container"><i class="fa fa-file-code-o"></i> Save XML Only (No Restart)</button>
                    <button type="button" class="homepage-btn homepage-btn--apply" id="homepage-btn-confirm-recreate" onclick="homepageConfirmSaveAndRecreate()"><i class="fa fa-refresh"></i> Confirm &amp; Recreate</button>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
    return $html;
}

/**
 * Render Template Import Modal markup (File upload + Paste JSON).
 *
 * @return string HTML markup
 */
function renderTemplateImportModal() {
    $html = <<<HTML
<div id="homepage-import-modal" class="homepage-modal-backdrop" style="display:none;" onclick="if(event.target===this)homepageCloseImportModal();">
    <div class="homepage-modal-dialog" style="max-width:44em;height:auto;max-height:88vh;">
        <div class="homepage-modal-header">
            <div class="homepage-modal-title-wrap">
                <i class="fa fa-upload homepage-modal-icon" style="color:#2980b9;"></i>
                <h3 class="homepage-modal-title">Import Custom Templates</h3>
            </div>
            <button type="button" class="homepage-modal-close" onclick="homepageCloseImportModal()">&times;</button>
        </div>

        <div class="homepage-modal-toolbar">
            <div class="homepage-tabs" style="display:flex;gap:0.4em;">
                <button type="button" class="homepage-tab homepage-tab--active" id="import-tab-file" onclick="homepageSetImportTab('file')"><i class="fa fa-file-text-o"></i> Upload JSON File</button>
                <button type="button" class="homepage-tab" id="import-tab-paste" onclick="homepageSetImportTab('paste')"><i class="fa fa-clipboard"></i> Paste JSON Text</button>
            </div>
        </div>

        <div class="homepage-modal-body" style="padding:1.4em;">
            <!-- Tab 1: File Upload -->
            <div id="import-panel-file">
                <div class="homepage-dropzone" id="homepage-import-dropzone" onclick="$('#homepage-import-file-input').click()">
                    <i class="fa fa-cloud-upload" style="font-size:2.4em;opacity:0.6;margin-bottom:0.4em;display:block;"></i>
                    <div style="font-weight:600;font-size:1.05em;margin-bottom:0.25em;">Choose a JSON file or drag &amp; drop it here</div>
                    <div style="font-size:0.85em;opacity:0.7;">Supports exported user_templates.json or backup files</div>
                    <input type="file" id="homepage-import-file-input" accept=".json,application/json" style="display:none;" onchange="homepageOnFileSelected(this)">
                </div>
                <div id="homepage-import-file-name" style="margin-top:0.7em;font-size:0.9em;font-weight:500;display:none;"></div>
            </div>

            <!-- Tab 2: Paste JSON -->
            <div id="import-panel-paste" style="display:none;">
                <label style="display:block;font-size:0.9em;margin-bottom:0.5em;font-weight:500;">Paste community or backup JSON below:</label>
                <textarea id="homepage-import-textarea" class="homepage-code-block" style="width:100%;box-sizing:border-box;height:12em;font-family:Consolas,monospace;font-size:0.88em;padding:0.75em;resize:vertical;" placeholder="{\n  &quot;version&quot;: &quot;1.0&quot;,\n  &quot;templates&quot;: {\n    &quot;my-template&quot;: { ... }\n  }\n}" oninput="homepageOnImportTextChange()"></textarea>
            </div>

            <!-- Detection / Preview box -->
            <div id="homepage-import-preview-wrap" style="margin-top:1.1em;display:none;">
                <div style="font-weight:600;font-size:0.92em;margin-bottom:0.5em;display:flex;align-items:center;gap:0.5em;">
                    <i class="fa fa-check-circle" style="color:#27ae60;"></i>
                    <span>Detected Templates: <span id="homepage-import-detected-count">0</span></span>
                </div>
                <div id="homepage-import-detected-list" class="homepage-import-detected-pills"></div>
            </div>

            <!-- Import Conflict Mode -->
            <div style="margin-top:1.3em;border-top:1px solid var(--line-color,rgba(120,120,120,0.2));padding-top:1em;">
                <div style="font-weight:600;font-size:0.92em;margin-bottom:0.6em;"><i class="fa fa-sliders"></i> Conflict Handling:</div>
                <div style="display:flex;flex-direction:column;gap:0.5em;font-size:0.88em;">
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:0.55em;">
                        <input type="radio" name="homepage_import_mode" value="merge" checked>
                        <span><strong>Merge (Recommended)</strong> — Add new templates and preserve existing ones (duplicates get renamed)</span>
                    </label>
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:0.55em;">
                        <input type="radio" name="homepage_import_mode" value="overwrite">
                        <span><strong>Overwrite</strong> — Replace existing templates with matching IDs or names</span>
                    </label>
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:0.55em;">
                        <input type="radio" name="homepage_import_mode" value="replace">
                        <span><strong>Replace All</strong> — Replace all templates with imported ones (automatic backup created)</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="homepage-modal-footer">
            <div class="homepage-modal-actions" style="width:100%;display:flex;justify-content:space-between;align-items:center;">
                <button type="button" class="homepage-btn" onclick="homepageCloseImportModal()">Cancel</button>
                <button type="button" class="homepage-btn homepage-btn--apply" id="homepage-btn-submit-import" onclick="homepageSubmitImportTemplates()" disabled><i class="fa fa-upload"></i> Import Templates</button>
            </div>
        </div>
    </div>
</div>
HTML;
    return $html;
}

/**
 * Render Bulk Group Assignment Modal markup.
 *
 * @param array $existingGroups Existing group suggestions
 * @return string HTML markup
 */
function renderBulkGroupModal($existingGroups = []) {
    $html = <<<HTML
<div id="homepage-bulk-group-modal" class="homepage-modal-backdrop" style="display:none;" onclick="if(event.target===this)homepageCloseBulkGroupModal();">
    <div class="homepage-modal-dialog" style="max-width:36em;height:auto;">
        <div class="homepage-modal-header">
            <div class="homepage-modal-title-wrap">
                <i class="fa fa-folder homepage-modal-icon" style="color:#e65100;"></i>
                <h3 class="homepage-modal-title">Assign Homepage Group</h3>
            </div>
            <button type="button" class="homepage-modal-close" onclick="homepageCloseBulkGroupModal()">&times;</button>
        </div>

        <div class="homepage-modal-body" style="padding:1.4em;">
            <p style="margin-top:0;font-size:0.95em;">
                Assign a group to <strong id="homepage-bulk-group-count">0</strong> selected container(s):
            </p>
            <div id="homepage-bulk-group-list" class="homepage-import-detected-pills" style="margin-bottom:1.2em;max-height:6em;overflow-y:auto;"></div>

            <div style="margin-bottom:1em;">
                <label style="display:block;font-weight:600;font-size:0.9em;margin-bottom:0.4em;">Choose Existing Group:</label>
                <select id="homepage-bulk-group-select" style="width:100%;box-sizing:border-box;padding:0.5em;" onchange="$('#homepage-bulk-group-input').val(this.value)">
                    <option value="">-- Or enter a new group name below --</option>
HTML;
    foreach ($existingGroups as $grp) {
        $safeGrp = htmlspecialchars($grp);
        $html .= '<option value="' . $safeGrp . '">' . $safeGrp . '</option>' . "\n";
    }
    $html .= <<<HTML
                </select>
            </div>

            <div style="margin-bottom:1em;">
                <label style="display:block;font-weight:600;font-size:0.9em;margin-bottom:0.4em;">Target Group Name:</label>
                <input type="text" id="homepage-bulk-group-input" style="width:100%;box-sizing:border-box;padding:0.5em;" placeholder="e.g. Media, Management, Downloads">
                <small style="opacity:0.75;display:block;margin-top:0.3em;">Leaving this blank will remove the homepage.group label.</small>
            </div>
        </div>

        <div class="homepage-modal-footer">
            <div class="homepage-modal-actions" style="width:100%;display:flex;justify-content:space-between;align-items:center;">
                <button type="button" class="homepage-btn" onclick="homepageCloseBulkGroupModal()">Cancel</button>
                <button type="button" class="homepage-btn homepage-btn--apply" onclick="homepageSubmitBulkGroup()"><i class="fa fa-check"></i> Assign Group</button>
            </div>
        </div>
    </div>
</div>
HTML;
    return $html;
}





