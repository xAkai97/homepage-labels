# Homepage Label Manager for Unraid

Homepage Label Manager is an [Unraid](https://unraid.net/) plugin that simplifies configuring [Homepage](https://gethomepage.dev/) automatic service discovery labels on Docker containers.

Instead of manually editing Docker template XML files or remembering Homepage label syntax, the plugin provides a visual editor, pre-configured application presets, and a dashboard for managing labels across all containers.

---

## Features

### Visual Label Editor
- **Application Presets**: Automatic detection and 1-click presets for over 30 popular self-hosted apps (Sonarr, Radarr, Plex, Jellyfin, qBittorrent, etc.).
- **Live Preview**: Real-time visual Homepage service card preview, services.yaml translation, and Docker CLI label generation.
- **Application Icons & Presets**: Pre-configured icons for 30+ services with 1-click presets, multi-source live preview support (Dashboard Icons, Simple Icons, Selfh.st, and Material Design), and a direct link to the official Homepage icon reference guide.
- **Secret Masking**: Sensitive fields (API keys, passwords, tokens) are automatically detected, masked in the WebGUI, and synced with Unraid XML `Mask="true"` attributes.
- **URL Quick-Fill**: One-click shortcuts to populate Host, Container (for custom Docker networks), and WebUI URLs.
- **Field Catalog**: Full support for all standard Homepage fields, site monitor configurations, and application widgets.

### Safe Template Management
- **Differential XML Engine**: Detects actual changes before writing; identical configurations trigger no file updates or unnecessary container restarts.
- **Non-Destructive**: Preserves all existing non-Homepage ports, paths, environment variables, and labels.
- **Pre-Save Diff**: Inspect label additions, modifications, and removals before applying changes, with a "Save XML Only" fallback.
- **Automatic Backups**: Snapshots previous label configurations before writes or removals for 1-click restore.
- **Pending Recreate Tracker**: Flags containers whose templates have been modified on disk but not yet recreated.

### Dashboard and Bulk Operations
- **Overview Table**: View discovery status, active widgets, assigned groups, and container states in a single view.
- **Status Filter Pills**: Quick filter containers by status (All, Active, Inactive, Widgets).
- **Bulk Operations**: Multi-select containers to enable discovery, remove labels, assign groups, or apply templates in batches.
- **Sequential Recreate**: Recreate multiple containers sequentially with real-time status polling and a cancel option.
- **Health Alerts**: Notifies you if the Homepage container is stopped or lacks Docker socket access.

### Unraid Integration
- **Unified Navigation**: Single entry under **Settings -> User Utilities -> Homepage Labels** with tabbed switching between Dashboard and Global Settings.
- **Native Docker Edit Hook**: Minimal link anchored directly inside Unraid's native Add/Edit Container (`UpdateContainer`) views.
- **Overlay Editor**: Edit Homepage labels inside a modal overlay directly on the Unraid Docker edit form without losing unsaved changes.
- **New Container Notifications**: Optional Dynamix event alerts when a newly installed container can be configured for Homepage discovery.

---

## Installation

1. In the Unraid WebGUI, navigate to **Plugins -> Install Plugin**.
2. Enter the plugin URL:
   ```text
   https://raw.githubusercontent.com/xAkai97/homepage-labels/main/homepage-labels.plg
   ```
3. Click **Install**.

---

## Quick Start

### 1. Initial Setup
1. Navigate to **Settings -> User Utilities -> Homepage Labels**.
2. Select the **Global Settings** tab.
3. Configure your server settings:
   - **Base Host IP**: The IP address of your Unraid server.
   - **URL Scheme**: `http` or `https`.
   - **Homepage Container Name**: The name of your running Homepage container (default: `homepage`).
4. Click **Apply**. The health status indicator will confirm whether the Homepage container and Docker socket connection are active.

### 2. Configuring a Container
1. On the **Dashboard & Labels** tab, click **Edit Labels** next to any container.
2. Matching presets are detected automatically. To use a different one, pick a preset or saved template from the template dropdown at the top of the editor and click **Apply**.
3. Enter any required credentials or API keys.
4. Choose an action:
   - **Save XML Only**: Updates the container template without restarting the running container.
   - **Save & Apply (Recreate Container)**: Opens the Pre-Save Diff modal and recreates the container with the new labels active.

---

## Documentation

For technical details, configuration formats, and development guides, refer to the documentation:

- [**Presets and Custom Templates**](docs/TEMPLATES.md): Shipped application presets, custom template JSON schema, import conflict handling, and custom preset overrides.
- [**Architecture and File Structure**](docs/ARCHITECTURE.md): Directory layout, XML differential engine, hook mechanisms, and container execution model.
- [**Development and Testing**](docs/DEVELOPMENT.md): Line ending requirements (LF), running the 74-test standalone test suite, keyword filters, and coding invariants.

---

## Requirements

- Unraid 6.12.0 or later
- Running Homepage container with access to `/var/run/docker.sock` (or Docker Socket Proxy)

---

## Support

- **Bug Reports & Issues**: [GitHub Issues](https://github.com/xAkai97/homepage-labels/issues)

---

## License

This project is licensed under the [MIT License](LICENSE).
