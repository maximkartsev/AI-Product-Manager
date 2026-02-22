## ComfyUI assets, bundles, fleets (no S3 duplication)

### S3 layout

All objects live in the **models bucket** (e.g. `bp-models-<account>-<stage>`).

- **Assets (content-addressed singletons)**:
  - Key: `assets/<kind>/<sha256>`
  - `kind`: `checkpoint|lora|vae|embedding|controlnet|custom_node|other`
  - The object key does **not** include the original filename. The original filename is stored in the DB and used when installing into ComfyUI paths.

- **Bundles (manifest only; no copied assets)**:
  - Prefix: `bundles/<bundle_id>/`
  - Manifest: `bundles/<bundle_id>/manifest.json`

### Bundle manifest schema (versioned JSON)

The manifest is designed to be consumed by:
- Packer (AMI bake time)
- EC2 user-data (boot time)
- “apply bundle” (SSM to a running instance)

#### Top-level

- `manifest_version` (number): currently `1`
- `bundle_id` (string)
- `name` (string)
- `created_at` (ISO-8601 string)
- `notes` (string|null)
- `assets` (array)

#### Asset item

Each `assets[]` entry describes how to install one S3 object into the ComfyUI filesystem.

- `asset_id` (number): DB id
- `kind` (string)
- `sha256` (string)
- `asset_s3_key` (string): `assets/<kind>/<sha256>`
- `original_filename` (string)
- `target_path` (string): relative to `/opt/comfyui/` (e.g. `models/checkpoints/foo.safetensors`)
- `action` (string):
  - `copy` (default): download object and write exactly to `target_path`
  - `extract_zip`: download object and unzip into `target_path` directory (for custom nodes shipped as zip)
  - `extract_tar_gz`: download object and untar into `target_path` directory

#### target_path guidance

- Always relative to `/opt/comfyui/` (no leading `/`).
- `copy`: include the filename (e.g. `models/checkpoints/sdxl.safetensors`).
- `extract_*`: point to a directory (e.g. `custom_nodes/ComfyUI-Manager`) and ensure the archive contents are relative to that folder.

#### Example

```json
{
  "manifest_version": 1,
  "bundle_id": "6c2e1b2c-2a7d-4a0f-9d2d-4a0b1d2c3e4f",
  "name": "Base SDXL + nodes",
  "created_at": "2026-02-20T12:34:56Z",
  "notes": "Pinned for staging",
  "assets": [
    {
      "asset_id": 123,
      "kind": "checkpoint",
      "sha256": "abc123...",
      "asset_s3_key": "assets/checkpoint/abc123...",
      "original_filename": "sdxl.safetensors",
      "target_path": "models/checkpoints/sdxl.safetensors",
      "action": "copy"
    },
    {
      "asset_id": 456,
      "kind": "custom_node",
      "sha256": "def456...",
      "asset_s3_key": "assets/custom_node/def456...",
      "original_filename": "comfyui-manager.zip",
      "target_path": "custom_nodes/ComfyUI-Manager",
      "action": "extract_zip"
    }
  ]
}
```

