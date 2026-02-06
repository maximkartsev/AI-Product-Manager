"use client";

import { useCallback, useEffect, useState } from "react";
import SmartFilters, { type FilterValue } from "@/components/ui/SmartFilters";
import SmartPagination, { type PaginationData } from "@/components/ui/SmartPagination";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, getAvailableColumns, type ColumnConfig } from "@/lib/api";
import {
  FileQuestion,
  Lock,
  AlertCircle,
  Loader2,
  LogIn,
  X,
  Columns,
  ArrowUp,
  ArrowDown,
  ArrowUpDown,
  Trash2,
  AlertTriangle,
} from "lucide-react";

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
  create: (data: TCreate) => Promise<T>;
  update: (id: number, data: TUpdate) => Promise<T>;
  delete: (id: number) => Promise<void>;
};

export type DataTableProps<T, TCreate, TUpdate> = {
  entityClass: string;
  entityName: string;
  storageKey: string;

  crud: DataTableCRUD<T, TCreate, TUpdate>;

  formFields: DataTableFormField[];
  initialFormState: Record<string, any>;
  getFormData: (formState: Record<string, any>) => TCreate | TUpdate;
  validateForm?: (formState: Record<string, any>) => string | null;

  getItemId: (item: T) => number;
  getItemTitle: (item: T) => string;
  renderCellValue?: (item: T, columnKey: string) => React.ReactNode;

  title?: string;
  description?: string;
  relationToIdMap?: Record<string, string>;
};

