# Presets and Templates Reference

Homepage Label Manager includes built-in application presets, custom template management, and repository-level override files.

## Bundled Application Presets

The plugin includes verified presets for over 30 popular self-hosted applications:

| Category | Supported Applications |
|---|---|
| Media Management | Sonarr, Radarr, Lidarr, Readarr, Prowlarr, Bazarr, Overseerr, Jellyseerr, Tautulli |
| Media Streaming | Plex, Jellyfin, Emby, Immich, Audiobookshelf, Kavita, Komga |
| Downloads | qBittorrent, Deluge, Transmission, SABnzbd, NZBGet |
| Network & Security | Pi-hole, AdGuard Home, Nginx Proxy Manager, Speedtest-Tracker, Vaultwarden |
| Productivity & Tools | Paperless-ngx |
| Monitoring & Stats | Uptime Kuma, Glances |
| Home Automation | Home Assistant |

Presets automatically map application icons, external URLs, ports, widget types, and recommended configuration fields when opening the editor for a container.

## Custom User Templates

Users can save, manage, and reuse custom label configurations across multiple containers.

### Storage Location

Custom templates are stored in persistent flash configuration at:
```text
/boot/config/plugins/homepage-labels/user_templates.json
```

### JSON Schema

```json
{
  "my-custom-template": {
    "id": "my-custom-template",
    "name": "My Custom Template",
    "labels": {
      "homepage.group": "Media",
      "homepage.name": "Custom App",
      "homepage.icon": "app-icon.png",
      "homepage.href": "http://[IP]:[PORT:8080]/",
      "homepage.description": "Custom Application Service"
    },
    "created_at": "2026-10-31 12:00:00"
  }
}
```

### Import Conflict Modes

When importing custom templates via JSON file or text paste, three conflict handling modes are available:

1. **Merge (Safe)**: Retains existing templates. Conflicting template IDs have an `(Imported)` suffix appended to their name to avoid data loss.
2. **Overwrite**: Updates existing templates matching imported IDs with incoming values, while adding any new templates.
3. **Replace All**: Clears existing custom templates and replaces the entire template catalog with the imported set. An automatic safety backup is generated before replacement.

## Custom Preset Overrides

Advanced users can define repository matching rules that override or augment the bundled default presets without modifying plugin code.

Place a `custom_presets.json` file at:
```text
/boot/config/plugins/homepage-labels/custom_presets.json
```

### Format

```json
{
  "my-custom-repo": {
    "name": "Custom Service",
    "aliases": ["organization/custom-service", "custom-service"],
    "icon": "service-icon.png",
    "group": "Utilities",
    "url_pattern": "http://[IP]:[PORT:9000]/",
    "widget": {
      "type": "custom",
      "fields": {
        "url": "http://[IP]:[PORT:9000]",
        "key": ""
      }
    }
  }
}
```

When matching containers, the plugin searches `custom_presets.json` before consulting `default_presets.json`.

## Advanced XML Template `<Config>` Mode

Unraid stores container configurations in XML templates containing `<Config>` nodes. By default, Homepage Label Manager manages these nodes with standard defaults.

Enabling Advanced Mode in the editor allows customization of native Unraid template attributes for individual labels:

| Attribute | Description | Default |
|---|---|---|
| `Name` | Human-readable label displayed in Unraid's Docker edit view | Generated from label key |
| `Default` | Default value if left blank | Current label value |
| `Description` | Field description shown in Unraid's Docker edit view | "Homepage service discovery label" |
| `Display` | Display style in Unraid template editor (`always`, `advanced`, `hidden`) | `always` |
| `Required` | Whether Unraid requires the field to be non-empty (`true` or `false`) | `false` |
| `Mask` | Whether Unraid masks the value in the WebGUI (`true` or `false`) | Auto-detected for secrets |

