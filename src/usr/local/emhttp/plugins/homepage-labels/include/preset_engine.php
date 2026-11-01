<?php
/**
 * preset_engine.php — Preset Matching & Resolution Logic
 *
 * Handles matching container repositories to preset configurations,
 * resolving icons, URLs, and ports, and merging custom presets.
 */

// Default preset file locations
if (!defined('DEFAULT_PRESETS_PATH')) {
    define('DEFAULT_PRESETS_PATH', '/usr/local/emhttp/plugins/homepage-labels/default_presets.json');
}
if (!defined('CUSTOM_PRESETS_PATH')) {
    define('CUSTOM_PRESETS_PATH', '/boot/config/plugins/homepage-labels/custom_presets.json');
}
if (!defined('USER_TEMPLATES_PATH')) {
    define('USER_TEMPLATES_PATH', '/boot/config/plugins/homepage-labels/user_templates.json');
}
if (!defined('PLUGIN_SETTINGS_PATH')) {
    define('PLUGIN_SETTINGS_PATH', '/boot/config/plugins/homepage-labels/settings.cfg');
}
if (!defined('PENDING_RECREATES_PATH')) {
    define('PENDING_RECREATES_PATH', '/boot/config/plugins/homepage-labels/pending_recreates.json');
}

/**
 * Load pending recreate container list.
 *
 * @param string|null $path
 * @return array Map of containerName => timestamp
 */
function loadPendingRecreates($path = null) {
    $filePath = $path ?? (defined('PENDING_RECREATES_PATH') ? PENDING_RECREATES_PATH : '/boot/config/plugins/homepage-labels/pending_recreates.json');
    if (file_exists($filePath)) {
        $json = @file_get_contents($filePath);
        if ($json) {
            $data = json_decode($json, true);
            if (is_array($data)) return $data;
        }
    }
    return [];
}

/**
 * Mark a container as pending recreation.
 *
 * @param string $containerName
 * @param string|null $path
 * @return bool
 */
