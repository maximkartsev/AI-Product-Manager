"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import * as z from "zod";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView, type DataTableFormField } from "@/components/ui/DataTable";
import { EntityFormSheet } from "@/components/ui/EntityFormSheet";
import { DeleteConfirmDialog } from "@/components/ui/DeleteConfirmDialog";
import { useDataTable } from "@/hooks/useDataTable";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  getAdminWorkflows,
  createAdminWorkflow,
  updateAdminWorkflow,
  deleteAdminWorkflow,
  assignWorkflowFleets,
  getComfyUiFleets,
  initWorkflowAssetUpload,
  ApiError,
  type ComfyUiGpuFleet,
  type AdminWorkflow,
  type AdminWorkflowPayload,
} from "@/lib/api";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { WorkflowPropertyBuilder } from "@/components/admin/WorkflowPropertyBuilder";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";
import { extractErrorMessage } from "@/lib/apiErrors";

// ---- Helpers ----

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/\s+/g, "-")
    .replace(/[^a-z0-9-]/g, "")
    .replace(/-+/g, "-")
    .replace(/^-+|-+$/g, "");
}

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

function parseBoolean(value: string): boolean {
  return ["true", "1", "yes", "y", "on"].includes(value.trim().toLowerCase());
}

// ---- Zod Schema ----

const workflowSchema = z.object({
  name: z.string().min(1, "Name is required").max(255),
  slug: z.string().min(1, "Slug is required").max(255),
  is_active: z.boolean(),
});

// ---- Upload Field Component ----

function UploadField({
  kind,
  value,
  onChange,
  placeholder,
  accept,
  workflowId,
}: {
  kind: "workflow_json" | "property_asset";
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  accept?: string;
  workflowId?: number | null;
}) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleFileSelect(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;

    setUploading(true);
    setError(null);

    try {
      const init = await initWorkflowAssetUpload({
        kind,
        mime_type: file.type || "application/octet-stream",
        size: file.size,
        original_filename: file.name,
        workflow_id: workflowId ?? undefined,
      });

      if (!init.upload_url) {
        throw new Error("Upload URL not provided.");
      }

      const headers = normalizeUploadHeaders(init.upload_headers, file.type || "application/octet-stream");
      const response = await fetch(init.upload_url, {
        method: "PUT",
        headers,
        body: file,
      });

      if (!response.ok) {
        throw new Error(`Upload failed (${response.status}).`);
      }

      onChange(init.path || "");
    } catch (err) {
      const message = err instanceof Error ? err.message : "Upload failed.";
      setError(message);
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        <Input
          type="text"
          className="flex-1"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => inputRef.current?.click()}
          disabled={uploading}
        >
          {uploading ? "Uploading..." : "Upload"}
        </Button>
      </div>
      <input ref={inputRef} type="file" className="hidden" accept={accept} onChange={handleFileSelect} />
      {error ? <span className="text-xs text-red-400">{error}</span> : null}
    </div>
  );
}

// ---- Initial Form State ----

const initialFormState: Record<string, string> = {
  name: "",
  slug: "",
  description: "",
  is_active: "true",
  comfyui_workflow_path: "",
  output_node_id: "",
  output_extension: "mp4",
  output_mime_type: "video/mp4",
  properties: "[]",
  staging_fleet_id: "",
  production_fleet_id: "",
};

type WorkflowFormPayload = AdminWorkflowPayload & {
  staging_fleet_id?: number | null;
  production_fleet_id?: number | null;
};

// ---- Page Component ----

