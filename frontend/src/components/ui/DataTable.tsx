"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
  useSortable,
  arrayMove,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import SmartFilters, { type FilterValue } from "@/components/ui/SmartFilters";
import SmartPagination, { type PaginationData } from "@/components/ui/SmartPagination";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";
import { ApiError, getAvailableColumns, type ColumnConfig } from "@/lib/api";
import {
  FileQuestion,
  Lock,
  AlertCircle,
  Loader2,
  LogIn,
  Columns,
  ArrowUp,
  ArrowDown,
  ArrowUpDown,
  Trash2,
  AlertTriangle,
  ImageIcon,
  Play,
  GripVertical,
} from "lucide-react";
import VideoPlayer from "@/components/video/VideoPlayer";

function useDebounce<T>(value: T, delay: number): T {
  const [debouncedValue, setDebouncedValue] = useState<T>(value);

  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedValue(value);
    }, delay);

    return () => {
      clearTimeout(handler);
    };
  }, [value, delay]);

  return debouncedValue;
}

export type DataTableColumn<T> = {
  key: string;
  label: string;
  render?: (item: T) => React.ReactNode;
  sortable?: boolean;
};

export type DataTableFormField = {
  key: string;
  label: string;
  type?: "text" | "textarea" | "number" | "email" | "date" | "datetime-local";
  required?: boolean;
  placeholder?: string;
  options?: { value: string; label: string }[];
  render?: (opts: {
    field: DataTableFormField;
    value: string;
    onChange: (value: string) => void;
    formState: Record<string, any>;
    setFormState: React.Dispatch<React.SetStateAction<Record<string, any>>>;
  }) => React.ReactNode;
};

export type DataTableCRUD<T, TCreate, TUpdate> = {
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
  create?: (data: TCreate) => Promise<T>;
  update?: (id: number, data: TUpdate) => Promise<T>;
  delete?: (id: number) => Promise<void>;
};

export type DataTableProps<T, TCreate, TUpdate> = {
  entityClass: string;
  entityName: string;
  storageKey: string;

  crud: DataTableCRUD<T, TCreate, TUpdate>;

  formFields?: DataTableFormField[];
  initialFormState?: Record<string, any>;
  getFormData?: (formState: Record<string, any>) => TCreate | TUpdate;
  validateForm?: (formState: Record<string, any>) => string | null;

  getItemId: (item: T) => number;
  getItemTitle: (item: T) => string;
  renderCellValue?: (item: T, columnKey: string) => React.ReactNode;

  title?: string;
  description?: string;
  relationToIdMap?: Record<string, string>;

  // New optional props
  mediaColumns?: Record<string, "image" | "video">;
  readOnly?: boolean;
  onRowClick?: (item: T) => void;
  settingsKey?: string;
};

function SortableColumnItem({
  id,
  label,
  checked,
  onToggle,
}: {
  id: string;
  label: string;
  checked: boolean;
  onToggle: () => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id });
  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <div ref={setNodeRef} style={style} className="flex items-center space-x-3 p-2 rounded-md hover:bg-accent/50">
      <button type="button" className="cursor-grab touch-none text-muted-foreground hover:text-foreground" {...attributes} {...listeners}>
        <GripVertical className="w-4 h-4" />
      </button>
      <label className="flex items-center space-x-3 cursor-pointer flex-1 min-w-0">
        <Checkbox checked={checked} onCheckedChange={onToggle} />
        <span className="text-sm text-foreground truncate">{label}</span>
      </label>
    </div>
  );
}