function addPendingRecreate($containerName, $path = null) {
    $filePath = $path ?? (defined('PENDING_RECREATES_PATH') ? PENDING_RECREATES_PATH : '/boot/config/plugins/homepage-labels/pending_recreates.json');
    $data = loadPendingRecreates($filePath);
    $data[$containerName] = time();
    $dir = dirname($filePath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return @file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Remove a container from pending recreation list.
 *
 * @param string $containerName
 * @param string|null $path
 * @return bool
 */
function removePendingRecreate($containerName, $path = null) {
    $filePath = $path ?? (defined('PENDING_RECREATES_PATH') ? PENDING_RECREATES_PATH : '/boot/config/plugins/homepage-labels/pending_recreates.json');
    $data = loadPendingRecreates($filePath);
    if (isset($data[$containerName])) {
        unset($data[$containerName]);
        $dir = dirname($filePath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return @file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }
    return true;
}

/**
 * Check if a container is pending recreation.
 *
 * @param string $containerName
 * @param string|null $path
 * @return bool
 */
function isPendingRecreate($containerName, $path = null) {
    $filePath = $path ?? (defined('PENDING_RECREATES_PATH') ? PENDING_RECREATES_PATH : '/boot/config/plugins/homepage-labels/pending_recreates.json');
    $data = loadPendingRecreates($filePath);
    return !empty($data[$containerName]);
}

/**
 * Return default plugin settings.
 *
 * @return array
 */
function getDefaultPluginSettings() {
    return [
        'BASE_HOST'               => '',
        'BASE_SCHEME'             => 'http',
        'INTERNAL_URL_MODE'       => 'host_port',
        'DEFAULT_MASK_SECRETS'    => 'true',
        'HOMEPAGE_CONTAINER_NAME' => 'homepage',
        'BACKUP_BEFORE_WRITE'     => 'true',
        'BACKUP_RETENTION_HOURS'  => '24',
        'GROUP_LABELS_AT_BOTTOM'  => 'true',
        'CUSTOM_ICONS_HOST_DIR'       => '',
        'CUSTOM_ICONS_URL_PREFIX'     => '/icons/',
        'UI_FONT_SCALE'               => '100',
        'HOMEPAGE_SERVICES_FILE_PATH' => '/mnt/user/appdata/homepage/config/services.yaml',
        'DOCKER_EDIT_OVERLAY'         => 'true',
    ];
}

/**
 * Load plugin settings merged with defaults.
 *
 * @param string|null $path Optional path override to settings.cfg
 * @return array Merged settings array
 */
function loadPluginSettings($path = null) {
    $settingsPath = $path ?? (defined('PLUGIN_SETTINGS_PATH') ? PLUGIN_SETTINGS_PATH : '/boot/config/plugins/homepage-labels/settings.cfg');
    $defaults = getDefaultPluginSettings();
    if (file_exists($settingsPath)) {
        $saved = @parse_ini_file($settingsPath, false, INI_SCANNER_RAW);
        if (is_array($saved)) {
            $cleaned = array_map(function($val) {
                return is_string($val) ? stripslashes($val) : $val;
            }, $saved);
            return array_merge($defaults, $cleaned);
        }
    }
    return $defaults;
}

/**
 * Save plugin settings array to settings.cfg in INI format.
 *
 * @param array $settings Key-value array of settings to persist
 * @param string|null $path Optional path override to settings.cfg
 * @return bool True on success, false on failure
 */
function savePluginSettings(array $settings, $path = null) {
    $settingsPath = $path ?? (defined('PLUGIN_SETTINGS_PATH') ? PLUGIN_SETTINGS_PATH : '/boot/config/plugins/homepage-labels/settings.cfg');
    $dir = dirname($settingsPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $content = '';
    foreach ($settings as $key => $value) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
        $safeVal = addcslashes(str_replace(["\r", "\n"], '', (string)$value), '"\\');
        $content .= "{$safeKey}=\"{$safeVal}\"\n";
    }
    return @file_put_contents($settingsPath, $content) !== false;
}

/**
 * Load presets from a JSON file.
 *
 * @param string $path Path to the JSON presets file
 * @return array|null Decoded preset data, or null on failure
 */
function loadPresets($path) {
    if (!file_exists($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $data;
}

/**
 * Load and merge default + custom presets.
 * Custom presets override defaults with the same key.
 *
 * @return array Merged preset catalog with 'presets' key
 */
function loadAllPresets($forceReload = false) {
    static $cached = null;
    if ($cached !== null && !$forceReload) {
        return $cached;
    }

    $defaults = loadPresets(DEFAULT_PRESETS_PATH);
    $customs = loadPresets(CUSTOM_PRESETS_PATH);

    $defaultPresets = ($defaults && isset($defaults['presets'])) ? $defaults['presets'] : [];
    $customPresets = ($customs && isset($customs['presets'])) ? $customs['presets'] : [];

    // Custom presets override defaults with the same key
    $merged = array_merge($defaultPresets, $customPresets);

    $cached = [
        'version' => isset($defaults['version']) ? $defaults['version'] : '1.0',
        'presets' => $merged,
    ];

    return $cached;
}

/**
 * Match a container's repository string against available presets.
 *
 * Resolution order:
 * 1. Exact match on repository key
 * 2. Match via aliases array
 * 3. Keyword extraction (last path segment without version tags)
 *
 * @param string $repository Full repository string (e.g., "linuxserver/sonarr:latest")
 * @param array  $presets The presets array (key => preset data)
 * @return array|null Matched preset data with added 'match_key' field, or null
 */
function matchPreset($repository, $presets = null) {
    if ($presets === null) {
        $allPresets = loadAllPresets();
        $presets = $allPresets['presets'];
    }

    // Clean the repository string — strip tags like ":latest", ":v3"
    $cleanRepo = preg_replace('/:[\w\.\-]+$/', '', $repository);
    $cleanRepo = strtolower(trim($cleanRepo));

    // Step 1: Exact match
    foreach ($presets as $key => $preset) {
        if (strtolower($key) === $cleanRepo) {
            $preset['match_key'] = $key;
            $preset['match_type'] = 'exact';
            return $preset;
        }
    }

    // Step 2: Alias match
    foreach ($presets as $key => $preset) {
        if (isset($preset['aliases']) && is_array($preset['aliases'])) {
            foreach ($preset['aliases'] as $alias) {
                if (strtolower($alias) === $cleanRepo) {
                    $preset['match_key'] = $key;
                    $preset['match_type'] = 'alias';
                    return $preset;
                }
            }
        }
    }

    // Step 3: Keyword extraction — match on the image name (last path segment)
    $parts = explode('/', $cleanRepo);
    $imageName = end($parts);
    // Strip common prefixes like "arch-", "docker-"
    $keyword = preg_replace('/^(arch-|docker-|container-)/', '', $imageName);

    foreach ($presets as $key => $preset) {
        $presetParts = explode('/', strtolower($key));
        $presetImageName = end($presetParts);

        if ($keyword === $presetImageName) {
            $preset['match_key'] = $key;
            $preset['match_type'] = 'keyword';
            return $preset;
        }

        // Also check preset name (case-insensitive)
        if (isset($preset['name']) && strtolower($preset['name']) === $keyword) {
            $preset['match_key'] = $key;
            $preset['match_type'] = 'keyword_name';
            return $preset;
        }
    }

    return null;
}

/**
 * Scan a host directory for custom icon/image files.
 *
 * @param string $hostDir Host path (e.g., "/mnt/user/metadata/icons")
 * @param string $urlPrefix URL prefix inside container (e.g., "/icons/" or "/images/")
 * @return array List of icon items [ ['filename' => 'healarr.png', 'url_path' => '/icons/healarr.png', 'name' => 'healarr', 'ext' => 'png'], ... ]
 */
function getLocalCustomIcons($hostDir, $urlPrefix = '/icons/', $forceReload = false) {
    static $cache = [];
    $cacheKey = $hostDir . '|' . $urlPrefix;
    if (!$forceReload && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if (empty($hostDir) || !is_dir($hostDir)) {
        return [];
    }

    $hostDir = rtrim($hostDir, '/');
    $urlPrefix = '/' . trim($urlPrefix, '/') . '/';
    if ($urlPrefix === '//') {
        $urlPrefix = '/';
    }

    $pattern = $hostDir . '/*.{png,svg,webp,jpg,jpeg,ico,PNG,SVG,WEBP,JPG,JPEG,ICO}';
    $files = glob($pattern, GLOB_BRACE);
    if (!$files) {
        $cache[$cacheKey] = [];
        return [];
    }

    $icons = [];
    foreach ($files as $file) {
        $filename = basename($file);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $urlPath = ($urlPrefix === '/') ? ('/' . $filename) : ($urlPrefix . $filename);

        $icons[] = [
            'filename'  => $filename,
            'url_path'  => $urlPath,
            'name'      => $name,
            'ext'       => $ext,
        ];
    }

    usort($icons, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    $cache[$cacheKey] = $icons;
    return $icons;
}

/**
 * Find a local custom icon matching a container name or repository.
 *
 * @param string $containerName Container name or repo (e.g., "binhex-healarr", "healarr", "sonarr")
 * @param string $hostDir Host directory for icons
 * @param string $urlPrefix URL prefix inside container
 * @return string|null Matching url_path (e.g. "/icons/healarr.png") or null
 */
function matchLocalCustomIcon($containerName, $hostDir, $urlPrefix = '/icons/') {
    $icons = getLocalCustomIcons($hostDir, $urlPrefix);
    if (empty($icons)) {
        return null;
    }

    // Clean container name (strip prefixes like "binhex-", "linuxserver-", "docker-")
    $cleanName = strtolower(trim($containerName));
    $cleanName = preg_replace('/^(binhex|linuxserver|docker|app|container)-/i', '', $cleanName);
    $cleanName = preg_replace('/[^a-z0-9_-]/i', '', $cleanName);

    // Exact match on filename without extension
    foreach ($icons as $icon) {
        if (strcasecmp($icon['name'], $cleanName) === 0) {
            return $icon['url_path'];
        }
    }

    // Exact match with original container name
    $origClean = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $containerName));
    foreach ($icons as $icon) {
        if (strcasecmp($icon['name'], $origClean) === 0) {
            return $icon['url_path'];
        }
    }

    // Substring match
    foreach ($icons as $icon) {
        $iconName = strtolower($icon['name']);
        if ($cleanName !== '' && (strpos($cleanName, $iconName) !== false || strpos($iconName, $cleanName) !== false)) {
            return $icon['url_path'];
        }
    }

    return null;
}

/**
 * Resolve the Homepage icon for a container.
 *
 * Resolution order:
 * 1. Existing homepage.icon label in template XML
 * 2. Local custom icon match in CUSTOM_ICONS_HOST_DIR (if configured)
 * 3. Preset icon from default_presets.json
 * 4. Template <Icon> URL
 * 5. Fallback: "docker.png"
 *
 * @param array       $existingLabels Current homepage labels (from getHomepageLabels)
 * @param array|null  $preset Matched preset data
 * @param array       $containerMeta Container metadata (from getContainerMeta)
 * @param string      $containerName Container name (for local icon matching)
 * @param array       $settings Plugin settings
 * @return string Resolved icon value
 */
function resolveIcon($existingLabels, $preset, $containerMeta, $containerName = '', $settings = []) {
    // Step 1: Existing homepage.icon label
    if (isset($existingLabels['homepage.icon']) && !empty($existingLabels['homepage.icon']['value'])) {
        return $existingLabels['homepage.icon']['value'];
    }

    // Step 2: Check custom local icons folder if configured
    $hostDir = !empty($settings['CUSTOM_ICONS_HOST_DIR']) ? $settings['CUSTOM_ICONS_HOST_DIR'] : '';
    $urlPrefix = !empty($settings['CUSTOM_ICONS_URL_PREFIX']) ? $settings['CUSTOM_ICONS_URL_PREFIX'] : '/icons/';
    if (!empty($hostDir) && is_dir($hostDir) && !empty($containerName)) {
        $localMatch = matchLocalCustomIcon($containerName, $hostDir, $urlPrefix);
        if (!empty($localMatch)) {
            return $localMatch;
        }
    }

    // Step 3: Preset icon
    if ($preset && isset($preset['icon']) && !empty($preset['icon'])) {
        // If preset icon (e.g. "sonarr.svg") matches a file in local directory, return prefixed path
        if (!empty($hostDir) && is_dir($hostDir)) {
            $presetFilename = basename($preset['icon']);
            if (file_exists(rtrim($hostDir, '/') . '/' . $presetFilename)) {
                $pfx = '/' . trim($urlPrefix, '/') . '/';
                return ($pfx === '//') ? ('/' . $presetFilename) : ($pfx . $presetFilename);
            }
        }
        return $preset['icon'];
    }

    // Step 4: Template <Icon> URL
    if (isset($containerMeta['Icon']) && !empty($containerMeta['Icon'])) {
        return $containerMeta['Icon'];
    }

    // Step 5: Fallback
    return 'docker.png';
}

/**
 * Resolve the Unraid server's IPv4 address.
 *
 * @param array $settings Plugin settings
 * @return string IPv4 address
 */
function resolveServerIp($settings = []) {
    if (!empty($settings['BASE_HOST']) && filter_var($settings['BASE_HOST'], FILTER_VALIDATE_IP)) {
        return $settings['BASE_HOST'];
    }

    if (!empty($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1' && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP)) {
        return $_SERVER['SERVER_ADDR'];
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = explode(':', $_SERVER['HTTP_HOST'])[0];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
    }

    // Check Unraid network.cfg
    if (file_exists('/boot/config/network.cfg')) {
        $netCfg = @parse_ini_file('/boot/config/network.cfg', false, INI_SCANNER_RAW);
        if (!empty($netCfg['IPADDR[0]']) && filter_var($netCfg['IPADDR[0]'], FILTER_VALIDATE_IP)) {
            return $netCfg['IPADDR[0]'];
        }
        if (!empty($netCfg['IPADDR']) && filter_var($netCfg['IPADDR'], FILTER_VALIDATE_IP)) {
            return $netCfg['IPADDR'];
        }
    }

    $detected = @gethostbyname(gethostname());
    if (!empty($detected) && $detected !== '127.0.0.1' && filter_var($detected, FILTER_VALIDATE_IP)) {
        return $detected;
    }

    return '127.0.0.1';
}

/**
 * Resolve the Unraid server's Hostname / FQDN.
 *
 * @param array $settings Plugin settings
 * @return string Hostname / Domain
 */
function resolveServerHostname($settings = []) {
    if (!empty($settings['BASE_HOST']) && !filter_var($settings['BASE_HOST'], FILTER_VALIDATE_IP)) {
        return $settings['BASE_HOST'];
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
        if (preg_match('/^\[([a-fA-F0-9:]+)\](?::\d+)?$/', $host, $m)) {
            $host = $m[1];
        } else {
            $host = explode(':', $host)[0];
        }
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
    }

    // Check Unraid ident.cfg
    if (file_exists('/boot/config/ident.cfg')) {
        $ident = @parse_ini_file('/boot/config/ident.cfg', false, INI_SCANNER_RAW);
        if (!empty($ident['NAME'])) {
            $fqdn = $ident['NAME'];
            if (!empty($ident['DOMAIN'])) {
                $fqdn .= '.' . $ident['DOMAIN'];
            }
            return $fqdn;
        }
    }

    $hostname = @gethostname();
    return !empty($hostname) ? $hostname : 'tower.local';
}

/**
 * Resolve the base server host (defaults to IP if configured, else hostname/HTTP_HOST).
 *
 * @param array $settings Plugin settings
 * @return string Server IP/Host
 */
function resolveServerHost($settings = []) {
    $baseHost = !empty($settings['BASE_HOST']) ? trim($settings['BASE_HOST']) : '';
    if (!empty($baseHost)) {
        return $baseHost;
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $hostHeader = $_SERVER['HTTP_HOST'];
        if (preg_match('/^\[([a-fA-F0-9:]+)\](?::\d+)?$/', $hostHeader, $m)) {
            return $m[1];
        }
        $parts = explode(':', $hostHeader);
        if (!empty($parts[0])) {
            return $parts[0];
        }
    }

    return resolveServerIp($settings);
}

/**
 * Parse the <WebUI> node and resolve to a full URL.
 *
 * Handles Unraid's WebUI format: http://[IP]:[PORT:8080]/
 * Replaces [IP] with BASE_HOST and [PORT:xxxx] with the mapped host port.
 *
 * @param array $containerMeta Container metadata with 'WebUI' and 'Ports'
 * @param array $settings Plugin settings (BASE_HOST, BASE_SCHEME)
 * @return string Resolved URL, or empty string if unable to resolve
 */
function resolveUrl($containerMeta, $settings) {
    $webui = isset($containerMeta['WebUI']) ? $containerMeta['WebUI'] : '';

    if (empty($webui)) {
        return '';
    }

    $baseHost = resolveServerHost($settings);
    $formattedHost = filter_var($baseHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$baseHost}]" : $baseHost;
    $baseScheme = isset($settings['BASE_SCHEME']) ? $settings['BASE_SCHEME'] : 'http';

    // Replace [IP] with the configured or detected base host
    $url = str_replace('[IP]', $formattedHost, $webui);

    // Replace [PORT:xxxx] with the mapped host port
    if (preg_match('/\[PORT(?::(\d+))?\]/', $url, $matches)) {
        $ports = isset($containerMeta['Ports']) ? $containerMeta['Ports'] : [];

        if (isset($matches[1]) && $matches[1] !== '') {
            // [PORT:8080] — resolve specific container port to host port
            $containerPort = $matches[1];
            $hostPort = isset($ports[$containerPort]) ? $ports[$containerPort] : $containerPort;
        } else {
            // [PORT] — use the first available mapped host port
            $hostPort = !empty($ports) ? reset($ports) : '';
        }
        $url = str_replace($matches[0], $hostPort, $url);
    }

    // Clean up dangling colon if port was empty/removed (e.g. host networking or unmapped WebUI)
    $url = preg_replace('#:(?!//)(?=/|$)#', '', $url);

    // If the scheme was specified as a placeholder, replace it
    if (!preg_match('#^https?://#i', $url)) {
        $url = $baseScheme . '://' . ltrim($url, ':/');
    }

    return $url;
}

/**
 * Build the internal URL for a container based on INTERNAL_URL_MODE.
 *
 * @param string $containerName The container name
 * @param int    $containerPort The container's internal port
 * @param array  $containerMeta Container metadata with port mappings
 * @param array  $settings Plugin settings
 * @return string The constructed URL
 */
function resolveInternalUrl($containerName, $containerPort, $containerMeta, $settings) {
    $scheme = isset($settings['BASE_SCHEME']) ? $settings['BASE_SCHEME'] : 'http';
    $mode = isset($settings['INTERNAL_URL_MODE']) ? $settings['INTERNAL_URL_MODE'] : 'host_port';

    if ($mode === 'container_network') {
        // Use container name as hostname with internal port
        return "{$scheme}://{$containerName}:{$containerPort}";
    }

    // Default: host_port mode — use host IP and mapped port
    $baseHost = resolveServerHost($settings);
    $formattedHost = filter_var($baseHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$baseHost}]" : $baseHost;
    $ports = isset($containerMeta['Ports']) ? $containerMeta['Ports'] : [];
    $hostPort = isset($ports[(string)$containerPort]) ? $ports[(string)$containerPort] : $containerPort;

    return "{$scheme}://{$formattedHost}:{$hostPort}";
}

/**
 * Generate default label values for a container based on its preset.
 *
 * Creates a full set of labels with sensible defaults that can be
 * presented to the user for review before saving.
 *
 * @param string     $containerName The container name
 * @param array|null $preset Matched preset data
 * @param array      $containerMeta Container metadata
 * @param array      $existingLabels Existing homepage labels
 * @param array      $settings Plugin settings
 * @return array Suggested labels [target => ['value' => string, 'mask' => string, 'name' => string]]
 */
function generateDefaultLabels($containerName, $preset, $containerMeta, $existingLabels, $settings) {
    $labels = [];

    // --- Core Discovery Labels ---

    // homepage.group
    $group = '';
    if (isset($existingLabels['homepage.group']) && trim((string)$existingLabels['homepage.group']['value']) !== '') {
        $group = $existingLabels['homepage.group']['value'];
    } elseif ($preset && isset($preset['default_group']) && trim((string)$preset['default_group']) !== '') {
        $group = $preset['default_group'];
    } else {
        $group = 'Services';
    }
    $labels['homepage.group'] = [
        'value' => $group,
        'mask'  => 'false',
        'name'  => 'Homepage Group',
    ];

    // homepage.name
    $displayName = '';
    if (isset($existingLabels['homepage.name'])) {
        $displayName = $existingLabels['homepage.name']['value'];
    } elseif ($preset && isset($preset['name'])) {
        $displayName = $preset['name'];
    } else {
        $displayName = ucfirst($containerName);
    }
    $labels['homepage.name'] = [
        'value' => $displayName,
        'mask'  => 'false',
        'name'  => 'Homepage Name',
    ];

    // homepage.icon
    $icon = resolveIcon($existingLabels, $preset, $containerMeta, $containerName, $settings);
    $labels['homepage.icon'] = [
        'value' => $icon,
        'mask'  => 'false',
        'name'  => 'Homepage Icon',
    ];

    // homepage.href
    $href = '';
    if (isset($existingLabels['homepage.href'])) {
        $href = $existingLabels['homepage.href']['value'];
    } else {
        $href = resolveUrl($containerMeta, $settings);
    }
    $labels['homepage.href'] = [
        'value' => $href,
        'mask'  => 'false',
        'name'  => 'Homepage URL',
    ];

    // --- Widget Labels ---
    if ($preset && isset($preset['widget'])) {
        $widget = $preset['widget'];

        // homepage.widget.type
        $labels['homepage.widget.type'] = [
            'value' => $widget['type'],
            'mask'  => 'false',
            'name'  => 'Homepage Widget Type',
        ];

        // Widget fields from preset metadata
        if (isset($widget['field_metadata'])) {
            foreach ($widget['field_metadata'] as $fieldTarget => $fieldMeta) {
                // Preserve existing values
                if (isset($existingLabels[$fieldTarget])) {
                    $labels[$fieldTarget] = $existingLabels[$fieldTarget];
                    continue;
                }

                // Generate default value
                $defaultValue = '';
                if (isset($fieldMeta['default'])) {
                    $defaultValue = $fieldMeta['default'];
                } elseif (isset($fieldMeta['default_from']) && $fieldMeta['default_from'] === 'webui') {
                    // Auto-populate URL from WebUI
                    $port = $preset['default_port'] ?? 0;
                    if ($port > 0) {
                        $defaultValue = resolveInternalUrl($containerName, $port, $containerMeta, $settings);
                    } else {
                        $defaultValue = resolveUrl($containerMeta, $settings);
                    }
                }

                $shouldMask = isset($fieldMeta['mask']) && $fieldMeta['mask'] === true ? 'true' : 'false';

                // Check global mask setting for sensitive fields
                if (isset($settings['DEFAULT_MASK_SECRETS']) && $settings['DEFAULT_MASK_SECRETS'] === 'true') {
                    if ($fieldMeta['type'] === 'password') {
                        $shouldMask = 'true';
                    }
                }

                $labels[$fieldTarget] = [
                    'value' => $defaultValue,
                    'mask'  => $shouldMask,
                    'name'  => 'Homepage ' . (isset($fieldMeta['label']) ? $fieldMeta['label'] : $fieldTarget),
                ];
            }
        }
    }

    return $labels;
}

/**
 * Get all available group names from existing containers.
 *
 * Uses fast regex scanning instead of full DOM parsing for each template XML.
 * Used for autocomplete suggestions in the UI.
 *
 * @return array Unique group names sorted alphabetically
 */
function getExistingGroups() {
    require_once __DIR__ . '/xml_parser.php';

    $groups = [];
    $files = glob(TEMPLATES_PATH . '/my-*.xml');
    if (!$files) {
        return [];
    }

    foreach ($files as $file) {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->load($file, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            continue;
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//Config[@Target="homepage.group"]');
        if ($nodes && $nodes->length > 0) {
            $val = trim($nodes->item(0)->textContent);
            if ($val !== '') {
                $groups[] = $val;
            }
        }
    }

    $groups = array_values(array_unique($groups));
    sort($groups);

    return $groups;
}

/**
 * Get the master catalog of all known Homepage discovery labels.
 *
 * @return array Master field definitions grouped by section
 */
function getAllKnownHomepageFields() {
    return [
        'core' => [
            'homepage.group' => [
                'label'       => 'Group',
                'type'        => 'text',
                'required'    => true,
                'mask'        => false,
                'placeholder' => 'e.g. Media, Downloads',
                'help'        => 'Homepage section / category group.',
            ],
            'homepage.name' => [
                'label'       => 'Display Name',
                'type'        => 'text',
                'required'    => true,
                'mask'        => false,
                'placeholder' => 'Service display name',
                'help'        => 'Friendly service name displayed on the Homepage tile.',
            ],
            'homepage.icon' => [
                'label'       => 'Homepage Icon',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g. sonarr.svg, plex.png, or URL',
                'help'        => 'Dashboard icon name or image URL.',
            ],
            'homepage.href' => [
                'label'       => 'External URL',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'http://IP:PORT',
                'help'        => 'URL opened when clicking the tile in Homepage.',
                'quickfill'   => true,
            ],
        ],
        'display' => [
            'homepage.description' => [
                'label'       => 'Description / Subtitle',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., Media Management Server',
                'help'        => 'Secondary subtitle text displayed on the service tile.',
            ],
            'homepage.weight' => [
                'label'       => 'Sort Weight',
                'type'        => 'number',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., 10, 20',
                'help'        => 'Ordering weight within the category group (lower numbers appear first).',
            ],
            'homepage.target' => [
                'label'       => 'Link Target',
                'type'        => 'select',
                'options'     => ['_blank', '_self', '_top'],
                'required'    => false,
                'mask'        => false,
                'default'     => '_blank',
                'help'        => 'How the external URL opens (_blank opens in new tab).',
            ],
            'homepage.statusStyle' => [
                'label'       => 'Status Style',
                'type'        => 'select',
                'options'     => ['dot', 'badge'],
                'required'    => false,
                'mask'        => false,
                'default'     => 'dot',
                'help'        => 'Visual style of the health check status indicator.',
            ],
            'homepage.pod' => [
                'label'       => 'Pod Name',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., Media, Infrastructure',
                'help'        => 'Homepage pod grouping name for multi-pod organization.',
            ],
            'homepage.tab' => [
                'label'       => 'Navigation Tab',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., Home, Media, Admin',
                'help'        => 'Homepage tab name for multi-page layouts in Homepage v0.8+.',
            ],
        ],
        'health' => [
            'homepage.siteMonitor' => [
                'label'       => 'Site Monitor URL',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'http://IP:PORT',
                'help'        => 'HTTP endpoint that Homepage will poll for live service status.',
                'quickfill'   => true,
            ],
            'homepage.ping' => [
                'label'       => 'Ping Target',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'IP or hostname to ping',
                'help'        => 'Host or IP address for ICMP / TCP ping status monitoring.',
            ],
            'homepage.pingInterval' => [
                'label'       => 'Ping Interval (ms)',
                'type'        => 'number',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., 30000',
                'help'        => 'Interval in milliseconds between ping checks.',
            ],
            'homepage.showStats' => [
                'label'       => 'Show Container Stats',
                'type'        => 'select',
                'options'     => ['true', 'false'],
                'required'    => false,
                'mask'        => false,
                'default'     => 'true',
                'help'        => 'Display live CPU and Memory usage on the Homepage card.',
            ],
            'homepage.siteMonitor.method' => [
                'label'       => 'Probe Method',
                'type'        => 'select',
                'options'     => ['GET', 'HEAD', 'POST'],
                'required'    => false,
                'mask'        => false,
                'default'     => 'GET',
                'help'        => 'HTTP request method used by the site monitor probe.',
            ],
            'homepage.siteMonitor.status' => [
                'label'       => 'Expected HTTP Status',
                'type'        => 'number',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., 200, 302',
                'help'        => 'Expected HTTP response status code for healthy status.',
            ],
            'homepage.siteMonitor.headers' => [
                'label'       => 'Request Headers (JSON)',
                'type'        => 'text',
                'required'    => false,
                'mask'        => true,
                'placeholder' => '{"CF-Access-Client-Id":"..."}',
                'help'        => 'Custom request headers for authenticated reverse proxies.',
            ],
        ],
        'widget_known' => [
            'homepage.widget.type' => [
                'label'       => 'Widget Type',
                'type'        => 'text',
                'required'    => true,
                'mask'        => false,
                'placeholder' => 'e.g. sonarr, qbittorrent, plex',
                'help'        => 'Homepage widget identifier.',
            ],
            'homepage.widget.url' => [
                'label'       => 'Widget API URL',
                'type'        => 'text',
                'required'    => true,
                'mask'        => false,
                'placeholder' => 'http://IP:PORT',
                'help'        => 'Internal or external API endpoint for the widget.',
                'quickfill'   => true,
            ],
            'homepage.widget.fields' => [
                'label'       => 'Widget Display Fields',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., ["rate", "active"] or comma-separated',
                'help'        => 'Selectable JSON or comma-separated array of metrics to render on the widget.',
            ],
            'homepage.widget.key' => [
                'label'       => 'API Key / Token',
                'type'        => 'password',
                'required'    => false,
                'mask'        => true,
                'placeholder' => 'API Key or Token',
                'help'        => 'Secret API key or access token.',
            ],
            'homepage.widget.token' => [
                'label'       => 'Access Token',
                'type'        => 'password',
                'required'    => false,
                'mask'        => true,
                'placeholder' => 'Long-lived token',
                'help'        => 'Alternative token parameter for services using token auth.',
            ],
            'homepage.widget.username' => [
                'label'       => 'Username',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'Username / Token ID',
                'help'        => 'Basic authentication username or Token ID.',
            ],
            'homepage.widget.password' => [
                'label'       => 'Password',
                'type'        => 'password',
                'required'    => false,
                'mask'        => true,
                'placeholder' => 'Password / Secret',
                'help'        => 'Basic authentication password or API secret.',
            ],
            'homepage.widget.accountid' => [
                'label'       => 'Account ID (Cloudflare)',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'Cloudflare Account ID',
                'help'        => 'Cloudflare account identifier.',
            ],
            'homepage.widget.tunnelid' => [
                'label'       => 'Tunnel ID (Cloudflare)',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'Cloudflare Tunnel UUID',
                'help'        => 'Cloudflare tunnel UUID.',
            ],
            'homepage.widget.apiKey' => [
                'label'       => 'External API Key (Weather)',
                'type'        => 'password',
                'required'    => false,
                'mask'        => true,
                'placeholder' => 'OpenWeather API Key',
                'help'        => 'API key for weather widgets.',
            ],
            'homepage.widget.latitude' => [
                'label'       => 'Latitude',
                'type'        => 'number',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., 40.7128',
                'help'        => 'Geographic latitude coordinate.',
            ],
            'homepage.widget.longitude' => [
                'label'       => 'Longitude',
                'type'        => 'number',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., -74.0060',
                'help'        => 'Geographic longitude coordinate.',
            ],
            'homepage.widget.units' => [
                'label'       => 'Units',
                'type'        => 'select',
                'options'     => ['metric', 'imperial', 'standard'],
                'required'    => false,
                'mask'        => false,
                'default'     => 'metric',
                'help'        => 'Measurement units system.',
            ],
            'homepage.widget.tailnet' => [
                'label'       => 'Tailnet Name (Tailscale)',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., example.ts.net',
                'help'        => 'Tailscale organization or tailnet domain.',
            ],
            'homepage.widget.serverId' => [
                'label'       => 'Server ID (Speedtest)',
                'type'        => 'number',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'Speedtest Server ID',
                'help'        => 'Speedtest Tracker server identification number.',
            ],
            'homepage.widget.enableQueue' => [
                'label'       => 'Enable Queue Display',
                'type'        => 'select',
                'options'     => ['true', 'false'],
                'required'    => false,
                'mask'        => false,
                'default'     => 'true',
                'help'        => 'Show active activity queue on media widgets.',
            ],
            'homepage.widget.version' => [
                'label'       => 'API Version',
                'type'        => 'text',
                'required'    => false,
                'mask'        => false,
                'placeholder' => 'e.g., 1, 2, 5, 6',
                'help'        => 'API version specification for widgets supporting multiple versions.',
            ],
        ],
    ];
}

/**
 * Load user-defined custom templates from JSON with file locking.
 *
 * @return array Array of user templates
 */
function loadUserTemplates() {
    if (!file_exists(USER_TEMPLATES_PATH)) {
        return ['version' => '1.0', 'templates' => []];
    }

    $fp = @fopen(USER_TEMPLATES_PATH, 'rb');
    if (!$fp) {
        return ['version' => '1.0', 'templates' => []];
    }

    @flock($fp, LOCK_SH);
    $json = @stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);

    if (empty($json)) {
        return ['version' => '1.0', 'templates' => []];
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return ['version' => '1.0', 'templates' => []];
    }

    if (!isset($data['templates']) || !is_array($data['templates'])) {
        $data['templates'] = [];
    }

    return $data;
}

/**
 * Save user custom templates atomically with file locking.
 *
 * @param array $data Full template structure ['version' => '1.0', 'templates' => [...]]
 * @return bool True on success
 */
function saveUserTemplates($data) {
    $dir = dirname(USER_TEMPLATES_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmpPath = USER_TEMPLATES_PATH . '.tmp.' . uniqid('', true);
    if (@file_put_contents($tmpPath, $json, LOCK_EX) === false) {
        @unlink($tmpPath);
        return false;
    }

    // Atomic file replacement to eliminate unsafe in-place truncate
    if (!@rename($tmpPath, USER_TEMPLATES_PATH)) {
        @copy($tmpPath, USER_TEMPLATES_PATH);
        @unlink($tmpPath);
    }

    return true;
}

/**
 * Clone a default preset as a custom user template.
 *
 * @param string $presetKey The preset key (e.g., "linuxserver/sonarr")
 * @param string $customName User-defined template name
 * @return array Result status ['success' => bool, 'name' => string, 'error' => string|null]
 */
function cloneDefaultAsUserTemplate($presetKey, $customName) {
    $customName = trim($customName);
    if (empty($customName)) {
        return ['success' => false, 'error' => 'Template name cannot be empty.'];
    }

    $allPresetsData = loadAllPresets();
    $presets = $allPresetsData['presets'];

    if (!isset($presets[$presetKey])) {
        return ['success' => false, 'error' => 'Source preset not found: ' . $presetKey];
    }

    $preset = $presets[$presetKey];
    $userTemplatesData = loadUserTemplates();
    $templates = $userTemplatesData['templates'];

    // Generate safe unique key
    $baseKey = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '-', $customName));
    $key = $baseKey;
    $counter = 2;
    while (isset($templates[$key])) {
        $key = "{$baseKey}-{$counter}";
        $counter++;
    }

    // Build template field values from preset
    $fields = [];
    $fields['homepage.group'] = [
        'value'  => $preset['default_group'] ?? '',
        'mask'   => 'false',
        'active' => !empty($preset['default_group']),
    ];
    $fields['homepage.name'] = [
        'value'  => $preset['name'] ?? '',
        'mask'   => 'false',
        'active' => !empty($preset['name']),
    ];
    $fields['homepage.icon'] = [
        'value'  => $preset['icon'] ?? '',
        'mask'   => 'false',
        'active' => !empty($preset['icon']),
    ];
    $fields['homepage.href'] = [
        'value'  => '',
        'mask'   => 'false',
        'active' => false,
    ];

    $hasWidget = isset($preset['widget']);
    if ($hasWidget) {
        $widget = $preset['widget'];
        $fields['homepage.widget.type'] = [
            'value'  => $widget['type'] ?? '',
            'mask'   => 'false',
            'active' => true,
        ];
        if (isset($widget['field_metadata'])) {
            foreach ($widget['field_metadata'] as $target => $meta) {
                $fields[$target] = [
                    'value'  => $meta['default'] ?? '',
                    'mask'   => (!empty($meta['mask'])) ? 'true' : 'false',
                    'active' => true,
                ];
            }
        }
    }

    $templates[$key] = [
        'id'             => $key,
        'name'           => $customName,
        'created'        => date('c'),
        'modified'       => date('c'),
        'cloned_from'    => $presetKey,
        'widget_enabled' => $hasWidget,
        'fields'         => $fields,
    ];

    $userTemplatesData['templates'] = $templates;
    if (!saveUserTemplates($userTemplatesData)) {
        return ['success' => false, 'error' => 'Failed to save template to disk.'];
    }

    return ['success' => true, 'id' => $key, 'name' => $customName];
}

/**
 * Save or update a user custom template from form data.
 *
 * @param string $name Template display name
 * @param array  $fields Form fields [target => ['value' => string, 'mask' => string, 'active' => bool]]
 * @param bool   $widgetEnabled Whether widget is enabled
 * @param string $templateId Optional template ID to update
 * @return array Result ['success' => bool, 'id' => string, 'error' => string|null]
 */
function saveCustomUserTemplate($name, $fields, $widgetEnabled = false, $templateId = '') {
    $name = trim($name);
    if (empty($name)) {
        return ['success' => false, 'error' => 'Template name cannot be empty.'];
    }

    $userTemplatesData = loadUserTemplates();
    $templates = $userTemplatesData['templates'];

    if (!empty($templateId) && isset($templates[$templateId])) {
        $key = $templateId;
    } else {
        $baseKey = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name));
        $key = $baseKey;
        $counter = 2;
        while (isset($templates[$key])) {
            $key = "{$baseKey}-{$counter}";
            $counter++;
        }
    }

    $cleanFields = [];
    foreach ($fields as $target => $data) {
        if (strpos($target, 'homepage.') !== 0) continue;
        $fieldEntry = [
            'value'  => isset($data['value']) ? (string)$data['value'] : '',
            'mask'   => (isset($data['mask']) && $data['mask'] === 'true') ? 'true' : 'false',
            'active' => !empty($data['active']),
        ];
        if (isset($data['name']) && trim($data['name']) !== '') {
            $fieldEntry['name'] = trim((string)$data['name']);
        }
        if (isset($data['default'])) {
            $fieldEntry['default'] = (string)$data['default'];
        }
        if (isset($data['description']) && trim($data['description']) !== '') {
            $fieldEntry['description'] = trim((string)$data['description']);
        }
        if (isset($data['display']) && in_array(trim($data['display']), ['always', 'advanced', 'hidden'], true)) {
            $fieldEntry['display'] = trim((string)$data['display']);
        }
        if (isset($data['required'])) {
            $fieldEntry['required'] = ($data['required'] === 'true' || $data['required'] === true || $data['required'] === '1') ? 'true' : 'false';
        }
        $cleanFields[$target] = $fieldEntry;
    }

    $templates[$key] = [
        'id'             => $key,
        'name'           => $name,
        'created'        => isset($templates[$key]['created']) ? $templates[$key]['created'] : date('c'),
        'modified'       => date('c'),
        'cloned_from'    => isset($templates[$key]['cloned_from']) ? $templates[$key]['cloned_from'] : 'custom',
        'widget_enabled' => $widgetEnabled,
        'fields'         => $cleanFields,
    ];

    $userTemplatesData['templates'] = $templates;
    if (!saveUserTemplates($userTemplatesData)) {
        return ['success' => false, 'error' => 'Failed to write template to user_templates.json'];
    }

    return ['success' => true, 'id' => $key, 'name' => $name];
}

/**
 * Rename an existing user custom template.
 *
 * @param string $templateId Template identifier
 * @param string $newName New display name
 * @return array Result ['success' => bool, 'error' => string|null]
 */
function renameUserTemplate($templateId, $newName) {
    $newName = trim($newName);
    if (empty($newName)) {
        return ['success' => false, 'error' => 'New template name cannot be empty.'];
    }

    $userTemplatesData = loadUserTemplates();
    if (!isset($userTemplatesData['templates'][$templateId])) {
        return ['success' => false, 'error' => 'Template not found: ' . $templateId];
    }

    $userTemplatesData['templates'][$templateId]['name'] = $newName;
    $userTemplatesData['templates'][$templateId]['modified'] = date('c');

    if (!saveUserTemplates($userTemplatesData)) {
        return ['success' => false, 'error' => 'Failed to save updated template name.'];
    }

    return ['success' => true];
}

/**
 * Delete a user custom template.
 *
 * @param string $templateId Template identifier
 * @return array Result ['success' => bool, 'error' => string|null]
 */
function deleteUserTemplate($templateId) {
    $userTemplatesData = loadUserTemplates();
    if (!isset($userTemplatesData['templates'][$templateId])) {
        return ['success' => false, 'error' => 'Template not found: ' . $templateId];
    }

    unset($userTemplatesData['templates'][$templateId]);

    if (!saveUserTemplates($userTemplatesData)) {
        return ['success' => false, 'error' => 'Failed to remove template from user_templates.json'];
    }

    return ['success' => true];
}

/**
 * Export user custom templates structure for backup or community sharing.
 *
 * @param string|null $templateId Specific template ID or null for all templates
 * @return array ['success' => bool, 'data' => array, 'filename' => string, 'error' => string|null]
 */
function exportUserTemplates($templateId = null) {
    $userTemplatesData = loadUserTemplates();
    $allTemplates = $userTemplatesData['templates'] ?? [];

    if (!empty($templateId)) {
        if (!isset($allTemplates[$templateId])) {
            return ['success' => false, 'error' => 'Template not found: ' . $templateId];
        }
        $templatesToExport = [$templateId => $allTemplates[$templateId]];
        $filename = 'homepage-template-' . preg_replace('/[^a-zA-Z0-9_\-]/', '-', $templateId) . '-' . date('Ymd') . '.json';
    } else {
        $templatesToExport = $allTemplates;
        $filename = 'homepage-templates-backup-' . date('Ymd_His') . '.json';
    }

    $exportPayload = [
        'version'     => '1.0',
        'exported_at' => date('c'),
        'plugin'      => 'homepage-labels',
        'count'       => count($templatesToExport),
        'templates'   => $templatesToExport,
    ];

    return [
        'success'  => true,
        'data'     => $exportPayload,
        'filename' => $filename,
    ];
}

/**
 * Import custom templates from parsed array or JSON string with conflict resolution.
 *
 * @param array|string $input Raw JSON string or parsed array
 * @param string       $mode 'merge' | 'overwrite' | 'replace'
 * @return array ['success' => bool, 'imported_count' => int, 'total_count' => int, 'backup_path' => string|null, 'templates' => array, 'error' => string|null]
 */
function importUserTemplates($input, $mode = 'merge') {
    if (is_string($input)) {
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return ['success' => false, 'error' => 'Invalid JSON formatting: ' . json_last_error_msg()];
        }
    } elseif (is_array($input)) {
        $data = $input;
    } else {
        return ['success' => false, 'error' => 'Invalid import payload.'];
    }

    // Determine templates list: supports { templates: { ... } } or { ... } directly
    $incomingTemplates = [];
    if (isset($data['templates']) && is_array($data['templates'])) {
        $incomingTemplates = $data['templates'];
    } elseif (!empty($data) && !isset($data['version'])) {
        $incomingTemplates = $data;
    }

    if (empty($incomingTemplates)) {
        return ['success' => false, 'error' => 'No templates found in import data.'];
    }

    // Automated backup before modifying user_templates.json
    $backupPath = null;
    if (file_exists(USER_TEMPLATES_PATH)) {
        $backupDir = dirname(USER_TEMPLATES_PATH) . '/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }
        $backupFile = $backupDir . '/user_templates_' . date('Ymd_His') . '.json.bak';
        if (@copy(USER_TEMPLATES_PATH, $backupFile)) {
            $backupPath = $backupFile;
        }
    }

    $userTemplatesData = loadUserTemplates();
    $existingTemplates = $userTemplatesData['templates'] ?? [];

    $importedCount = 0;
    $updatedTemplates = ($mode === 'replace') ? [] : $existingTemplates;

    foreach ($incomingTemplates as $key => $tpl) {
        if (!is_array($tpl) || empty($tpl['name'])) {
            continue;
        }

        $name = trim($tpl['name']);
        $rawFields = $tpl['fields'] ?? [];
        $widgetEnabled = !empty($tpl['widget_enabled']);
        $clonedFrom = $tpl['cloned_from'] ?? 'imported';

        // Sanitize fields
        $cleanFields = [];
        foreach ($rawFields as $target => $fieldData) {
            if (strpos($target, 'homepage.') !== 0) continue;
            $cleanFields[$target] = [
                'value'  => isset($fieldData['value']) ? (string)$fieldData['value'] : '',
                'mask'   => (isset($fieldData['mask']) && ($fieldData['mask'] === 'true' || $fieldData['mask'] === true)) ? 'true' : 'false',
                'active' => !empty($fieldData['active']),
            ];
            if (isset($fieldData['name'])) $cleanFields[$target]['name'] = (string)$fieldData['name'];
            if (isset($fieldData['default'])) $cleanFields[$target]['default'] = (string)$fieldData['default'];
            if (isset($fieldData['description'])) $cleanFields[$target]['description'] = (string)$fieldData['description'];
            if (isset($fieldData['display'])) $cleanFields[$target]['display'] = (string)$fieldData['display'];
            if (isset($fieldData['required'])) $cleanFields[$target]['required'] = ($fieldData['required'] === 'true' || $fieldData['required'] === true) ? 'true' : 'false';
        }

        $rawId = !empty($tpl['id']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$tpl['id']) : '';
        $fallbackKey = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name));
        if (empty($fallbackKey)) {
            $fallbackKey = 'tpl-' . substr(md5(uniqid('', true)), 0, 8);
        }
        $baseKey = !empty($rawId) ? $rawId : $fallbackKey;

        if ($mode === 'replace') {
            $tplKey = $baseKey;
            $counter = 2;
            while (isset($updatedTemplates[$tplKey])) {
                $tplKey = "{$baseKey}-{$counter}";
                $counter++;
            }
        } elseif ($mode === 'overwrite') {
            $matchedKey = null;
            if (!empty($rawId) && isset($updatedTemplates[$rawId])) {
                $matchedKey = $rawId;
            } else {
                foreach ($updatedTemplates as $k => $ex) {
                    if (strcasecmp($ex['name'], $name) === 0) {
                        $matchedKey = $k;
                        break;
                    }
                }
            }
            if ($matchedKey) {
                $tplKey = $matchedKey;
            } else {
                $tplKey = $baseKey;
                $counter = 2;
                while (isset($updatedTemplates[$tplKey])) {
                    $tplKey = "{$baseKey}-{$counter}";
                    $counter++;
                }
            }
        } else {
            // Default: merge
            $tplKey = $baseKey;
            $counter = 2;
            while (isset($updatedTemplates[$tplKey])) {
                $tplKey = "{$baseKey}-{$counter}";
                $counter++;
            }
            $nameExists = false;
            foreach ($updatedTemplates as $ex) {
                if (strcasecmp($ex['name'], $name) === 0) {
                    $nameExists = true;
                    break;
                }
            }
            if ($nameExists) {
                $name .= ' (Imported)';
            }
        }

        $updatedTemplates[$tplKey] = [
            'id'             => $tplKey,
            'name'           => $name,
            'created'        => $tpl['created'] ?? date('c'),
            'modified'       => date('c'),
            'cloned_from'    => $clonedFrom,
            'widget_enabled' => $widgetEnabled,
            'fields'         => $cleanFields,
        ];
        $importedCount++;
    }

    if ($importedCount === 0) {
        return ['success' => false, 'error' => 'No valid template entries could be processed.'];
    }

    $userTemplatesData['templates'] = $updatedTemplates;
    if (!saveUserTemplates($userTemplatesData)) {
        return ['success' => false, 'error' => 'Failed to write imported templates to user_templates.json.'];
    }

    return [
        'success'        => true,
        'imported_count' => $importedCount,
        'total_count'    => count($updatedTemplates),
        'backup_path'    => $backupPath,
        'templates'      => $updatedTemplates,
    ];
}

