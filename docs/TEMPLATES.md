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

Templates are keyed by ID under a top-level `templates` object. Each field entry records its value, whether it is masked, and whether it is active in the editor. Only keys beginning with `homepage.` are accepted.

```json
{
  "templates": {
    "my-custom-template": {
      "id": "my-custom-template",
      "name": "My Custom Template",
      "created": "2026-10-31T12:00:00-04:00",
      "modified": "2026-10-31T12:00:00-04:00",
      "cloned_from": "custom",
      "widget_enabled": true,
      "fields": {
        "homepage.group":       { "value": "Media",                   "mask": "false", "active": true },
        "homepage.icon":        { "value": "app-icon.png",            "mask": "false", "active": true },
        "homepage.href":        { "value": "http://[IP]:[PORT:8080]/", "mask": "false", "active": true },
        "homepage.widget.type": { "value": "customapi",               "mask": "false", "active": true },
        "homepage.widget.key":  { "value": "",                        "mask": "true",  "active": true }
      }
    }
  }
}
```

Field entries may also carry the optional Advanced Config attributes `name`, `default`, `description`, `display`, and `required`. Exported files use the same shape, so an export can be re-imported as-is.

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

The file uses the same schema as the bundled `default_presets.json`: presets are keyed by Docker repository under a top-level `presets` object. Files without the `presets` wrapper are ignored.

```json
{
  "presets": {
    "myorg/custom-service": {
      "name": "Custom Service",
      "status": "verified",
      "aliases": ["ghcr.io/myorg/custom-service", "custom-service"],
      "default_group": "Utilities",
      "icon": "service-icon.png",
      "default_port": 9000,
      "widget": {
        "type": "customapi",
        "supported_fields": ["homepage.widget.url", "homepage.widget.key"],
        "required_fields": ["homepage.widget.url"],
        "field_metadata": {
          "homepage.widget.url": { "label": "API URL", "type": "text", "default_from": "webui", "mask": false },
          "homepage.widget.key": { "label": "API Key", "type": "password", "mask": true, "help": "Settings > API" }
        }
      }
    }
  }
}
```

Custom presets are merged over the bundled presets by key: a custom entry with the same repository key as a bundled preset replaces it, and new keys are added. Matching then tries the exact repository key, then `aliases`, then the image name without registry or tag.

## Advanced XML Template `<Config>` Mode

Unraid stores container configurations in XML templates containing `<Config>` nodes. By default, Homepage Label Manager manages these nodes with standard defaults.

Enabling Advanced Mode in the editor allows customization of native Unraid template attributes for individual labels:

| Attribute | Description | Default |
|---|---|---|
| `Name` | Human-readable label displayed in Unraid's Docker edit view | Generated from label key |
| `Default` | Default value stored in the template | Empty |
| `Description` | Field description shown in Unraid's Docker edit view | "Homepage Discovery Label" |
| `Display` | Display style in Unraid template editor (`always`, `advanced`, `hidden`) | `always` |
| `Required` | Whether Unraid requires the field to be non-empty (`true` or `false`) | `false` |
| `Mask` | Whether Unraid masks the value in the WebGUI (`true` or `false`) | Auto-detected for secrets |

