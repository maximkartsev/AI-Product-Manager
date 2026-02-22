"use client";

import { useMemo, useRef, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView, type DataTableFormField } from "@/components/ui/DataTable";
import { EntityFormSheet } from "@/components/ui/EntityFormSheet";
import { useDataTable } from "@/hooks/useDataTable";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";
import {
  createComfyUiAssetFile,
  getComfyUiAssetFiles,
  initComfyUiAssetUpload,
  updateComfyUiAssetFile,
  type ComfyUiAssetFile,
  type ComfyUiAssetFileCreateRequest,
} from "@/lib/api";

const ASSET_KIND_OPTIONS = [
  { value: "checkpoint", label: "Checkpoint" },
  { value: "diffusion_model", label: "Diffusion Model" },
  { value: "lora", label: "LoRA" },
  { value: "vae", label: "VAE" },
  { value: "embedding", label: "Embedding" },
  { value: "text_encoder", label: "Text Encoder" },
  { value: "controlnet", label: "ControlNet" },
  { value: "custom_node", label: "Custom Node" },
  { value: "other", label: "Other" },
];

type AssetFormState = {
  kind: string;
  notes: string;
  file: File | null;
  original_filename: string;
  sha256: string;
  s3_key: string;
};

const initialFormState: AssetFormState = {
  kind: "checkpoint",
  notes: "",
  file: null,
  original_filename: "",
  sha256: "",
  s3_key: "",
};

function normalizeUploadHeaders(
  headers: Record<string, string | string[]> | undefined,
  fallbackContentType: string,
): Record<string, string> {
  const normalized: Record<string, string> = {};
  if (headers) {
    for (const [key, value] of Object.entries(headers)) {
      if (Array.isArray(value)) {
        if (value[0]) normalized[key] = value[0];
        continue;
      }
      if (value) normalized[key] = value;
    }
  }
  if (!normalized["Content-Type"]) {
    normalized["Content-Type"] = fallbackContentType;
  }
  return normalized;
}