/**
 * Calculate quick-fill URL suggestions for a container.
 *
 * @param string $containerName Container name
 * @param array  $containerMeta Container metadata (Ports, WebUI)
 * @param array  $settings Plugin settings (BASE_HOST, BASE_SCHEME)
 * @param int    $defaultPort Optional default port from preset
 * @return array ['hostUrl' => string, 'containerUrl' => string, 'webuiUrl' => string]
 */
function getQuickFillUrls($containerName, $containerMeta, $settings, $defaultPort = 0) {
    $scheme = !empty($settings['BASE_SCHEME']) ? $settings['BASE_SCHEME'] : 'http';
    $serverIp = resolveServerIp($settings);
    $serverHost = resolveServerHostname($settings);
    $ports = isset($containerMeta['Ports']) ? $containerMeta['Ports'] : [];

    // WebUI URL
    $webuiUrl = resolveUrl($containerMeta, $settings);

    // Host mapped port & internal port
    $hostPort = '';
    $internalPort = '';
    if ($defaultPort > 0 && isset($ports[(string)$defaultPort])) {
        $hostPort = $ports[(string)$defaultPort];
        $internalPort = $defaultPort;
    } elseif (!empty($ports)) {
        $hostPort = reset($ports);
        $internalPort = key($ports);
    } elseif ($defaultPort > 0) {
        $hostPort = $defaultPort;
        $internalPort = $defaultPort;
    }

    $portSuffix = $hostPort ? ":{$hostPort}" : '';
    $ipUrl = "{$scheme}://{$serverIp}{$portSuffix}";
    $hostUrl = "{$scheme}://{$serverHost}{$portSuffix}";
    $containerUrl = "{$scheme}://{$containerName}" . ($internalPort ? ":{$internalPort}" : '');

    return [
        'ipUrl'        => $ipUrl,
        'hostUrl'      => $hostUrl,
        'containerUrl' => $containerUrl,
        'webuiUrl'     => $webuiUrl ?: $hostUrl,
    ];
}

