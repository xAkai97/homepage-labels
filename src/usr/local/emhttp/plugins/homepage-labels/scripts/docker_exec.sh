#!/bin/bash
# docker_exec.sh — Container Re-creation Bridge for Unraid's dockerMan
#
# Wraps the native Unraid Docker manager "dockerupdate" command to
# safely re-create a container with updated labels from its XML template.
#
# Usage: docker_exec.sh <container_name>
#        docker_exec.sh recreate <container_name>
#
# This triggers the standard Unraid Docker rebuild process, which:
# 1. Reads the container's XML template
# 2. Stops and removes the existing container
# 3. Creates a new container with the updated configuration
# 4. Starts the new container

set -euo pipefail

# Support both 'docker_exec.sh <container>' and 'docker_exec.sh recreate <container>'
if [ $# -ge 2 ]; then
    ACTION="${1:-recreate}"
    CONTAINER_NAME="${2:-}"
else
    ACTION="recreate"
    CONTAINER_NAME="${1:-}"
fi
DOCKERUPDATE_SCRIPT="/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/dockerupdate"
LOG_DIR="/tmp/homepage-labels/logs"
LOG_FILE="${LOG_DIR}/docker_exec_$(date +%Y-%m-%d_%H%M%S).log"

# --- Validation ---

if [ -z "$CONTAINER_NAME" ]; then
    echo '{"success":false,"error":"Container name is required."}'
    exit 1
fi

# Sanitize container name (alphanumeric, hyphens, underscores only)
SAFE_NAME=$(echo "$CONTAINER_NAME" | sed 's/[^a-zA-Z0-9_.-]//g')
if [ -z "$SAFE_NAME" ]; then
    echo '{"success":false,"error":"Invalid container name."}'
    exit 1
fi

# Check that the dockerupdate script exists
if [ ! -f "$DOCKERUPDATE_SCRIPT" ]; then
    echo '{"success":false,"error":"Unraid dockerupdate script not found at '"$DOCKERUPDATE_SCRIPT"'."}'
    exit 1
fi

# Check that the template XML exists
TEMPLATE_FILE="/boot/config/plugins/dockerMan/templates-user/my-${SAFE_NAME}.xml"
if [ ! -f "$TEMPLATE_FILE" ]; then
    echo '{"success":false,"error":"Template file not found: '"$TEMPLATE_FILE"'."}'
    exit 1
fi

# --- Execution ---

# Create log directory and prune logs older than 7 days
mkdir -p "$LOG_DIR"
find "$LOG_DIR" -type f -name 'docker_exec_*.log' -mtime +7 -delete 2>/dev/null || true

echo "[$(date)] Starting container recreation for: $SAFE_NAME" >> "$LOG_FILE"

# Run the native Unraid dockerupdate command
# This is the same process that runs when you click "Apply" in Unraid's Docker edit screen
EXIT_CODE=0
"$DOCKERUPDATE_SCRIPT" create "my-${SAFE_NAME}" >> "$LOG_FILE" 2>&1 || EXIT_CODE=$?
if [ "$EXIT_CODE" -eq 0 ]; then
    echo "[$(date)] Container recreation completed successfully." >> "$LOG_FILE"
    echo '{"success":true,"message":"Container '"$SAFE_NAME"' recreated successfully."}'
    exit 0
else
    echo "[$(date)] Container recreation FAILED with exit code: $EXIT_CODE" >> "$LOG_FILE"
    echo '{"success":false,"error":"Container recreation failed (exit code: '"$EXIT_CODE"'). Check log: '"$LOG_FILE"'."}'
    exit "$EXIT_CODE"
fi
