"use client";

import React from "react";
import SmartPagination from "@/components/ui/SmartPagination";
import { Table, TableBody, TableCell, TableHeader, TableRow } from "@/components/ui/table";
import {
  FileQuestion,
  Lock,
  AlertCircle,
  Loader2,
  LogIn,
} from "lucide-react";
import {
  DndContext,
  closestCenter,
} from "@dnd-kit/core";
import { restrictToHorizontalAxis } from "@dnd-kit/modifiers";
import {
  SortableContext,
  horizontalListSortingStrategy,
} from "@dnd-kit/sortable";
import { DraggableTableHeader } from "@/components/ui/DraggableTableHeader";
import { DragAlongCell } from "@/components/ui/DragAlongCell";
import { DataTableToolbar } from "@/components/ui/DataTableToolbar";
import { VideoPreviewDialog } from "@/components/ui/VideoPreviewDialog";
import type { UseDataTableReturn } from "@/hooks/useDataTable";

// --- Re-export types for consumer compatibility ---
export type DataTableFormField = {
  key: string;
  label: string;
  helpText?: string;
  type?: "text" | "textarea" | "number" | "email" | "date" | "datetime-local" | "select" | "checkbox";
  required?: boolean;
  placeholder?: string;
  options?: { value: string; label: string }[];
  fullWidth?: boolean;
  section?: string;
  render?: (opts: {
    field: DataTableFormField;
    value: string;
    onChange: (value: string) => void;
    formState: Record<string, any>;
    setFormState: React.Dispatch<React.SetStateAction<Record<string, any>>>;
  }) => React.ReactNode;
};

// --- DataTableView props ---
export type DataTableViewOptions = {
  entityClass: string;
  entityName: string;
  title?: string;
  description?: string;
  readOnly?: boolean;
  onRowClick?: (item: any) => void;
  mediaColumns?: Record<string, "image" | "video">;
};

export type DataTableViewProps<T extends Record<string, any>> = {
  state: UseDataTableReturn<T>;
  options: DataTableViewOptions;
  renderRowActions?: (item: T) => React.ReactNode;
  toolbarActions?: React.ReactNode;
};

