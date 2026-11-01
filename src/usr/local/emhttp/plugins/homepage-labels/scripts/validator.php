<?php
/**
 * validator.php — Homepage Container & Docker Socket Validator
 *
 * Validates that:
 * 1. The Homepage container exists and is running
 * 2. The Docker socket is mounted in the Homepage container's template
 *
 * Used by the settings page health check card.
 */

if (!function_exists('readTemplate')) {
    $xmlParserPath = file_exists(__DIR__ . '/../include/xml_parser.php')
        ? __DIR__ . '/../include/xml_parser.php'
        : '/usr/local/emhttp/plugins/homepage-labels/include/xml_parser.php';
    require_once $xmlParserPath;
}

/**
 * Auto-detect Homepage container name by inspecting template repositories or live Docker containers.
 *
 * @param string $preferredName Default/configured container name
 * @return string Detected or preferred container name
 */
function findHomepageContainer($preferredName = 'homepage') {
    // 1. Check if preferred container exists
    if (!empty($preferredName)) {
        $read = readTemplate($preferredName);
        if ($read['success']) {
            return $preferredName;
        }
    }

    // 2. Scan all templates for Homepage image repositories
    $templates = listAllTemplates();
    foreach ($templates as $t) {
        $tName = is_array($t) ? ($t['name'] ?? '') : $t;
        if (empty($tName)) continue;
        $read = readTemplate($tName);
        if ($read['success']) {
            $meta = getContainerMeta($read['doc']);
            $repo = strtolower($meta['Repository'] ?? '');
            if (strpos($repo, 'gethomepage/homepage') !== false || strpos($repo, 'ghcr.io/gethomepage/homepage') !== false || $repo === 'homepage') {
                return $tName;
            }
        }
    }

    // 3. Inspect live docker containers if docker command is available
    $nullDev = (stripos(PHP_OS, 'WIN') === 0) ? '2>nul' : '2>/dev/null';
    $cmd = "docker ps -a --format '{{.Names}}\t{{.Image}}' {$nullDev}";
    $output = trim(shell_exec($cmd) ?? '');
    if (!empty($output)) {
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) >= 2) {
                $cName = trim($parts[0]);
                $cImg = strtolower(trim($parts[1]));
                if (strpos($cImg, 'gethomepage/homepage') !== false || strpos($cImg, 'ghcr.io/gethomepage/homepage') !== false) {
                    return $cName;
                }
            }
        }
    }

    return $preferredName ?: 'homepage';
}

/**
 * Validate the full Homepage container setup.
 *
 * @param string $containerName The Homepage container name (default: "homepage")
 * @return array Health check results
 */
function validateHomepageSetup($containerName = 'homepage') {
    $resolvedName = findHomepageContainer($containerName);

    $result = [
        'containerName'    => $resolvedName,
        'running'          => false,
        'socketMounted'    => false,
        'connectionType'   => 'none',
        'connectionDetail' => '',
        'templateFound'    => false,
        'errors'           => [],
    ];

    // Check 1: Is the container running?
    $result['running'] = isContainerRunning($resolvedName);

    // Check 2: Check Docker connection (socket mount, DOCKER_HOST proxy, etc.)
    $socketCheck = validateDockerSocket($resolvedName);
    $result['templateFound'] = $socketCheck['templateFound'];
    $result['socketMounted'] = $socketCheck['socketMounted'];
    $result['connectionType'] = $socketCheck['connectionType'];
    $result['connectionDetail'] = $socketCheck['connectionDetail'];
    $result['errors'] = $socketCheck['errors'];

    return $result;
}

/**
 * Check if a Docker container is currently running.
 *
 * @param string $containerName The container name
 * @return bool True if the container is running
 */
function isContainerRunning($containerName) {
    $safeName = escapeshellarg($containerName);

    // Use docker inspect to check running state
    $nullDev = (stripos(PHP_OS, 'WIN') === 0) ? '2>nul' : '2>/dev/null';
    $cmd = "docker inspect --format='{{.State.Running}}' {$safeName} {$nullDev}";
    $output = trim(shell_exec($cmd) ?? '');

    return $output === 'true';
}

/**
 * Validate that Docker is connected to the Homepage container.
 *
 * Checks XML template and live container inspect for:
 * 1. Path mount containing /var/run/docker.sock
 * 2. Environment variable DOCKER_HOST (e.g. tcp://socket-proxy:2375)
 *
 * @param string $containerName The container name
 * @return array Health check data
 */
