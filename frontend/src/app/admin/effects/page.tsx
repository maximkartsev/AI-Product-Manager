"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import * as z from "zod";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView, type DataTableFormField } from "@/components/ui/DataTable";
import { EntityFormSheet } from "@/components/ui/EntityFormSheet";
import { DeleteConfirmDialog } from "@/components/ui/DeleteConfirmDialog";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { useDataTable } from "@/hooks/useDataTable";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Progress } from "@/components/ui/progress";
import EffectConfigFields from "@/components/effects/EffectConfigFields";
import {
  ApiError,
  createAdminEffect,
  deleteAdminEffect,
  getAdminEffects,
  getAdminWorkflows,
  initAdminEffectUpload,
  initEffectAssetUpload,
  initVideoUpload,
  stressTestEffect,
  updateAdminEffect,
  type AdminEffect,
  type AdminEffectPayload,
  type AdminWorkflow,
} from "@/lib/api";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";
import { extractErrorMessage } from "@/lib/apiErrors";
import type { PendingAssetsMap } from "@/lib/effectUploadTypes";

// ---- Helpers ----

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/\s+/g, "-")
    .replace(/[^a-z0-9-]/g, "")
    .replace(/-+/g, "-")
    .replace(/^-+|-+$/g, "");
}

const FORBIDDEN_UPLOAD_HEADERS = new Set([
  "accept-encoding",
  "connection",
  "content-length",
  "cookie",
  "host",
  "origin",
  "referer",
  "user-agent",
]);

function normalizeUploadHeaders(
  headers: Record<string, string | string[]> | undefined,
  fallbackContentType: string,
): Record<string, string> {
  const normalized: Record<string, string> = {};
  if (headers) {
    for (const [key, value] of Object.entries(headers)) {
      const trimmedKey = key.trim();
      if (!trimmedKey) continue;
      if (FORBIDDEN_UPLOAD_HEADERS.has(trimmedKey.toLowerCase())) continue;
      if (Array.isArray(value)) {
        if (value[0]) normalized[trimmedKey] = value[0];
        continue;
      }
      if (value) normalized[trimmedKey] = value;
    }
  }
  const hasContentType = Object.keys(normalized).some((header) => header.toLowerCase() === "content-type");
  if (!hasContentType) {
    normalized["Content-Type"] = fallbackContentType;
  }
  return normalized;
}

function uploadWithProgress(opts: {
  url: string;
  headers: Record<string, string>;
  file: File;
  onProgress?: (value: number) => void;
}): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("PUT", opts.url, true);

    Object.entries(opts.headers).forEach(([key, value]) => {
      xhr.setRequestHeader(key, value);
    });

    xhr.upload.onprogress = (event) => {
      if (!event.lengthComputable) return;
      const pct = Math.round((event.loaded / event.total) * 100);
      opts.onProgress?.(Math.min(100, Math.max(0, pct)));
    };

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve();
      } else {
        reject(new Error(`Upload failed (${xhr.status}).`));
      }
    };

    xhr.onerror = () => {
      reject(new Error("Upload failed."));
    };

    xhr.send(opts.file);
  });
}

