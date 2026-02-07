"use client";

import DataTable, { type DataTableFormField } from "@/components/ui/DataTable";
import {
  createAdminCategory,
  deleteAdminCategory,
  getAdminCategories,
  updateAdminCategory,
  type AdminCategory,
  type AdminCategoryPayload,
} from "@/lib/api";
import type { FilterValue } from "@/components/ui/SmartFilters";

type CategoryFormState = {
  name: string;
  slug: string;
  description: string;
};

const initialFormState: CategoryFormState = {
  name: "",
  slug: "",
  description: "",
};

export default function AdminCategoriesPage() {
  const crud = {
    list: async (params: {
      page: number;
      perPage: number;
      search?: string;
      filters?: FilterValue[];
      order?: string;
    }) => {
      const data = await getAdminCategories({
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
    create: async (data: AdminCategoryPayload) => {
      return await createAdminCategory(data);
    },
    update: async (id: number, data: AdminCategoryPayload) => {
      return await updateAdminCategory(id, data);
    },
    delete: async (id: number) => {
      await deleteAdminCategory(id);
    },
  };

  const formFields: DataTableFormField[] = [
    { key: "name", label: "Name", type: "text", required: true, placeholder: "Category name" },
    { key: "slug", label: "Slug", type: "text", required: true, placeholder: "category-slug" },
    { key: "description", label: "Description", type: "textarea", placeholder: "Category description" },
  ];

  const getFormData = (formState: Record<string, any>): AdminCategoryPayload => {
    return {
      name: String(formState.name || "").trim(),
      slug: String(formState.slug || "").trim(),
      description: formState.description ? String(formState.description).trim() || null : null,
    };
  };

  const validateForm = (formState: Record<string, any>): string | null => {
    if (!formState.name?.trim()) return "Name is required.";
    if (!formState.slug?.trim()) return "Slug is required.";
    return null;
  };

  const renderCellValue = (category: AdminCategory, columnKey: string) => {
    if (columnKey === "id") {
      return <span className="text-gray-200">{category.id}</span>;
    }
    if (columnKey === "name") {
      return <span className="text-white">{category.name}</span>;
    }
    if (columnKey === "description") {
      const text = category.description || "";
      return <span className="text-gray-400">{text ? `${text.slice(0, 60)}${text.length > 60 ? "..." : ""}` : "-"}</span>;
    }
    const value = category[columnKey as keyof AdminCategory];
    if (value === null || value === undefined || value === "") {
      return <span className="text-gray-500">-</span>;
    }
    return <span className="text-gray-300">{String(value)}</span>;
  };

  return (
    <DataTable<AdminCategory, AdminCategoryPayload, AdminCategoryPayload>
      entityClass="Category"
      entityName="Category"
      storageKey="admin-categories-table-columns"
      crud={crud}
      formFields={formFields}
      initialFormState={initialFormState}
      getFormData={getFormData}
      validateForm={validateForm}
      getItemId={(item) => item.id}
      getItemTitle={(item) => item.name || `Category #${item.id}`}
      renderCellValue={renderCellValue}
      title="Categories"
      description="Create, update, and manage effect categories."
    />
  );
}
