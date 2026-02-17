"use client";

import { useCallback } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import type { WorkflowProperty } from "@/lib/api";

const EMPTY_PROPERTY: WorkflowProperty = {
  key: "",
  name: "",
  description: "",
  type: "text",
  placeholder: "",
  default_value: "",
  default_value_hash: null,
  required: false,
  user_configurable: false,
  is_primary_input: false,
};

export function WorkflowPropertyBuilder({
  value,
  onChange,
}: {
  value: WorkflowProperty[];
  onChange: (props: WorkflowProperty[]) => void;
}) {
  const update = useCallback(
    (index: number, field: keyof WorkflowProperty, val: unknown) => {
      const next = value.map((p, i) => (i === index ? { ...p, [field]: val } : p));
      onChange(next);
    },
    [value, onChange],
  );

  const add = useCallback(() => {
    onChange([...value, { ...EMPTY_PROPERTY }]);
  }, [value, onChange]);

  const remove = useCallback(
    (index: number) => {
      onChange(value.filter((_, i) => i !== index));
    },
    [value, onChange],
  );

  const primaryCount = value.filter((p) => p.is_primary_input).length;
  const keys = value.map((p) => p.key);
  const duplicateKeys = keys.filter((k, i) => k && keys.indexOf(k) !== i);

  return (
    <div className="flex flex-col gap-4">
      {primaryCount > 1 && (
        <p className="text-xs text-red-400">Only one property can be marked as primary input.</p>
      )}
      {duplicateKeys.length > 0 && (
        <p className="text-xs text-red-400">Duplicate keys: {[...new Set(duplicateKeys)].join(", ")}</p>
      )}

      {value.map((prop, index) => (
        <div key={index} className="rounded-md border border-border p-3 flex flex-col gap-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-muted-foreground">
              Property #{index + 1}
            </span>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="text-red-400 hover:text-red-300 text-xs h-6 px-2"
              onClick={() => remove(index)}
            >
              Remove
            </Button>
          </div>

          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="text-xs text-muted-foreground">Key</label>
              <Input
                value={prop.key}
                onChange={(e) => update(index, "key", e.target.value)}
                placeholder="property_key"
                className="h-8 text-sm"
              />
            </div>
            <div>
              <label className="text-xs text-muted-foreground">Name</label>
              <Input
                value={prop.name}
                onChange={(e) => update(index, "name", e.target.value)}
                placeholder="Display Name"
                className="h-8 text-sm"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="text-xs text-muted-foreground">Type</label>
              <select
                value={prop.type}
                onChange={(e) => update(index, "type", e.target.value)}
                className="w-full h-8 rounded-md border border-input bg-background px-2 text-sm"
              >
                <option value="text">Text</option>
                <option value="image">Image</option>
                <option value="video">Video</option>
              </select>
            </div>
            <div>
              <label className="text-xs text-muted-foreground">Placeholder</label>
              <Input
                value={prop.placeholder}
                onChange={(e) => update(index, "placeholder", e.target.value)}
                placeholder="__PLACEHOLDER__"
                className="h-8 text-sm"
              />
            </div>
          </div>

          {prop.type === "text" && (
            <div>
              <label className="text-xs text-muted-foreground">Default Value</label>
              <Input
                value={prop.default_value || ""}
                onChange={(e) => update(index, "default_value", e.target.value)}
                placeholder="Default text value"
                className="h-8 text-sm"
              />
            </div>
          )}

          <div>
            <label className="text-xs text-muted-foreground">Description</label>
            <Input
              value={prop.description || ""}
              onChange={(e) => update(index, "description", e.target.value)}
              placeholder="Brief description"
              className="h-8 text-sm"
            />
          </div>

          <div className="flex items-center gap-4 flex-wrap">
            <div className="flex items-center gap-1.5">
              <Checkbox
                checked={!!prop.required}
                onCheckedChange={(c) => update(index, "required", !!c)}
                id={`prop-${index}-required`}
              />
              <label htmlFor={`prop-${index}-required`} className="text-xs text-muted-foreground cursor-pointer">
                Required
              </label>
            </div>
            <div className="flex items-center gap-1.5">
              <Checkbox
                checked={!!prop.user_configurable}
                onCheckedChange={(c) => update(index, "user_configurable", !!c)}
                id={`prop-${index}-user-config`}
              />
              <label htmlFor={`prop-${index}-user-config`} className="text-xs text-muted-foreground cursor-pointer">
                User Configurable
              </label>
            </div>
            <div className="flex items-center gap-1.5">
              <Checkbox
                checked={!!prop.is_primary_input}
                onCheckedChange={(c) => update(index, "is_primary_input", !!c)}
                id={`prop-${index}-primary`}
              />
              <label htmlFor={`prop-${index}-primary`} className="text-xs text-muted-foreground cursor-pointer">
                Primary Input
              </label>
            </div>
          </div>
        </div>
      ))}

      <Button type="button" variant="outline" size="sm" onClick={add}>
        Add Property
      </Button>
    </div>
  );
}
