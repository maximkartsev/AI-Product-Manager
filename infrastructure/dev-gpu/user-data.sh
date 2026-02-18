#!/bin/bash
# Dev GPU user-data: reconfigure production AMI for interactive ComfyUI development.
# This script runs as root on first boot via EC2 user-data.
set -euo
set -o pipefail 2>/dev/null || true

LOG="/var/log/dev-gpu-setup.log"
exec > >(tee -a "$LOG") 2>&1
echo "=== dev-gpu setup started at $(date -u) ==="

AUTO_SHUTDOWN_HOURS="__AUTO_SHUTDOWN_HOURS__"

# ── 1. Disable the production worker daemon ──────────────────────────────────
echo "Disabling comfyui-worker.service..."
systemctl disable --now comfyui-worker.service || true

# ── 2. Reconfigure ComfyUI to listen on all interfaces ──────────────────────
echo "Reconfiguring comfyui.service for dev mode (0.0.0.0:8188)..."
mkdir -p /etc/systemd/system/comfyui.service.d
cat > /etc/systemd/system/comfyui.service.d/dev-override.conf <<'OVERRIDE'
[Service]
ExecStart=
ExecStart=/opt/comfyui/venv/bin/python main.py --listen 0.0.0.0 --port 8188
OVERRIDE

systemctl daemon-reload
systemctl restart comfyui.service
echo "ComfyUI restarted in dev mode."

# ── 3. Schedule auto-shutdown ────────────────────────────────────────────────
SHUTDOWN_MINUTES=$(( AUTO_SHUTDOWN_HOURS * 60 ))
WARN_MINUTES=$(( SHUTDOWN_MINUTES - 30 ))

shutdown -c 2>/dev/null || true
echo "Scheduling auto-shutdown in ${AUTO_SHUTDOWN_HOURS}h (${SHUTDOWN_MINUTES}m)..."
shutdown -h "+${SHUTDOWN_MINUTES}" "Dev GPU auto-stop after ${AUTO_SHUTDOWN_HOURS}h time limit"

if [ "$WARN_MINUTES" -gt 0 ]; then
  # One-shot systemd timer for the 30-min warning (more reliable than at/cron on first boot)
  cat > /etc/systemd/system/dev-gpu-warn.service <<WARN_SVC
[Unit]
Description=Dev GPU shutdown warning

[Service]
Type=oneshot
ExecStart=/usr/bin/wall "WARNING: This dev GPU instance will shut down in 30 minutes."
ExecStart=/usr/bin/logger -t dev-gpu "Instance will shut down in 30 minutes."
WARN_SVC

  cat > /etc/systemd/system/dev-gpu-warn.timer <<WARN_TMR
[Unit]
Description=Dev GPU shutdown warning timer

[Timer]
OnBootSec=${WARN_MINUTES}min
Unit=dev-gpu-warn.service

[Install]
WantedBy=timers.target
WARN_TMR

  systemctl daemon-reload
  systemctl enable --now dev-gpu-warn.timer
fi

echo "=== dev-gpu setup completed at $(date -u) ==="
