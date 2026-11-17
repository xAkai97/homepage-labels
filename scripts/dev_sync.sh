#!/bin/bash
# scripts/dev_sync.sh — Instant local development sync for Unraid
# Copies local repository files directly to Unraid's active emhttp plugin directory.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

SRC_DIR="${REPO_ROOT}/src/usr/local/emhttp/plugins/homepage-labels"
DEST_DIR="/usr/local/emhttp/plugins/homepage-labels"
BOOT_PLG="/boot/config/plugins/homepage-labels.plg"

if [ ! -d "${SRC_DIR}" ]; then
    echo "Error: Source directory ${SRC_DIR} not found."
    exit 1
fi

mkdir -p "${DEST_DIR}"
cp -rf "${SRC_DIR}/"* "${DEST_DIR}/"
cp -f "${REPO_ROOT}/homepage-labels.plg" "${BOOT_PLG}"

echo "✓ Synced ${SRC_DIR} -> ${DEST_DIR}"
echo "✓ Synced homepage-labels.plg -> ${BOOT_PLG}"
echo "✓ Ready to test on Unraid immediately (no git push or CDN cache delay)."