function makeUploadId(prefix: string): string {
  const uuid = typeof crypto !== "undefined" && crypto.randomUUID ? crypto.randomUUID() : null;
  const fallback = `${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
  return `${prefix}_${uuid ?? fallback}`;
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
  publication_status: z.enum(["development", "published"]).nullable().optional(),
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
  publication_status: "development",
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
  const [stressTestOpen, setStressTestOpen] = useState(false);
  const [stressTestItem, setStressTestItem] = useState<AdminEffect | null>(null);
  const [stressTestCount, setStressTestCount] = useState("5");
  const [stressTestExecuteOnProduction, setStressTestExecuteOnProduction] = useState(false);
  const [stressTestFile, setStressTestFile] = useState<File | null>(null);
  const [stressTestUploadedFileId, setStressTestUploadedFileId] = useState<number | null>(null);
  const [stressTestInputPayload, setStressTestInputPayload] = useState<Record<string, unknown>>({});
  const [stressTestPendingAssets, setStressTestPendingAssets] = useState<PendingAssetsMap>({});
  const [stressTestUploadId, setStressTestUploadId] = useState<string>(() => makeUploadId("stress"));
  const [stressTestProgress, setStressTestProgress] = useState(0);
  const [stressTestError, setStressTestError] = useState<string | null>(null);
  const [stressTestRunning, setStressTestRunning] = useState(false);
  const stressTestFileInputRef = useRef<HTMLInputElement | null>(null);

  // Load workflows for dropdown
  useEffect(() => {
    getAdminWorkflows({ perPage: 100 }).then((data) => setWorkflows(data.items ?? [])).catch(() => {});
  }, []);

  useEffect(() => {
    if (!stressTestOpen) return;
    setStressTestCount("5");
    setStressTestExecuteOnProduction(false);
    setStressTestFile(null);
    setStressTestUploadedFileId(null);
    setStressTestInputPayload({});
    setStressTestPendingAssets({});
    setStressTestUploadId(makeUploadId("stress"));
    setStressTestProgress(0);
    setStressTestError(null);
    setStressTestRunning(false);
  }, [stressTestOpen, stressTestItem?.id]);

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

  const handleStressTest = (item: AdminEffect) => {
    setStressTestItem(item);
    setStressTestOpen(true);
  };

  const handleEditRef = useRef(handleEdit);
  handleEditRef.current = handleEdit;
  const handleDeleteRef = useRef(handleDelete);
  handleDeleteRef.current = handleDelete;
  const handleStressTestRef = useRef(handleStressTest);
  handleStressTestRef.current = handleStressTest;

  const actionsColumn = useMemo<ColumnDef<AdminEffect>[]>(() => [{
    id: "_actions",
    header: "Actions",
    enableSorting: false,
    enableHiding: false,
    enableResizing: false,
    size: 220,
    minSize: 200,
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
            className="text-sm px-3"
            onClick={(e) => { e.stopPropagation(); handleStressTestRef.current(item); }}
          >
            Stress Test
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

  const stressTestWorkflow = useMemo(
    () => workflows.find((workflow) => workflow.id === stressTestItem?.workflow_id) ?? null,
    [stressTestItem?.workflow_id, workflows],
  );

  const stressTestProperties = useMemo(() => {
    const props = stressTestWorkflow?.properties ?? [];
    return props.filter((prop) => prop.user_configurable && !prop.is_primary_input);
  }, [stressTestWorkflow]);

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
      if (columnKey === "publication_status") {
        const value = effect.publication_status || "published";
        const label = value === "development" ? "development" : "published";
        return <span className="text-muted-foreground capitalize">{label}</span>;
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
      key: "publication_status",
      label: "Publication Status",
      type: "select",
      section: "Publishing",
      fullWidth: true,
      options: [
        { value: "development", label: "Development" },
        { value: "published", label: "Published" },
      ],
      helpText: "Published effects require a production fleet assignment for their workflow.",
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
    const publicationStatus = str("publication_status");

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
      publication_status: publicationStatus,
      thumbnail_url: stripQuery(str("thumbnail_url")),
      preview_video_url: stripQuery(str("preview_video_url")),
    };
  };

  const handleCreateEffect = async (payload: AdminEffectPayload) => {
    try {
      return await createAdminEffect(payload);
    } catch (error) {
      if (payload.publication_status === "published" && error instanceof ApiError) {
        const message = extractErrorMessage(error, "");
        if (message.toLowerCase().includes("production")) {
          throw new Error("Assign a production fleet to the workflow before publishing this effect.");
        }
      }
      throw error;
    }
  };

  const handleUpdateEffect = async (id: number, payload: AdminEffectPayload) => {
    try {
      return await updateAdminEffect(id, payload);
    } catch (error) {
      if (payload.publication_status === "published" && error instanceof ApiError) {
        const message = extractErrorMessage(error, "");
        if (message.toLowerCase().includes("production")) {
          throw new Error("Assign a production fleet to the workflow before publishing this effect.");
        }
      }
      throw error;
    }
  };

  const handleStressTestRun = async () => {
    if (!stressTestItem) return;

    const countValue = parseNumber(stressTestCount);
    if (!countValue || countValue < 1 || countValue > 200) {
      setStressTestError("Count must be between 1 and 200.");
      return;
    }
    if (!stressTestFile && !stressTestUploadedFileId) {
      setStressTestError("Select a video file to upload.");
      return;
    }
    if (!stressTestItem.workflow_id) {
      setStressTestError("This effect does not have an assigned workflow.");
      return;
    }

    setStressTestRunning(true);
    setStressTestError(null);
    setStressTestProgress(0);

    try {
      let inputFileId = stressTestUploadedFileId;
      if (!inputFileId) {
        const file = stressTestFile;
        if (!file) {
          setStressTestError("Select a video file to upload.");
          return;
        }
        const mimeType = file.type || "application/octet-stream";
        const init = await initVideoUpload({
          effect_id: stressTestItem.id,
          mime_type: mimeType,
          size: file.size,
          original_filename: file.name,
        });
        const headers = normalizeUploadHeaders(init.upload_headers, mimeType);
        await uploadWithProgress({
          url: init.upload_url,
          headers,
          file,
          onProgress: (value) => setStressTestProgress(value),
        });
        setStressTestProgress(100);
        inputFileId = init.file?.id ?? null;
        setStressTestUploadedFileId(inputFileId);
      }

      if (!inputFileId) {
        setStressTestError("Upload failed to return a file id.");
        return;
      }

      let payload: Record<string, unknown> = { ...stressTestInputPayload };
      const pendingAssets = Object.values(stressTestPendingAssets);
      if (pendingAssets.length > 0) {
        const uploadId = stressTestUploadId || makeUploadId("stress");
        setStressTestUploadId(uploadId);
        for (const asset of pendingAssets) {
          const mimeType = asset.file.type || "application/octet-stream";
          const init = await initEffectAssetUpload({
            effect_id: stressTestItem.id,
            upload_id: uploadId,
            property_key: asset.propertyKey,
            kind: asset.kind,
            mime_type: mimeType,
            size: asset.file.size,
            original_filename: asset.file.name,
          });
          const headers = normalizeUploadHeaders(init.upload_headers, mimeType);
          const response = await fetch(init.upload_url, {
            method: "PUT",
            headers,
            body: asset.file,
          });
          if (!response.ok) {
            throw new Error(`Asset upload failed (${response.status}).`);
          }
          if (init.file?.id) {
            payload[asset.propertyKey] = init.file.id;
          }
        }
      }

      const inputPayload = Object.keys(payload).length > 0 ? payload : undefined;
      const executeOnProduction =
        stressTestItem.publication_status === "development" ? stressTestExecuteOnProduction : undefined;

      const result = await stressTestEffect(stressTestItem.id, {
        count: countValue,
        input_file_id: inputFileId,
        input_payload: inputPayload,
        execute_on_production_fleet: executeOnProduction,
      });

      const queued = result.queued_count ?? countValue;
      toast.success(`Queued ${queued} stress test run${queued === 1 ? "" : "s"}.`);
      setStressTestOpen(false);
      setStressTestItem(null);
    } catch (error) {
      if (error instanceof ApiError) {
        setStressTestError(extractErrorMessage(error, "Stress test failed."));
      } else if (error instanceof Error) {
        setStressTestError(error.message || "Stress test failed.");
      } else {
        setStressTestError("Stress test failed.");
      }
    } finally {
      setStressTestRunning(false);
    }
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
        className="text-xs flex-1"
        onClick={(e) => { e.stopPropagation(); handleStressTest(item); }}
      >
        Stress Test
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
        onCreate={handleCreateEffect}
        onUpdate={handleUpdateEffect}
        onSaved={() => state.loadItems()}
      />

      <Dialog
        open={stressTestOpen}
        onOpenChange={(open) => {
          if (!open) {
            setStressTestOpen(false);
            setStressTestItem(null);
            return;
          }
          setStressTestOpen(true);
        }}
      >
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>Stress Test Effect</DialogTitle>
            <DialogDescription>
              Queue multiple runs for{" "}
              <span className="font-semibold text-foreground">
                {stressTestItem?.name || stressTestItem?.slug || "this effect"}
              </span>
              .
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <label className="text-xs font-semibold text-muted-foreground">Run Count</label>
                <Input
                  type="number"
                  min={1}
                  max={200}
                  value={stressTestCount}
                  onChange={(event) => setStressTestCount(event.target.value)}
                  placeholder="e.g. 5"
                />
                <p className="text-[11px] text-muted-foreground">Max 200 runs per stress test.</p>
              </div>
              <div className="space-y-2">
                <label className="text-xs font-semibold text-muted-foreground">Execution Stage</label>
                {stressTestItem?.publication_status === "development" ? (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Checkbox
                      id="stress-test-production"
                      checked={stressTestExecuteOnProduction}
                      onCheckedChange={(checked) => setStressTestExecuteOnProduction(Boolean(checked))}
                    />
                    <label htmlFor="stress-test-production" className="cursor-pointer">
                      Execute on production fleet
                    </label>
                  </div>
                ) : (
                  <div className="rounded-md border border-border/60 bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    Published effects always run on the production fleet.
                  </div>
                )}
              </div>
            </div>

            <div className="space-y-2">
              <label className="text-xs font-semibold text-muted-foreground">Input Video</label>
              <div className="flex flex-wrap items-center gap-2">
                <Input
                  readOnly
                  value={
                    stressTestFile?.name
                    ?? (stressTestUploadedFileId ? `Uploaded file #${stressTestUploadedFileId}` : "")
                  }
                  placeholder="Select a video file..."
                  className="flex-1 min-w-[220px]"
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => stressTestFileInputRef.current?.click()}
                  disabled={stressTestRunning}
                >
                  {stressTestFile ? "Replace" : "Choose File"}
                </Button>
              </div>
              <input
                ref={stressTestFileInputRef}
                type="file"
                accept="video/*"
                className="hidden"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  event.target.value = "";
                  if (!file) return;
                  setStressTestFile(file);
                  setStressTestUploadedFileId(null);
                  setStressTestProgress(0);
                  setStressTestError(null);
                }}
              />
              {stressTestProgress > 0 ? <Progress value={stressTestProgress} /> : null}
            </div>

            {stressTestWorkflow ? (
              <div className="rounded-lg border border-border/60 bg-muted/30 px-4 py-3">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-xs font-semibold text-muted-foreground">Configurable Fields</p>
                    <p className="text-[11px] text-muted-foreground">
                      Adjust optional parameters for this stress test run.
                    </p>
                  </div>
                  <span className="text-[11px] text-muted-foreground">
                    Workflow: {stressTestWorkflow.name || stressTestWorkflow.slug || `#${stressTestWorkflow.id}`}
                  </span>
                </div>
                {stressTestProperties.length > 0 ? (
                  <EffectConfigFields
                    properties={stressTestProperties}
                    value={stressTestInputPayload}
                    onChange={setStressTestInputPayload}
                    pendingAssets={stressTestPendingAssets}
                    onPendingAssetsChange={setStressTestPendingAssets}
                  />
                ) : (
                  <p className="mt-3 text-xs text-muted-foreground">No configurable fields for this workflow.</p>
                )}
              </div>
            ) : (
              <div className="rounded-md border border-dashed border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                Select a workflow in the effect to configure stress test inputs.
              </div>
            )}

            {stressTestError ? (
              <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-200">
                {stressTestError}
              </div>
            ) : null}
          </div>

          <div className="flex flex-wrap justify-end gap-2 pt-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setStressTestOpen(false);
                setStressTestItem(null);
              }}
              disabled={stressTestRunning}
            >
              Cancel
            </Button>
            <Button type="button" onClick={handleStressTestRun} disabled={stressTestRunning}>
              {stressTestRunning ? "Queuing..." : "Run Stress Test"}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

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
            toast.error(extractErrorMessage(error, "Failed to delete Effect."));
          } finally {
            setDeletingId(null);
          }
        }}
      />
    </>
  );
}