export default function DataTable<T extends Record<string, any>, TCreate, TUpdate>({
  entityClass,
  entityName,
  storageKey,
  crud,
  formFields,
  initialFormState,
  getFormData,
  validateForm,
  getItemId,
  getItemTitle,
  renderCellValue,
  title,
  description,
  relationToIdMap = {},
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

  const [visibleColumns, setVisibleColumns] = useState<Set<string>>(new Set<string>());

  const debouncedSearchValue = useDebounce(searchValue, 500);
  const debouncedActiveFilters = useDebounce(activeFilters, 500);

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
        } else if (error.status >= 500) {
          setErrorStatus(500);
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
      } catch (error) {
        console.error("Failed to fetch columns:", error);
      } finally {
        setLoadingColumns(false);
      }
    };

    fetchColumns();
  }, [entityClass, storageKey]);

  useEffect(() => {
    if (typeof window !== "undefined" && visibleColumns.size > 0) {
      localStorage.setItem(storageKey, JSON.stringify(Array.from(visibleColumns)));
    }
  }, [visibleColumns, storageKey]);

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
      const payload = getFormData(formState);
      if (editingItemId) {
        await crud.update(editingItemId, payload as TUpdate);
      } else {
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
    if (!itemToDelete) return;

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

  const cancelDelete = useCallback(() => {
    setShowDeleteModal(false);
    setItemToDelete(null);
  }, []);

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
      return <ArrowUpDown className="w-4 h-4 text-gray-500" />;
    } else if (currentDirection === "asc") {
      return <ArrowUp className="w-4 h-4 text-blue-400" />;
    } else if (currentDirection === "desc") {
      return <ArrowDown className="w-4 h-4 text-blue-400" />;
    }
    return <ArrowUpDown className="w-4 h-4 text-gray-500" />;
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

  const visibleColumnCount = Math.max(visibleColumns.size, 1) + 1;

  const defaultRenderCellValue = (item: T, columnKey: string) => {
    if (renderCellValue) {
      return renderCellValue(item, columnKey);
    }
    const value = item[columnKey];
    if (value === null || value === undefined) {
      return <span className="text-gray-400">-</span>;
    }
    return <span className="text-gray-200">{String(value)}</span>;
  };

  useEffect(() => {
    const handleEsc = (event: KeyboardEvent) => {
      if (event.key === "Escape" && showPanel) {
        closePanel();
      }
    };

    if (showPanel) {
      document.addEventListener("keydown", handleEsc);
      document.body.style.overflow = "hidden";
    }

    return () => {
      document.removeEventListener("keydown", handleEsc);
      document.body.style.overflow = "unset";
    };
  }, [showPanel, closePanel]);

  useEffect(() => {
    const handleEsc = (event: KeyboardEvent) => {
      if (event.key === "Escape" && showColumnsModal) {
        setShowColumnsModal(false);
      }
    };

    if (showColumnsModal) {
      document.addEventListener("keydown", handleEsc);
      document.body.style.overflow = "hidden";
    }

    return () => {
      document.removeEventListener("keydown", handleEsc);
      if (!showPanel) {
        document.body.style.overflow = "unset";
      }
    };
  }, [showColumnsModal, showPanel]);

  useEffect(() => {
    const handleEsc = (event: KeyboardEvent) => {
      if (event.key === "Escape" && showDeleteModal) {
        cancelDelete();
      }
    };

    if (showDeleteModal) {
      document.addEventListener("keydown", handleEsc);
      document.body.style.overflow = "hidden";
    }

    return () => {
      document.removeEventListener("keydown", handleEsc);
      if (!showPanel && !showColumnsModal) {
        document.body.style.overflow = "unset";
      }
    };
  }, [showDeleteModal, showPanel, showColumnsModal, cancelDelete]);

  return (
    <div className="min-h-screen bg-gray-950 text-white">
      <div className="max-w-6xl mx-auto px-4 py-6 md:py-10 space-y-6 md:space-y-10">
        <header className="space-y-1 md:space-y-2">
          <div>
            <h1 className="text-2xl md:text-3xl font-semibold">{title || entityName}</h1>
            {description && <p className="text-sm md:text-base text-gray-400">{description}</p>}
          </div>
        </header>

        {showPanel && (
          <>
            <div className="fixed inset-0 bg-black/50 z-40 transition-opacity" onClick={closePanel} />

            <div className="fixed top-0 right-0 h-full w-full md:w-[80%] bg-gray-900 border-l border-gray-800 z-50 shadow-2xl transform transition-transform duration-300 ease-in-out overflow-y-auto">
              <div className="p-4 md:p-6 space-y-4 md:space-y-6">
                <div className="flex items-center justify-between border-b border-gray-800 pb-3 md:pb-4">
                  <h2 className="text-xl md:text-2xl font-semibold">
                    {editingItemId ? `Edit ${entityName}` : `Create ${entityName}`}
                  </h2>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-gray-300 hover:text-white"
                    onClick={closePanel}
                    type="button"
                  >
                    <X className="w-5 h-5" />
                  </Button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                  <div className="grid gap-4 md:grid-cols-2">
                    {formFields.map((field) => (
                      <label
                        key={field.key}
                        className={`space-y-2 text-sm text-gray-300 ${field.type === "textarea" ? "md:col-span-2" : ""}`}
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
                          <input
                            type={field.type || "text"}
                            className="w-full rounded-md border border-gray-700 bg-gray-800/50 px-3 py-2 text-sm text-white placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-600"
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

                  <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-gray-800">
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
                      className="border-gray-700 text-gray-200 hover:bg-gray-800"
                      onClick={closePanel}
                    >
                      Cancel
                    </Button>
                  </div>
                </form>
              </div>
            </div>
          </>
        )}

        {showColumnsModal && (
          <>
            <div className="fixed inset-0 bg-black/50 z-40 transition-opacity" onClick={() => setShowColumnsModal(false)} />

            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
              <div className="bg-gray-900 border border-gray-800 rounded-lg shadow-2xl w-full max-w-md max-h-[80vh] overflow-hidden flex flex-col">
                <div className="flex items-center justify-between border-b border-gray-800 p-4">
                  <h2 className="text-xl font-semibold">Select Columns</h2>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-gray-300 hover:text-white"
                    onClick={() => setShowColumnsModal(false)}
                    type="button"
                  >
                    <X className="w-5 h-5" />
                  </Button>
                </div>

                <div className="p-4 overflow-y-auto flex-1">
                  {loadingColumns ? (
                    <div className="flex items-center justify-center py-8">
                      <Loader2 className="w-6 h-6 text-gray-400 animate-spin" />
                    </div>
                  ) : (
                    <div className="space-y-2">
                      {availableColumns.map((column) => (
                        <label
                          key={column.key}
                          className="flex items-center space-x-3 p-2 rounded-md hover:bg-gray-800/50 cursor-pointer"
                        >
                          <input
                            type="checkbox"
                            checked={visibleColumns.has(column.key)}
                            onChange={() => toggleColumn(column.key)}
                            className="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500 focus:ring-2"
                          />
                          <span className="text-sm text-gray-200">{column.label}</span>
                        </label>
                      ))}
                    </div>
                  )}
                </div>

                <div className="flex items-center justify-end gap-3 border-t border-gray-800 p-4">
                  <Button
                    type="button"
                    variant="outline"
                    className="border-gray-700 text-gray-200 hover:bg-gray-800"
                    onClick={() => setShowColumnsModal(false)}
                  >
                    Close
                  </Button>
                </div>
              </div>
            </div>
          </>
        )}

        {showDeleteModal && itemToDelete && (
          <>
            <div className="fixed inset-0 bg-black/60 z-50 transition-opacity backdrop-blur-sm" onClick={cancelDelete} />

            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
              <div className="bg-gray-900 border border-red-500/20 rounded-lg shadow-2xl w-full max-w-md overflow-hidden transform transition-all">
                <div className="p-6 pb-4">
                  <div className="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 border border-red-500/20">
                    <AlertTriangle className="w-8 h-8 text-red-400" />
                  </div>
                  <h2 className="text-2xl font-semibold text-white text-center mb-2">
                    Delete {entityName}?
                  </h2>
                  <p className="text-gray-400 text-center text-sm">
                    This action cannot be undone. This will permanently delete the {entityName.toLowerCase()}
                  </p>
                </div>

                <div className="px-6 py-4 bg-gray-800/50 border-y border-gray-800">
                  <div className="flex items-start gap-3">
                    <div className="flex-1 min-w-0">
                      <p className="text-xs text-gray-500 mb-1">{entityName} Title</p>
                      <p className="text-sm font-medium text-white truncate">{getItemTitle(itemToDelete)}</p>
                      {getItemId(itemToDelete) && (
                        <>
                          <p className="text-xs text-gray-500 mt-2 mb-1">{entityName} ID</p>
                          <p className="text-sm text-gray-300">#{getItemId(itemToDelete)}</p>
                        </>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex items-center justify-end gap-3 p-6 pt-4">
                  <Button
                    type="button"
                    variant="outline"
                    className="border-gray-700 text-gray-200 hover:bg-gray-800"
                    onClick={cancelDelete}
                    disabled={deletingId === getItemId(itemToDelete)}
                  >
                    Cancel
                  </Button>
                  <Button
                    type="button"
                    className="bg-red-600 hover:bg-red-700 text-white border-red-600"
                    onClick={confirmDelete}
                    disabled={deletingId === getItemId(itemToDelete)}
                  >
                    {deletingId === getItemId(itemToDelete) ? (
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
                </div>
              </div>
            </div>
          </>
        )}

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
                className="flex-1 sm:flex-none border-gray-700 text-gray-200 hover:bg-gray-800"
                onClick={() => setShowColumnsModal(true)}
              >
                <Columns className="w-4 h-4 mr-2" />
                <span className="hidden sm:inline">Columns</span>
              </Button>
              <Button
                className="flex-1 sm:flex-none"
                onClick={() => {
                  resetForm();
                  setShowPanel(true);
                }}
              >
                Add {entityName}
              </Button>
            </div>
          </div>

          <div className="rounded-lg border border-gray-800 overflow-hidden">
            <div className="block md:hidden">
              {loading ? (
                <div className="py-8 flex items-center justify-center">
                  <Loader2 className="w-6 h-6 text-gray-400 animate-spin" />
                </div>
              ) : errorStatus === 401 ? (
                <div className="py-8 px-4">
                  <div className="flex flex-col items-center justify-center">
                    <LogIn className="w-12 h-12 text-gray-600 mb-3" />
                    <h3 className="text-lg font-semibold text-white mb-2 text-center">Authentication Required</h3>
                    <p className="text-sm text-gray-400 text-center max-w-md px-4">
                      You need to be authenticated to access this page. Please log in to continue.
                    </p>
                  </div>
                </div>
              ) : errorStatus === 403 ? (
                <div className="py-8 px-4">
                  <div className="flex flex-col items-center justify-center">
                    <Lock className="w-12 h-12 text-gray-600 mb-3" />
                    <h3 className="text-lg font-semibold text-white mb-2 text-center">Access Denied</h3>
                    <p className="text-sm text-gray-400 text-center max-w-md px-4">
                      You don't have permission to access this page. Please contact your administrator if you believe
                      this is an error.
                    </p>
                  </div>
                </div>
              ) : errorStatus === 500 ? (
                <div className="py-8 px-4">
                  <div className="flex flex-col items-center justify-center">
                    <AlertCircle className="w-12 h-12 text-gray-600 mb-3" />
                    <h3 className="text-lg font-semibold text-white mb-2 text-center">Oops! Something went wrong</h3>
                    <p className="text-sm text-gray-400 text-center max-w-md px-4">
                      We are already fixing it. Please try again in a few minutes.
                    </p>
                  </div>
                </div>
              ) : errorStatus === 404 || items.length === 0 ? (
                <div className="py-8 px-4">
                  <div className="flex flex-col items-center justify-center">
                    <FileQuestion className="w-12 h-12 text-gray-600 mb-3" />
                    <h3 className="text-lg font-semibold text-white mb-2 text-center">Nothing Found</h3>
                    <p className="text-sm text-gray-400 text-center max-w-md px-4">
                      No {entityName.toLowerCase()} were found. Try adjusting your search or filters, or create a new{" "}
                      {entityName.toLowerCase()}.
                    </p>
                  </div>
                </div>
              ) : (
                <div className="divide-y divide-gray-800">
                  {items.map((item) => (
                    <div key={getItemId(item)} className="p-4 hover:bg-gray-900/50 transition-colors">
                      <div className="space-y-3">
                        {availableColumns
                          .filter((col) => visibleColumns.has(col.key))
                          .map((column) => (
                            <div key={column.key} className="flex flex-col">
                              <span className="text-xs text-gray-500 mb-1 font-medium">{column.label}</span>
                              <div className="text-sm text-gray-200">{defaultRenderCellValue(item, column.key)}</div>
                            </div>
                          ))}
                        <div className="flex items-center justify-end gap-2 pt-2 border-t border-gray-800">
                          <Button
                            variant="outline"
                            size="sm"
                            className="border-gray-700 text-gray-200 hover:bg-gray-800 text-xs flex-1"
                            onClick={() => handleEdit(item)}
                          >
                            Edit
                          </Button>
                          <Button
                            variant="outline"
                            size="sm"
                            className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-xs flex-1"
                            onClick={() => handleDelete(item)}
                            disabled={deletingId === getItemId(item)}
                          >
                          {deletingId === getItemId(item) ? "Deleting..." : "Delete"}
                          </Button>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="hidden md:block overflow-x-auto">
              <Table className="min-w-full">
                <TableHeader>
                  <TableRow>
                    {availableColumns
                      .filter((col) => visibleColumns.has(col.key))
                      .map((column) => (
                        <TableHead
                          key={column.key}
                          className="cursor-pointer select-none hover:bg-gray-800/50 transition-colors whitespace-nowrap min-w-[100px]"
                          onClick={() => handleColumnSort(column.key)}
                        >
                          <div className="flex items-center gap-2">
                            <span className="text-sm">{column.label}</span>
                            {getSortIcon(column.key)}
                          </div>
                        </TableHead>
                      ))}
                    <TableHead className="text-right whitespace-nowrap min-w-[120px]">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {loading ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16">
                        <div className="flex items-center justify-center">
                          <Loader2 className="w-6 h-6 text-gray-400 animate-spin" />
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : errorStatus === 401 ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16 px-4">
                        <div className="flex flex-col items-center justify-center">
                          <LogIn className="w-16 h-16 text-gray-600 mb-4" />
                          <h3 className="text-xl font-semibold text-white mb-2 text-center">Authentication Required</h3>
                          <p className="text-base text-gray-400 text-center max-w-md px-4">
                            You need to be authenticated to access this page. Please log in to continue.
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : errorStatus === 403 ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16 px-4">
                        <div className="flex flex-col items-center justify-center">
                          <Lock className="w-16 h-16 text-gray-600 mb-4" />
                          <h3 className="text-xl font-semibold text-white mb-2 text-center">Access Denied</h3>
                          <p className="text-base text-gray-400 text-center max-w-md px-4">
                            You don't have permission to access this page. Please contact your administrator if you
                            believe this is an error.
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : errorStatus === 500 ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16 px-4">
                        <div className="flex flex-col items-center justify-center">
                          <AlertCircle className="w-16 h-16 text-gray-600 mb-4" />
                          <h3 className="text-xl font-semibold text-white mb-2 text-center">Oops! Something went wrong</h3>
                          <p className="text-base text-gray-400 text-center max-w-md px-4">
                            We are already fixing it. Please try again in a few minutes.
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : errorStatus === 404 || items.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={visibleColumnCount} className="py-16 px-4">
                        <div className="flex flex-col items-center justify-center">
                          <FileQuestion className="w-16 h-16 text-gray-600 mb-4" />
                          <h3 className="text-xl font-semibold text-white mb-2 text-center">Nothing Found</h3>
                          <p className="text-base text-gray-400 text-center max-w-md px-4">
                            No {entityName.toLowerCase()} were found. Try adjusting your search or filters, or create a
                            new {entityName.toLowerCase()}.
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : (
                    items.map((item) => (
                      <TableRow key={getItemId(item)}>
                        {availableColumns
                          .filter((col) => visibleColumns.has(col.key))
                          .map((column) => (
                            <TableCell
                              key={column.key}
                              className={`text-sm ${
                                ["created_at", "updated_at", "published_at"].includes(column.key)
                                  ? "whitespace-nowrap"
                                  : ""
                              }`}
                            >
                              {defaultRenderCellValue(item, column.key)}
                            </TableCell>
                          ))}
                        <TableCell className="text-right whitespace-nowrap">
                          <div className="flex items-center justify-end gap-2">
                            <Button
                              variant="outline"
                              size="sm"
                              className="border-gray-700 text-gray-200 hover:bg-gray-800 text-sm px-3"
                              onClick={() => handleEdit(item)}
                            >
                              Edit
                            </Button>
                            <Button
                              variant="outline"
                              size="sm"
                              className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-sm px-3"
                              onClick={() => handleDelete(item)}
                              disabled={deletingId === getItemId(item)}
                            >
                              {deletingId === getItemId(item) ? "Deleting..." : "Delete"}
                            </Button>
                          </div>
                        </TableCell>
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
