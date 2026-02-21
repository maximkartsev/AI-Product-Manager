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
import {
  createAdminEffect,
  deleteAdminEffect,
  getAdminEffects,
  getAdminWorkflows,
  initAdminEffectUpload,
  updateAdminEffect,
  type AdminEffect,
  type AdminEffectPayload,
  type AdminWorkflow,
} from "@/lib/api";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";

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

function parseNumber(value: string): number | null {
  const num = Number(value);
  return Number.isFinite(num) ? num : null;
}

function parseBoolean(value: string): boolean {
  return ["true", "1", "yes", "y", "on"].includes(value.trim().toLowerCase());
}

// ---- Zod Schema ----

const effectSchema = z.object({
  name: z.string().min(1, "Name is required").max(255),
  slug: z.string().min(1, "Slug is required").max(255),
  description: z.string().nullable().optional(),
  category_id: z.number().int().positive().nullable().optional(),
  workflow_id: z.number().int().positive().nullable().optional(),
  tags: z.array(z.string()).nullable().optional(),
  type: z.string().min(1, "Type is required"),
  credits_cost: z.number({ error: "Must be a number" }).min(0, "Must be 0 or more"),
  popularity_score: z.number({ error: "Must be a number" }).int().min(0, "Must be 0 or more"),
  is_active: z.boolean(),
  is_premium: z.boolean(),
  is_new: z.boolean(),
  thumbnail_url: z.string().nullable().optional(),
  preview_video_url: z.string().nullable().optional(),
});

// ---- Upload Field Component ----

