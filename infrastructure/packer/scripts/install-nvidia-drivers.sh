#!/bin/bash
set -euo pipefail

echo "=== Installing NVIDIA drivers and CUDA toolkit ==="

# Ensure base Ubuntu repo is present. Some images only include -updates/-security entries,
# which can make common packages (e.g., build-essential) unavailable.
CODENAME="$(. /etc/os-release && echo "${VERSION_CODENAME:-}")"
if [ -z "${CODENAME}" ]; then
  CODENAME="jammy"
fi

sudo apt-get update

if apt-cache policy build-essential | grep -q "Candidate: (none)"; then
  echo "Adding base Ubuntu repo for ${CODENAME} (main/restricted/universe/multiverse)."
  echo "deb http://archive.ubuntu.com/ubuntu ${CODENAME} main restricted universe multiverse" | sudo tee "/etc/apt/sources.list.d/bp-${CODENAME}-base.list" > /dev/null
  sudo apt-get update
fi

# Install kernel headers and build tools
if ! sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
  build-essential \
  dkms \
  pkg-config \
  "linux-headers-$(uname -r)"; then
  # Fallback for AWS/generic kernels when exact headers aren't available
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    build-essential \
    dkms \
    pkg-config \
    linux-headers-aws \
    || sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
      build-essential \
      dkms \
      pkg-config \
      linux-headers-generic
fi

# Add NVIDIA driver repository
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:graphics-drivers/ppa
sudo apt-get update

# Install NVIDIA driver (headless, no X11)
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y nvidia-driver-550-server

# Install CUDA toolkit 12.x
wget -q https://developer.download.nvidia.com/compute/cuda/repos/ubuntu2204/x86_64/cuda-keyring_1.1-1_all.deb
sudo dpkg -i cuda-keyring_1.1-1_all.deb
rm cuda-keyring_1.1-1_all.deb
sudo apt-get update
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y cuda-toolkit-12-4

# Add CUDA to PATH
echo 'export PATH=/usr/local/cuda/bin:$PATH' | sudo tee /etc/profile.d/cuda.sh
echo 'export LD_LIBRARY_PATH=/usr/local/cuda/lib64:$LD_LIBRARY_PATH' | sudo tee -a /etc/profile.d/cuda.sh

echo "=== NVIDIA driver and CUDA installation complete ==="
nvidia-smi || echo "nvidia-smi not available yet (will work after reboot)"
