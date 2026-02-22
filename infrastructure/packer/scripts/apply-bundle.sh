#!/usr/bin/env bash
set -euo pipefail

MODELS_BUCKET="${MODELS_BUCKET:-}"
BUNDLE_PREFIX="${BUNDLE_PREFIX:-}"
INSTALL_ROOT="${INSTALL_ROOT:-/opt/comfyui}"

if [ -z "$MODELS_BUCKET" ] || [ -z "$BUNDLE_PREFIX" ]; then
  echo "MODELS_BUCKET and BUNDLE_PREFIX are required."
  exit 1
fi

BUNDLE_PREFIX="${BUNDLE_PREFIX%/}"
MANIFEST_KEY="${BUNDLE_PREFIX}/manifest.json"
WORK_DIR="$(mktemp -d)"
OPS_FILE="${WORK_DIR}/ops.tsv"
PATHS_FILE="${WORK_DIR}/paths.txt"
MANIFEST_FILE="${WORK_DIR}/manifest.json"
ACTIVE_BUNDLE_FILE="${INSTALL_ROOT}/.active_bundle_id"
INSTALLED_PATHS_FILE="${INSTALL_ROOT}/.installed_bundle_paths"

cleanup() {
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT

echo "Downloading bundle manifest: s3://${MODELS_BUCKET}/${MANIFEST_KEY}"
aws s3 cp "s3://${MODELS_BUCKET}/${MANIFEST_KEY}" "$MANIFEST_FILE"

python3 - "$MANIFEST_FILE" "$OPS_FILE" "$PATHS_FILE" "$INSTALL_ROOT" <<'PY'
import json
import os
import sys

manifest_path, ops_path, paths_path, install_root = sys.argv[1:5]
with open(manifest_path, "r", encoding="utf-8") as handle:
    data = json.load(handle)

assets = data.get("assets", [])
if not isinstance(assets, list):
    raise SystemExit("manifest.assets must be a list")

def is_safe_path(p: str) -> bool:
    if p.startswith("/"):
        return False
    parts = [part for part in p.split("/") if part]
    return all(part not in ("..", ".") for part in parts)

ops_lines = []
paths = []

for asset in assets:
    action = asset.get("action") or "copy"
    target = asset.get("target_path")
    s3_key = asset.get("asset_s3_key") or asset.get("s3_key")
    if not target or not s3_key:
        raise SystemExit("manifest asset missing target_path or asset_s3_key")
    if not is_safe_path(target):
        raise SystemExit(f"unsafe target_path: {target}")

    ops_lines.append(f"{action}\t{s3_key}\t{target}")
    full_path = os.path.join(install_root, target)
    paths.append(full_path)

with open(ops_path, "w", encoding="utf-8") as handle:
    handle.write("\n".join(ops_lines))

with open(paths_path, "w", encoding="utf-8") as handle:
    handle.write("\n".join(paths))
PY

if [ -f "$INSTALLED_PATHS_FILE" ]; then
  comm -23 <(sort "$INSTALLED_PATHS_FILE") <(sort "$PATHS_FILE") | while read -r stale_path; do
    if [[ "$stale_path" == "$INSTALL_ROOT/"* ]]; then
      echo "Removing stale path: $stale_path"
      rm -rf "$stale_path"
    fi
  done
fi

while IFS=$'\t' read -r action s3_key target_path; do
  dest="${INSTALL_ROOT}/${target_path}"
  case "$action" in
    copy)
      mkdir -p "$(dirname "$dest")"
      aws s3 cp "s3://${MODELS_BUCKET}/${s3_key}" "$dest"
      ;;
    extract_zip)
      tmp="${WORK_DIR}/bundle.zip"
      aws s3 cp "s3://${MODELS_BUCKET}/${s3_key}" "$tmp"
      mkdir -p "$dest"
      python3 - "$tmp" "$dest" <<'PY'
import os
import sys
import zipfile

archive, dest = sys.argv[1], sys.argv[2]
dest_real = os.path.realpath(dest)

def is_safe_path(base: str, target: str) -> bool:
    base_real = os.path.realpath(base)
    target_real = os.path.realpath(target)
    return target_real.startswith(base_real + os.sep)

with zipfile.ZipFile(archive, "r") as handle:
    for member in handle.infolist():
        member_path = os.path.join(dest, member.filename)
        if not is_safe_path(dest_real, member_path):
            raise SystemExit(f"unsafe path in zip: {member.filename}")
    handle.extractall(dest)
PY
      ;;
    extract_tar_gz)
      tmp="${WORK_DIR}/bundle.tar.gz"
      aws s3 cp "s3://${MODELS_BUCKET}/${s3_key}" "$tmp"
      mkdir -p "$dest"
      python3 - "$tmp" "$dest" <<'PY'
import os
import sys
import tarfile

archive, dest = sys.argv[1], sys.argv[2]
dest_real = os.path.realpath(dest)

def is_safe_path(base: str, target: str) -> bool:
    base_real = os.path.realpath(base)
    target_real = os.path.realpath(target)
    return target_real.startswith(base_real + os.sep)

with tarfile.open(archive, "r:gz") as handle:
    for member in handle.getmembers():
        member_path = os.path.join(dest, member.name)
        if not is_safe_path(dest_real, member_path):
            raise SystemExit(f"unsafe path in tar.gz: {member.name}")
    handle.extractall(dest)
PY
      ;;
    *)
      echo "Unknown action: $action"
      exit 1
      ;;
  esac
done < "$OPS_FILE"

mv "$PATHS_FILE" "$INSTALLED_PATHS_FILE"

python3 - "$MANIFEST_FILE" "$ACTIVE_BUNDLE_FILE" <<'PY'
import json
import sys

manifest_path, output_path = sys.argv[1], sys.argv[2]
with open(manifest_path, "r", encoding="utf-8") as handle:
    data = json.load(handle)
bundle_id = data.get("bundle_id", "")
with open(output_path, "w", encoding="utf-8") as handle:
    handle.write(str(bundle_id))
PY

echo "Bundle applied successfully."
