#!/bin/bash
set -euo pipefail

echo "=== Installing ComfyUI ==="

# Install Python 3.11 and pip
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
  python3.11 \
  python3.11-venv \
  python3-pip \
  git \
  ffmpeg \
  libgl1-mesa-glx \
  libglib2.0-0 \
  awscli

# Create ComfyUI directory
sudo mkdir -p /opt/comfyui
sudo chown ubuntu:ubuntu /opt/comfyui

# Clone ComfyUI
cd /opt/comfyui
git clone https://github.com/comfyanonymous/ComfyUI.git .

# Create virtual environment
python3.11 -m venv /opt/comfyui/venv
source /opt/comfyui/venv/bin/activate

# Install PyTorch with CUDA support
pip install --no-cache-dir \
  torch \
  torchvision \
  torchaudio \
  --index-url https://download.pytorch.org/whl/cu124

# Install ComfyUI requirements
pip install --no-cache-dir -r requirements.txt

# Create model directories
mkdir -p models/checkpoints models/clip models/vae models/loras models/controlnet

# Create systemd service for ComfyUI
sudo tee /etc/systemd/system/comfyui.service > /dev/null <<'UNIT'
[Unit]
Description=ComfyUI Server
After=network.target

[Service]
Type=simple
User=ubuntu
WorkingDirectory=/opt/comfyui
ExecStart=/opt/comfyui/venv/bin/python main.py --listen 127.0.0.1 --port 8188
Restart=on-failure
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable comfyui.service

echo "=== ComfyUI installation complete ==="
