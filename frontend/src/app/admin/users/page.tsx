"use client";

import { useRouter } from "next/navigation";
import { DataTableView } from "@/components/ui/DataTable";
import { useDataTable } from "@/hooks/useDataTable";
import { getAdminUsers, type AdminUser } from "@/lib/api";
import type { FilterValue } from "@/components/ui/SmartFilters";

export default function AdminUsersPage() {
  const router = useRouter();

  const state = useDataTable<AdminUser>({
    entityClass: "User",
    entityName: "User",
    storageKey: "admin-users-table-columns",
    settingsKey: "admin-users",
    list: async (params: {
      page: number;
      perPage: number;
      search?: string;
      filters?: FilterValue[];
      order?: string;
    }) => {
      const data = await getAdminUsers({
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
    renderCellValue: (user, columnKey) => {
      if (columnKey === "is_admin") {
        return user.is_admin ? (
          <span className="inline-flex items-center rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-medium text-orange-400 ring-1 ring-inset ring-orange-500/20">
            Admin
          </span>
        ) : (
          <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">
            User
          </span>
        );
      }
      if (columnKey === "name") {
        return <span className="text-foreground font-medium">{user.name}</span>;
      }
      if (columnKey === "email") {
        return <span className="text-muted-foreground">{user.email}</span>;
      }
      const value = user[columnKey as keyof AdminUser];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
  });

  return (
    <DataTableView
      state={state}
      options={{
        entityClass: "User",
        entityName: "User",
        title: "Users",
        description: "View and manage registered users.",
        readOnly: true,
        onRowClick: (item) => router.push(`/admin/users/${item.id}`),
      }}
    />
  );
}
