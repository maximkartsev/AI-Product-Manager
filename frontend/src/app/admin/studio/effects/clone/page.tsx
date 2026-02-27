"use client";

import Link from "next/link";
import { Suspense, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Button } from "@/components/ui/button";
import { cloneStudioEffect, getAdminEffects, type AdminEffect, type StudioEffectCloneMode } from "@/lib/api";
import { extractErrorMessage } from "@/lib/apiErrors";

export default function StudioEffectClonePage() {
  return (
    <Suspense
      fallback={<div className="text-sm text-muted-foreground">Loading clone page...</div>}
    >
      <StudioEffectCloneInner />
    </Suspense>
  );
}

function StudioEffectCloneInner() {
  const searchParams = useSearchParams();
  const [effects, setEffects] = useState<AdminEffect[]>([]);
  const [loadingEffects, setLoadingEffects] = useState(false);
  const [selectedEffectId, setSelectedEffectId] = useState<string>(searchParams.get("effectId") || "");
  const [mode, setMode] = useState<StudioEffectCloneMode>("effect_only");
  const [cloning, setCloning] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [createdEffectId, setCreatedEffectId] = useState<number | null>(null);
  const [createdWorkflowId, setCreatedWorkflowId] = useState<number | null>(null);

  useEffect(() => {
    setLoadingEffects(true);
    getAdminEffects({ perPage: 200 })
      .then((response) => {
        setEffects(response.items ?? []);
      })
      .catch((error: unknown) => {
        setErrorMessage(extractErrorMessage(error, "Failed to load effects."));
      })
      .finally(() => {
        setLoadingEffects(false);
      });
  }, []);

  const selectedEffect = useMemo(() => {
    const id = Number(selectedEffectId);
    if (!Number.isFinite(id) || id <= 0) return null;
    return effects.find((effect) => effect.id === id) ?? null;
  }, [effects, selectedEffectId]);

  async function handleClone(): Promise<void> {
    if (!selectedEffect) {
      setErrorMessage("Select an effect to clone.");
      return;
    }

    setCloning(true);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      const result = await cloneStudioEffect(selectedEffect.id, mode);
      const effectId = result.effect?.id ?? null;
      const workflowId = result.workflow?.id ?? null;
      setCreatedEffectId(effectId);
      setCreatedWorkflowId(workflowId);
      setSuccessMessage(
        mode === "effect_and_workflow"
          ? "Effect and workflow cloned successfully."
          : "Effect cloned successfully.",
      );
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to clone effect."));
    } finally {
      setCloning(false);
    }
  }

  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold tracking-tight">Studio Effect Clone</h1>
        <p className="text-sm text-muted-foreground">
          Clone an effect only, or clone both effect and workflow in one backend operation.
        </p>
      </header>

      <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
        <div className="space-y-1.5">
          <label className="text-xs font-semibold text-muted-foreground">Effect</label>
          <select
            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            value={selectedEffectId}
            onChange={(event) => setSelectedEffectId(event.target.value)}
            disabled={loadingEffects}
          >
            <option value="">Select effect</option>
            {effects.map((effect) => (
              <option key={effect.id} value={effect.id}>
                {effect.name || effect.slug || `Effect #${effect.id}`}
              </option>
            ))}
          </select>
        </div>

        <div className="space-y-1.5">
          <label className="text-xs font-semibold text-muted-foreground">Clone mode</label>
          <select
            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            value={mode}
            onChange={(event) => {
              const nextMode = event.target.value;
              if (nextMode === "effect_only" || nextMode === "effect_and_workflow") {
                setMode(nextMode);
              }
            }}
          >
            <option value="effect_only">effect_only (keep existing workflow)</option>
            <option value="effect_and_workflow">effect_and_workflow (clone workflow first)</option>
          </select>
        </div>

        {selectedEffect ? (
          <div className="rounded-md border border-border/60 bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
            Source effect #{selectedEffect.id} currently uses workflow #{selectedEffect.workflow_id ?? "n/a"}.
          </div>
        ) : null}

        {errorMessage ? (
          <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
            {errorMessage}
          </div>
        ) : null}

        {successMessage ? (
          <div className="space-y-2 rounded-md border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-100">
            <p>{successMessage}</p>
            <div className="flex flex-wrap gap-2 text-xs">
              {createdEffectId ? (
                <Link href="/admin/effects" className="inline-flex items-center rounded-md border px-3 py-1.5">
                  Open Effects (created #{createdEffectId})
                </Link>
              ) : null}
              {createdWorkflowId ? (
                <Link href="/admin/workflows" className="inline-flex items-center rounded-md border px-3 py-1.5">
                  Open Workflows (created #{createdWorkflowId})
                </Link>
              ) : null}
            </div>
          </div>
        ) : null}

        <div className="flex justify-end">
          <Button type="button" onClick={() => void handleClone()} disabled={cloning}>
            {cloning ? "Cloning..." : "Clone Effect"}
          </Button>
        </div>
      </section>
    </div>
  );
}

