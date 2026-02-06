"use client";

import { useRef, useState } from "react";
import DataTable, { type DataTableFormField } from "@/components/ui/DataTable";
import {
  createAdminEffect,
  deleteAdminEffect,
  getAdminEffects,
  initEffectAssetUpload,
  updateAdminEffect,
  type AdminEffect,
  type AdminEffectPayload,
} from "@/lib/api";
import type { FilterValue } from "@/components/ui/SmartFilters";

type EffectFormState = {
  name: string;
  slug: string;
  description: string;
  type: string;
  credits_cost: string;
  popularity_score: string;
  sort_order: string;
  is_active: string;
  is_premium: string;
  is_new: string;
  comfyui_workflow_path: string;
  thumbnail_url: string;
  preview_video_url: string;
};

const initialFormState: EffectFormState = {
  name: "",
  slug: "",
  description: "",
  type: "transform",
  credits_cost: "5",
  popularity_score: "0",
  sort_order: "0",
  is_active: "true",
  is_premium: "false",
  is_new: "false",
  comfyui_workflow_path: "",
  thumbnail_url: "",
  preview_video_url: "",
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

function parseNumber(value: string): number | null {
  const num = Number(value);
  return Number.isFinite(num) ? num : null;
}

function parseBoolean(value: string): boolean {
  return ["true", "1", "yes", "y", "on"].includes(value.trim().toLowerCase());
}

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
      const init = await initEffectAssetUpload({
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
        <input
          type="text"
          className="flex-1 rounded-md border border-gray-700 bg-gray-800/50 px-3 py-2 text-sm text-white placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-600"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
        />
        <button
          type="button"
          onClick={() => inputRef.current?.click()}
          disabled={uploading}
          className="rounded-md border border-gray-700 px-3 py-2 text-xs text-gray-200 hover:bg-gray-800 disabled:opacity-60"
        >
          {uploading ? "Uploading..." : "Upload"}
        </button>
      </div>
      <input ref={inputRef} type="file" className="hidden" accept={accept} onChange={handleFileSelect} />
      {error ? <span className="text-xs text-red-400">{error}</span> : null}
    </div>
  );
}

export default function AdminEffectsPage() {
  const crud = {
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
    create: async (data: AdminEffectPayload) => {
      return await createAdminEffect(data);
    },
    update: async (id: number, data: AdminEffectPayload) => {
      return await updateAdminEffect(id, data);
    },
    delete: async (id: number) => {
      await deleteAdminEffect(id);
    },
  };

  const formFields: DataTableFormField[] = [
    { key: "name", label: "Name", type: "text", required: true, placeholder: "Effect name" },
    { key: "slug", label: "Slug", type: "text", required: true, placeholder: "effect-slug" },
    { key: "description", label: "Description", type: "textarea", placeholder: "Effect description" },
    { key: "type", label: "Type", type: "text", required: true, placeholder: "transform" },
    { key: "credits_cost", label: "Credits Cost", type: "number", required: true, placeholder: "5" },
    { key: "popularity_score", label: "Popularity Score", type: "number", required: true, placeholder: "0" },
    { key: "sort_order", label: "Sort Order", type: "number", required: true, placeholder: "0" },
    { key: "is_active", label: "Is Active", type: "text", required: true, placeholder: "true/false" },
    { key: "is_premium", label: "Is Premium", type: "text", required: true, placeholder: "true/false" },
    { key: "is_new", label: "Is New", type: "text", required: true, placeholder: "true/false" },
    {
      key: "comfyui_workflow_path",
      label: "Workflow Path",
      type: "text",
      render: ({ value, onChange }) => (
        <UploadField
          kind="workflow"
          value={value}
          onChange={onChange}
          placeholder="resources/comfyui/workflows/..."
          accept=".json,application/json"
        />
      ),
    },
    {
      key: "thumbnail_url",
      label: "Thumbnail URL",
      type: "text",
      render: ({ value, onChange }) => (
        <UploadField
          kind="thumbnail"
          value={value}
          onChange={onChange}
          placeholder="https://..."
          accept="image/*"
        />
      ),
    },
    {
      key: "preview_video_url",
      label: "Preview Video URL",
      type: "text",
      render: ({ value, onChange }) => (
        <UploadField
          kind="preview_video"
          value={value}
          onChange={onChange}
          placeholder="https://..."
          accept="video/*"
        />
      ),
    },
  ];

  const getFormData = (formState: Record<string, any>): AdminEffectPayload => {
    return {
      name: String(formState.name || "").trim(),
      slug: String(formState.slug || "").trim(),
      description: formState.description ? String(formState.description).trim() || null : null,
      type: String(formState.type || "").trim(),
      credits_cost: parseNumber(String(formState.credits_cost || "")) ?? 0,
      popularity_score: parseNumber(String(formState.popularity_score || "")) ?? 0,
      sort_order: parseNumber(String(formState.sort_order || "")) ?? 0,
      is_active: parseBoolean(String(formState.is_active || "")),
      is_premium: parseBoolean(String(formState.is_premium || "")),
      is_new: parseBoolean(String(formState.is_new || "")),
      comfyui_workflow_path: formState.comfyui_workflow_path
        ? String(formState.comfyui_workflow_path).trim() || null
        : null,
      thumbnail_url: formState.thumbnail_url ? String(formState.thumbnail_url).trim() || null : null,
      preview_video_url: formState.preview_video_url ? String(formState.preview_video_url).trim() || null : null,
    };
  };

  const validateForm = (formState: Record<string, any>): string | null => {
    if (!formState.name?.trim()) return "Name is required.";
    if (!formState.slug?.trim()) return "Slug is required.";
    if (!formState.type?.trim()) return "Type is required.";
    if (parseNumber(String(formState.credits_cost || "")) === null) return "Credits cost must be a number.";
    if (parseNumber(String(formState.popularity_score || "")) === null) return "Popularity score must be a number.";
    if (parseNumber(String(formState.sort_order || "")) === null) return "Sort order must be a number.";
    if (!String(formState.is_active || "").trim()) return "Is active is required.";
    if (!String(formState.is_premium || "").trim()) return "Is premium is required.";
    if (!String(formState.is_new || "").trim()) return "Is new is required.";
    return null;
  };

  const renderCellValue = (effect: AdminEffect, columnKey: string) => {
    if (columnKey === "id") {
      return <span className="text-gray-200">{effect.id}</span>;
    }
    if (columnKey === "name") {
      return <span className="text-white">{effect.name}</span>;
    }
    if (["is_active", "is_premium", "is_new"].includes(columnKey)) {
      const value = Boolean(effect[columnKey as keyof AdminEffect]);
      return <span className="text-gray-300">{value ? "true" : "false"}</span>;
    }
    if (["thumbnail_url", "preview_video_url"].includes(columnKey)) {
      const url = effect[columnKey as keyof AdminEffect] as string | null | undefined;
      return url ? (
        <a className="text-indigo-300 hover:text-indigo-200" href={url} target="_blank" rel="noreferrer">
          View
        </a>
      ) : (
        <span className="text-gray-500">-</span>
      );
    }
    if (columnKey === "description") {
      const text = effect.description || "";
      return <span className="text-gray-400">{text ? `${text.slice(0, 60)}${text.length > 60 ? "..." : ""}` : "-"}</span>;
    }
    const value = effect[columnKey as keyof AdminEffect];
    if (value === null || value === undefined || value === "") {
      return <span className="text-gray-500">-</span>;
    }
    return <span className="text-gray-300">{String(value)}</span>;
  };

  return (
    <DataTable<AdminEffect, AdminEffectPayload, AdminEffectPayload>
      entityClass="Effect"
      entityName="Effect"
      storageKey="admin-effects-table-columns"
      crud={crud}
      formFields={formFields}
      initialFormState={initialFormState}
      getFormData={getFormData}
      validateForm={validateForm}
      getItemId={(item) => item.id}
      getItemTitle={(item) => item.name || `Effect #${item.id}`}
      renderCellValue={renderCellValue}
      title="Effects"
      description="Create, update, and manage effect metadata and workflows."
    />
  );
}