/* =========================================================================
   LABEL BACKUPS, SNAPSHOTS & UNDO / RESTORE ENGINE
   ========================================================================= */

if (!defined('LABEL_BACKUPS_DIR')) {
    define('LABEL_BACKUPS_DIR', '/boot/config/plugins/homepage-labels/backups');
}

/**
 * Get filesystem path for a container's label backup file.
 *
 * @param string $containerName
 * @return string
 */
function getLabelBackupPath($containerName) {
    require_once __DIR__ . '/xml_parser.php';
    return LABEL_BACKUPS_DIR . '/' . sanitizeContainerKey($containerName) . '.json';
}

/**
 * Save a snapshot backup of container labels before disabling/overwriting.
 *
 * @param string $containerName
 * @param array $labels Key-value array of labels
 * @param string $reason
 * @return bool
 */
function saveLabelBackup($containerName, $labels, $reason = 'manual') {
    if (empty($containerName) || empty($labels)) {
        return false;
    }
    if (!is_dir(LABEL_BACKUPS_DIR)) {
        @mkdir(LABEL_BACKUPS_DIR, 0755, true);
    }
    $backupFile = getLabelBackupPath($containerName);
    $payload = [
        'container' => $containerName,
        'timestamp' => time(),
        'date'      => date('Y-m-d H:i:s'),
        'reason'    => $reason,
        'labels'    => $labels,
    ];
    return file_put_contents($backupFile, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

/**
 * Get snapshot backup of container labels.
 *
 * @param string $containerName
 * @param int $retentionHours (0 = forever, otherwise expires after X hours)
 * @return array|null
 */
function getLabelBackup($containerName, $retentionHours = 24) {
    $backupFile = getLabelBackupPath($containerName);
    if (!file_exists($backupFile)) {
        return null;
    }
    $json = @file_get_contents($backupFile);
    if (!$json) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['labels'])) {
        return null;
    }
    if ($retentionHours > 0 && isset($data['timestamp'])) {
        $ageSeconds = time() - intval($data['timestamp']);
        if ($ageSeconds > ($retentionHours * 3600)) {
            @unlink($backupFile);
            return null;
        }
    }
    return $data;
}