function validateDockerSocket($containerName) {
    $result = [
        'templateFound'    => false,
        'socketMounted'    => false,
        'connectionType'   => 'none',
        'connectionDetail' => '',
        'errors'           => [],
    ];

    // 1. Read the container's template
    $templateResult = readTemplate($containerName);

    if ($templateResult['success']) {
        $result['templateFound'] = true;
        $doc = $templateResult['doc'];

        // Search Config nodes
        $configs = $doc->getElementsByTagName('Config');
        for ($i = 0; $i < $configs->length; $i++) {
            $config = $configs->item($i);
            $type = strtolower($config->getAttribute('Type'));
            $name = $config->getAttribute('Name');
            $target = $config->getAttribute('Target');
            $value = trim($config->nodeValue);

            // Path mount: docker.sock
            if ($type === 'path') {
                if (strpos($target, 'docker.sock') !== false || strpos($value, 'docker.sock') !== false) {
                    $result['socketMounted'] = true;
                    $result['connectionType'] = 'socket';
                    $result['connectionDetail'] = '/var/run/docker.sock';
                    return $result;
                }
            }

            // Variable: DOCKER_HOST (socket-proxy)
            if ($type === 'variable') {
                if (strcasecmp($target, 'DOCKER_HOST') === 0 || strcasecmp($name, 'DOCKER_HOST') === 0 || strpos($value, 'tcp://') === 0 || (strpos($value, ':2375') !== false || strpos($value, ':2376') !== false)) {
                    $result['socketMounted'] = true;
                    $result['connectionType'] = 'proxy';
                    $result['connectionDetail'] = $value ?: $target;
                    return $result;
                }
            }
        }

        // Check ExtraParams
        $extraParams = $doc->getElementsByTagName('ExtraParams');
        if ($extraParams->length > 0) {
            $params = $extraParams->item(0)->nodeValue;
            if (strpos($params, 'docker.sock') !== false) {
                $result['socketMounted'] = true;
                $result['connectionType'] = 'socket';
                $result['connectionDetail'] = '/var/run/docker.sock';
                return $result;
            }
            if (preg_match('/(?:-e\s+)?DOCKER_HOST=([^\s]+)/', $params, $m)) {
                $result['socketMounted'] = true;
                $result['connectionType'] = 'proxy';
                $result['connectionDetail'] = $m[1];
                return $result;
            }
        }
    }

    // 2. Fallback: inspect live running container directly
    $nullDev = (stripos(PHP_OS, 'WIN') === 0) ? '2>nul' : '2>/dev/null';
    $safeName = escapeshellarg($containerName);
    $inspectJson = shell_exec("docker inspect {$safeName} {$nullDev}");
    if (!empty($inspectJson)) {
        $data = json_decode($inspectJson, true);
        if (!empty($data[0])) {
            $result['templateFound'] = true;
            $containerData = $data[0];

            // Check Env
            if (!empty($containerData['Config']['Env'])) {
                foreach ($containerData['Config']['Env'] as $env) {
                    if (strpos($env, 'DOCKER_HOST=') === 0) {
                        $val = substr($env, 12);
                        $result['socketMounted'] = true;
                        $result['connectionType'] = 'proxy';
                        $result['connectionDetail'] = $val;
                        return $result;
                    }
                }
            }

            // Check Mounts
            if (!empty($containerData['Mounts'])) {
                foreach ($containerData['Mounts'] as $mount) {
                    $src = $mount['Source'] ?? '';
                    $dst = $mount['Destination'] ?? '';
                    if (strpos($src, 'docker.sock') !== false || strpos($dst, 'docker.sock') !== false) {
                        $result['socketMounted'] = true;
                        $result['connectionType'] = 'socket';
                        $result['connectionDetail'] = '/var/run/docker.sock';
                        return $result;
                    }
                }
            }
        }
    }

    if (!$result['socketMounted']) {
        $result['errors'][] = 'No Docker socket mount or DOCKER_HOST variable detected for Homepage container.';
    }

    return $result;
}

/**
 * Validate that a container's Homepage labels are properly formatted.
 *
 * @param string $containerName The container name to validate
 * @return array ['valid' => bool, 'labels' => array, 'warnings' => array]
 */
function validateContainerLabels($containerName) {
    $result = [
        'valid'    => true,
        'labels'   => [],
        'warnings' => [],
    ];

    $templateResult = readTemplate($containerName);
    if (!$templateResult['success']) {
        $result['valid'] = false;
        $result['warnings'][] = $templateResult['error'];
        return $result;
    }

    $labels = getHomepageLabels($templateResult['doc']);
    $result['labels'] = $labels;

    // Validate: if labels exist, homepage.group and homepage.name should be set
    if (!empty($labels)) {
        if (!isset($labels['homepage.name']) || empty($labels['homepage.name']['value'])) {
            $result['warnings'][] = 'homepage.name is not set. The service may not display correctly on the Homepage dashboard.';
        }
        if (!isset($labels['homepage.group']) || empty($labels['homepage.group']['value'])) {
            $result['warnings'][] = 'homepage.group is not set. The service may be grouped under default or uncategorized.';
        }
    }

    // Validate: if widget.type is set, widget.url should also be set
    if (isset($labels['homepage.widget.type']) && !empty($labels['homepage.widget.type']['value'])) {
        if (!isset($labels['homepage.widget.url']) || empty($labels['homepage.widget.url']['value'])) {
            $result['warnings'][] = 'homepage.widget.type is set but homepage.widget.url is missing. The widget may not function.';
        }
    }

    // Validate: URL format for href & siteMonitor
    foreach (['homepage.href', 'homepage.siteMonitor'] as $urlKey) {
        if (isset($labels[$urlKey]) && !empty($labels[$urlKey]['value'])) {
            $uVal = $labels[$urlKey]['value'];
            $testUrl = preg_replace('/\[PORT:\d+\]/i', '80', $uVal);
            $testUrl = str_ireplace('[IP]', '127.0.0.1', $testUrl);
            if (!filter_var($testUrl, FILTER_VALIDATE_URL)) {
                $result['warnings'][] = "{$urlKey} value '{$uVal}' does not appear to be a valid URL.";
            }
        }
    }

    // Validate: Numeric fields
    $numericKeys = ['homepage.weight', 'homepage.pingInterval', 'homepage.widget.latitude', 'homepage.widget.longitude', 'homepage.widget.serverId'];
    foreach ($numericKeys as $numKey) {
        if (isset($labels[$numKey]) && !empty($labels[$numKey]['value'])) {
            $nVal = $labels[$numKey]['value'];
            if (!is_numeric($nVal)) {
                $result['warnings'][] = "{$numKey} value '{$nVal}' must be a valid numeric value.";
            }
        }
    }

    if (!empty($result['warnings'])) {
        $result['valid'] = false;
    }

    return $result;
}
