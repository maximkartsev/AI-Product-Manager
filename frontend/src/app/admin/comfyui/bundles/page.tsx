"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView, type DataTableFormField } from "@/components/ui/DataTable";
import { EntityFormSheet } from "@/components/ui/EntityFormSheet";
import { useDataTable } from "@/hooks/useDataTable";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";
import { Info } from "lucide-react";
import type { FilterValue } from "@/components/ui/SmartFilters";
import {
  createComfyUiAssetBundle,
  getComfyUiAssetBundles,
  getComfyUiAssetFiles,
  getComfyUiAssetBundleManifest,
  updateComfyUiAssetBundle,
  type ComfyUiAssetBundle,
  type ComfyUiAssetBundleCreateRequest,
  type ComfyUiAssetFile,
} from "@/lib/api";

const ACTION_OPTIONS = [
  { value: "copy", label: "Copy" },
  { value: "extract_zip", label: "Extract ZIP" },
  { value: "extract_tar_gz", label: "Extract TAR.GZ" },
 ] as const;

const ASSET_KIND_PATHS: Record<string, string> = {
  checkpoint: "models/checkpoints",
  diffusion_model: "models/diffusion_models",
  lora: "models/loras",
  vae: "models/vae",
  embedding: "models/embeddings",
  text_encoder: "models/text_encoders",
  controlnet: "models/controlnet",
  custom_node: "custom_nodes",
  other: "models/other",
};

type BundleAssetAction = (typeof ACTION_OPTIONS)[number]["value"];

type BundleFormState = {
  name: string;
  notes: string;
  asset_file_ids: number[];
  asset_overrides: Record<number, { target_path?: string; action?: BundleAssetAction }>;
  bundle_id: string;
  s3_prefix: string;
};

const emptyBundleState: BundleFormState = {
  name: "",
  notes: "",
  asset_file_ids: [],
  asset_overrides: {},
  bundle_id: "",
  s3_prefix: "",
};

function formatAssetLabel(asset: ComfyUiAssetFile): string {
  return `${asset.kind} • ${asset.original_filename}`;
}