async function computeSha256(file: File): Promise<string> {
  const buffer = await file.arrayBuffer();
  const hashBuffer = await crypto.subtle.digest("SHA-256", buffer);
  return Array.from(new Uint8Array(hashBuffer))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

function formatBytes(value?: number | null): string {
  if (!value && value !== 0) return "-";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let size = value;
  let unitIndex = 0;
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex += 1;
  }
  return `${size.toFixed(size >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

export default function AdminComfyUiAssetsPage() {
  const [showPanel, setShowPanel] = useState(false);
  const [editingItem, setEditingItem] = useState<{ id: number; data: Record<string, any> } | null>(null);

  const handleEdit = (item: ComfyUiAssetFile) => {
    setEditingItem({
      id: item.id,
      data: {
        kind: item.kind || "checkpoint",
        notes: item.notes || "",
        file: null,
        original_filename: item.original_filename || "",
        sha256: item.sha256 || "",
        s3_key: item.s3_key || "",
      },
    });
    setShowPanel(true);
  };

  const handleEditRef = useRef(handleEdit);
  handleEditRef.current = handleEdit;

  const actionsColumn = useMemo<ColumnDef<ComfyUiAssetFile>[]>(
    () => [
      {
        id: "_actions",
        header: "Actions",
        enableSorting: false,
        enableHiding: false,
        enableResizing: false,
        size: 120,
        minSize: 120,
        cell: ({ row }: { row: { original: ComfyUiAssetFile } }) => (
          <div className="flex items-center justify-end gap-2">
            <Button
              variant="outline"
              size="sm"
              className="text-sm px-3"
              onClick={(event) => {
                event.stopPropagation();
                handleEditRef.current(row.original);
              }}
            >
              Edit
            </Button>
          </div>
        ),
      },
    ],
    [],
  );

  const state = useDataTable<ComfyUiAssetFile>({
    entityClass: "ComfyUiAssetFile",
    entityName: "Asset",
    storageKey: "admin-comfyui-assets-table-columns",
    settingsKey: "admin-comfyui-assets",
    list: async (params: { page: number; perPage: number; search?: string; filters?: FilterValue[]; order?: string }) => {
      const data = await getComfyUiAssetFiles({
        page: params.page,
        perPage: params.perPage,
        search: params.search,
        filters: params.filters,
        order: params.order,
      });
      return {
        items: data.items,
        totalItems: data.totalItems,
        totalPages: data.totalPages,
      };
    },
    getItemId: (item) => item.id,
    renderCellValue: (asset, columnKey) => {
      if (columnKey === "kind") {
        const label = ASSET_KIND_OPTIONS.find((opt) => opt.value === asset.kind)?.label || asset.kind;
        return <span className="text-foreground font-medium">{label}</span>;
      }
      if (columnKey === "original_filename") {
        return <span className="text-foreground">{asset.original_filename}</span>;
      }
      if (columnKey === "sha256") {
        return <span className="text-foreground font-mono text-xs">{asset.sha256}</span>;
      }
      if (columnKey === "s3_key") {
        return <span className="text-muted-foreground font-mono text-xs">{asset.s3_key}</span>;
      }
      if (columnKey === "size_bytes") {
        return <span className="text-muted-foreground">{formatBytes(asset.size_bytes)}</span>;
      }
      if (columnKey === "uploaded_at") {
        return (
          <span className="text-muted-foreground">
            {asset.uploaded_at ? new Date(asset.uploaded_at).toLocaleString() : "-"}
          </span>
        );
      }
      if (columnKey === "notes") {
        const text = asset.notes || "";
        return <span className="text-muted-foreground">{text ? `${text.slice(0, 60)}${text.length > 60 ? "..." : ""}` : "-"}</span>;
      }
      const value = asset[columnKey as keyof ComfyUiAssetFile];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    extraColumns: actionsColumn,
  });

  const isEditing = Boolean(editingItem);

  const createFields: DataTableFormField[] = [
    {
      key: "kind",
      label: "Kind",
      type: "select",
      required: true,
      section: "Asset",
      options: ASSET_KIND_OPTIONS,
    },
    {
      key: "notes",
      label: "Notes",
      type: "textarea",
      fullWidth: true,
      placeholder: "Optional notes about this asset",
    },
    {
      key: "file",
      label: "File",
      fullWidth: true,
      section: "Upload",
      render: ({ formState, setFormState }) => (
        <div className="space-y-2">
          <Input
            id="file"
            type="file"
            onChange={(event) => {
              const file = event.target.files?.[0] ?? null;
              setFormState((prev) => ({
                ...prev,
                file,
                original_filename: file?.name || "",
              }));
            }}
          />
          {formState.file ? (
            <p className="text-xs text-muted-foreground">
              Selected: {formState.file.name} ({formatBytes(formState.file.size)})
            </p>
          ) : (
            <p className="text-xs text-muted-foreground">Choose a file to upload to the models bucket.</p>
          )}
        </div>
      ),
    },
  ];

  const editFields: DataTableFormField[] = [
    {
      key: "kind",
      label: "Kind",
      section: "Asset",
      render: ({ value }) => <Input id="kind" value={value} disabled />,
    },
    {
      key: "sha256",
      label: "SHA256",
      render: ({ value }) => <Input id="sha256" value={value} disabled />,
    },
    {
      key: "s3_key",
      label: "S3 Key",
      fullWidth: true,
      render: ({ value }) => <Input id="s3_key" value={value} disabled />,
    },
    { key: "original_filename", label: "Original Filename", type: "text", placeholder: "filename.safetensors" },
    { key: "notes", label: "Notes", type: "textarea", fullWidth: true, placeholder: "Optional notes" },
  ];

  const formFields = isEditing ? editFields : createFields;

  const validateForm = (formState: Record<string, any>): string | null => {
    if (!formState.kind) return "Kind is required.";
    if (!isEditing && !formState.file) return "Select a file to upload.";
    return null;
  };

  const handleCreate = async (formState: Record<string, any>): Promise<ComfyUiAssetFile> => {
    const file = formState.file as File | null;
    if (!file) {
      throw new Error("Select a file to upload.");
    }

    const sha256 = await computeSha256(file);
    const kind = String(formState.kind || "checkpoint");
    const notes = formState.notes ? String(formState.notes).trim() || null : null;

    const init = await initComfyUiAssetUpload({
      kind,
      mime_type: file.type || "application/octet-stream",
      size_bytes: file.size,
      original_filename: file.name,
      sha256,
      notes: notes || undefined,
    });

    if (init.already_exists) {
      toast.info("Asset already exists; updating metadata.");
    }

    const headers = normalizeUploadHeaders(init.upload_headers, file.type || "application/octet-stream");
    const uploadResponse = await fetch(init.upload_url, {
      method: "PUT",
      headers,
      body: file,
    });

    if (!uploadResponse.ok) {
      throw new Error(`Upload failed (${uploadResponse.status}).`);
    }

    const payload: ComfyUiAssetFileCreateRequest = {
      kind,
      original_filename: file.name,
      content_type: file.type || "application/octet-stream",
      size_bytes: file.size,
      sha256,
      notes: notes || undefined,
    };

    return createComfyUiAssetFile(payload);
  };

  const handleUpdate = async (id: number, formState: Record<string, any>): Promise<ComfyUiAssetFile> => {
    const notes = formState.notes ? String(formState.notes).trim() : "";
    const originalFilename = formState.original_filename ? String(formState.original_filename).trim() : "";
    return updateComfyUiAssetFile(id, {
      notes: notes === "" ? null : notes,
      original_filename: originalFilename === "" ? null : originalFilename,
    });
  };

  const handleSaved = () => {
    setShowPanel(false);
    setEditingItem(null);
    state.loadItems();
  };

  const renderMobileRowActions = (item: ComfyUiAssetFile) => (
    <Button
      variant="outline"
      size="sm"
      className="text-xs flex-1"
      onClick={(event) => {
        event.stopPropagation();
        handleEdit(item);
      }}
    >
      Edit
    </Button>
  );

  return (
    <>
      <DataTableView
        state={state}
        options={{
          entityClass: "ComfyUiAssetFile",
          entityName: "Asset",
          title: "ComfyUI Assets",
          description: "Manage global, content-addressed ComfyUI assets.",
        }}
        renderRowActions={renderMobileRowActions}
        toolbarActions={
          <Button
            className="flex-1 sm:flex-none"
            onClick={() => {
              setEditingItem(null);
              setShowPanel(true);
            }}
          >
            Upload Asset
          </Button>
        }
      />

      <EntityFormSheet<Record<string, any>, Record<string, any>>
        entityName={isEditing ? "Asset" : "Asset Upload"}
        formFields={formFields}
        initialFormState={initialFormState}
        formSchema={undefined}
        availableColumns={state.availableColumns}
        fkOptions={state.fkOptions}
        fkLoading={state.fkLoading}
        open={showPanel}
        onOpenChange={setShowPanel}
        editingItem={editingItem}
        getFormData={(formState) => formState}
        validateForm={validateForm}
        onCreate={handleCreate}
        onUpdate={handleUpdate}
        onSaved={handleSaved}
      />
    </>
  );
}