function UploadField({
  kind,
  value,
  onChange,
  placeholder,
  accept,
}: {
  kind: "workflow" | "thumbnail" | "preview_video";
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  accept?: string;
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
      const init = await initAdminEffectUpload({
        kind,
        mime_type: file.type || "application/octet-stream",
        size: file.size,
        original_filename: file.name,
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

      const nextValue = kind === "workflow" ? init.path : init.public_url || init.path;
      onChange(nextValue || "");
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
  category_id: "",
  workflow_id: "",
  property_overrides: "{}",
  tags: "",
  type: "configurable",
  credits_cost: "5",
  popularity_score: "100",
  is_active: "true",
  is_premium: "true",
  is_new: "true",
  thumbnail_url: "",
  preview_video_url: "",
  technical_note: "",
};

const PREMIUM_CREDITS = "5";
const NON_PREMIUM_CREDITS = "3";

// ---- Page Component ----

export default function AdminEffectsPage() {
  const [showPanel, setShowPanel] = useState(false);
  const [editingItem, setEditingItem] = useState<{ id: number; data: Record<string, any> } | null>(null);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [itemToDelete, setItemToDelete] = useState<AdminEffect | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [workflows, setWorkflows] = useState<AdminWorkflow[]>([]);

  // Load workflows for dropdown
  useEffect(() => {
    getAdminWorkflows({ perPage: 100 }).then((data) => setWorkflows(data.items ?? [])).catch(() => {});
  }, []);

  const deletingIdRef = useRef(deletingId);
  deletingIdRef.current = deletingId;

  const handleEdit = (item: AdminEffect) => {
    const newFormState: Record<string, any> = { ...initialFormState };
    Object.keys(newFormState).forEach((key) => {
      if (key === "property_overrides") {
        newFormState[key] = item.property_overrides ? JSON.stringify(item.property_overrides) : "{}";
        return;
      }
      if (item[key as keyof AdminEffect] !== undefined && item[key as keyof AdminEffect] !== null) {
        const val = item[key as keyof AdminEffect];
        if (Array.isArray(val)) {
          newFormState[key] = (val as string[]).join(", ");
        } else {
          newFormState[key] = String(val);
        }
      }
    });
    setEditingItem({ id: item.id, data: newFormState });
    setShowPanel(true);
  };

  const handleDelete = (item: AdminEffect) => {
    setItemToDelete(item);
    setShowDeleteModal(true);
  };

  const handleEditRef = useRef(handleEdit);
  handleEditRef.current = handleEdit;
  const handleDeleteRef = useRef(handleDelete);
  handleDeleteRef.current = handleDelete;

  const actionsColumn = useMemo<ColumnDef<AdminEffect>[]>(() => [{
    id: "_actions",
    header: "Actions",
    enableSorting: false,
    enableHiding: false,
    enableResizing: false,
    size: 150,
    minSize: 150,
    cell: ({ row }: { row: { original: AdminEffect } }) => {
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

  const state = useDataTable<AdminEffect>({
    entityClass: "Effect",
    entityName: "Effect",
    storageKey: "admin-effects-table-columns",
    settingsKey: "admin-effects",
    list: async (params: {
      page: number;
      perPage: number;
      search?: string;
      filters?: FilterValue[];
      order?: string;
    }) => {
      const data = await getAdminEffects({
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
    renderCellValue: (effect, columnKey) => {
      if (columnKey === "id") {
        return <span className="text-foreground">{effect.id}</span>;
      }
      if (columnKey === "name") {
        return <span className="text-foreground font-medium">{effect.name}</span>;
      }
      if (["is_active", "is_premium", "is_new"].includes(columnKey)) {
        const value = Boolean(effect[columnKey as keyof AdminEffect]);
        return <span className="text-muted-foreground">{value ? "true" : "false"}</span>;
      }
      if (columnKey === "description") {
        const text = effect.description || "";
        return <span className="text-muted-foreground">{text ? `${text.slice(0, 60)}${text.length > 60 ? "..." : ""}` : "-"}</span>;
      }
      if (columnKey === "category") {
        const cat = effect.category as { name?: string } | null | undefined;
        return <span className="text-muted-foreground">{cat?.name || "-"}</span>;
      }
      if (columnKey === "tags") {
        const tags = effect.tags;
        if (!tags || tags.length === 0) return <span className="text-muted-foreground">-</span>;
        return <span className="text-muted-foreground">{tags.join(", ")}</span>;
      }
      const value = effect[columnKey as keyof AdminEffect];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    mediaColumns: { thumbnail_url: "image", preview_video_url: "video" },
    relationToIdMap: { category: "category_id" },
    extraColumns: actionsColumn,
  });

  const formFields: DataTableFormField[] = [
    {
      key: "name",
      label: "Name",
      type: "text",
      required: true,
      placeholder: "Effect name",
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
            if (currentSlug === "" || currentSlug === autoSlug) {
              setFormState((prev) => ({ ...prev, slug: slugify(newName) }));
            }
          }}
          placeholder="Effect name"
        />
      ),
    },
    { key: "slug", label: "Slug", type: "text", required: true, placeholder: "effect-slug" },
    { key: "description", label: "Description", type: "text", placeholder: "Effect description", fullWidth: true },
    { key: "category_id", label: "Category" },
    {
      key: "workflow_id",
      label: "Workflow",
      type: "select",
      section: "Workflow",
      options: [
        { value: "", label: "-- None --" },
        ...workflows.map((w) => ({ value: String(w.id), label: w.name || w.slug || `#${w.id}` })),
      ],
    },
    {
      key: "property_overrides",
      label: "Property Overrides",
      fullWidth: true,
      render: ({ value, onChange, formState }) => {
        const wfId = Number(formState.workflow_id);
        const wf = workflows.find((w) => w.id === wfId);
        const props = (wf?.properties ?? []).filter((p) => !p.is_primary_input);
        let overrides: Record<string, string> = {};
        try { overrides = JSON.parse(value || "{}"); } catch { /* empty */ }
        if (props.length === 0) {
          return <p className="text-xs text-muted-foreground">Select a workflow to configure property overrides.</p>;
        }
        return (
          <div className="flex flex-col gap-3">
            {props.map((prop) => (
              <div key={prop.key} className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <span className="text-xs font-medium text-foreground">{prop.name || prop.key}</span>
                    <span className="rounded-full border border-border/70 bg-background/60 px-2 py-0.5 text-[10px] font-semibold text-muted-foreground uppercase tracking-wide">
                      {prop.type}
                    </span>
                  </div>
                  {prop.user_configurable ? (
                      <span className="rounded-full border border-red-500/40 bg-red-200/10 px-2 py-0.5 text-[10px] font-semibold text-red-400">
                      User configurable
                    </span>
                  ) : null}
                </div>
                {prop.description ? (
                  <div className="text-[11px] text-muted-foreground">{prop.description}</div>
                ) : null}
                {prop.type === "text" ? (
                  <Input
                    value={overrides[prop.key] ?? ""}
                    onChange={(e) => {
                      const next = { ...overrides, [prop.key]: e.target.value };
                      if (!e.target.value) delete next[prop.key];
                      onChange(JSON.stringify(next));
                    }}
                    placeholder={`Default: ${prop.default_value || "(empty)"}`}
                    className="h-9 text-sm bg-background/80"
                  />
                ) : (
                  <Input
                    value={overrides[prop.key] ?? ""}
                    onChange={(e) => {
                      const next = { ...overrides, [prop.key]: e.target.value };
                      if (!e.target.value) delete next[prop.key];
                      onChange(JSON.stringify(next));
                    }}
                    placeholder={`S3 path for ${prop.type}`}
                    className="h-9 text-sm bg-background/80"
                  />
                )}
              </div>
            ))}
          </div>
        );
      },
    },
    { key: "tags", label: "Tags", type: "text", placeholder: "tag1, tag2, tag3" },
    {
      key: "type",
      label: "Type",
      type: "select",
      required: true,
      section: "Configuration",
      fullWidth: true,
      options: [
        { value: "configurable", label: "Configurable" },
        { value: "simple", label: "Simple" },
      ],
    },
    { key: "credits_cost", label: "Credits Cost", type: "number", required: true, placeholder: "5" },
    { key: "popularity_score", label: "Popularity Score", type: "number", required: true, placeholder: "100" },
    {
      key: "is_premium",
      label: "Flags",
      type: "checkbox",
      fullWidth: true,
      render: ({ formState, setFormState }) => {
        const isPremium = formState.is_premium === "true";
        const isActive = formState.is_active === "true";
        const isNew = formState.is_new === "true";
        return (
          <div className="flex items-center gap-6">
            <div className="flex items-center gap-2">
              <Checkbox
                id="is_premium"
                checked={isPremium}
                onCheckedChange={(checked) => {
                  const newPremium = !!checked;
                  const currentCost = formState.credits_cost || "";
                  const isDefaultCost =
                    currentCost === PREMIUM_CREDITS ||
                    currentCost === NON_PREMIUM_CREDITS ||
                    currentCost === "";
                  setFormState((prev) => ({
                    ...prev,
                    is_premium: newPremium ? "true" : "false",
                    ...(isDefaultCost
                      ? { credits_cost: newPremium ? PREMIUM_CREDITS : NON_PREMIUM_CREDITS }
                      : {}),
                  }));
                }}
              />
              <label htmlFor="is_premium" className="text-sm text-muted-foreground cursor-pointer">
                Is Premium
              </label>
            </div>
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
            <div className="flex items-center gap-2">
              <Checkbox
                id="is_new"
                checked={isNew}
                onCheckedChange={(checked) =>
                  setFormState((prev) => ({ ...prev, is_new: checked ? "true" : "false" }))
                }
              />
              <label htmlFor="is_new" className="text-sm text-muted-foreground cursor-pointer">
                Is New
              </label>
            </div>
          </div>
        );
      },
    },
    {
      key: "thumbnail_url",
      label: "Thumbnail URL",
      section: "Media",
      render: ({ value, onChange }) => (
        <UploadField kind="thumbnail" value={value} onChange={onChange} placeholder="https://..." accept="image/*" />
      ),
    },
    {
      key: "preview_video_url",
      label: "Preview Video URL",
      render: ({ value, onChange }) => (
        <UploadField kind="preview_video" value={value} onChange={onChange} placeholder="https://..." accept="video/*" />
      ),
    },
    {
      key: "technical_note",
      label: "Technical settings",
      section: "Technical",
      fullWidth: true,
      render: () => (
        <div className="rounded-md border border-dashed border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
          Technical settings are managed on the linked Workflow. Update workflow JSON, outputs, and placeholders there.
        </div>
      ),
    },
  ];

  const getFormData = (formState: Record<string, any>): AdminEffectPayload => {
    const str = (key: string) => {
      const v = String(formState[key] || "").trim();
      return v || null;
    };
    const stripQuery = (value: string | null) => {
      if (!value) return null;
      const trimmed = value.trim();
      if (!trimmed) return null;
      return trimmed.split(/[?#]/)[0] || null;
    };

    const rawTags = String(formState.tags || "").trim();
    const tags = rawTags
      ? rawTags.split(",").map((t) => t.trim()).filter(Boolean)
      : null;

    const catId = formState.category_id ? Number(formState.category_id) : null;

    const wfId = formState.workflow_id ? Number(formState.workflow_id) : null;
    let propertyOverrides: Record<string, string> | null = null;
    try {
      const parsed = JSON.parse(String(formState.property_overrides || "{}"));
      if (parsed && typeof parsed === "object" && Object.keys(parsed).length > 0) {
        propertyOverrides = parsed;
      }
    } catch { /* empty */ }

    return {
      name: str("name") || "",
      slug: str("slug") || "",
      description: str("description"),
      category_id: catId && Number.isFinite(catId) ? catId : null,
      workflow_id: wfId && Number.isFinite(wfId) ? wfId : null,
      property_overrides: propertyOverrides,
      tags,
      type: str("type") || "",
      credits_cost: parseNumber(String(formState.credits_cost || "")) ?? 0,
      popularity_score: parseNumber(String(formState.popularity_score || "")) ?? 0,
      is_active: parseBoolean(String(formState.is_active || "")),
      is_premium: parseBoolean(String(formState.is_premium || "")),
      is_new: parseBoolean(String(formState.is_new || "")),
      thumbnail_url: stripQuery(str("thumbnail_url")),
      preview_video_url: stripQuery(str("preview_video_url")),
    };
  };

  const renderMobileRowActions = (item: AdminEffect) => (
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
          entityClass: "Effect",
          entityName: "Effect",
          title: "Effects",
          description: "Create, update, and manage effect metadata and workflows.",
          mediaColumns: { thumbnail_url: "image", preview_video_url: "video" },
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
            Add Effect
          </Button>
        }
      />

      <EntityFormSheet<AdminEffectPayload, AdminEffectPayload>
        entityName="Effect"
        formFields={formFields}
        initialFormState={initialFormState}
        getFormData={getFormData}
        formSchema={effectSchema}
        availableColumns={state.availableColumns}
        fkOptions={state.fkOptions}
        fkLoading={state.fkLoading}
        open={showPanel}
        onOpenChange={setShowPanel}
        editingItem={editingItem}
        onCreate={createAdminEffect}
        onUpdate={updateAdminEffect}
        onSaved={() => state.loadItems()}
      />

      <DeleteConfirmDialog
        entityName="Effect"
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
            await deleteAdminEffect(itemToDelete.id);
            await state.loadItems();
            setShowDeleteModal(false);
            setItemToDelete(null);
            toast.success("Effect deleted");
          } catch (error) {
            console.error("Failed to delete Effect.", error);
            toast.error("Failed to delete Effect. Please try again.");
          } finally {
            setDeletingId(null);
          }
        }}
      />
    </>
  );
}