export default function AdminComfyUiBundlesPage() {
  const [showPanel, setShowPanel] = useState(false);
  const [editingItem, setEditingItem] = useState<{ id: number; data: Record<string, any> } | null>(null);
  const [bundleDraft, setBundleDraft] = useState<BundleFormState>(emptyBundleState);
  const [assetOptions, setAssetOptions] = useState<ComfyUiAssetFile[]>([]);

  useEffect(() => {
    getComfyUiAssetFiles({ perPage: 200 })
      .then((data) => setAssetOptions(data.items ?? []))
      .catch(() => {});
  }, []);

  const handleEdit = (bundle: ComfyUiAssetBundle) => {
    setEditingItem({
      id: bundle.id,
      data: {
        name: bundle.name || "",
        notes: bundle.notes || "",
        bundle_id: bundle.bundle_id || "",
        s3_prefix: bundle.s3_prefix || "",
        asset_file_ids: [],
        asset_overrides: {},
      },
    });
    setShowPanel(true);
  };

  const handleClone = (bundle: ComfyUiAssetBundle) => {
    const manifest = bundle.manifest as { assets?: Array<any> } | null;
    const assets = Array.isArray(manifest?.assets) ? manifest!.assets : [];
    const assetIds = assets.map((item) => Number(item.asset_id)).filter((id) => Number.isFinite(id));
    const overrides: Record<number, { target_path?: string; action?: BundleAssetAction }> = {};
    assets.forEach((item) => {
      const id = Number(item.asset_id);
      if (!Number.isFinite(id)) return;
      overrides[id] = {
        target_path: item.target_path,
        action: item.action as BundleAssetAction | undefined,
      };
    });

    setBundleDraft({
      name: bundle.name ? `${bundle.name} (Copy)` : "",
      notes: bundle.notes || "",
      asset_file_ids: assetIds,
      asset_overrides: overrides,
      bundle_id: "",
      s3_prefix: "",
    });
    setEditingItem(null);
    setShowPanel(true);
  };

  const handleEditRef = useRef(handleEdit);
  handleEditRef.current = handleEdit;
  const handleCloneRef = useRef(handleClone);
  handleCloneRef.current = handleClone;

  const actionsColumn = useMemo<ColumnDef<ComfyUiAssetBundle>[]>(
    () => [
      {
        id: "_actions",
        header: "Actions",
        enableSorting: false,
        enableHiding: false,
        enableResizing: false,
        size: 220,
        minSize: 220,
        cell: ({ row }: { row: { original: ComfyUiAssetBundle } }) => {
          const bundle = row.original;
          return (
            <div className="flex items-center justify-end gap-2">
              <Button
                variant="outline"
                size="sm"
                className="text-sm px-3"
                onClick={async (event) => {
                  event.stopPropagation();
                  try {
                    const manifest = await getComfyUiAssetBundleManifest(bundle.id);
                    window.open(manifest.download_url, "_blank", "noopener,noreferrer");
                  } catch {
                    toast.error("Failed to fetch manifest.");
                  }
                }}
              >
                Manifest
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="text-sm px-3"
                onClick={(event) => {
                  event.stopPropagation();
                  handleCloneRef.current(bundle);
                }}
              >
                Clone
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="text-sm px-3"
                onClick={(event) => {
                  event.stopPropagation();
                  handleEditRef.current(bundle);
                }}
              >
                Edit
              </Button>
            </div>
          );
        },
      },
    ],
    [],
  );

  const state = useDataTable<ComfyUiAssetBundle>({
    entityClass: "ComfyUiAssetBundle",
    entityName: "Bundle",
    storageKey: "admin-comfyui-bundles-table-columns",
    settingsKey: "admin-comfyui-bundles",
    list: async (params: { page: number; perPage: number; search?: string; filters?: FilterValue[]; order?: string }) => {
      const data = await getComfyUiAssetBundles({
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
    renderCellValue: (bundle, columnKey) => {
      if (columnKey === "name") {
        return <span className="text-foreground font-medium">{bundle.name || "-"}</span>;
      }
      if (columnKey === "bundle_id") {
        return <span className="text-foreground font-mono text-xs">{bundle.bundle_id}</span>;
      }
      if (columnKey === "s3_prefix") {
        return <span className="text-muted-foreground font-mono text-xs">{bundle.s3_prefix}</span>;
      }
      if (columnKey === "notes") {
        const text = bundle.notes || "";
        return <span className="text-muted-foreground">{text ? `${text.slice(0, 60)}${text.length > 60 ? "..." : ""}` : "-"}</span>;
      }
      if (columnKey === "created_at") {
        return (
          <span className="text-muted-foreground">
            {bundle.created_at ? new Date(bundle.created_at).toLocaleString() : "-"}
          </span>
        );
      }
      const value = bundle[columnKey as keyof ComfyUiAssetBundle];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    extraColumns: actionsColumn,
  });

  const isEditing = Boolean(editingItem);

  const createFields: DataTableFormField[] = [
    { key: "name", label: "Bundle Name", type: "text", required: true, section: "Bundle" },
    { key: "notes", label: "Notes", type: "textarea", fullWidth: true },
    {
      key: "asset_file_ids",
      label: "Assets",
      fullWidth: true,
      section: "Bundle Contents",
      render: ({ formState, setFormState }) => {
        const selectedIds = new Set<number>(formState.asset_file_ids || []);
        const overrides: Record<number, { target_path?: string; action?: BundleAssetAction }> = formState.asset_overrides || {};

        const toggleAsset = (id: number) => {
          const nextIds = new Set(selectedIds);
          if (nextIds.has(id)) {
            nextIds.delete(id);
            const nextOverrides = { ...overrides };
            delete nextOverrides[id];
            setFormState((prev) => ({
              ...prev,
              asset_file_ids: Array.from(nextIds),
              asset_overrides: nextOverrides,
            }));
            return;
          }
          nextIds.add(id);
          setFormState((prev) => ({
            ...prev,
            asset_file_ids: Array.from(nextIds),
          }));
        };

        const updateOverride = (id: number, patch: { target_path?: string; action?: BundleAssetAction }) => {
          setFormState((prev) => ({
            ...prev,
            asset_overrides: {
              ...overrides,
              [id]: { ...overrides[id], ...patch },
            },
          }));
        };

        return (
          <div className="space-y-4">
            <div className="max-h-48 overflow-y-auto rounded-lg border border-border">
              <table className="w-full text-sm">
                <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="p-2 text-left">Select</th>
                    <th className="p-2 text-left">Asset</th>
                  </tr>
                </thead>
                <tbody>
                  {assetOptions.length === 0 ? (
                    <tr>
                      <td className="p-3 text-muted-foreground" colSpan={2}>
                        No assets available yet.
                      </td>
                    </tr>
                  ) : (
                    assetOptions.map((asset) => (
                      <tr key={asset.id} className="border-t border-border">
                        <td className="p-2">
                          <Checkbox
                            checked={selectedIds.has(asset.id)}
                            onCheckedChange={() => toggleAsset(asset.id)}
                          />
                        </td>
                        <td className="p-2 text-muted-foreground">{formatAssetLabel(asset)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {selectedIds.size > 0 && (
              <div className="space-y-3">
                <p className="text-xs uppercase text-muted-foreground">Overrides (optional)</p>
                {Array.from(selectedIds).map((assetId) => {
                  const asset = assetOptions.find((item) => item.id === assetId);
                  const override = overrides[assetId] || {};
                  const defaultTargetPath = asset
                    ? `${ASSET_KIND_PATHS[asset.kind] ?? ASSET_KIND_PATHS.other}/${asset.original_filename}`
                    : null;
                  return (
                    <div key={assetId} className="rounded-lg border border-border p-3 space-y-2">
                      <p className="text-sm text-foreground">{asset ? formatAssetLabel(asset) : `Asset ${assetId}`}</p>
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <Input
                            value={override.target_path || ""}
                            onChange={(e) => updateOverride(assetId, { target_path: e.target.value })}
                            placeholder="Override target path (optional)"
                          />
                          <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="h-10 w-10 shrink-0"
                            title={`target_path is relative to /opt/comfyui.\n${defaultTargetPath ? `Default: ${defaultTargetPath}\n` : ""}Copy example: models/checkpoints/sdxl.safetensors\nExtract example: custom_nodes/ComfyUI-Manager/`}
                            aria-label="Target path info"
                          >
                            <Info className="h-4 w-4" />
                          </Button>
                        </div>
                        {defaultTargetPath ? (
                          <p className="text-xs text-muted-foreground">
                            Default: <span className="font-mono">{defaultTargetPath}</span> (relative to /opt/comfyui/)
                          </p>
                        ) : null}
                      </div>
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <div className="min-w-0 flex-1">
                            <Select
                              value={override.action || "copy"}
                              onValueChange={(value) => updateOverride(assetId, { action: value as BundleAssetAction })}
                            >
                              <SelectTrigger>
                                <SelectValue placeholder="Select action" />
                              </SelectTrigger>
                              <SelectContent>
                                {ACTION_OPTIONS.map((option) => (
                                  <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </div>
                          <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="h-10 w-10 shrink-0"
                            title={`Action controls how the asset is installed when the bundle is applied.\nCopy: downloads to target_path (file).\nExtract ZIP/TAR.GZ: extracts into target_path (directory).`}
                            aria-label="Bundle asset action info"
                          >
                            <Info className="h-4 w-4" />
                          </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                          Extract actions unpack into <span className="font-mono">target_path/</span> (a directory).
                        </p>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        );
      },
    },
  ];

  const editFields: DataTableFormField[] = [
    { key: "name", label: "Bundle Name", type: "text", required: true, section: "Bundle" },
    { key: "notes", label: "Notes", type: "textarea", fullWidth: true },
    { key: "bundle_id", label: "Bundle ID", render: ({ value }) => <Input value={value} disabled /> },
    { key: "s3_prefix", label: "S3 Prefix", fullWidth: true, render: ({ value }) => <Input value={value} disabled /> },
  ];

  const formFields = isEditing ? editFields : createFields;

  const validateForm = (formState: Record<string, any>): string | null => {
    if (!formState.name?.trim()) return "Bundle name is required.";
    if (!isEditing && (!formState.asset_file_ids || formState.asset_file_ids.length === 0)) {
      return "Select at least one asset.";
    }
    return null;
  };

  const handleCreate = async (formState: Record<string, any>): Promise<ComfyUiAssetBundle> => {
    const overrides: Record<number, { target_path?: string; action?: BundleAssetAction }> = formState.asset_overrides || {};
    const assetOverrides = Object.entries(overrides)
      .map(([id, value]) => ({
        asset_file_id: Number(id),
        target_path: value?.target_path || undefined,
        action: value?.action || undefined,
      }))
      .filter((item) => Number.isFinite(item.asset_file_id));

    const payload: ComfyUiAssetBundleCreateRequest = {
      name: String(formState.name || "").trim(),
      notes: formState.notes ? String(formState.notes).trim() || null : null,
      asset_file_ids: (formState.asset_file_ids || []).map(Number),
      asset_overrides: assetOverrides.length > 0 ? assetOverrides : undefined,
    };
    return createComfyUiAssetBundle(payload);
  };

  const handleUpdate = async (id: number, formState: Record<string, any>): Promise<ComfyUiAssetBundle> => {
    const payload = {
      name: String(formState.name || "").trim() || null,
      notes: formState.notes ? String(formState.notes).trim() || null : null,
    };
    return updateComfyUiAssetBundle(id, payload);
  };

  const handleSaved = () => {
    setShowPanel(false);
    setEditingItem(null);
    setBundleDraft(emptyBundleState);
    state.loadItems();
  };

  const renderMobileRowActions = (item: ComfyUiAssetBundle) => (
    <div className="flex flex-col gap-2">
      <Button
        variant="outline"
        size="sm"
        className="text-xs"
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
        className="text-xs"
        onClick={(event) => {
          event.stopPropagation();
          handleClone(item);
        }}
      >
        Clone
      </Button>
    </div>
  );

  return (
    <>
      <DataTableView
        state={state}
        options={{
          entityClass: "ComfyUiAssetBundle",
          entityName: "Bundle",
          title: "ComfyUI Bundles",
          description: "Create manifest-only bundles and reuse them across fleets.",
        }}
        renderRowActions={renderMobileRowActions}
        toolbarActions={
          <Button
            className="flex-1 sm:flex-none"
            onClick={() => {
              setBundleDraft(emptyBundleState);
              setEditingItem(null);
              setShowPanel(true);
            }}
          >
            Create Bundle
          </Button>
        }
      />

      <EntityFormSheet<Record<string, any>, Record<string, any>>
        entityName={isEditing ? "Bundle" : "Bundle"}
        formFields={formFields}
        initialFormState={isEditing ? initialFromEditing(editingItem, bundleDraft) : bundleDraft}
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

function initialFromEditing(
  editingItem: { id: number; data: Record<string, any> } | null,
  bundleDraft: BundleFormState,
): BundleFormState {
  if (!editingItem) return bundleDraft;
  return {
    name: editingItem.data.name || "",
    notes: editingItem.data.notes || "",
    asset_file_ids: [],
    asset_overrides: {},
    bundle_id: editingItem.data.bundle_id || "",
    s3_prefix: editingItem.data.s3_prefix || "",
  };
}
