"use client";

import { useMemo, useRef, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView, type DataTableFormField } from "@/components/ui/DataTable";
import { EntityFormSheet } from "@/components/ui/EntityFormSheet";
import { DeleteConfirmDialog } from "@/components/ui/DeleteConfirmDialog";
import { useDataTable } from "@/hooks/useDataTable";
import { Button } from "@/components/ui/button";
import {
  createAdminCategory,
  deleteAdminCategory,
  getAdminCategories,
  updateAdminCategory,
  type AdminCategory,
  type AdminCategoryPayload,
} from "@/lib/api";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";

function parseNumber(value: string): number | null {
  const num = Number(value);
  return Number.isFinite(num) ? num : null;
}

type CategoryFormState = {
  name: string;
  slug: string;
  description: string;
  sort_order: string;
};

const initialFormState: CategoryFormState = {
  name: "",
  slug: "",
  description: "",
  sort_order: "0",
};

export default function AdminCategoriesPage() {
  const [showPanel, setShowPanel] = useState(false);
  const [editingItem, setEditingItem] = useState<{ id: number; data: Record<string, any> } | null>(null);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [itemToDelete, setItemToDelete] = useState<AdminCategory | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const deletingIdRef = useRef(deletingId);
  deletingIdRef.current = deletingId;

  const handleEdit = (item: AdminCategory) => {
    const newFormState: Record<string, any> = { ...initialFormState };
    Object.keys(newFormState).forEach((key) => {
      if (item[key as keyof AdminCategory] !== undefined && item[key as keyof AdminCategory] !== null) {
        newFormState[key] = String(item[key as keyof AdminCategory]);
      }
    });
    setEditingItem({ id: item.id, data: newFormState });
    setShowPanel(true);
  };

  const handleDelete = (item: AdminCategory) => {
    setItemToDelete(item);
    setShowDeleteModal(true);
  };

  const handleEditRef = useRef(handleEdit);
  handleEditRef.current = handleEdit;
  const handleDeleteRef = useRef(handleDelete);
  handleDeleteRef.current = handleDelete;

  const actionsColumn = useMemo<ColumnDef<AdminCategory>[]>(() => [{
    id: "_actions",
    header: "Actions",
    enableSorting: false,
    enableHiding: false,
    enableResizing: false,
    size: 150,
    minSize: 150,
    cell: ({ row }: { row: { original: AdminCategory } }) => {
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

  const state = useDataTable<AdminCategory>({
    entityClass: "Category",
    entityName: "Category",
    storageKey: "admin-categories-table-columns",
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
    getItemId: (item) => item.id,
    renderCellValue: (category, columnKey) => {
      if (columnKey === "id") {
        return <span className="text-foreground">{category.id}</span>;
      }
      if (columnKey === "name") {
        return <span className="text-foreground font-medium">{category.name}</span>;
      }
      if (columnKey === "description") {
        const text = category.description || "";
        return <span className="text-muted-foreground">{text ? `${text.slice(0, 60)}${text.length > 60 ? "..." : ""}` : "-"}</span>;
      }
      const value = category[columnKey as keyof AdminCategory];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    extraColumns: actionsColumn,
  });

  const formFields: DataTableFormField[] = [
    { key: "name", label: "Name", type: "text", required: true, placeholder: "Category name" },
    { key: "slug", label: "Slug", type: "text", required: true, placeholder: "category-slug" },
    { key: "description", label: "Description", type: "textarea", placeholder: "Category description" },
    { key: "sort_order", label: "Sort Order", type: "number", required: true, placeholder: "0" },
  ];

  const getFormData = (formState: Record<string, any>): AdminCategoryPayload => {
    return {
      name: String(formState.name || "").trim(),
      slug: String(formState.slug || "").trim(),
      description: formState.description ? String(formState.description).trim() || null : null,
      sort_order: parseNumber(String(formState.sort_order || "")) ?? 0,
    };
  };

  const validateForm = (formState: Record<string, any>): string | null => {
    if (!formState.name?.trim()) return "Name is required.";
    if (!formState.slug?.trim()) return "Slug is required.";
    if (parseNumber(String(formState.sort_order || "")) === null) return "Sort order must be a number.";
    return null;
  };

  const renderMobileRowActions = (item: AdminCategory) => (
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
          entityClass: "Category",
          entityName: "Category",
          title: "Categories",
          description: "Create, update, and manage effect categories.",
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
            Add Category
          </Button>
        }
      />

      <EntityFormSheet<AdminCategoryPayload, AdminCategoryPayload>
        entityName="Category"
        formFields={formFields}
        initialFormState={initialFormState}
        getFormData={getFormData}
        validateForm={validateForm}
        availableColumns={state.availableColumns}
        fkOptions={state.fkOptions}
        fkLoading={state.fkLoading}
        open={showPanel}
        onOpenChange={setShowPanel}
        editingItem={editingItem}
        onCreate={createAdminCategory}
        onUpdate={updateAdminCategory}
        onSaved={() => state.loadItems()}
      />

      <DeleteConfirmDialog
        entityName="Category"
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
            await deleteAdminCategory(itemToDelete.id);
            await state.loadItems();
            setShowDeleteModal(false);
            setItemToDelete(null);
            toast.success("Category deleted");
          } catch (error) {
            console.error("Failed to delete Category.", error);
            toast.error("Failed to delete Category. Please try again.");
          } finally {
            setDeletingId(null);
          }
        }}
      />
    </>
  );
}
