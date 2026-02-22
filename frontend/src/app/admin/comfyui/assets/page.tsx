"use client";

import { useMemo, useRef, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView, type DataTableFormField } from "@/components/ui/DataTable";
import { EntityFormSheet } from "@/components/ui/EntityFormSheet";
import { DeleteConfirmDialog } from "@/components/ui/DeleteConfirmDialog";
import { useDataTable } from "@/hooks/useDataTable";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { sha256 } from "@noble/hashes/sha2.js";
import { bytesToHex } from "@noble/hashes/utils.js";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";
import { uploadMultipartParts } from "@/lib/multipartUpload";
import {
  createComfyUiAssetFile,
  abortComfyUiAssetMultipartUpload,
  completeComfyUiAssetMultipartUpload,
  deleteComfyUiAssetFile,
  getComfyUiAssetFiles,
  initComfyUiAssetUpload,
  initComfyUiAssetMultipartUpload,
  updateComfyUiAssetFile,
  ApiError,
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

const HASH_CHUNK_SIZE = 8 * 1024 * 1024;
const MULTIPART_THRESHOLD_BYTES = 5 * 1024 * 1024 * 1024;
const MULTIPART_CONCURRENCY = 4;

async function computeSha256(file: File): Promise<string> {
  const hasher = sha256.create();
  let offset = 0;
  while (offset < file.size) {
    const end = Math.min(offset + HASH_CHUNK_SIZE, file.size);
    const chunk = file.slice(offset, end);
    const buffer = await chunk.arrayBuffer();
    hasher.update(new Uint8Array(buffer));
    offset = end;
  }
  return bytesToHex(hasher.digest());
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
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [itemToDelete, setItemToDelete] = useState<ComfyUiAssetFile | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [blockedBundles, setBlockedBundles] = useState<{ id: number; bundle_id: string; name: string | null }[]>([]);
  const [showBlockedDialog, setShowBlockedDialog] = useState(false);

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

  const handleDelete = (item: ComfyUiAssetFile) => {
    setItemToDelete(item);
    setShowDeleteModal(true);
  };

  const deletingIdRef = useRef(deletingId);
  deletingIdRef.current = deletingId;
  const handleEditRef = useRef(handleEdit);
  handleEditRef.current = handleEdit;
  const handleDeleteRef = useRef(handleDelete);
  handleDeleteRef.current = handleDelete;

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
            <Button
              variant="outline"
              size="sm"
              className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-sm px-3"
              onClick={(event) => {
                event.stopPropagation();
                handleDeleteRef.current(row.original);
              }}
              disabled={deletingIdRef.current === row.original.id}
            >
              {deletingIdRef.current === row.original.id ? "Deleting..." : "Delete"}
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
    const contentType = file.type || "application/octet-stream";

    const initPayload = {
      kind,
      mime_type: contentType,
      size_bytes: file.size,
      original_filename: file.name,
      sha256,
      notes: notes || undefined,
    };

    let multipartKey: string | null = null;
    let multipartUploadId: string | null = null;

    try {
      if (file.size > MULTIPART_THRESHOLD_BYTES) {
        const init = await initComfyUiAssetMultipartUpload(initPayload);
        if (init.already_exists) {
          toast.info("Asset already exists; updating metadata.");
        }
        multipartKey = init.key;
        multipartUploadId = init.upload_id;

        const parts = await uploadMultipartParts({
          file,
          partSize: init.part_size,
          partUrls: init.part_urls,
          contentType,
          concurrency: MULTIPART_CONCURRENCY,
        });

        await completeComfyUiAssetMultipartUpload({
          key: init.key,
          upload_id: init.upload_id,
          parts,
        });
      } else {
        const init = await initComfyUiAssetUpload(initPayload);
        if (init.already_exists) {
          toast.info("Asset already exists; updating metadata.");
        }

        const headers = normalizeUploadHeaders(init.upload_headers, contentType);
        const uploadResponse = await fetch(init.upload_url, {
          method: "PUT",
          headers,
          body: file,
        });

        if (!uploadResponse.ok) {
          throw new Error(`Upload failed (${uploadResponse.status}).`);
        }
      }
    } catch (error) {
      if (multipartKey && multipartUploadId) {
        try {
          await abortComfyUiAssetMultipartUpload({ key: multipartKey, upload_id: multipartUploadId });
        } catch {
          // ignore abort failures
        }
      }
      throw error;
    }

    const payload: ComfyUiAssetFileCreateRequest = {
      kind,
      original_filename: file.name,
      content_type: contentType,
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
    <>
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
      <Button
        variant="outline"
        size="sm"
        className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-xs flex-1"
        onClick={(event) => {
          event.stopPropagation();
          handleDelete(item);
        }}
        disabled={deletingId === item.id}
      >
        {deletingId === item.id ? "Deleting..." : "Delete"}
      </Button>
    </>
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

      <Dialog open={showBlockedDialog} onOpenChange={setShowBlockedDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cannot Delete Asset</DialogTitle>
            <DialogDescription className="pb-2">
              This asset is used by {blockedBundles.length} bundle(s) and cannot be deleted.
              Remove the asset from these bundles first:
            </DialogDescription>
          </DialogHeader>
          <ul className="list-disc pl-5 space-y-1 text-sm text-foreground max-h-60 overflow-y-auto">
            {blockedBundles.map((bundle) => (
              <li key={bundle.id}>
                {bundle.name || "Bundle"} <span className="text-muted-foreground">({bundle.bundle_id})</span>
              </li>
            ))}
          </ul>
          <div className="flex justify-end pt-2">
            <Button variant="outline" onClick={() => setShowBlockedDialog(false)}>OK</Button>
          </div>
        </DialogContent>
      </Dialog>

      <DeleteConfirmDialog
        entityName="Asset"
        open={showDeleteModal}
        onOpenChange={(open) => {
          if (!open) {
            setShowDeleteModal(false);
            setItemToDelete(null);
          }
        }}
        itemTitle={itemToDelete?.original_filename || undefined}
        itemId={itemToDelete?.id}
        onConfirm={async () => {
          if (!itemToDelete) return;
          setDeletingId(itemToDelete.id);
          try {
            await deleteComfyUiAssetFile(itemToDelete.id);
            await state.loadItems();
            setShowDeleteModal(false);
            setItemToDelete(null);
            toast.success("Asset deleted");
          } catch (error) {
            if (error instanceof ApiError && error.status === 409) {
              const bundles = (error.data as any)?.data?.bundles ?? [];
              setBlockedBundles(bundles);
              setShowDeleteModal(false);
              setItemToDelete(null);
              setShowBlockedDialog(true);
            } else {
              console.error("Failed to delete Asset.", error);
              toast.error("Failed to delete Asset. Please try again.");
            }
          } finally {
            setDeletingId(null);
          }
        }}
      />
    </>
  );
}