/**
 * Check if a container has a valid label backup.
 *
 * @param string $containerName
 * @param int $retentionHours
 * @return bool
 */
function hasLabelBackup($containerName, $retentionHours = 24) {
    return getLabelBackup($containerName, $retentionHours) !== null;
}

/**
 * Delete a container's label backup snapshot.
 *
 * @param string $containerName
 * @return bool
 */
function deleteLabelBackup($containerName) {
    $backupFile = getLabelBackupPath($containerName);
    if (file_exists($backupFile)) {
        return @unlink($backupFile);
    }
    return true;
}

/**
 * Purge expired label backups.
 *
 * @param int $retentionHours
 * @return int Number of purged files
 */
function purgeExpiredBackups($retentionHours = 24) {
    if (!is_dir(LABEL_BACKUPS_DIR) || $retentionHours <= 0) {
        return 0;
    }
    $files = glob(LABEL_BACKUPS_DIR . '/*.json');
    if (!$files) return 0;
    $purged = 0;
    $now = time();
    $maxAge = $retentionHours * 3600;
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $maxAge) {
            @unlink($file);
            $purged++;
        }
    }
    return $purged;
}



/**
 * Probe an HTTP/HTTPS endpoint for connection testing with latency and status code.
 *
 * @param string $url The target URL
 * @param array $options Probe options: method, timeout, key, username, password, widget_type
 * @return array ['success' => bool, 'http_code' => int, 'latency_ms' => int, 'message' => string, 'error' => string|null]
 */
