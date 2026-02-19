packer {
  required_plugins {
    amazon = {
      version = ">= 1.3.0"
      source  = "github.com/hashicorp/amazon"
    }
  }
}

source "amazon-ebs" "comfyui" {
  ami_name      = "bp-comfyui-${var.workflow_slug}-{{timestamp}}"
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
    Name         = "bp-comfyui-${var.workflow_slug}"
    WorkflowSlug = var.workflow_slug
    BuildTime    = "{{timestamp}}"
    ManagedBy    = "packer"
    BundleId     = var.bundle_id
  }

  ami_description = "ComfyUI GPU worker AMI for workflow: ${var.workflow_slug}"
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
    source      = "scripts/comfyui_worker.py"
    destination = "/tmp/comfyui_worker.py"
  }

  provisioner "shell" {
    script = "scripts/install-python-worker.sh"
  }

  # Sync workflow-specific models from S3 (if bucket configured)
  provisioner "shell" {
    inline = [
      "if [ -n '${var.models_s3_bucket}' ] && [ -n '${var.models_s3_prefix}' ]; then",
      "  echo 'Syncing models from S3...'",
      "  PREFIX='${var.models_s3_prefix}'",
      "  PREFIX=${PREFIX%/}",
      "  aws s3 sync s3://${var.models_s3_bucket}/${PREFIX}/models/ /opt/comfyui/models/ --no-progress",
      "  aws s3 sync s3://${var.models_s3_bucket}/${PREFIX}/custom_nodes/ /opt/comfyui/custom_nodes/ --no-progress || true",
      "  echo 'Model sync complete'",
      "else",
      "  echo 'No S3 model bucket configured, skipping model sync'",
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