export default function DataTable<T extends Record<string, any>, TCreate, TUpdate>({
  entityClass,
  entityName,
  storageKey,
  crud,
  formFields = [],
  initialFormState = {},
  getFormData,
  validateForm,
  getItemId,
  getItemTitle,
  renderCellValue,
  title,
  description,
  relationToIdMap = {},
  mediaColumns,
  readOnly = false,
  onRowClick,
  settingsKey,
}: DataTableProps<T, TCreate, TUpdate>) {
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
  const [loading, setLoading] = useState(false);
  const [errorStatus, setErrorStatus] = useState<401 | 403 | 404 | 500 | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [formState, setFormState] = useState<Record<string, any>>(initialFormState);
  const [editingItemId, setEditingItemId] = useState<number | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [showPanel, setShowPanel] = useState(false);
  const [showColumnsModal, setShowColumnsModal] = useState(false);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [itemToDelete, setItemToDelete] = useState<T | null>(null);
  const [availableColumns, setAvailableColumns] = useState<ColumnConfig[]>([]);
  const [loadingColumns, setLoadingColumns] = useState(false);
  const [columnOrder, setColumnOrder] = useState<string[]>([]);
  const [videoPreviewUrl, setVideoPreviewUrl] = useState<string | null>(null);
  const [columnWidths, setColumnWidths] = useState<Record<string, number>>({});

  const [visibleColumns, setVisibleColumns] = useState<Set<string>>(new Set<string>());

  const debouncedSearchValue = useDebounce(searchValue, 500);
  const debouncedActiveFilters = useDebounce(activeFilters, 500);

  // Server-persisted settings
  const [settingsLoaded, setSettingsLoaded] = useState(false);

  useEffect(() => {
    if (!settingsKey) {
      setSettingsLoaded(true);
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
        }
      } catch {
        // Fallback to localStorage silently
      } finally {
        setSettingsLoaded(true);
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
    }),
    [visibleColumns, columnOrder, columnWidths, pagination.perPage],
  );

  const debouncedSettings = useDebounce(settingsToSave, 2000);

  useEffect(() => {
    if (!settingsKey || !settingsLoaded || visibleColumns.size === 0) return;
    const save = async () => {
      try {
        const { updateAdminUiSettings } = await import("@/lib/api");
        await updateAdminUiSettings({ [settingsKey]: debouncedSettings });
      } catch {
        // Fallback: save to localStorage
        if (typeof window !== "undefined") {
          localStorage.setItem(storageKey, JSON.stringify(debouncedSettings.visibleColumns));
        }
      }
    };
    save();
  }, [debouncedSettings, settingsKey, settingsLoaded, storageKey, visibleColumns.size]);

  const loadItems = useCallback(async () => {
    setLoading(true);
    setErrorStatus(null);
    try {
      const data = await crud.list({
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
        if (error.status === 401) {
          setErrorStatus(401);
        } else if (error.status === 403) {
          setErrorStatus(403);
        } else if (error.status === 404) {
          setErrorStatus(404);
        } else {
          setErrorStatus(500);
        }
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
    crud,
    entityName,
  ]);

  useEffect(() => {
    loadItems();
  }, [loadItems]);

  useEffect(() => {
    const fetchColumns = async () => {
      setLoadingColumns(true);
      try {
        const columns = await getAvailableColumns(entityClass);
        setAvailableColumns(columns);

        setVisibleColumns((current) => {
          if (current.size > 0) {
            return current;
          }

          let savedColumns: string[] | null = null;
          if (typeof window !== "undefined") {
            const saved = localStorage.getItem(storageKey);
            if (saved) {
              try {
                const parsed = JSON.parse(saved) as string[];
                if (parsed.length > 0) {
                  savedColumns = parsed;
                }
              } catch {
                // ignore invalid JSON
              }
            }
          }

          if (savedColumns) {
            return new Set(savedColumns);
          }

          const allColumnKeys = columns.map((col) => col.key);
          return new Set(allColumnKeys);
        });

        // Set column order: reconcile saved order with available columns, media first
        setColumnOrder((current) => {
          const allKeys = columns.map((col) => col.key);
          if (current.length > 0) {
            const allSet = new Set(allKeys);
            const currentSet = new Set(current);
            const merged = current.filter((k) => allSet.has(k));
            const newKeys = allKeys.filter((k) => !currentSet.has(k));
            const reconciled = [...merged, ...newKeys];
            if (mediaColumns) {
              const mediaKeys = reconciled.filter((k) => k in mediaColumns);
              const rest = reconciled.filter((k) => !(k in mediaColumns));
              return [...mediaKeys, ...rest];
            }
            return reconciled;
          }
          if (mediaColumns) {
            const mediaKeys = allKeys.filter((k) => k in mediaColumns);
            const rest = allKeys.filter((k) => !(k in mediaColumns));
            return [...mediaKeys, ...rest];
          }
          return allKeys;
        });
      } catch (error) {
        console.error("Failed to fetch columns:", error);
      } finally {
        setLoadingColumns(false);
      }
    };

    fetchColumns();
  }, [entityClass, storageKey, mediaColumns]);

  useEffect(() => {
    if (typeof window !== "undefined" && visibleColumns.size > 0 && !settingsKey) {
      localStorage.setItem(storageKey, JSON.stringify(Array.from(visibleColumns)));
    }
  }, [visibleColumns, storageKey, settingsKey]);

  // Ordered visible columns
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

  const resetForm = () => {
    setFormState(initialFormState);
    setEditingItemId(null);
    setShowPanel(false);
  };

  const closePanel = useCallback(() => {
    setShowPanel(false);
    setFormState(initialFormState);
    setEditingItemId(null);
    setFormError(null);
  }, [initialFormState]);

  const handleSearchChange = (value: string) => {
    setSearchValue(value);
    setPagination((prev) => ({ ...prev, page: 1 }));
  };

  const handleFiltersChange = (filters: FilterValue[]) => {
    setActiveFilters(filters);
    setPagination((prev) => ({ ...prev, page: 1 }));
  };

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);

    if (validateForm) {
      const validationError = validateForm(formState);
      if (validationError) {
        setFormError(validationError);
        return;
      }
    }

    setIsSaving(true);
    try {
      const payload = getFormData ? getFormData(formState) : formState;
      if (editingItemId && crud.update) {
        await crud.update(editingItemId, payload as TUpdate);
      } else if (crud.create) {
        await crud.create(payload as TCreate);
      }
      resetForm();
      await loadItems();
    } catch (error) {
      console.error(`Failed to save ${entityName}.`, error);
      setFormError(`Failed to save ${entityName}.`);
    } finally {
      setIsSaving(false);
    }
  };

  const handleEdit = (item: T) => {
    setEditingItemId(getItemId(item));
    setShowPanel(true);
    const newFormState = { ...initialFormState };
    Object.keys(newFormState).forEach((key) => {
      if (item[key] !== undefined && item[key] !== null) {
        newFormState[key] = String(item[key]);
      }
    });
    setFormState(newFormState);
    setFormError(null);
  };

  const handleDelete = (item: T) => {
    setItemToDelete(item);
    setShowDeleteModal(true);
  };

  const confirmDelete = async () => {
    if (!itemToDelete || !crud.delete) return;

    setDeletingId(getItemId(itemToDelete));
    try {
      await crud.delete(getItemId(itemToDelete));
      await loadItems();
      setShowDeleteModal(false);
      setItemToDelete(null);
    } catch (error) {
      console.error(`Failed to delete ${entityName}.`, error);
      alert(`Failed to delete ${entityName}. Please try again.`);
    } finally {
      setDeletingId(null);
    }
  };

  const getSortField = (columnKey: string): string => {
    if (relationToIdMap[columnKey]) {
      return relationToIdMap[columnKey];
    }
    return columnKey;
  };

  const handleColumnSort = (columnKey: string) => {
    const sortField = getSortField(columnKey);
    const [currentField, currentDirection] = order ? order.split(":") : [null, null];

    if (currentField !== sortField) {
      setOrder(`${sortField}:asc`);
    } else if (currentDirection === "asc") {
      setOrder(`${sortField}:desc`);
    } else if (currentDirection === "desc") {
      setOrder(null);
    } else {
      setOrder(`${sortField}:asc`);
    }

    setPagination((prev) => ({ ...prev, page: 1 }));
  };

  const getSortIcon = (columnKey: string) => {
    const sortField = getSortField(columnKey);
    const [currentField, currentDirection] = order ? order.split(":") : [null, null];

    if (currentField !== sortField) {
      return <ArrowUpDown className="w-4 h-4 shrink-0 text-muted-foreground" />;
    } else if (currentDirection === "asc") {
      return <ArrowUp className="w-4 h-4 shrink-0 text-primary" />;
    } else if (currentDirection === "desc") {
      return <ArrowDown className="w-4 h-4 shrink-0 text-primary" />;
    }
    return <ArrowUpDown className="w-4 h-4 shrink-0 text-muted-foreground" />;
  };

  const toggleColumn = (columnKey: string) => {
    setVisibleColumns((prev) => {
      const next = new Set(prev);
      if (next.has(columnKey)) {
        next.delete(columnKey);
      } else {
        next.add(columnKey);
      }
      return next;
    });
  };

  // DnD sensors for column reorder in dialog
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      const { active, over } = event;
      if (over && active.id !== over.id) {
        setColumnOrder((prev) => {
          const oldIndex = prev.indexOf(active.id as string);
          const newIndex = prev.indexOf(over.id as string);
          if (oldIndex === -1 || newIndex === -1) return prev;
          return arrayMove(prev, oldIndex, newIndex);
        });
      }
    },
    [],
  );

  // Columns in dialog order (follows columnOrder)
  const dialogColumns = useMemo(() => {
    if (columnOrder.length === 0) return availableColumns;
    const orderMap = new Map(columnOrder.map((key, idx) => [key, idx]));
    return [...availableColumns].sort((a, b) => {
      const ai = orderMap.get(a.key) ?? 999;
      const bi = orderMap.get(b.key) ?? 999;
      return ai - bi;
    });
  }, [availableColumns, columnOrder]);

  const visibleColumnCount = Math.max(visibleColumns.size, 1) + (readOnly ? 0 : 1);

  const canEdit = !readOnly && crud.update;
  const canCreate = !readOnly && crud.create;
  const canDelete = !readOnly && crud.delete;

  const renderMediaCell = (url: string | null | undefined, type: "image" | "video") => {
    if (!url) {
      return <span className="text-muted-foreground">-</span>;
    }
    if (type === "image") {
      return (
        <img
          src={url}
          alt=""
          className="h-10 w-10 rounded object-cover"
          onError={(e) => {
            (e.target as HTMLImageElement).style.display = "none";
            (e.target as HTMLImageElement).nextElementSibling?.classList.remove("hidden");
          }}
        />
      );
    }
    // video
    return (
      <button
        type="button"
        className="relative h-10 w-10 rounded overflow-hidden bg-muted group"
        onClick={(e) => {
          e.stopPropagation();
          setVideoPreviewUrl(url);
        }}
      >
        <VideoPlayer src={url} muted preload="metadata" className="h-full w-full object-cover" />
        <div className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity">
          <Play className="h-4 w-4 text-white" />
        </div>
      </button>
    );
  };

  const defaultRenderCellValue = (item: T, columnKey: string) => {
    // Check mediaColumns first
    if (mediaColumns && columnKey in mediaColumns) {
      const url = item[columnKey] as string | null | undefined;
      return renderMediaCell(url, mediaColumns[columnKey]);
    }

    if (renderCellValue) {
      return renderCellValue(item, columnKey);
    }
    const value = item[columnKey];
    if (value === null || value === undefined) {
      return <span className="text-muted-foreground">-</span>;
    }
    return <span className="text-foreground">{String(value)}</span>;
  };

  const MIN_COL_WIDTH = 120;
  const hasAnyColumnWidth = Object.keys(columnWidths).length > 0;

  // Column resize handler
  const resizeRef = useRef<{
    key: string;
    startX: number;
    startWidth: number;
  } | null>(null);

  const handleResizeMouseDown = useCallback(
    (e: React.MouseEvent, columnKey: string) => {
      e.preventDefault();
      e.stopPropagation();
      const th = (e.target as HTMLElement).closest("th");
      const startWidth = columnWidths[columnKey] || th?.offsetWidth || 100;
      resizeRef.current = { key: columnKey, startX: e.clientX, startWidth };

      const onMouseMove = (ev: MouseEvent) => {
        if (!resizeRef.current) return;
        const delta = ev.clientX - resizeRef.current.startX;
        const newWidth = Math.max(MIN_COL_WIDTH, resizeRef.current.startWidth + delta);
        setColumnWidths((prev) => ({ ...prev, [resizeRef.current!.key]: newWidth }));
      };

      const onMouseUp = () => {
        resizeRef.current = null;
        document.removeEventListener("mousemove", onMouseMove);
        document.removeEventListener("mouseup", onMouseUp);
      };

      document.addEventListener("mousemove", onMouseMove);
      document.addEventListener("mouseup", onMouseUp);
    },
    [columnWidths],
  );

  return (
    <div>
      <div className="space-y-6">
        <header className="space-y-1 md:space-y-2">
          <div>
            <h1 className="text-2xl md:text-3xl font-semibold">{title || entityName}</h1>
            {description && <p className="text-sm md:text-base text-muted-foreground">{description}</p>}
          </div>
        </header>

        {/* Create/Edit Sheet */}
        <Sheet open={showPanel} onOpenChange={(open) => !open && closePanel()}>
          <SheetContent side="right">
            <div className="p-4 md:p-6 space-y-4 md:space-y-6">
              <SheetHeader className="border-b border-border pb-3 md:pb-4">
                <SheetTitle className="text-xl md:text-2xl">
                  {editingItemId ? `Edit ${entityName}` : `Create ${entityName}`}
                </SheetTitle>
                <SheetDescription className="sr-only">
                  {editingItemId ? `Edit the ${entityName.toLowerCase()} details` : `Create a new ${entityName.toLowerCase()}`}
                </SheetDescription>
              </SheetHeader>

              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid gap-4 md:grid-cols-2">
                  {formFields.map((field) => (
                    <label
                      key={field.key}
                      className={`space-y-2 text-sm text-muted-foreground ${field.type === "textarea" ? "md:col-span-2" : ""}`}
                    >
                      <span>
                        {field.label}
                        {field.required && <span className="text-red-400">*</span>}
                      </span>
                      {field.render ? (
                        field.render({
                          field,
                          value: formState[field.key] || "",
                          onChange: (value) =>
                            setFormState((prev) => ({
                              ...prev,
                              [field.key]: value,
                            })),
                          formState,
                          setFormState,
                        })
                      ) : field.type === "textarea" ? (
                        <Textarea
                          value={formState[field.key] || ""}
                          onChange={(event) =>
                            setFormState((prev) => ({
                              ...prev,
                              [field.key]: event.target.value,
                            }))
                          }
                          placeholder={field.placeholder}
                          className="min-h-[140px]"
                          required={field.required}
                        />
                      ) : (
                        <Input
                          type={field.type || "text"}
                          value={formState[field.key] || ""}
                          onChange={(event) =>
                            setFormState((prev) => ({
                              ...prev,
                              [field.key]: event.target.value,
                            }))
                          }
                          placeholder={field.placeholder}
                          required={field.required}
                        />
                      )}
                    </label>
                  ))}
                </div>

                {formError && <p className="text-sm text-red-400">{formError}</p>}

                <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-border">
                  <Button type="submit" disabled={isSaving}>
                    {isSaving
                      ? editingItemId
                        ? "Saving..."
                        : "Creating..."
                      : editingItemId
                        ? "Save changes"
                        : `Create ${entityName}`}
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    onClick={closePanel}
                  >
                    Cancel
                  </Button>
                </div>
              </form>
            </div>
          </SheetContent>
        </Sheet>

        {/* Columns Dialog */}
        <Dialog open={showColumnsModal} onOpenChange={setShowColumnsModal}>
          <DialogContent className="max-h-[80vh] overflow-hidden flex flex-col">
            <DialogHeader>
              <DialogTitle>Select & Reorder Columns</DialogTitle>
              <DialogDescription className="text-xs text-muted-foreground">
                Drag to reorder. Toggle visibility with checkboxes.
              </DialogDescription>
            </DialogHeader>

            <div className="overflow-y-auto flex-1 py-2">
              {loadingColumns ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="w-6 h-6 text-muted-foreground animate-spin" />
                </div>
              ) : (
                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                  <SortableContext items={dialogColumns.map((c) => c.key)} strategy={verticalListSortingStrategy}>
                    <div className="space-y-1">
                      {dialogColumns.map((column) => (
                        <SortableColumnItem
                          key={column.key}
                          id={column.key}
                          label={column.label}
                          checked={visibleColumns.has(column.key)}
                          onToggle={() => toggleColumn(column.key)}
                        />
                      ))}
                    </div>
                  </SortableContext>
                </DndContext>
              )}
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setShowColumnsModal(false)}
              >
                Close
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Delete Confirmation Dialog */}
        <Dialog open={showDeleteModal} onOpenChange={(open) => { if (!open) { setShowDeleteModal(false); setItemToDelete(null); } }}>
          <DialogContent className="border-red-500/20">
            <DialogHeader>
              <div className="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 border border-red-500/20">
                <AlertTriangle className="w-8 h-8 text-red-400" />
              </div>
              <DialogTitle className="text-center">Delete {entityName}?</DialogTitle>
              <DialogDescription className="text-center">
                This action cannot be undone. This will permanently delete the {entityName.toLowerCase()}
              </DialogDescription>
            </DialogHeader>

            {itemToDelete && (
              <div className="px-2 py-4 bg-muted/50 border-y border-border rounded-md">
                <div className="flex items-start gap-3">
                  <div className="flex-1 min-w-0">
                    <p className="text-xs text-muted-foreground mb-1">{entityName} Title</p>
                    <p className="text-sm font-medium text-foreground truncate">{getItemTitle(itemToDelete)}</p>
                    {getItemId(itemToDelete) && (
                      <>
                        <p className="text-xs text-muted-foreground mt-2 mb-1">{entityName} ID</p>
                        <p className="text-sm text-muted-foreground">#{getItemId(itemToDelete)}</p>
                      </>
                    )}
                  </div>
                </div>
              </div>
            )}

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => { setShowDeleteModal(false); setItemToDelete(null); }}
                disabled={!!itemToDelete && deletingId === getItemId(itemToDelete)}
              >
                Cancel
              </Button>
              <Button
                type="button"
                className="bg-red-600 hover:bg-red-700 text-white border-red-600"
                onClick={confirmDelete}
                disabled={!!itemToDelete && deletingId === getItemId(itemToDelete)}
              >
                {itemToDelete && deletingId === getItemId(itemToDelete) ? (
                  <>
                    <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                    Deleting...
                  </>
                ) : (
                  <>
                    <Trash2 className="w-4 h-4 mr-2" />
                    Delete {entityName}
                  </>
                )}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Video Preview Dialog */}
        <Dialog open={!!videoPreviewUrl} onOpenChange={(open) => { if (!open) setVideoPreviewUrl(null); }}>
          <DialogContent className="max-w-3xl p-0 overflow-hidden">
            <DialogHeader className="sr-only">
              <DialogTitle>Video Preview</DialogTitle>
              <DialogDescription>Preview of the video</DialogDescription>
            </DialogHeader>
            {videoPreviewUrl && (
              <video
                src={videoPreviewUrl}
                controls
                autoPlay
                className="w-full max-h-[80vh]"
              />
            )}
          </DialogContent>
        </Dialog>

        <section className="space-y-4">
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4">
            <div className="flex-1 w-full">
              <SmartFilters
                entityClass={entityClass}
                searchValue={searchValue}
                onSearchChange={handleSearchChange}
                activeFilters={activeFilters}
                onFiltersChange={handleFiltersChange}
                placeholder={`Search ${entityName.toLowerCase()}...`}
              />
            </div>
            <div className="flex gap-2 sm:gap-4">
              <Button
                variant="outline"
                className="flex-1 sm:flex-none"
                onClick={() => setShowColumnsModal(true)}
              >
                <Columns className="w-4 h-4 mr-2" />
                <span className="hidden sm:inline">Columns</span>
              </Button>
              {canCreate && (
                <Button
                  className="flex-1 sm:flex-none"
                  onClick={() => {
                    resetForm();
                    setShowPanel(true);
                  }}
                >
                  Add {entityName}
                </Button>
              )}
            </div>
          </div>

          <div className="rounded-lg border border-border overflow-hidden">
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
                      key={getItemId(item)}
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
                        {!readOnly && (
                          <div className="flex items-center justify-end gap-2 pt-2 border-t border-border">
                            {canEdit && (
                              <Button
                                variant="outline"
                                size="sm"
                                className="text-xs flex-1"
                                onClick={(e) => { e.stopPropagation(); handleEdit(item); }}
                              >
                                Edit
                              </Button>
                            )}
                            {canDelete && (
                              <Button
                                variant="outline"
                                size="sm"
                                className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-xs flex-1"
                                onClick={(e) => { e.stopPropagation(); handleDelete(item); }}
                                disabled={deletingId === getItemId(item)}
                              >
                                {deletingId === getItemId(item) ? "Deleting..." : "Delete"}
                              </Button>
                            )}
                          </div>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="hidden md:block overflow-x-auto">
              <Table className="min-w-full" style={hasAnyColumnWidth ? { tableLayout: "fixed", minWidth: (orderedVisibleColumns.length + (readOnly ? 0 : 1)) * MIN_COL_WIDTH } : undefined}>
                <TableHeader>
                  <TableRow>
                    {orderedVisibleColumns.map((column) => (
                      <TableHead
                        key={column.key}
                        className="relative cursor-pointer select-none hover:bg-accent/50 transition-colors whitespace-nowrap"
                        style={{ width: columnWidths[column.key] || "auto", minWidth: MIN_COL_WIDTH }}
                        onClick={() => handleColumnSort(column.key)}
                      >
                        <div className="flex items-center gap-2">
                          <span className="text-sm">{column.label}</span>
                          {getSortIcon(column.key)}
                        </div>
                        {/* Resize handle */}
                        <div
                          className="absolute right-0 top-0 bottom-0 w-1.5 cursor-col-resize hover:bg-primary/30 z-10"
                          onMouseDown={(e) => handleResizeMouseDown(e, column.key)}
                          onClick={(e) => e.stopPropagation()}
                        />
                      </TableHead>
                    ))}
                    {!readOnly && (
                      <TableHead className="text-right whitespace-nowrap min-w-[120px]">Actions</TableHead>
                    )}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {loading ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16">
                        <div className="flex items-center justify-center">
                          <Loader2 className="w-6 h-6 text-muted-foreground animate-spin" />
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : errorStatus === 401 ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16 px-4">
                        <div className="flex flex-col items-center justify-center">
                          <LogIn className="w-16 h-16 text-muted-foreground mb-4" />
                          <h3 className="text-xl font-semibold text-foreground mb-2 text-center">Authentication Required</h3>
                          <p className="text-base text-muted-foreground text-center max-w-md px-4">
                            You need to be authenticated to access this page. Please log in to continue.
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : errorStatus === 403 ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16 px-4">
                        <div className="flex flex-col items-center justify-center">
                          <Lock className="w-16 h-16 text-muted-foreground mb-4" />
                          <h3 className="text-xl font-semibold text-foreground mb-2 text-center">Access Denied</h3>
                          <p className="text-base text-muted-foreground text-center max-w-md px-4">
                            You don&apos;t have permission to access this page.
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : errorStatus === 500 ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16 px-4">
                        <div className="flex flex-col items-center justify-center">
                          <AlertCircle className="w-16 h-16 text-muted-foreground mb-4" />
                          <h3 className="text-xl font-semibold text-foreground mb-2 text-center">Oops! Something went wrong</h3>
                          <p className="text-base text-muted-foreground text-center max-w-md px-4">
                            We are already fixing it. Please try again in a few minutes.
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : errorStatus === 404 || items.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16 px-4">
                        <div className="flex flex-col items-center justify-center">
                          <FileQuestion className="w-16 h-16 text-muted-foreground mb-4" />
                          <h3 className="text-xl font-semibold text-foreground mb-2 text-center">Nothing Found</h3>
                          <p className="text-base text-muted-foreground text-center max-w-md px-4">
                            No {entityName.toLowerCase()} were found. Try adjusting your search or filters.
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : (
                    items.map((item) => (
                      <TableRow
                        key={getItemId(item)}
                        className={onRowClick ? "cursor-pointer hover:bg-accent/50" : ""}
                        onClick={() => onRowClick?.(item)}
                      >
                        {orderedVisibleColumns.map((column) => (
                          <TableCell
                            key={column.key}
                            className={`text-sm overflow-hidden ${
                              ["created_at", "updated_at", "published_at"].includes(column.key)
                                ? "whitespace-nowrap"
                                : ""
                            }`}
                            style={{ width: columnWidths[column.key] || "auto", minWidth: MIN_COL_WIDTH }}
                          >
                            {defaultRenderCellValue(item, column.key)}
                          </TableCell>
                        ))}
                        {!readOnly && (
                          <TableCell className="text-right whitespace-nowrap">
                            <div className="flex items-center justify-end gap-2">
                              {canEdit && (
                                <Button
                                  variant="outline"
                                  size="sm"
                                  className="text-sm px-3"
                                  onClick={(e) => { e.stopPropagation(); handleEdit(item); }}
                                >
                                  Edit
                                </Button>
                              )}
                              {canDelete && (
                                <Button
                                  variant="outline"
                                  size="sm"
                                  className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-sm px-3"
                                  onClick={(e) => { e.stopPropagation(); handleDelete(item); }}
                                  disabled={deletingId === getItemId(item)}
                                >
                                  {deletingId === getItemId(item) ? "Deleting..." : "Delete"}
                                </Button>
                              )}
                            </div>
                          </TableCell>
                        )}
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
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
      </div>
    </div>
  );
}