function probeHttpConnection($url, $options = []) {
    $url = trim($url);
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
        return [
            'success'    => false,
            'http_code'  => 0,
            'latency_ms' => 0,
            'message'    => 'Invalid URL provided. Only http and https protocols are supported.',
            'error'      => 'Invalid URL scheme or format',
        ];
    }

    $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 4;
    $method = strtoupper($options['method'] ?? 'GET');
    $key = trim($options['key'] ?? '');
    $username = trim($options['username'] ?? '');
    $password = trim($options['password'] ?? '');
    $widgetType = strtolower(trim($options['widget_type'] ?? ''));

    // Construct headers
    $headers = [
        'User-Agent: HomepageLabels-Probe/1.0',
        'Accept: application/json, text/html, */*',
    ];

    if (!empty($key)) {
        if (in_array($widgetType, ['sonarr', 'radarr', 'lidarr', 'readarr', 'prowlarr', 'bazarr'], true)) {
            $headers[] = 'X-Api-Key: ' . $key;
        } elseif ($widgetType === 'plex') {
            $headers[] = 'X-Plex-Token: ' . $key;
        } elseif (in_array($widgetType, ['jellyfin', 'emby'], true)) {
            $headers[] = 'X-Emby-Token: ' . $key;
        } else {
            $headers[] = 'Authorization: Bearer ' . $key;
            $headers[] = 'X-Api-Key: ' . $key;
        }
    }

    $startTime = microtime(true);

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(3, $timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }

        if (!empty($username) || !empty($password)) {
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
        }

        $response = curl_exec($ch);
        $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        if ($curlErrNo !== 0 && $httpCode === 0) {
            return [
                'success'    => false,
                'http_code'  => 0,
                'latency_ms' => $latencyMs,
                'message'    => "Connection error: {$curlErr}",
                'error'      => $curlErr,
            ];
        }
    } else {
        // Stream wrapper fallback
        $streamHeaders = $headers;
        if (!empty($username) || !empty($password)) {
            $streamHeaders[] = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
        }
        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $streamHeaders),
                'timeout'         => $timeout,
                'ignore_errors'   => true,
                'follow_location' => 1,
                'max_redirects'   => 3,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $fp = @fopen($url, 'r', false, $context);
        $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
        $httpCode = 0;

        if ($fp) {
            $meta = stream_get_meta_data($fp);
            fclose($fp);
            if (!empty($meta['wrapper_data'])) {
                foreach ($meta['wrapper_data'] as $line) {
                    if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $line, $m)) {
                        $httpCode = (int)$m[1];
                    }
                }
            }
        } else {
            $respHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: []) : ($http_response_header ?? []);
            if (!empty($respHeaders)) {
                foreach ($respHeaders as $line) {
                    if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $line, $m)) {
                        $httpCode = (int)$m[1];
                    }
                }
            }
            if ($httpCode === 0) {
                $err = error_get_last();
                return [
                    'success'    => false,
                    'http_code'  => 0,
                    'latency_ms' => $latencyMs,
                    'message'    => 'Connection failed: Unable to reach host',
                    'error'      => $err['message'] ?? 'Connection refused or timed out',
                ];
            }
        }
    }

    $isSuccess = ($httpCode >= 200 && $httpCode < 400);
    $statusMsg = "HTTP {$httpCode}";
    if ($httpCode === 200) $statusMsg = "200 OK ({$latencyMs}ms)";
    elseif ($httpCode === 401) $statusMsg = "HTTP 401 Unauthorized (Check API key/credentials)";
    elseif ($httpCode === 403) $statusMsg = "HTTP 403 Forbidden";
    elseif ($httpCode === 404) $statusMsg = "HTTP 404 Not Found";
    elseif ($httpCode >= 500) $statusMsg = "HTTP {$httpCode} Server Error";
    else $statusMsg = "HTTP {$httpCode} ({$latencyMs}ms)";

    return [
        'success'    => $isSuccess,
        'http_code'  => $httpCode,
        'latency_ms' => $latencyMs,
        'message'    => $statusMsg,
        'error'      => $isSuccess ? null : $statusMsg,
    ];
}