export default function AdminWorkflowsPage() {
  const [showPanel, setShowPanel] = useState(false);
  const [editingItem, setEditingItem] = useState<{ id: number; data: Record<string, any> } | null>(null);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [itemToDelete, setItemToDelete] = useState<AdminWorkflow | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [blockedEffects, setBlockedEffects] = useState<{ id: number; name: string; slug: string }[]>([]);
  const [showBlockedDialog, setShowBlockedDialog] = useState(false);
  const [fleetOptions, setFleetOptions] = useState<ComfyUiGpuFleet[]>([]);

  useEffect(() => {
    getComfyUiFleets({ perPage: 200 })
      .then((data) => setFleetOptions(data.items ?? []))
      .catch(() => {});
  }, []);

  const deletingIdRef = useRef(deletingId);
  deletingIdRef.current = deletingId;

  const handleEdit = (item: AdminWorkflow) => {
    const newFormState: Record<string, any> = { ...initialFormState };
    Object.keys(newFormState).forEach((key) => {
      if (item[key as keyof AdminWorkflow] !== undefined && item[key as keyof AdminWorkflow] !== null) {
        const val = item[key as keyof AdminWorkflow];
        if (key === "properties") {
          newFormState[key] = JSON.stringify(val);
        } else if (Array.isArray(val)) {
          newFormState[key] = (val as unknown as string[]).join(", ");
        } else {
          newFormState[key] = String(val);
        }
      }
    });
    const stagingFleet = (item.fleets ?? []).find((fleet) => fleet.pivot?.stage === "staging");
    const productionFleet = (item.fleets ?? []).find((fleet) => fleet.pivot?.stage === "production");
    newFormState.staging_fleet_id = stagingFleet ? String(stagingFleet.id) : "";
    newFormState.production_fleet_id = productionFleet ? String(productionFleet.id) : "";
    setEditingItem({ id: item.id, data: newFormState });
    setShowPanel(true);
  };

  const handleDelete = (item: AdminWorkflow) => {
    setItemToDelete(item);
    setShowDeleteModal(true);
  };

  const handleEditRef = useRef(handleEdit);
  handleEditRef.current = handleEdit;
  const handleDeleteRef = useRef(handleDelete);
  handleDeleteRef.current = handleDelete;

  const actionsColumn = useMemo<ColumnDef<AdminWorkflow>[]>(() => [{
    id: "_actions",
    header: "Actions",
    enableSorting: false,
    enableHiding: false,
    enableResizing: false,
    size: 150,
    minSize: 150,
    cell: ({ row }: { row: { original: AdminWorkflow } }) => {
      const item = row.original;
      return (
        <div className="flex items-center justify-end gap-2">
          <Button
            variant="outline"
            size="sm"
            className="text-sm px-3"
            onClick={(e) => { e.stopPropagation(); handleEditRef.current(item); }}
          >
            Edit
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-sm px-3"
            onClick={(e) => { e.stopPropagation(); handleDeleteRef.current(item); }}
            disabled={deletingIdRef.current === item.id}
          >
            {deletingIdRef.current === item.id ? "Deleting..." : "Delete"}
          </Button>
        </div>
      );
    },
  }], []);

  const state = useDataTable<AdminWorkflow>({
    entityClass: "Workflow",
    entityName: "Workflow",
    storageKey: "admin-workflows-table-columns",
    settingsKey: "admin-workflows",
    list: async (params: {
      page: number;
      perPage: number;
      search?: string;
      filters?: FilterValue[];
      order?: string;
    }) => {
      const data = await getAdminWorkflows({
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
    renderCellValue: (workflow, columnKey) => {
      if (columnKey === "id") {
        return <span className="text-foreground">{workflow.id}</span>;
      }
      if (columnKey === "name") {
        return <span className="text-foreground font-medium">{workflow.name}</span>;
      }
      if (columnKey === "is_active") {
        const value = Boolean(workflow.is_active);
        return <span className="text-muted-foreground">{value ? "true" : "false"}</span>;
      }
      if (columnKey === "description") {
        const text = workflow.description || "";
        return <span className="text-muted-foreground">{text ? `${text.slice(0, 60)}${text.length > 60 ? "..." : ""}` : "-"}</span>;
      }
      const value = workflow[columnKey as keyof AdminWorkflow];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    extraColumns: actionsColumn,
  });

  const formFields: DataTableFormField[] = [
    {
      key: "name",
      label: "Name",
      type: "text",
      required: true,
      placeholder: "Workflow name",
      section: "Basic Info",
      render: ({ value, onChange, formState, setFormState }) => (
        <Input
          id="name"
          type="text"
          value={value}
          onChange={(e) => {
            const newName = e.target.value;
            const currentSlug = formState.slug || "";
            const autoSlug = slugify(value);
            onChange(newName);
            if (!editingItem && (currentSlug === "" || currentSlug === autoSlug)) {
              setFormState((prev) => ({ ...prev, slug: slugify(newName) }));
            }
          }}
          placeholder="Workflow name"
        />
      ),
    },
    {
      key: "slug",
      label: "Slug",
      type: "text",
      required: true,
      placeholder: "workflow-slug",
      render: ({ value, onChange }) => (
        <div className="flex flex-col gap-1">
          <Input
            id="slug"
            type="text"
            value={value}
            onChange={(event) => onChange(event.target.value)}
            placeholder="workflow-slug"
            disabled={Boolean(editingItem)}
          />
          {editingItem ? (
            <span className="text-xs text-muted-foreground">
              Slug cannot be changed after creation.
            </span>
          ) : null}
        </div>
      ),
    },
    { key: "description", label: "Description", type: "text", placeholder: "Workflow description", fullWidth: true },
    {
      key: "is_active",
      label: "Active",
      type: "checkbox",
      fullWidth: true,
      render: ({ formState, setFormState }) => {
        const isActive = formState.is_active === "true";
        return (
          <div className="flex items-center gap-2">
            <Checkbox
              id="is_active"
              checked={isActive}
              onCheckedChange={(checked) =>
                setFormState((prev) => ({ ...prev, is_active: checked ? "true" : "false" }))
              }
            />
            <label htmlFor="is_active" className="text-sm text-muted-foreground cursor-pointer">
              Is Active
            </label>
          </div>
        );
      },
    },
    {
      key: "comfyui_workflow_path",
      label: "ComfyUI Workflow JSON",
      section: "Workflow JSON",
      render: ({ value, onChange }) => (
        <UploadField
          kind="workflow_json"
          value={value}
          onChange={onChange}
          placeholder="resources/comfyui/workflows/..."
          accept=".json,application/json"
          workflowId={editingItem?.id}
        />
      ),
    },
    {
      key: "output_node_id",
      label: "Output Node ID",
      type: "text",
      placeholder: "Node ID",
      section: "Output Config",
    },
    { key: "output_extension", label: "Output Extension", type: "text", placeholder: "mp4" },
    { key: "output_mime_type", label: "Output MIME Type", type: "text", placeholder: "video/mp4" },
    {
      key: "properties",
      label: "Properties",
      section: "Properties",
      fullWidth: true,
      render: ({ value, onChange }) => (
        <WorkflowPropertyBuilder
          value={(() => { try { return JSON.parse(value || "[]"); } catch { return []; } })()}
          onChange={(props) => onChange(JSON.stringify(props))}
        />
      ),
    },
    {
      key: "staging_fleet_id",
      label: "Staging Fleet",
      section: "ComfyUI Routing",
      render: ({ value, onChange }) => (
        <Select
          value={value || "__none__"}
          onValueChange={(nextValue) => onChange(nextValue === "__none__" ? "" : nextValue)}
        >
          <SelectTrigger>
            <SelectValue placeholder="Select staging fleet" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="__none__">Unassigned</SelectItem>
            {fleetOptions
              .filter((fleet) => fleet.stage === "staging")
              .map((fleet) => (
                <SelectItem key={fleet.id} value={String(fleet.id)}>
                  {fleet.name} ({fleet.slug})
                </SelectItem>
              ))}
          </SelectContent>
        </Select>
      ),
    },
    {
      key: "production_fleet_id",
      label: "Production Fleet",
      render: ({ value, onChange }) => (
        <Select
          value={value || "__none__"}
          onValueChange={(nextValue) => onChange(nextValue === "__none__" ? "" : nextValue)}
        >
          <SelectTrigger>
            <SelectValue placeholder="Select production fleet" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="__none__">Unassigned</SelectItem>
            {fleetOptions
              .filter((fleet) => fleet.stage === "production")
              .map((fleet) => (
                <SelectItem key={fleet.id} value={String(fleet.id)}>
                  {fleet.name} ({fleet.slug})
                </SelectItem>
              ))}
          </SelectContent>
        </Select>
      ),
    },
  ];

  const getFormData = (formState: Record<string, any>): WorkflowFormPayload => {
    const str = (key: string) => {
      const v = String(formState[key] || "").trim();
      return v || null;
    };
    const toOptionalId = (key: string) => {
      const raw = String(formState[key] || "").trim();
      if (!raw) return null;
      const num = Number(raw);
      return Number.isFinite(num) ? num : null;
    };

    let properties = null;
    try {
      const parsed = JSON.parse(String(formState.properties || "[]"));
      if (Array.isArray(parsed)) {
        properties = parsed;
      }
    } catch {
      properties = null;
    }

    return {
      name: str("name") || "",
      slug: str("slug") || "",
      description: str("description"),
      is_active: parseBoolean(String(formState.is_active || "")),
      comfyui_workflow_path: str("comfyui_workflow_path"),
      output_node_id: str("output_node_id"),
      output_extension: str("output_extension"),
      output_mime_type: str("output_mime_type"),
      properties,
      staging_fleet_id: toOptionalId("staging_fleet_id"),
      production_fleet_id: toOptionalId("production_fleet_id"),
    };
  };

  const renderMobileRowActions = (item: AdminWorkflow) => (
    <>
      <Button
        variant="outline"
        size="sm"
        className="text-xs flex-1"
        onClick={(e) => { e.stopPropagation(); handleEdit(item); }}
      >
        Edit
      </Button>
      <Button
        variant="outline"
        size="sm"
        className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-xs flex-1"
        onClick={(e) => { e.stopPropagation(); handleDelete(item); }}
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
          entityClass: "Workflow",
          entityName: "Workflow",
          title: "Workflows",
          description: "Create, update, and manage workflow configurations.",
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
            Add Workflow
          </Button>
        }
      />

      <EntityFormSheet<WorkflowFormPayload, WorkflowFormPayload>
        entityName="Workflow"
        formFields={formFields}
        initialFormState={initialFormState}
        getFormData={getFormData}
        formSchema={workflowSchema}
        availableColumns={state.availableColumns}
        fkOptions={state.fkOptions}
        fkLoading={state.fkLoading}
        open={showPanel}
        onOpenChange={setShowPanel}
        editingItem={editingItem}
        onCreate={async (payload) => {
          const { staging_fleet_id, production_fleet_id, ...workflowPayload } = payload;
          const created = await createAdminWorkflow(workflowPayload);
          await assignWorkflowFleets(created.id, { staging_fleet_id, production_fleet_id });
          return created;
        }}
        onUpdate={async (id, payload) => {
          const { staging_fleet_id, production_fleet_id, ...workflowPayload } = payload;
          const updated = await updateAdminWorkflow(id, workflowPayload);
          await assignWorkflowFleets(id, { staging_fleet_id, production_fleet_id });
          return updated;
        }}
        onSaved={() => state.loadItems()}
      />

      <Dialog open={showBlockedDialog} onOpenChange={setShowBlockedDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cannot Delete Workflow</DialogTitle>
            <DialogDescription className="pb-2">
              This workflow is used by {blockedEffects.length} effect(s) and cannot be deleted.
              Remove the workflow reference from these effects first:
            </DialogDescription>
          </DialogHeader>
          <ul className="list-disc pl-5 space-y-1 text-sm text-foreground max-h-60 overflow-y-auto">
            {blockedEffects.map((e) => (
              <li key={e.id}>{e.name} <span className="text-muted-foreground">({e.slug})</span></li>
            ))}
          </ul>
          <div className="flex justify-end pt-2">
            <Button variant="outline" onClick={() => setShowBlockedDialog(false)}>OK</Button>
          </div>
        </DialogContent>
      </Dialog>

      <DeleteConfirmDialog
        entityName="Workflow"
        open={showDeleteModal}
        onOpenChange={(open) => {
          if (!open) {
            setShowDeleteModal(false);
            setItemToDelete(null);
          }
        }}
        itemTitle={itemToDelete?.name || undefined}
        itemId={itemToDelete?.id}
        onConfirm={async () => {
          if (!itemToDelete) return;
          setDeletingId(itemToDelete.id);
          try {
            await deleteAdminWorkflow(itemToDelete.id);
            await state.loadItems();
            setShowDeleteModal(false);
            setItemToDelete(null);
            toast.success("Workflow deleted");
          } catch (error) {
            if (error instanceof ApiError && error.status === 409) {
              const effects = (error.data as any)?.data?.effects ?? [];
              setBlockedEffects(effects);
              setShowDeleteModal(false);
              setItemToDelete(null);
              setShowBlockedDialog(true);
            } else {
              console.error("Failed to delete Workflow.", error);
              toast.error(extractErrorMessage(error, "Failed to delete Workflow."));
            }
          } finally {
            setDeletingId(null);
          }
        }}
      />
    </>
  );
}
