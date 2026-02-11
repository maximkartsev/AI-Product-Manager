"use client";

import React, { useState } from "react";
import SmartFilters, { type FilterValue } from "@/components/ui/SmartFilters";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Columns, Maximize } from "lucide-react";
import type { Table } from "@tanstack/react-table";

export type PreviewSize = "sm" | "md" | "lg";

export function DataTableToolbar<T>({
  entityClass,
  entityName,
  searchValue,
  onSearchChange,
  activeFilters,
  onFiltersChange,
  table,
  visibleColumns,
  onVisibleColumnsChange,
  mediaColumns,
  previewSize,
  onPreviewSizeChange,
  children,
}: {
  entityClass: string;
  entityName: string;
  searchValue: string;
  onSearchChange: (value: string) => void;
  activeFilters: FilterValue[];
  onFiltersChange: (filters: FilterValue[]) => void;
  table: Table<T>;
  visibleColumns: Set<string>;
  onVisibleColumnsChange: (columns: Set<string>) => void;
  mediaColumns?: Record<string, "image" | "video">;
  previewSize?: PreviewSize;
  onPreviewSizeChange?: (size: PreviewSize) => void;
  children?: React.ReactNode;
}) {
  const [columnsMenuOpen, setColumnsMenuOpen] = useState(false);
  const [pendingVisibility, setPendingVisibility] = useState<Set<string>>(new Set());

  return (
    <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4">
      <div className="flex-1 w-full">
        <SmartFilters
          entityClass={entityClass}
          searchValue={searchValue}
          onSearchChange={onSearchChange}
          activeFilters={activeFilters}
          onFiltersChange={onFiltersChange}
          placeholder={`Search ${entityName.toLowerCase()}...`}
        />
      </div>
      <div className="flex gap-2 sm:gap-4">
        {mediaColumns && previewSize && onPreviewSizeChange && (
          <Button
            variant="outline"
            className="flex-1 sm:flex-none"
            onClick={() => {
              onPreviewSizeChange(
                previewSize === "sm" ? "md" : previewSize === "md" ? "lg" : "sm"
              );
            }}
            title={`Preview size: ${previewSize}`}
          >
            <Maximize className="w-4 h-4 mr-2" />
            <span className="hidden sm:inline">
              {previewSize === "sm" ? "S" : previewSize === "md" ? "M" : "L"}
            </span>
          </Button>
        )}
        <DropdownMenu
          open={columnsMenuOpen}
          onOpenChange={(open) => {
            if (open) {
              setPendingVisibility(new Set(visibleColumns));
            } else {
              onVisibleColumnsChange(new Set(pendingVisibility));
            }
            setColumnsMenuOpen(open);
          }}
        >
          <DropdownMenuTrigger asChild>
            <Button
              variant="outline"
              className="flex-1 sm:flex-none"
            >
              <Columns className="w-4 h-4 mr-2" />
              <span className="hidden sm:inline">Columns</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="max-h-72 overflow-y-auto" style={{ animationDuration: '0s' }}>
            {table.getAllColumns()
              .filter(col => col.getCanHide())
              .map(col => (
                <DropdownMenuCheckboxItem
                  key={col.id}
                  checked={pendingVisibility.has(col.id)}
                  onCheckedChange={() => {
                    setPendingVisibility(prev => {
                      const next = new Set(prev);
                      if (next.has(col.id)) next.delete(col.id);
                      else next.add(col.id);
                      return next;
                    });
                  }}
                  onSelect={(e) => e.preventDefault()}
                >
                  {col.columnDef.header as string}
                </DropdownMenuCheckboxItem>
              ))}
          </DropdownMenuContent>
        </DropdownMenu>
        {children}
      </div>
    </div>
  );
}