/**
 * Escape scalar value for valid YAML serialization.
 *
 * @param mixed $val
 * @return string
 */
function yamlEscape($val) {
    if (is_numeric($val)) return (string)$val;
    if (is_bool($val)) return $val ? 'true' : 'false';
    $val = (string)$val;
    if ($val === '') return '""';
    if (preg_match('/[\s:#[\]{}&*!|>\'"%@`,?]/', $val) || in_array(strtolower($val), ['true', 'false', 'yes', 'no', 'null', 'on', 'off'], true)) {
        return '"' . addcslashes($val, '"\\') . '"';
    }
    return $val;
}

/**
 * Compile active containers into Homepage services.yaml structure.
 *
 * @param array $containers Array of container label items:
 *   [
 *     [
 *       'name' => string,
 *       'group' => string,
 *       'labels' => [target => ['value' => ..., 'mask' => ...]]
 *     ], ...
 *   ]
 * @return string YAML content
 */
function generateServicesYaml($containers) {
    $grouped = [];
    foreach ($containers as $c) {
        $labels = $c['labels'] ?? [];
        $name = $labels['homepage.name']['value'] ?? $c['name'];
        $group = $labels['homepage.group']['value'] ?? ($c['group'] ?? 'Services');
        if (empty($group)) $group = 'Services';

        $entry = [];
        if (!empty($labels['homepage.icon']['value'])) {
            $entry['icon'] = $labels['homepage.icon']['value'];
        }
        if (!empty($labels['homepage.href']['value'])) {
            $entry['href'] = $labels['homepage.href']['value'];
        }
        if (!empty($labels['homepage.description']['value'])) {
            $entry['description'] = $labels['homepage.description']['value'];
        }
        if (!empty($labels['homepage.siteMonitor']['value'])) {
            $entry['siteMonitor'] = $labels['homepage.siteMonitor']['value'];
        }
        if (!empty($labels['homepage.ping']['value'])) {
            $entry['ping'] = $labels['homepage.ping']['value'];
        }
        if (!empty($labels['homepage.weight']['value'])) {
            $entry['weight'] = (int)$labels['homepage.weight']['value'];
        }

        // Widget
        if (!empty($labels['homepage.widget.type']['value'])) {
            $widget = ['type' => $labels['homepage.widget.type']['value']];
            if (!empty($labels['homepage.widget.url']['value'])) {
                $widget['url'] = $labels['homepage.widget.url']['value'];
            }
            foreach ($labels as $k => $fData) {
                if (strpos($k, 'homepage.widget.') === 0 && !in_array($k, ['homepage.widget.type', 'homepage.widget.url'], true)) {
                    $wProp = substr($k, strlen('homepage.widget.'));
                    $wVal = $fData['value'] ?? '';
                    if ($wVal !== '') {
                        if (str_starts_with($wVal, '[') && str_ends_with($wVal, ']')) {
                            $decoded = json_decode($wVal, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $widget[$wProp] = $decoded;
                                continue;
                            }
                        }
                        $isSecretField = (isset($fData['mask']) && isMaskTrue($fData['mask'])) ||
                                         preg_match('/(key|token|password|secret|pass|auth|apikey)/i', $wProp);
                        if (!$isSecretField && is_numeric($wVal) && !preg_match('/^0[0-9]+/', $wVal)) {
                            $widget[$wProp] = strpos($wVal, '.') !== false ? (float)$wVal : (int)$wVal;
                        } elseif ($wVal === 'true' || $wVal === 'false') {
                            $widget[$wProp] = ($wVal === 'true');
                        } else {
                            $widget[$wProp] = (string)$wVal;
                        }
                    }
                }
            }
            $entry['widget'] = $widget;
        }

        $grouped[$group][$name] = $entry;
    }

    $yaml = "# Homepage services.yaml\n";
    $yaml .= "# Generated automatically by Unraid Homepage Labels Plugin on " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($grouped as $groupName => $services) {
        $yaml .= "- " . yamlEscape($groupName) . ":\n";
        foreach ($services as $serviceName => $attrs) {
            $yaml .= "    - " . yamlEscape($serviceName) . ":\n";
            foreach ($attrs as $key => $val) {
                if (is_array($val)) {
                    $yaml .= "        " . $key . ":\n";
                    $isList = (!empty($val) && array_keys($val) === range(0, count($val) - 1));
                    if ($isList) {
                        foreach ($val as $item) {
                            $yaml .= "          - " . yamlEscape($item) . "\n";
                        }
                    } else {
                        foreach ($val as $subKey => $subVal) {
                            if (is_array($subVal)) {
                                $yaml .= "          " . $subKey . ": " . json_encode($subVal) . "\n";
                            } elseif (is_bool($subVal)) {
                                $yaml .= "          " . $subKey . ": " . ($subVal ? 'true' : 'false') . "\n";
                            } elseif (is_numeric($subVal)) {
                                $yaml .= "          " . $subKey . ": " . $subVal . "\n";
                            } else {
                                $yaml .= "          " . $subKey . ": " . yamlEscape($subVal) . "\n";
                            }
                        }
                    }
                } elseif (is_bool($val)) {
                    $yaml .= "        " . $key . ": " . ($val ? 'true' : 'false') . "\n";
                } elseif (is_numeric($val)) {
                    $yaml .= "        " . $key . ": " . $val . "\n";
                } else {
                    $yaml .= "        " . $key . ": " . yamlEscape($val) . "\n";
                }
            }
        }
    }

    return $yaml;
}


