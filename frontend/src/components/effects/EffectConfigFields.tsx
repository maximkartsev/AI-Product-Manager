"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { listFiles, type ConfigurableProperty, type UserFile } from "@/lib/api";
import type { PendingAssetSelection, PendingAssetsMap } from "@/lib/effectUploadTypes";

type EffectConfigFieldsProps = {
  properties: ConfigurableProperty[];
  value: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
  pendingAssets?: PendingAssetsMap;
  onPendingAssetsChange?: (next: PendingAssetsMap) => void;
};

type FileKind = "image" | "video";

const EMPTY_FILES: Record<FileKind, UserFile[]> = { image: [], video: [] };

function parseFileId(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value) && value > 0) return value;
  if (typeof value === "string" && /^\d+$/.test(value)) return Number(value);
  return null;
}

export default function EffectConfigFields({
  properties,
  value,
  onChange,
  pendingAssets: pendingAssetsProp,
  onPendingAssetsChange,
}: EffectConfigFieldsProps) {
  const [filesByKind, setFilesByKind] = useState<Record<FileKind, UserFile[]>>(EMPTY_FILES);
  const [loadingKinds, setLoadingKinds] = useState<Record<FileKind, boolean>>({
    image: false,
    video: false,
  });
  const inputRefs = useRef<Record<string, HTMLInputElement | null>>({});
  const [pendingAssetsState, setPendingAssetsState] = useState<PendingAssetsMap>({});

  const pendingAssets = pendingAssetsProp ?? pendingAssetsState;
  const setPendingAssets = onPendingAssetsChange ?? setPendingAssetsState;

  const needsImage = useMemo(
    () => properties.some((prop) => prop.type === "image"),
    [properties],
  );
  const needsVideo = useMemo(
    () => properties.some((prop) => prop.type === "video"),
    [properties],
  );

  const updateValue = useCallback(
    (key: string, next: unknown) => {
      const nextPayload = { ...value };
      const isString = typeof next === "string";
      const trimmed = isString ? next.trim() : next;
      if (
        trimmed === null ||
        trimmed === undefined ||
        trimmed === "" ||
        (typeof trimmed === "number" && !Number.isFinite(trimmed))
      ) {
        delete nextPayload[key];
      } else {
        nextPayload[key] = isString ? next : trimmed;
      }
      onChange(nextPayload);
    },
    [onChange, value],
  );

  const loadFiles = useCallback(async (kind: FileKind) => {
    setLoadingKinds((prev) => ({ ...prev, [kind]: true }));
    try {
      const data = await listFiles({ kind, perPage: 50, order: "created_at:desc" });
      setFilesByKind((prev) => ({ ...prev, [kind]: data.items ?? [] }));
    } catch {
      setFilesByKind((prev) => ({ ...prev, [kind]: [] }));
    } finally {
      setLoadingKinds((prev) => ({ ...prev, [kind]: false }));
    }
  }, []);

  const setPendingAsset = useCallback(
    (key: string, asset: PendingAssetSelection | null) => {
      const next = { ...pendingAssets };
      if (!asset) {
        delete next[key];
      } else {
        next[key] = asset;
      }
      setPendingAssets(next);
    },
    [pendingAssets, setPendingAssets],
  );

  useEffect(() => {
    if (needsImage) {
      void loadFiles("image");
    }
  }, [loadFiles, needsImage]);

  useEffect(() => {
    if (needsVideo) {
      void loadFiles("video");
    }
  }, [loadFiles, needsVideo]);

  const [promptProps, otherTextProps, assetProps] = useMemo(() => {
    const textProps = properties.filter((prop) => prop.type === "text");
    const assets = properties.filter((prop) => prop.type === "image" || prop.type === "video");
    const positive = textProps.find((prop) => prop.key === "positive_prompt") ?? null;
    const negative = textProps.find((prop) => prop.key === "negative_prompt") ?? null;
    const hasPair = !!positive && !!negative;
    const remaining = hasPair
      ? textProps.filter(
          (prop) => prop.key !== "positive_prompt" && prop.key !== "negative_prompt",
        )
      : textProps;
    return [[positive, negative, hasPair] as const, remaining, assets] as const;
  }, [properties]);

  if (properties.length === 0) {
    return null;
  }

  const [positiveProp, negativeProp, showPromptPair] = promptProps;

  return (
    <div className="mt-4 space-y-4">
      {showPromptPair && (
        <div className="space-y-3">
          <div>
            <div className="flex items-center justify-between text-[11px] font-semibold text-white/70">
              <span>{positiveProp?.name || "Positive prompt"}</span>
            </div>
            {positiveProp?.description ? (
              <div className="mt-1 text-[11px] text-white/45">{positiveProp.description}</div>
            ) : null}
            <Textarea
              value={typeof value.positive_prompt === "string" ? value.positive_prompt : ""}
              onChange={(event) => updateValue("positive_prompt", event.target.value)}
              placeholder={positiveProp?.default_value || "Enter value..."}
              className="mt-2 min-h-[84px] text-xs"
            />
          </div>
          <div>
            <div className="flex items-center justify-between text-[11px] font-semibold text-white/70">
              <span>{negativeProp?.name || "Negative prompt"}</span>
            </div>
            {negativeProp?.description ? (
              <div className="mt-1 text-[11px] text-white/45">{negativeProp.description}</div>
            ) : null}
            <Textarea
              value={typeof value.negative_prompt === "string" ? value.negative_prompt : ""}
              onChange={(event) => updateValue("negative_prompt", event.target.value)}
              placeholder={negativeProp?.default_value || "Enter value..."}
              className="mt-2 min-h-[84px] text-xs"
            />
          </div>
        </div>
      )}

      {otherTextProps.length > 0 && (
        <div className="space-y-3">
          {otherTextProps.map((prop) => (
            <div key={prop.key}>
              <div className="flex items-center justify-between text-[11px] font-semibold text-white/70">
                <span>
                  {prop.name || prop.key}
                  {prop.required ? <span className="text-fuchsia-200 ml-1">*</span> : null}
                </span>
              </div>
              {prop.description ? (
                <div className="mt-1 text-[11px] text-white/45">{prop.description}</div>
              ) : null}
              {prop.key.endsWith("_prompt") ? (
                <Textarea
                  value={typeof value[prop.key] === "string" ? (value[prop.key] as string) : ""}
                  onChange={(event) => updateValue(prop.key, event.target.value)}
                  placeholder={prop.default_value || "Enter value..."}
                  className="mt-2 min-h-[84px] text-xs"
                />
              ) : (
                <Input
                  value={typeof value[prop.key] === "string" ? (value[prop.key] as string) : ""}
                  onChange={(event) => updateValue(prop.key, event.target.value)}
                  placeholder={prop.default_value || "Enter value..."}
                  className="mt-2 text-xs"
                />
              )}
            </div>
          ))}
        </div>
      )}

      {assetProps.length > 0 && (
        <div className="space-y-3">
          {assetProps.map((prop) => {
            const kind = prop.type as FileKind;
            const files = filesByKind[kind];
            const selectedId = parseFileId(value[prop.key]);
            const pending = pendingAssets[prop.key];
            return (
              <div key={prop.key} className="space-y-2">
                <div className="flex items-center justify-between text-[11px] font-semibold text-white/70">
                  <span>
                    {prop.name || prop.key}
                    {prop.required ? <span className="text-fuchsia-200 ml-1">*</span> : null}
                  </span>
                </div>
                {prop.description ? (
                  <div className="text-[11px] text-white/45">{prop.description}</div>
                ) : null}
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                  <Select
                    value={selectedId ? String(selectedId) : "__none__"}
                    onValueChange={(next) => {
                      if (next === "__none__") {
                        updateValue(prop.key, null);
                      } else {
                        updateValue(prop.key, Number(next));
                      }
                      if (pending) {
                        setPendingAsset(prop.key, null);
                      }
                    }}
                  >
                    <SelectTrigger className="flex-1 min-w-0 text-xs">
                      <SelectValue placeholder={loadingKinds[kind] ? "Loading..." : "Select a file..."} />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="__none__">— None —</SelectItem>
                      {files.map((file) => (
                        <SelectItem key={file.id} value={String(file.id)}>
                          {file.original_filename || `File #${file.id}`}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <input
                    ref={(el) => {
                      inputRefs.current[prop.key] = el;
                    }}
                    type="file"
                    className="hidden"
                    accept={kind === "image" ? "image/*" : "video/*"}
                    onChange={(event) => {
                      const file = event.target.files?.[0];
                      event.target.value = "";
                      if (!file) return;
                      updateValue(prop.key, null);
                      setPendingAsset(prop.key, { propertyKey: prop.key, kind, file });
                    }}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="text-xs"
                    onClick={() => inputRefs.current[prop.key]?.click()}
                  >
                    {pending ? "Replace file" : "Choose file"}
                  </Button>
                </div>
                {pending ? (
                  <div className="rounded-md border border-white/10 bg-white/5 px-2 py-1 text-[11px] text-white/60">
                    <div className="flex items-center justify-between gap-2">
                      <span className="min-w-0 truncate" title={pending.file.name}>
                        Selected: {pending.file.name}
                      </span>
                      <button
                        type="button"
                        className="shrink-0 text-[10px] font-semibold text-white/50 hover:text-white"
                        onClick={() => setPendingAsset(prop.key, null)}
                      >
                        Clear
                      </button>
                    </div>
                    <div className="mt-1 text-[10px] text-white/40">Uploads when processing starts.</div>
                  </div>
                ) : null}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
