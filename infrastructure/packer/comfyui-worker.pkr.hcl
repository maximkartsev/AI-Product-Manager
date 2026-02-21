packer {
  required_plugins {
    amazon = {
      version = ">= 1.3.0"
      source  = "github.com/hashicorp/amazon"
    }
  }
}

source "amazon-ebs" "comfyui" {
  ami_name      = "bp-comfyui-${var.fleet_slug}-{{timestamp}}"
  instance_type = var.instance_type
  region        = var.aws_region
  ssh_username  = var.ssh_username

  # Optional instance profile for S3 model sync
  iam_instance_profile = var.packer_instance_profile != "" ? var.packer_instance_profile : null

  source_ami_filter {
    filters = {
      name                = var.source_ami_filter_name
      root-device-type    = "ebs"
      virtualization-type = "hvm"
    }
    most_recent = true
    owners      = [var.source_ami_owner]
  }

  launch_block_device_mappings {
    device_name           = "/dev/sda1"
    volume_size           = 100
    volume_type           = "gp3"
    delete_on_termination = true
    encrypted             = true
  }

  tags = {
    Name      = "bp-comfyui-${var.fleet_slug}"
    FleetSlug = var.fleet_slug
    BuildTime = "{{timestamp}}"
    ManagedBy = "packer"
    BundleId  = var.bundle_id
  }

  ami_description = "ComfyUI GPU worker AMI for fleet: ${var.fleet_slug}"
}

build {
  sources = ["source.amazon-ebs.comfyui"]

  # Install NVIDIA drivers and CUDA
  provisioner "shell" {
    script = "scripts/install-nvidia-drivers.sh"
  }

  # Install ComfyUI and base dependencies
  provisioner "shell" {
    script = "scripts/install-comfyui.sh"
  }

  # Install Python worker
  provisioner "file" {
    source      = "../../worker/comfyui_worker.py"
    destination = "/tmp/comfyui_worker.py"
  }

  # Bundle installer script
  provisioner "file" {
    source      = "scripts/apply-bundle.sh"
    destination = "/tmp/apply-bundle.sh"
  }

  provisioner "shell" {
    script = "scripts/install-python-worker.sh"
  }

  provisioner "shell" {
    inline = [
      "sudo mkdir -p /opt/comfyui/bin",
      "sudo mv /tmp/apply-bundle.sh /opt/comfyui/bin/apply-bundle.sh",
      "sudo chmod 755 /opt/comfyui/bin/apply-bundle.sh",
    ]
  }

  # Apply bundle manifest from S3 (if provided)
  provisioner "shell" {
    inline = [
      "if [ -n '${var.models_s3_bucket}' ] && [ -n '${var.models_s3_prefix}' ]; then",
      "  echo 'Applying bundle from S3 manifest...'",
      "  PREFIX='${var.models_s3_prefix}'",
      "  PREFIX=${PREFIX%/}",
      "  MODELS_BUCKET='${var.models_s3_bucket}' BUNDLE_PREFIX=\"$PREFIX\" /opt/comfyui/bin/apply-bundle.sh",
      "  echo 'Bundle apply complete'",
      "else",
      "  echo 'No S3 model bucket configured, skipping model sync'",
      "fi",
      "if [ -n '${var.bundle_id}' ]; then",
      "  echo 'Recording baked bundle id...'",
      "  echo '${var.bundle_id}' | sudo tee /opt/comfyui/.baked_bundle_id > /dev/null",
      "  sudo chmod 644 /opt/comfyui/.baked_bundle_id",
      "fi",
    ]
  }

  # Cleanup
  provisioner "shell" {
    inline = [
      "sudo apt-get clean",
      "sudo rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*",
      "sudo journalctl --vacuum-time=1s",
    ]
  }
}
