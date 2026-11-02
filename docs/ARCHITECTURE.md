# Architecture and File Structure

This document outlines the file layout, runtime data paths, and subsystem responsibilities of the Homepage Label Manager plugin.

## Directory Structure

```text
/boot/config/plugins/homepage-labels/
├── settings.cfg              # Persistent plugin settings (INI format)
├── user_templates.json       # Custom user templates
├── custom_presets.json       # Optional custom preset overrides
├── pending_recreates.json    # Containers whose template changed but were not yet recreated
└── backups/                  # Label snapshots for undo/restore and pre-import template backups

/usr/local/emhttp/plugins/homepage-labels/
├── HomepageLabels.page        # Main dashboard and visual editor WebGUI
├── HomepageLabelsSettings.page # Global plugin settings and diagnostics WebGUI
├── HomepageLabelsHook.page    # Hook injected into Unraid Docker UpdateContainer views
├── default_presets.json       # Bundled catalog of 30+ application presets
├── homepage-labels.png        # 48x48 plugin icon
├── event/
│   └── docker_container_created # Dynamix event hook for new container alerts
├── scripts/
│   ├── apply_labels.php       # AJAX backend for label persistence, diffs, and export
│   ├── docker_exec.sh         # Wrapper for Unraid native dockerupdate recreations
│   └── validator.php          # Health checks, socket detection, and label validator
└── include/
    ├── xml_parser.php         # Non-destructive XML template reading and writing
    ├── preset_engine.php      # Preset matching, URL resolution, and YAML export
    └── view_components.php    # UI components, form renderers, and modal generators
```

## Core Subsystems

### 1. XML Differential Engine (`include/xml_parser.php`)

The plugin interacts directly with Unraid's user container templates located in `/boot/config/plugins/dockerMan/templates-user/`. 

Key behaviors:
- Reads and parses `<Config>` nodes using PHP's `DOMDocument`.
- Preserves all non-Homepage configuration nodes (ports, paths, environment variables, non-Homepage labels).
- Calculates exact diffs (added, modified, removed) between existing template labels and incoming values.
- Performs differential updates: if values are identical, no file writes or container restarts occur.
- Automatically captures pre-write XML snapshots in `/tmp/homepage-labels/backups/` before applying modifications.

### 2. Preset & Resolution Engine (`include/preset_engine.php`)

Matches Docker repositories to known application presets:
- Match hierarchy: Exact repository key -> Alias match -> Image name keyword (registry and tag stripped).
- Placeholders: Automatically resolves `[IP]`, `[PORT:xxxx]`, and container network names.
- IPv6 support: Automatically wraps IPv6 addresses in RFC 2732 bracket notation (`[2001:db8::1]`).
- Connection tester: Probes endpoints with latency measurement. Only `http` and `https` schemes are allowed, including on redirects. Private/LAN addresses are intentionally permitted, since homelab services live there.
- YAML exporter: Translates active containers into valid Homepage `services.yaml` configuration.

### 3. Native Docker Template Integration (`HomepageLabelsHook.page`)

Provides deep integration into Unraid's native Docker container editor:
- Injected automatically onto `/Docker/UpdateContainer` and `/Docker/AddContainer` pages.
- Minimal link anchored directly beneath Unraid's native configuration element:
  - Displays "Add Homepage Labels" if 0 discovery labels exist.
  - Displays "Edit Homepage Labels [ X Active ]" if discovery labels are already configured.
- Optional modal overlay: Allows editing Homepage labels without leaving the Unraid Docker edit form.
- Real-time DOM synchronization: Automatically updates Unraid's open `form#docker_form` without requiring a page reload.

### 4. Background Container Recreation (`scripts/docker_exec.sh`)

Container recreation is performed via Unraid's native `/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/dockerupdate` binary:
- Invoked strictly via POST requests in `apply_labels.php`.
- Sequential recreation support: Batched recreation processes one container at a time with live status polling.
- Clean process execution: Shell arguments are escaped via `escapeshellarg()`.