// --- DataTableView: the pure rendering component ---
export function DataTableView<T extends Record<string, any>>({
                                                               state,
                                                               options,
                                                               renderRowActions,
                                                               toolbarActions,
                                                             }: DataTableViewProps<T>) {
  const {
    entityName,
    title,
    description,
    onRowClick,
    mediaColumns,
  } = options;

  const {
    items,
    loading,
    errorStatus,
    pagination,
    setPagination,
    searchValue,
    handleSearchChange,
    activeFilters,
    handleFiltersChange,
    availableColumns,
    visibleColumns,
    setVisibleColumns,
    columnOrder,
    orderedVisibleColumns,
    previewSize,
    setPreviewSize,
    videoPreviewUrl,
    setVideoPreviewUrl,
    table,
    sensors,
    handleDragEnd,
    defaultRenderCellValue,
  } = state;

  const entityClass = options.entityClass;

  const desktopOverlay =
      availableColumns.length === 0
          ? null
          : loading
              ? (
                  <Loader2 className="w-6 h-6 text-muted-foreground animate-spin" />
              )
              : errorStatus === 401
                  ? (
                      <div className="flex flex-col items-center justify-center">
                        <LogIn className="w-16 h-16 text-muted-foreground mb-4" />
                        <h3 className="text-xl font-semibold text-foreground mb-2 text-center">Authentication Required</h3>
                        <p className="text-base text-muted-foreground text-center max-w-md px-4">
                          You need to be authenticated to access this page. Please log in to continue.
                        </p>
                      </div>
                  )
                  : errorStatus === 403
                      ? (
                          <div className="flex flex-col items-center justify-center">
                            <Lock className="w-16 h-16 text-muted-foreground mb-4" />
                            <h3 className="text-xl font-semibold text-foreground mb-2 text-center">Access Denied</h3>
                            <p className="text-base text-muted-foreground text-center max-w-md px-4">
                              You don&apos;t have permission to access this page.
                            </p>
                          </div>
                      )
                      : errorStatus === 500
                          ? (
                              <div className="flex flex-col items-center justify-center">
                                <AlertCircle className="w-16 h-16 text-muted-foreground mb-4" />
                                <h3 className="text-xl font-semibold text-foreground mb-2 text-center">Oops! Something went wrong</h3>
                                <p className="text-base text-muted-foreground text-center max-w-md px-4">
                                  We are already fixing it. Please try again in a few minutes.
                                </p>
                              </div>
                          )
                          : errorStatus === 404 || items.length === 0
                              ? (
                                  <div className="flex flex-col items-center justify-center">
                                    <FileQuestion className="w-16 h-16 text-muted-foreground mb-4" />
                                    <h3 className="text-xl font-semibold text-foreground mb-2 text-center">Nothing Found</h3>
                                    <p className="text-base text-muted-foreground text-center max-w-md px-4">
                                      No {entityName.toLowerCase()} were found. Try adjusting your search or filters.
                                    </p>
                                  </div>
                              )
                              : null;

  return (
      <div>
        <div className="space-y-6">
          <header className="space-y-1 md:space-y-2">
            <div>
              <h1 className="text-2xl md:text-3xl font-semibold">{title || entityName}</h1>
              {description && <p className="text-sm md:text-base text-muted-foreground">{description}</p>}
            </div>
          </header>

          <section className="space-y-4">
            <DataTableToolbar
                entityClass={entityClass}
                entityName={entityName}
                searchValue={searchValue}
                onSearchChange={handleSearchChange}
                activeFilters={activeFilters}
                onFiltersChange={handleFiltersChange}
                table={table}
                visibleColumns={visibleColumns}
                onVisibleColumnsChange={setVisibleColumns}
                mediaColumns={mediaColumns}
                previewSize={previewSize}
                onPreviewSizeChange={setPreviewSize}
            >
              {toolbarActions}
            </DataTableToolbar>

            <div className="rounded-lg border border-border overflow-hidden">
              {/* Mobile card view */}
              <div className="block md:hidden">
                {loading ? (
                    <div className="py-8 flex items-center justify-center">
                      <Loader2 className="w-6 h-6 text-muted-foreground animate-spin" />
                    </div>
                ) : errorStatus === 401 ? (
                    <div className="py-8 px-4">
                      <div className="flex flex-col items-center justify-center">
                        <LogIn className="w-12 h-12 text-muted-foreground mb-3" />
                        <h3 className="text-lg font-semibold text-foreground mb-2 text-center">Authentication Required</h3>
                        <p className="text-sm text-muted-foreground text-center max-w-md px-4">
                          You need to be authenticated to access this page. Please log in to continue.
                        </p>
                      </div>
                    </div>
                ) : errorStatus === 403 ? (
                    <div className="py-8 px-4">
                      <div className="flex flex-col items-center justify-center">
                        <Lock className="w-12 h-12 text-muted-foreground mb-3" />
                        <h3 className="text-lg font-semibold text-foreground mb-2 text-center">Access Denied</h3>
                        <p className="text-sm text-muted-foreground text-center max-w-md px-4">
                          You don&apos;t have permission to access this page.
                        </p>
                      </div>
                    </div>
                ) : errorStatus === 500 ? (
                    <div className="py-8 px-4">
                      <div className="flex flex-col items-center justify-center">
                        <AlertCircle className="w-12 h-12 text-muted-foreground mb-3" />
                        <h3 className="text-lg font-semibold text-foreground mb-2 text-center">Oops! Something went wrong</h3>
                        <p className="text-sm text-muted-foreground text-center max-w-md px-4">
                          We are already fixing it. Please try again in a few minutes.
                        </p>
                      </div>
                    </div>
                ) : errorStatus === 404 || items.length === 0 ? (
                    <div className="py-8 px-4">
                      <div className="flex flex-col items-center justify-center">
                        <FileQuestion className="w-12 h-12 text-muted-foreground mb-3" />
                        <h3 className="text-lg font-semibold text-foreground mb-2 text-center">Nothing Found</h3>
                        <p className="text-sm text-muted-foreground text-center max-w-md px-4">
                          No {entityName.toLowerCase()} were found. Try adjusting your search or filters.
                        </p>
                      </div>
                    </div>
                ) : (
                    <div className="divide-y divide-border">
                      {items.map((item) => (
                          <div
                              key={String((item as any).id ?? Math.random())}
                              className={`p-4 hover:bg-accent/50 transition-colors ${onRowClick ? "cursor-pointer" : ""}`}
                              onClick={() => onRowClick?.(item)}
                          >
                            <div className="space-y-3">
                              {orderedVisibleColumns.map((column) => (
                                  <div key={column.key} className="flex flex-col">
                                    <span className="text-xs text-muted-foreground mb-1 font-medium">{column.label}</span>
                                    <div className="text-sm text-foreground">{defaultRenderCellValue(item, column.key)}</div>
                                  </div>
                              ))}
                              {renderRowActions && (
                                  <div className="flex items-center justify-end gap-2 pt-2 border-t border-border">
                                    {renderRowActions(item)}
                                  </div>
                              )}
                            </div>
                          </div>
                      ))}
                    </div>
                )}
              </div>

              {/* Desktop table view */}
              <DndContext
                  collisionDetection={closestCenter}
                  modifiers={[restrictToHorizontalAxis]}
                  onDragEnd={handleDragEnd}
                  sensors={sensors}
              >
                <div className="hidden md:block overflow-x-auto">
                  {availableColumns.length === 0 ? (
                      <div className="py-16 flex items-center justify-center">
                        <Loader2 className="w-6 h-6 text-muted-foreground animate-spin" />
                      </div>
                  ) : (
                      <div className="relative">
                        <Table style={{ minWidth: table.getTotalSize(), width: "100%", tableLayout: "fixed" }}>
                          <TableHeader>
                            {table.getHeaderGroups().map(headerGroup => (
                                <TableRow key={headerGroup.id}>
                                  <SortableContext items={columnOrder} strategy={horizontalListSortingStrategy}>
                                    {headerGroup.headers.map(header => (
                                        <DraggableTableHeader key={header.id} header={header} />
                                    ))}
                                  </SortableContext>
                                </TableRow>
                            ))}
                          </TableHeader>
                          <TableBody>
                            {desktopOverlay ? (
                                <TableRow>
                                  <TableCell
                                      colSpan={Math.max(table.getVisibleLeafColumns().length, 1)}
                                      className="h-60"
                                  />
                                </TableRow>
                            ) : (
                                table.getRowModel().rows.map(row => (
                                    <TableRow
                                        key={row.id}
                                        className={onRowClick ? "cursor-pointer hover:bg-accent/50" : ""}
                                        onClick={() => onRowClick?.(row.original)}
                                    >
                                      <SortableContext items={columnOrder} strategy={horizontalListSortingStrategy}>
                                        {row.getVisibleCells().map(cell => (
                                            <DragAlongCell key={cell.id} cell={cell} />
                                        ))}
                                      </SortableContext>
                                    </TableRow>
                                ))
                            )}
                          </TableBody>
                        </Table>

                        {desktopOverlay && (
                            <div className="pointer-events-none absolute inset-x-0 bottom-0 top-12 z-30 flex items-center justify-center px-4">
                              <div className="-translate-y-6">
                                {desktopOverlay}
                              </div>
                            </div>
                        )}
                      </div>
                  )}
                </div>
              </DndContext>
            </div>

            {!loading && !errorStatus && items.length > 0 && (
                <SmartPagination
                    pagination={pagination}
                    onPageChange={(pageValue) => setPagination((prev) => ({ ...prev, page: pageValue }))}
                    onPerPageChange={(perPageValue) =>
                        setPagination((prev) => ({ ...prev, perPage: perPageValue, page: 1 }))
                    }
                    itemName={entityName.toLowerCase()}
                />
            )}
          </section>

          <VideoPreviewDialog
              url={videoPreviewUrl}
              onClose={() => setVideoPreviewUrl(null)}
          />
        </div>
      </div>
  );
}
