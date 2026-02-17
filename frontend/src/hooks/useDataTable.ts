"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  useReactTable,
  getCoreRowModel,
  type ColumnDef,
  type VisibilityState,
  type SortingState,
  type ColumnSizingState,
  type Table,
} from "@tanstack/react-table";
import {
  KeyboardSensor,
  MouseSensor,
  TouchSensor,
  type DragEndEvent,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import { arrayMove } from "@dnd-kit/sortable";
import { ApiError, getAvailableColumns, getFilterOptions, type ColumnConfig } from "@/lib/api";
import type { FilterValue } from "@/components/ui/SmartFilters";
import type { PaginationData } from "@/components/ui/SmartPagination";
import { useDebounce } from "@/hooks/useDebounce";
import type { PreviewSize } from "@/components/ui/DataTableToolbar";
import React from "react";
import { Play } from "lucide-react";

const PREVIEW_SIZE_CLASSES: Record<PreviewSize, { container: string; icon: string }> = {
  sm: { container: "h-10 w-10", icon: "h-4 w-4" },
  md: { container: "h-16 w-16", icon: "h-6 w-6" },
  lg: { container: "h-24 w-24", icon: "h-8 w-8" },
};

const MIN_COL_WIDTH = 120;

export type UseDataTableOptions<T> = {
  entityClass: string;
  entityName: string;
  storageKey: string;
  settingsKey?: string;

  list: (params: {
    page: number;
    perPage: number;
    search?: string;
    filters?: FilterValue[];
    order?: string;
  }) => Promise<{
    items: T[];
    totalItems: number;
    totalPages: number;
  }>;

  getItemId: (item: T) => number;
  renderCellValue?: (item: T, columnKey: string) => React.ReactNode;

  mediaColumns?: Record<string, "image" | "video">;
  relationToIdMap?: Record<string, string>;

  /** Additional TanStack column defs appended after the dynamic columns (e.g. actions) */
  extraColumns?: ColumnDef<T>[];
};

export type UseDataTableReturn<T> = {
  // Data
  items: T[];
  loading: boolean;
  errorStatus: 401 | 403 | 404 | 500 | null;

  // Pagination
  pagination: PaginationData;
  setPagination: React.Dispatch<React.SetStateAction<PaginationData>>;

  // Search & filters
  searchValue: string;
  handleSearchChange: (value: string) => void;
  activeFilters: FilterValue[];
  handleFiltersChange: (filters: FilterValue[]) => void;

  // Columns metadata
  availableColumns: ColumnConfig[];
  visibleColumns: Set<string>;
  setVisibleColumns: React.Dispatch<React.SetStateAction<Set<string>>>;
  columnOrder: string[];
  orderedVisibleColumns: ColumnConfig[];

  // FK
  fkOptions: Record<string, { value: string; label: string }[]>;
  fkLoading: Record<string, boolean>;

  // Media preview
  previewSize: PreviewSize;
  setPreviewSize: React.Dispatch<React.SetStateAction<PreviewSize>>;
  videoPreviewUrl: string | null;
  setVideoPreviewUrl: React.Dispatch<React.SetStateAction<string | null>>;

  // TanStack table
  table: Table<T>;

  // DnD
  sensors: ReturnType<typeof useSensors>;
  handleDragEnd: (event: DragEndEvent) => void;

  // Actions
  loadItems: () => Promise<void>;
  defaultRenderCellValue: (item: T, columnKey: string) => React.ReactNode;
};

export function useDataTable<T extends Record<string, any>>(
  options: UseDataTableOptions<T>,
): UseDataTableReturn<T> {
  const {
    entityClass,
    entityName,
    storageKey,
    settingsKey,
    list,
    getItemId,
    renderCellValue,
    mediaColumns,
    relationToIdMap = {},
    extraColumns,
  } = options;

  // --- Refs for caller-provided functions/objects (stabilise dependency arrays) ---
  const listRef = useRef(list);
  listRef.current = list;

  const renderCellValueRef = useRef(renderCellValue);
  renderCellValueRef.current = renderCellValue;

  const mediaColumnsRef = useRef(mediaColumns);
  mediaColumnsRef.current = mediaColumns;

  const relationToIdMapRef = useRef(relationToIdMap);
  relationToIdMapRef.current = relationToIdMap;

  // --- Core state ---
  const [items, setItems] = useState<T[]>([]);
  const [pagination, setPagination] = useState<PaginationData>({
    page: 1,
    perPage: 20,
    totalPages: 0,
    totalItems: 0,
  });
  const [searchValue, setSearchValue] = useState("");
  const [activeFilters, setActiveFilters] = useState<FilterValue[]>([]);
  const [order, setOrder] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [errorStatus, setErrorStatus] = useState<401 | 403 | 404 | 500 | null>(null);

  // --- Column state ---
  const [availableColumns, setAvailableColumns] = useState<ColumnConfig[]>([]);
  const [columnOrder, setColumnOrder] = useState<string[]>([]);
  const [columnWidths, setColumnWidths] = useState<Record<string, number>>({});
  const [visibleColumns, setVisibleColumns] = useState<Set<string>>(new Set<string>());

  // --- FK state ---
  const [fkOptions, setFkOptions] = useState<Record<string, { value: string; label: string }[]>>({});
  const [fkLoading, setFkLoading] = useState<Record<string, boolean>>({});

  // --- Media preview ---
  const [previewSize, setPreviewSize] = useState<PreviewSize>("sm");
  const [videoPreviewUrl, setVideoPreviewUrl] = useState<string | null>(null);

  // --- Debounced values ---
  const debouncedSearchValue = useDebounce(searchValue, 500);
  const debouncedActiveFilters = useDebounce(activeFilters, 500);

  // --- Settings persistence ---
  const [settingsLoaded, setSettingsLoaded] = useState(false);
  const settingsLoadedRef = useRef(false);

  useEffect(() => {
    if (!settingsKey) {
      setSettingsLoaded(true);
      settingsLoadedRef.current = true;
      return;
    }
    const loadSettings = async () => {
      try {
        const { getAdminUiSettings } = await import("@/lib/api");
        const allSettings = await getAdminUiSettings();
        const tableSettings = allSettings?.[settingsKey];
        if (tableSettings) {
          if (tableSettings.visibleColumns) {
            setVisibleColumns(new Set(tableSettings.visibleColumns));
          }
          if (tableSettings.columnOrder) {
            setColumnOrder(tableSettings.columnOrder);
          }
          if (tableSettings.columnWidths) {
            setColumnWidths(tableSettings.columnWidths);
          }
          if (tableSettings.perPage) {
            setPagination((prev) => ({ ...prev, perPage: tableSettings.perPage }));
          }
          if (tableSettings.previewSize) {
            setPreviewSize(tableSettings.previewSize);
          }
        }
      } catch {
        // Fallback to localStorage silently
      } finally {
        setSettingsLoaded(true);
        settingsLoadedRef.current = true;
      }
    };
    loadSettings();
  }, [settingsKey]);

  // Debounced save to server
  const settingsToSave = useMemo(
    () => ({
      visibleColumns: Array.from(visibleColumns),
      columnOrder,
      columnWidths,
      perPage: pagination.perPage,
      previewSize,
    }),
    [visibleColumns, columnOrder, columnWidths, pagination.perPage, previewSize],
  );

  const debouncedSettings = useDebounce(settingsToSave, 2000);

  useEffect(() => {
    if (!settingsKey || !settingsLoadedRef.current || debouncedSettings.visibleColumns.length === 0) return;
    const save = async () => {
      try {
        const { updateAdminUiSettings } = await import("@/lib/api");
        await updateAdminUiSettings({ [settingsKey]: debouncedSettings });
      } catch {
        if (typeof window !== "undefined") {
          localStorage.setItem(storageKey, JSON.stringify(debouncedSettings.visibleColumns));
        }
      }
    };
    save();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSettings, settingsKey, storageKey]);

  // --- Data loading ---
  const loadItems = useCallback(async () => {
    setLoading(true);
    setErrorStatus(null);
    try {
      const data = await listRef.current({
        page: pagination.page,
        perPage: pagination.perPage,
        search: debouncedSearchValue || undefined,
        filters: debouncedActiveFilters,
        order: order || undefined,
      });
      setItems(data.items ?? []);
      setPagination((prev) => ({
        ...prev,
        totalPages: data.totalPages,
        totalItems: data.totalItems,
      }));
      if (
        data.items.length === 0 &&
        data.totalItems === 0 &&
        !debouncedSearchValue &&
        debouncedActiveFilters.length === 0
      ) {
        setErrorStatus(404);
      } else {
        setErrorStatus(null);
      }
    } catch (error) {
      console.error(`Failed to load ${entityName}.`, error);
      if (error instanceof ApiError) {
        if (error.status === 401) setErrorStatus(401);
        else if (error.status === 403) setErrorStatus(403);
        else if (error.status === 404) setErrorStatus(404);
        else setErrorStatus(500);
      } else {
        setErrorStatus(500);
      }
    } finally {
      setLoading(false);
    }
  }, [
    debouncedActiveFilters,
    pagination.page,
    pagination.perPage,
    debouncedSearchValue,
    order,
    entityName,
  ]);

  useEffect(() => {
    loadItems();
  }, [loadItems]);

  // --- Fetch columns + FK options ---
  useEffect(() => {
    if (!settingsLoaded) return;
    const fetchColumns = async () => {
      try {
        const columns = await getAvailableColumns(entityClass);
        setAvailableColumns(columns);

        setVisibleColumns((current) => {
          if (current.size > 0) return current;

          let savedColumns: string[] | null = null;
          if (typeof window !== "undefined") {
            const saved = localStorage.getItem(storageKey);
            if (saved) {
              try {
                const parsed = JSON.parse(saved) as string[];
                if (parsed.length > 0) savedColumns = parsed;
              } catch { /* ignore */ }
            }
          }

          if (savedColumns) return new Set(savedColumns);
          return new Set(columns.map((col) => col.key));
        });

        setColumnOrder((current) => {
          const allKeys = columns.map((col) => col.key);
          if (current.length > 0) {
            const allSet = new Set(allKeys);
            const currentSet = new Set(current);
            const merged = current.filter((k) => allSet.has(k));
            const newKeys = allKeys.filter((k) => !currentSet.has(k));
            const reconciled = [...merged, ...newKeys];
            if (mediaColumnsRef.current) {
              const mediaKeys = reconciled.filter((k) => k in mediaColumnsRef.current!);
              const rest = reconciled.filter((k) => !(k in mediaColumnsRef.current!));
              return [...mediaKeys, ...rest];
            }
            return reconciled;
          }
          if (mediaColumnsRef.current) {
            const mediaKeys = allKeys.filter((k) => k in mediaColumnsRef.current!);
            const rest = allKeys.filter((k) => !(k in mediaColumnsRef.current!));
            return [...mediaKeys, ...rest];
          }
          return allKeys;
        });

        // Auto-fetch FK options
        const fkColumns = columns.filter((col) => col.foreignKey);
        if (fkColumns.length > 0) {
          const loadingState: Record<string, boolean> = {};
          fkColumns.forEach((col) => { loadingState[col.foreignKey!.field] = true; });
          setFkLoading((prev) => ({ ...prev, ...loadingState }));

          await Promise.all(
            fkColumns.map(async (col) => {
              try {
                const opts = await getFilterOptions(entityClass, col.foreignKey!.field);
                setFkOptions((prev) => ({
                  ...prev,
                  [col.foreignKey!.field]: opts.map((opt) => ({
                    value: String(opt.id),
                    label: String(opt.name),
                  })),
                }));
              } catch { /* non-critical */ }
              finally {
                setFkLoading((prev) => ({ ...prev, [col.foreignKey!.field]: false }));
              }
            }),
          );
        }
      } catch (error) {
        console.error("Failed to fetch columns:", error);
      }
    };
    fetchColumns();
  }, [entityClass, storageKey, settingsLoaded]);

  // Save visible columns to localStorage (non-settings mode)
  useEffect(() => {
    if (typeof window !== "undefined" && visibleColumns.size > 0 && !settingsKey) {
      localStorage.setItem(storageKey, JSON.stringify(Array.from(visibleColumns)));
    }
  }, [visibleColumns, storageKey, settingsKey]);

  // --- Ordered visible columns (for mobile card view) ---
  const orderedVisibleColumns = useMemo(() => {
    const visible = availableColumns.filter((col) => visibleColumns.has(col.key));
    if (columnOrder.length === 0) return visible;
    const orderMap = new Map(columnOrder.map((key, idx) => [key, idx]));
    return [...visible].sort((a, b) => {
      const ai = orderMap.get(a.key) ?? 999;
      const bi = orderMap.get(b.key) ?? 999;
      return ai - bi;
    });
  }, [availableColumns, visibleColumns, columnOrder]);

  // --- Search/filter handlers ---
  const handleSearchChange = useCallback((value: string) => {
    setSearchValue(value);
    setPagination((prev) => ({ ...prev, page: 1 }));
  }, []);

  const handleFiltersChange = useCallback((filters: FilterValue[]) => {
    setActiveFilters(filters);
    setPagination((prev) => ({ ...prev, page: 1 }));
  }, []);

  // --- Media rendering ---
  const renderMediaCell = useCallback((url: string | null | undefined, type: "image" | "video") => {
    if (!url) {
      return React.createElement("span", { className: "text-muted-foreground" }, "-");
    }
    if (type === "image") {
      return React.createElement("img", {
        src: url,
        alt: "",
        className: `${PREVIEW_SIZE_CLASSES[previewSize].container} rounded object-cover`,
        onError: (e: React.SyntheticEvent<HTMLImageElement>) => {
          (e.target as HTMLImageElement).style.display = "none";
          (e.target as HTMLImageElement).nextElementSibling?.classList.remove("hidden");
        },
      });
    }
    return React.createElement(
      "button",
      {
        type: "button",
        className: `relative ${PREVIEW_SIZE_CLASSES[previewSize].container} rounded overflow-hidden bg-muted group`,
        onClick: (e: React.MouseEvent) => {
          e.stopPropagation();
          setVideoPreviewUrl(url);
        },
      },
      React.createElement(
        "div",
        { className: "h-full w-full flex items-center justify-center bg-muted" },
        React.createElement(Play, {
          className: `${PREVIEW_SIZE_CLASSES[previewSize].icon} text-muted-foreground`,
        }),
      ),
    );
  }, [previewSize]);

  const defaultRenderCellValue = useCallback((item: T, columnKey: string) => {
    if (mediaColumnsRef.current && columnKey in mediaColumnsRef.current) {
      const url = item[columnKey] as string | null | undefined;
      return renderMediaCell(url, mediaColumnsRef.current[columnKey]);
    }
    if (renderCellValueRef.current) {
      return renderCellValueRef.current(item, columnKey);
    }
    const value = item[columnKey];
    if (value === null || value === undefined) {
      return React.createElement("span", { className: "text-muted-foreground" }, "-");
    }
    return React.createElement("span", { className: "text-foreground" }, String(value));
  }, [renderMediaCell]);

  // Stable ref for cell renderer (avoids rebuilding column defs on every state change)
  const defaultRenderCellValueRef = useRef(defaultRenderCellValue);
  defaultRenderCellValueRef.current = defaultRenderCellValue;

  // --- Sort field helper ---
  const getSortField = useCallback((columnKey: string): string => {
    if (relationToIdMapRef.current[columnKey]) return relationToIdMapRef.current[columnKey];
    return columnKey;
  }, []);

  // --- TanStack Table ---
  const tanstackColumns = useMemo<ColumnDef<T>[]>(() => {
    const cols: ColumnDef<T>[] = availableColumns.map((col) => ({
      id: col.key,
      accessorKey: col.key,
      header: col.label,
      enableSorting: true,
      enableHiding: true,
      enableResizing: true,
      size: 150,
      minSize: MIN_COL_WIDTH,
      cell: ({ row }: { row: { original: T } }) => defaultRenderCellValueRef.current(row.original, col.key),
    }));

    if (extraColumns) {
      const extraMap = new Map(extraColumns.filter(ec => ec.id).map(ec => [ec.id, ec]));
      // Replace dynamic cols with matching extras in-place
      const merged = cols.map(c => (c.id && extraMap.has(c.id as string) ? extraMap.get(c.id as string)! : c));
      // Append any extras that don't match existing dynamic columns
      const existingIds = new Set(cols.map(c => c.id));
      const newExtras = extraColumns.filter(ec => !existingIds.has(ec.id));
      merged.push(...newExtras);
      return merged;
    }

    return cols;
  }, [availableColumns, extraColumns]);

  const tanstackVisibility = useMemo<VisibilityState>(() => {
    if (visibleColumns.size === 0 || availableColumns.length === 0) return {};
    const state: VisibilityState = {};
    for (const col of availableColumns) {
      if (!visibleColumns.has(col.key)) state[col.key] = false;
    }
    return state;
  }, [visibleColumns, availableColumns]);

  const handleVisibilityChange = useCallback((updater: VisibilityState | ((prev: VisibilityState) => VisibilityState)) => {
    const newState = typeof updater === "function" ? updater(tanstackVisibility) : updater;
    setVisibleColumns(new Set(
      availableColumns.filter(c => newState[c.key] !== false).map(c => c.key),
    ));
  }, [tanstackVisibility, availableColumns]);

  const tanstackSorting = useMemo<SortingState>(() => {
    if (!order) return [];
    const [field, dir] = order.split(":");
    let columnId = field;
    for (const [colKey, sortField] of Object.entries(relationToIdMapRef.current)) {
      if (sortField === field) { columnId = colKey; break; }
    }
    return [{ id: columnId, desc: dir === "desc" }];
  }, [order]);

  const handleSortingChange = useCallback((updater: SortingState | ((prev: SortingState) => SortingState)) => {
    const newSorting = typeof updater === "function" ? updater(tanstackSorting) : updater;
    if (newSorting.length === 0) {
      setOrder(null);
    } else {
      const { id, desc } = newSorting[0];
      setOrder(`${getSortField(id)}:${desc ? "desc" : "asc"}`);
    }
    setPagination(prev => ({ ...prev, page: 1 }));
  }, [tanstackSorting, getSortField]);

  const handleColumnSizingChange = useCallback((updater: ColumnSizingState | ((prev: ColumnSizingState) => ColumnSizingState)) => {
    const newSizing = typeof updater === "function" ? updater(columnWidths) : updater;
    setColumnWidths(newSizing);
  }, [columnWidths]);

  const table = useReactTable({
    data: items,
    columns: tanstackColumns,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    manualSorting: true,
    pageCount: pagination.totalPages,
    enableMultiSort: false,
    enableSortingRemoval: true,
    sortDescFirst: false,
    enableColumnResizing: true,
    columnResizeMode: "onEnd",
    state: {
      columnVisibility: tanstackVisibility,
      sorting: tanstackSorting,
      columnOrder,
      columnSizing: columnWidths,
    },
    onColumnVisibilityChange: handleVisibilityChange,
    onSortingChange: handleSortingChange,
    onColumnOrderChange: setColumnOrder,
    onColumnSizingChange: handleColumnSizingChange,
  });

  // --- DnD ---
  const sensors = useSensors(
    useSensor(MouseSensor, { activationConstraint: { distance: 5 } }),
    useSensor(TouchSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor),
  );

  const handleDragEnd = useCallback((event: DragEndEvent) => {
    const { active, over } = event;
    if (active && over && active.id !== over.id) {
      setColumnOrder((current) => {
        const oldIndex = current.indexOf(active.id as string);
        const newIndex = current.indexOf(over.id as string);
        return arrayMove(current, oldIndex, newIndex);
      });
    }
  }, []);

  return {
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
    fkOptions,
    fkLoading,
    previewSize,
    setPreviewSize,
    videoPreviewUrl,
    setVideoPreviewUrl,
    table,
    sensors,
    handleDragEnd,
    loadItems,
    defaultRenderCellValue,
  };
}
