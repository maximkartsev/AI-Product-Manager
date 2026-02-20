variable "fleet_slug" {
  type        = string
  description = "Fleet slug (e.g. 'gpu-default'). Used for AMI naming and SSM alias."
}

variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "source_ami_filter_name" {
  type        = string
  default     = "ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-amd64-server-*"
  description = "AMI name filter for the base Ubuntu image"
}

variable "source_ami_owner" {
  type    = string
  default = "099720109477" # Canonical
}

variable "instance_type" {
  type    = string
  default = "g4dn.xlarge"
}

variable "ssh_username" {
  type    = string
  default = "ubuntu"
}

variable "models_s3_bucket" {
  type        = string
  default     = ""
  description = "S3 bucket containing ComfyUI asset bundles and assets"
}

variable "models_s3_prefix" {
  type        = string
  default     = ""
  description = "S3 prefix for a ComfyUI bundle manifest (e.g. 'bundles/<bundle_id>')"
}

variable "bundle_id" {
  type        = string
  default     = ""
  description = "Optional bundle ID for tagging/audit"
}

variable "packer_instance_profile" {
  type        = string
  default     = ""
  description = "Optional IAM instance profile for Packer build (S3 read access)"
}
