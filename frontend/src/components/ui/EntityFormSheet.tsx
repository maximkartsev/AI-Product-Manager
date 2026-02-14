"use client";

import React, { useCallback, useState } from "react";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import type { z } from "zod";
import type { DataTableFormField } from "@/components/ui/DataTable";
import type { ColumnConfig } from "@/lib/api";
import { extractFieldErrors, extractErrorMessage } from "@/lib/apiErrors";

export function EntityFormSheet<TCreate, TUpdate>({
  entityName,
  formFields,
  initialFormState,
  getFormData,
  validateForm,
  formSchema,
  availableColumns,
  fkOptions,
  fkLoading,
  open,
  onOpenChange,
  editingItem,
  onCreate,
  onUpdate,
  onSaved,
}: {
  entityName: string;
  formFields: DataTableFormField[];
  initialFormState: Record<string, any>;
  getFormData?: (formState: Record<string, any>) => TCreate | TUpdate;
  validateForm?: (formState: Record<string, any>) => string | null;
  formSchema?: z.ZodObject<any>;
  availableColumns: ColumnConfig[];
  fkOptions: Record<string, { value: string; label: string }[]>;
  fkLoading: Record<string, boolean>;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editingItem: { id: number; data: Record<string, any> } | null;
  onCreate?: (data: TCreate) => Promise<any>;
  onUpdate?: (id: number, data: TUpdate) => Promise<any>;
  onSaved: () => void;
}) {
  const [formState, setFormState] = useState<Record<string, any>>(initialFormState);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const isEditing = !!editingItem;

  // Sync form state when editingItem changes
  const prevEditingRef = React.useRef<typeof editingItem>(null);
  if (editingItem !== prevEditingRef.current) {
    prevEditingRef.current = editingItem;
    if (editingItem) {
      setFormState(editingItem.data);
      setFieldErrors({});
      setFormError(null);
    } else if (open) {
      // Reset to initial when switching from edit to create
    }
  }

  const closePanel = useCallback(() => {
    onOpenChange(false);
    setFormState(initialFormState);
    setFormError(null);
    setFieldErrors({});
  }, [initialFormState, onOpenChange]);

  const handleOpenChange = useCallback((o: boolean) => {
    if (!o) {
      closePanel();
    } else {
      onOpenChange(true);
    }
  }, [closePanel, onOpenChange]);

  // Reset form when opening for "create" (no editingItem)
  const prevOpenRef = React.useRef(false);
  if (open && !prevOpenRef.current && !editingItem) {
    setFormState(initialFormState);
    setFieldErrors({});
    setFormError(null);
  }
  prevOpenRef.current = open;

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    setFieldErrors({});

    const payload = getFormData ? getFormData(formState) : formState;

    // Zod validation
    if (formSchema) {
      const result = formSchema.safeParse(payload);
      if (!result.success) {
        const errors: Record<string, string> = {};
        result.error.issues.forEach((issue) => {
          const field = issue.path[0];
          if (field !== undefined && !errors[String(field)]) {
            errors[String(field)] = issue.message;
          }
        });
        setFieldErrors(errors);
        const firstErrorKey = Object.keys(errors)[0];
        if (firstErrorKey) {
          document.getElementById(firstErrorKey)?.focus();
        }
        return;
      }
    } else if (validateForm) {
      const validationError = validateForm(formState);
      if (validationError) {
        setFormError(validationError);
        return;
      }
    }

    setIsSaving(true);
    try {
      if (isEditing && onUpdate) {
        await onUpdate(editingItem!.id, payload as TUpdate);
        toast.success(`${entityName} updated successfully`);
      } else if (onCreate) {
        await onCreate(payload as TCreate);
        toast.success(`${entityName} created successfully`);
      }
      closePanel();
      onSaved();
    } catch (error) {
      console.error(`Failed to save ${entityName}.`, error);
      const serverFieldErrors = extractFieldErrors(error);
      if (Object.keys(serverFieldErrors).length > 0) {
        setFieldErrors(serverFieldErrors);
        const firstKey = Object.keys(serverFieldErrors)[0];
        if (firstKey) document.getElementById(firstKey)?.focus();
      }
      const message = extractErrorMessage(error, `Failed to save ${entityName}.`);
      toast.error(message);
      setFormError(message);
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Sheet open={open} onOpenChange={handleOpenChange}>
      <SheetContent side="right" className="overflow-y-auto">
        <div className="p-4 md:p-6 space-y-4 md:space-y-6">
          <SheetHeader className="border-b border-border pb-3 md:pb-4">
            <SheetTitle className="text-xl md:text-2xl">
              {isEditing ? `Edit ${entityName}` : `Create ${entityName}`}
            </SheetTitle>
            <SheetDescription className="sr-only">
              {isEditing ? `Edit the ${entityName.toLowerCase()} details` : `Create a new ${entityName.toLowerCase()}`}
            </SheetDescription>
          </SheetHeader>

          <form onSubmit={handleSubmit} noValidate className="space-y-4">
            <fieldset disabled={isSaving} className="grid gap-4 md:grid-cols-2">
              {formFields.map((field) => {
                const isFullWidth = field.type === "textarea" || field.fullWidth;
                const isCheckbox = field.type === "checkbox";
                const hasError = !!fieldErrors[field.key];

                const updateField = (key: string, value: string) => {
                  setFormState((prev) => ({ ...prev, [key]: value }));
                  if (fieldErrors[key]) {
                    setFieldErrors((prev) => {
                      const next = { ...prev };
                      delete next[key];
                      return next;
                    });
                  }
                };

                // Check FK auto-dropdown
                const fkCol = availableColumns.find(
                  (col) => col.foreignKey && col.foreignKey.field === field.key,
                );
                const fkOpts = fkCol ? fkOptions[fkCol.foreignKey!.field] : undefined;
                const isFkLoading = fkCol ? fkLoading[fkCol.foreignKey!.field] : false;

                return (
                  <React.Fragment key={field.key}>
                    {field.section && (
                      <div className="md:col-span-2 pt-4 first:pt-0">
                        <h3 className="text-sm font-semibold text-foreground border-b border-border pb-2">
                          {field.section}
                        </h3>
                      </div>
                    )}

                    {isCheckbox ? (
                      <div className={`space-y-2 text-sm ${isFullWidth ? "md:col-span-2" : ""}`}>
                        {field.render ? (
                          field.render({
                            field,
                            value: formState[field.key] || "",
                            onChange: (value) => updateField(field.key, value),
                            formState,
                            setFormState,
                          })
                        ) : (
                          <div className="flex items-center gap-2">
                            <Checkbox
                              id={field.key}
                              checked={formState[field.key] === "true"}
                              onCheckedChange={(checked) =>
                                updateField(field.key, checked ? "true" : "false")
                              }
                              aria-invalid={hasError || undefined}
                              aria-describedby={hasError ? `${field.key}-error` : undefined}
                            />
                            <label htmlFor={field.key} className="text-sm text-muted-foreground cursor-pointer">
                              {field.label}
                            </label>
                          </div>
                        )}
                        {hasError && (
                          <p className="text-xs text-destructive mt-1" role="alert" id={`${field.key}-error`}>
                            {fieldErrors[field.key]}
                          </p>
                        )}
                      </div>
                    ) : (
                      <div className={`space-y-2 text-sm text-muted-foreground ${isFullWidth ? "md:col-span-2" : ""}`}>
                        <label htmlFor={field.key}>
                          {field.label}
                          {field.required && <span className="text-red-400 ml-0.5">*</span>}
                        </label>

                        {field.render ? (
                          field.render({
                            field,
                            value: formState[field.key] || "",
                            onChange: (value) => updateField(field.key, value),
                            formState,
                            setFormState,
                          })
                        ) : fkCol && fkOpts ? (
                          <Select
                            value={formState[field.key] || "__none__"}
                            onValueChange={(v) => updateField(field.key, v === "__none__" ? "" : v)}
                            disabled={isFkLoading}
                          >
                            <SelectTrigger
                              id={field.key}
                              aria-invalid={hasError || undefined}
                              aria-describedby={hasError ? `${field.key}-error` : undefined}
                            >
                              <SelectValue placeholder={isFkLoading ? "Loading..." : `Select ${field.label.toLowerCase()}...`} />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="__none__">— None —</SelectItem>
                              {fkOpts.map((opt) => (
                                <SelectItem key={opt.value} value={opt.value}>
                                  {opt.label}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        ) : field.type === "select" && field.options ? (
                          <Select
                            value={formState[field.key] || ""}
                            onValueChange={(v) => updateField(field.key, v)}
                          >
                            <SelectTrigger
                              id={field.key}
                              aria-invalid={hasError || undefined}
                              aria-describedby={hasError ? `${field.key}-error` : undefined}
                            >
                              <SelectValue placeholder={field.placeholder || `Select ${field.label.toLowerCase()}...`} />
                            </SelectTrigger>
                            <SelectContent>
                              {field.options.map((opt) => (
                                <SelectItem key={opt.value} value={opt.value}>
                                  {opt.label}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        ) : field.type === "textarea" ? (
                          <Textarea
                            id={field.key}
                            value={formState[field.key] || ""}
                            onChange={(event) => updateField(field.key, event.target.value)}
                            placeholder={field.placeholder}
                            className="min-h-[140px]"
                            aria-invalid={hasError || undefined}
                            aria-describedby={hasError ? `${field.key}-error` : undefined}
                          />
                        ) : (
                          <Input
                            id={field.key}
                            type={field.type || "text"}
                            value={formState[field.key] || ""}
                            onChange={(event) => updateField(field.key, event.target.value)}
                            placeholder={field.placeholder}
                            aria-invalid={hasError || undefined}
                            aria-describedby={hasError ? `${field.key}-error` : undefined}
                          />
                        )}

                        {hasError && (
                          <p className="text-xs text-destructive mt-1" role="alert" id={`${field.key}-error`}>
                            {fieldErrors[field.key]}
                          </p>
                        )}
                      </div>
                    )}
                  </React.Fragment>
                );
              })}
            </fieldset>

            {formError && <p className="text-sm text-red-400">{formError}</p>}

            <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-border">
              <Button type="submit" disabled={isSaving}>
                {isSaving ? (
                  <>
                    <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                    {isEditing ? "Saving..." : "Creating..."}
                  </>
                ) : isEditing ? (
                  "Save changes"
                ) : (
                  `Create ${entityName}`
                )}
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
  );
}
