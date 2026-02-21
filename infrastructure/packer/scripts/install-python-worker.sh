#!/bin/bash
set -euo pipefail

echo "=== Installing Python worker ==="

# Create worker directory
sudo mkdir -p /opt/worker
sudo chown ubuntu:ubuntu /opt/worker

# Copy worker script (uploaded via Packer file provisioner)
if [ -f /tmp/comfyui_worker.py ]; then
  sudo install -m 755 /tmp/comfyui_worker.py /opt/worker/comfyui_worker.py
  sudo chown ubuntu:ubuntu /opt/worker/comfyui_worker.py
else
  echo "WARNING: /tmp/comfyui_worker.py not found; worker script missing."
fi

# Create requirements file for worker deps
cat > /opt/worker/requirements.txt <<'EOF'
requests>=2.31.0
boto3>=1.34.0
EOF

# Install worker dependencies in ComfyUI's venv
source /opt/comfyui/venv/bin/activate
pip install --no-cache-dir -r /opt/worker/requirements.txt

# Create systemd service for the worker
sudo tee /etc/systemd/system/comfyui-worker.service > /dev/null <<'UNIT'
[Unit]
Description=ComfyUI Worker (polls backend for jobs)
After=comfyui.service
Requires=comfyui.service

[Service]
Type=simple
User=ubuntu
WorkingDirectory=/opt/worker
EnvironmentFile=/opt/worker/env
ExecStart=/opt/comfyui/venv/bin/python comfyui_worker.py
Restart=on-failure
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT

# Create env file placeholder (populated by user-data at instance launch)
cat > /opt/worker/env <<'EOF'
# Populated by EC2 user-data at launch time
API_BASE_URL=
WORKER_ID=
FLEET_SECRET=
FLEET_SLUG=
FLEET_STAGE=
ASG_NAME=
COMFYUI_BASE_URL=http://127.0.0.1:8188
POLL_INTERVAL_SECONDS=3
HEARTBEAT_INTERVAL_SECONDS=30
MAX_CONCURRENCY=1
EOF

sudo systemctl daemon-reload
sudo systemctl enable comfyui-worker.service

echo "=== Python worker installation complete ==="
