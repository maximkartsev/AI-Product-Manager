"use client";

import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  analyzeStudioWorkflow,
  createAdminEffect,
  createAdminWorkflow,
  getAdminWorkflows,
  initWorkflowAssetUpload,
  type AdminEffect,
  type AdminWorkflow,
  type AdminWorkflowPayload,
  type StudioWorkflowAnalyzeJob,
  updateAdminWorkflow,
} from "@/lib/api";
import { extractErrorMessage } from "@/lib/apiErrors";
import {
  buildWorkflowUpdateFromAnalysis,
  parseWorkflowJsonInput,
} from "@/lib/studio/workflowJson";

type WorkflowSource = "existing" | "new_upload";

const STEPS = [
  "Workflow source",
  "Run analyzer",
  "Apply suggestions",
  "Create effect",
] as const;

const FORBIDDEN_UPLOAD_HEADERS = new Set([
  "accept-encoding",
  "connection",
  "content-length",
  "cookie",
  "host",
  "origin",
  "referer",
  "user-agent",
]);

function normalizeUploadHeaders(
  headers: Record<string, string | string[]> | undefined,
  fallbackContentType: string,
): Record<string, string> {
  const normalized: Record<string, string> = {};
  if (headers) {
    for (const [key, value] of Object.entries(headers)) {
      const trimmedKey = key.trim();
      if (!trimmedKey) continue;
      if (FORBIDDEN_UPLOAD_HEADERS.has(trimmedKey.toLowerCase())) continue;
      if (Array.isArray(value)) {
        if (value[0]) normalized[trimmedKey] = value[0];
        continue;
      }
      if (value) normalized[trimmedKey] = value;
    }
  }
  const hasContentType = Object.keys(normalized).some((header) => header.toLowerCase() === "content-type");
  if (!hasContentType) {
    normalized["Content-Type"] = fallbackContentType;
  }
  return normalized;
}

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/\s+/g, "-")
    .replace(/[^a-z0-9-]/g, "")
    .replace(/-+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function parseOptionalNumber(value: string): number | null {
  const trimmed = value.trim();
  if (!trimmed) return null;
  const parsed = Number(trimmed);
  return Number.isFinite(parsed) ? parsed : null;
}

export default function StudioCreateEffectWizardPage() {
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const [step, setStep] = useState(0);
  const [loadingWorkflows, setLoadingWorkflows] = useState(false);
  const [workflows, setWorkflows] = useState<AdminWorkflow[]>([]);
  const [sourceType, setSourceType] = useState<WorkflowSource>("existing");
  const [selectedWorkflowId, setSelectedWorkflowId] = useState<string>("");

  const [newWorkflowName, setNewWorkflowName] = useState("");
  const [newWorkflowSlug, setNewWorkflowSlug] = useState("");
  const [newWorkflowDescription, setNewWorkflowDescription] = useState("");
  const [uploadedWorkflowPath, setUploadedWorkflowPath] = useState("");
  const [uploadingWorkflowJson, setUploadingWorkflowJson] = useState(false);
  const [creatingWorkflow, setCreatingWorkflow] = useState(false);

  const [analyzeJob, setAnalyzeJob] = useState<StudioWorkflowAnalyzeJob | null>(null);
  const [analyzeRequestedOutputKind, setAnalyzeRequestedOutputKind] = useState<"image" | "video" | "audio">(
    "video",
  );
  const [analyzeExampleDescription, setAnalyzeExampleDescription] = useState("");
  const [analyzing, setAnalyzing] = useState(false);

  const [workflowPropertiesJson, setWorkflowPropertiesJson] = useState("[]");
  const [outputNodeId, setOutputNodeId] = useState("");
  const [outputExtension, setOutputExtension] = useState("");
  const [outputMimeType, setOutputMimeType] = useState("");
  const [workloadKind, setWorkloadKind] = useState<"" | "image" | "video">("");
  const [workUnitsPropertyKey, setWorkUnitsPropertyKey] = useState("");
  const [sloP95WaitSeconds, setSloP95WaitSeconds] = useState("");
  const [sloVideoSecondsPerProcessingSecondP95, setSloVideoSecondsPerProcessingSecondP95] = useState("");
  const [savingWorkflowSuggestions, setSavingWorkflowSuggestions] = useState(false);
  const [workflowSuggestionsSaved, setWorkflowSuggestionsSaved] = useState(false);

  const [effectName, setEffectName] = useState("");
  const [effectSlug, setEffectSlug] = useState("");
  const [effectDescription, setEffectDescription] = useState("");
  const [effectType, setEffectType] = useState("configurable");
  const [effectCreditsCost, setEffectCreditsCost] = useState("5");
  const [effectPopularityScore, setEffectPopularityScore] = useState("100");
  const [effectIsActive, setEffectIsActive] = useState(true);
  const [effectIsPremium, setEffectIsPremium] = useState(true);
  const [effectIsNew, setEffectIsNew] = useState(true);
  const [effectPropertyOverridesJson, setEffectPropertyOverridesJson] = useState("{}");
  const [creatingEffect, setCreatingEffect] = useState(false);
  const [createdEffect, setCreatedEffect] = useState<AdminEffect | null>(null);

  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const selectedWorkflow = useMemo(() => {
    const id = Number(selectedWorkflowId);
    if (!Number.isFinite(id) || id <= 0) return null;
    return workflows.find((workflow) => workflow.id === id) ?? null;
  }, [selectedWorkflowId, workflows]);

  useEffect(() => {
    setLoadingWorkflows(true);
    getAdminWorkflows({ perPage: 200 })
      .then((response) => {
        setWorkflows(response.items ?? []);
      })
      .catch((error: unknown) => {
        setErrorMessage(extractErrorMessage(error, "Failed to load workflows."));
      })
      .finally(() => {
        setLoadingWorkflows(false);
      });
  }, []);

  useEffect(() => {
    if (!effectSlug || effectSlug === slugify(effectName)) {
      setEffectSlug(slugify(effectName));
    }
  }, [effectName, effectSlug]);

  async function handleUploadWorkflowJson(file: File): Promise<void> {
    setErrorMessage(null);
    setUploadingWorkflowJson(true);
    try {
      const init = await initWorkflowAssetUpload({
        kind: "workflow_json",
        mime_type: file.type || "application/json",
        size: file.size,
        original_filename: file.name,
      });
      const headers = normalizeUploadHeaders(init.upload_headers, file.type || "application/json");
      const uploadResponse = await fetch(init.upload_url, {
        method: "PUT",
        headers,
        body: file,
      });
      if (!uploadResponse.ok) {
        throw new Error(`Upload failed (${uploadResponse.status}).`);
      }
      setUploadedWorkflowPath(init.path || "");
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to upload workflow JSON."));
    } finally {
      setUploadingWorkflowJson(false);
    }
  }

  async function handleCreateWorkflowDraft(): Promise<void> {
    if (!newWorkflowName.trim() || !newWorkflowSlug.trim()) {
      setErrorMessage("Workflow name and slug are required.");
      return;
    }
    if (!uploadedWorkflowPath.trim()) {
      setErrorMessage("Upload a workflow JSON file before creating a draft workflow.");
      return;
    }

    setCreatingWorkflow(true);
    setErrorMessage(null);
    try {
      const payload: AdminWorkflowPayload = {
        name: newWorkflowName.trim(),
        slug: newWorkflowSlug.trim(),
        description: newWorkflowDescription.trim() || null,
        is_active: true,
        comfyui_workflow_path: uploadedWorkflowPath.trim(),
      };
      const created = await createAdminWorkflow(payload);
      setWorkflows((prev) => [created, ...prev]);
      setSelectedWorkflowId(String(created.id));
      setEffectName(`${created.name || created.slug || "New"} Effect`);
      setWorkflowSuggestionsSaved(false);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to create workflow draft."));
    } finally {
      setCreatingWorkflow(false);
    }
  }

  async function handleRunAnalyzer(): Promise<void> {
    if (!selectedWorkflow) {
      setErrorMessage("Select or create a workflow first.");
      return;
    }

    setAnalyzing(true);
    setErrorMessage(null);
    try {
      const job = await analyzeStudioWorkflow({
        workflow_id: selectedWorkflow.id,
        requested_output_kind: analyzeRequestedOutputKind,
        example_io_description: analyzeExampleDescription.trim() || undefined,
      });
      setAnalyzeJob(job);

      const workflowPayload = buildWorkflowUpdateFromAnalysis(job.result_json);
      setWorkflowPropertiesJson(JSON.stringify(workflowPayload.properties ?? [], null, 2));
      setOutputNodeId(workflowPayload.output_node_id || "");
      setOutputExtension(workflowPayload.output_extension || "");
      setOutputMimeType(workflowPayload.output_mime_type || "");
      setWorkloadKind(workflowPayload.workload_kind || "");
      setWorkUnitsPropertyKey(workflowPayload.work_units_property_key || "");
      setSloP95WaitSeconds(
        workflowPayload.slo_p95_wait_seconds !== null && workflowPayload.slo_p95_wait_seconds !== undefined
          ? String(workflowPayload.slo_p95_wait_seconds)
          : "",
      );
      setSloVideoSecondsPerProcessingSecondP95(
        workflowPayload.slo_video_seconds_per_processing_second_p95 !== null
        && workflowPayload.slo_video_seconds_per_processing_second_p95 !== undefined
          ? String(workflowPayload.slo_video_seconds_per_processing_second_p95)
          : "",
      );
      setWorkflowSuggestionsSaved(false);
      setStep(2);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Workflow analysis failed."));
    } finally {
      setAnalyzing(false);
    }
  }

  async function handleSaveWorkflowSuggestions(): Promise<void> {
    if (!selectedWorkflow) {
      setErrorMessage("Workflow is required.");
      return;
    }

    let parsedProperties: unknown;
    try {
      parsedProperties = JSON.parse(workflowPropertiesJson);
    } catch {
      setErrorMessage("Properties must be valid JSON.");
      return;
    }
    if (!Array.isArray(parsedProperties)) {
      setErrorMessage("Properties must be a valid JSON array.");
      return;
    }

    const payload: AdminWorkflowPayload = {
      properties: parsedProperties as AdminWorkflowPayload["properties"],
      output_node_id: outputNodeId.trim() || null,
      output_extension: outputExtension.trim() || null,
      output_mime_type: outputMimeType.trim() || null,
      workload_kind: workloadKind || null,
      work_units_property_key: workUnitsPropertyKey.trim() || null,
      slo_p95_wait_seconds: parseOptionalNumber(sloP95WaitSeconds),
      slo_video_seconds_per_processing_second_p95: parseOptionalNumber(sloVideoSecondsPerProcessingSecondP95),
    };

    setSavingWorkflowSuggestions(true);
    setErrorMessage(null);
    try {
      const updated = await updateAdminWorkflow(selectedWorkflow.id, payload);
      setWorkflows((prev) => prev.map((workflow) => (workflow.id === updated.id ? updated : workflow)));
      setWorkflowSuggestionsSaved(true);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to save workflow suggestions."));
    } finally {
      setSavingWorkflowSuggestions(false);
    }
  }

  async function handleCreateEffect(): Promise<void> {
    if (!selectedWorkflow) {
      setErrorMessage("Workflow is required.");
      return;
    }
    if (!effectName.trim() || !effectSlug.trim()) {
      setErrorMessage("Effect name and slug are required.");
      return;
    }

    const parsedOverrides = parseWorkflowJsonInput(effectPropertyOverridesJson);
    if (!parsedOverrides.ok) {
      setErrorMessage("Property overrides must be a valid JSON object.");
      return;
    }

    setCreatingEffect(true);
    setErrorMessage(null);
    try {
      const created = await createAdminEffect({
        name: effectName.trim(),
        slug: effectSlug.trim(),
        description: effectDescription.trim() || null,
        workflow_id: selectedWorkflow.id,
        property_overrides: parsedOverrides.value,
        type: effectType,
        credits_cost: Number(effectCreditsCost) || 0,
        popularity_score: Number(effectPopularityScore) || 0,
        is_active: effectIsActive,
        is_premium: effectIsPremium,
        is_new: effectIsNew,
      });
      setCreatedEffect(created);
      setStep(3);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to create effect."));
    } finally {
      setCreatingEffect(false);
    }
  }

  const canContinueFromStepOne = Boolean(selectedWorkflowId);

  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold tracking-tight">Studio Effect Creation Wizard</h1>
        <p className="text-sm text-muted-foreground">
          Orchestrate workflow selection, analyzer suggestions, and effect creation in one flow.
        </p>
      </header>

      <div className="flex flex-wrap items-center gap-2">
        {STEPS.map((label, index) => (
          <div
            key={label}
            className={[
              "rounded-full border px-3 py-1 text-xs font-medium",
              index === step
                ? "border-primary/50 bg-primary/10 text-primary"
                : index < step
                  ? "border-emerald-500/40 bg-emerald-500/10 text-emerald-300"
                  : "border-border text-muted-foreground",
            ].join(" ")}
          >
            {index + 1}. {label}
          </div>
        ))}
      </div>

      {errorMessage ? (
        <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
          {errorMessage}
        </div>
      ) : null}

      {step === 0 ? (
        <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
          <h2 className="text-base font-semibold">1. Select workflow source</h2>

          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant={sourceType === "existing" ? "default" : "outline"}
              size="sm"
              onClick={() => setSourceType("existing")}
            >
              Existing workflow
            </Button>
            <Button
              type="button"
              variant={sourceType === "new_upload" ? "default" : "outline"}
              size="sm"
              onClick={() => setSourceType("new_upload")}
            >
              New + upload JSON
            </Button>
          </div>

          {sourceType === "existing" ? (
            <div className="space-y-2">
              <label className="text-xs font-semibold text-muted-foreground">Workflow</label>
              <select
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                value={selectedWorkflowId}
                onChange={(event) => setSelectedWorkflowId(event.target.value)}
                disabled={loadingWorkflows}
              >
                <option value="">Select workflow</option>
                {workflows.map((workflow) => (
                  <option key={workflow.id} value={workflow.id}>
                    {workflow.name || workflow.slug || `Workflow #${workflow.id}`}
                  </option>
                ))}
              </select>
            </div>
          ) : (
            <div className="space-y-3">
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-muted-foreground">Workflow name</label>
                  <Input
                    value={newWorkflowName}
                    onChange={(event) => {
                      const nextName = event.target.value;
                      setNewWorkflowName(nextName);
                      if (!newWorkflowSlug || newWorkflowSlug === slugify(newWorkflowName)) {
                        setNewWorkflowSlug(slugify(nextName));
                      }
                    }}
                    placeholder="Studio workflow"
                  />
                </div>
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-muted-foreground">Workflow slug</label>
                  <Input
                    value={newWorkflowSlug}
                    onChange={(event) => setNewWorkflowSlug(event.target.value)}
                    placeholder="studio-workflow"
                  />
                </div>
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground">Description (optional)</label>
                <Input
                  value={newWorkflowDescription}
                  onChange={(event) => setNewWorkflowDescription(event.target.value)}
                  placeholder="What this workflow produces..."
                />
              </div>
              <div className="space-y-2">
                <label className="text-xs font-semibold text-muted-foreground">Workflow JSON file</label>
                <div className="flex flex-wrap gap-2">
                  <Input value={uploadedWorkflowPath} readOnly placeholder="Upload path appears here" />
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={uploadingWorkflowJson}
                  >
                    {uploadingWorkflowJson ? "Uploading..." : "Upload JSON"}
                  </Button>
                </div>
                <input
                  ref={fileInputRef}
                  type="file"
                  className="hidden"
                  accept=".json,application/json"
                  onChange={(event) => {
                    const file = event.target.files?.[0];
                    event.target.value = "";
                    if (!file) return;
                    void handleUploadWorkflowJson(file);
                  }}
                />
              </div>
              <div className="flex justify-end">
                <Button type="button" onClick={() => void handleCreateWorkflowDraft()} disabled={creatingWorkflow}>
                  {creatingWorkflow ? "Creating..." : "Create Workflow Draft"}
                </Button>
              </div>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" onClick={() => setStep(1)} disabled={!canContinueFromStepOne}>
              Continue
            </Button>
          </div>
        </section>
      ) : null}

      {step === 1 ? (
        <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
          <h2 className="text-base font-semibold">2. Run workflow analyzer</h2>
          <p className="text-xs text-muted-foreground">
            Selected workflow: {selectedWorkflow?.name || selectedWorkflow?.slug || `#${selectedWorkflow?.id ?? "-"}`}
          </p>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">Requested output kind</label>
              <select
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                value={analyzeRequestedOutputKind}
                onChange={(event) => {
                  const next = event.target.value;
                  if (next === "image" || next === "video" || next === "audio") {
                    setAnalyzeRequestedOutputKind(next);
                  }
                }}
              >
                <option value="image">image</option>
                <option value="video">video</option>
                <option value="audio">audio</option>
              </select>
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">Example IO description (optional)</label>
              <Input
                value={analyzeExampleDescription}
                onChange={(event) => setAnalyzeExampleDescription(event.target.value)}
                placeholder="e.g. Prompt + reference image"
              />
            </div>
          </div>

          {analyzeJob ? (
            <div className="rounded-md border border-border/60 bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
              Last analysis job #{analyzeJob.id} status: <span className="font-semibold">{analyzeJob.status}</span>
            </div>
          ) : null}

          <div className="flex justify-between gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => setStep(0)}>
              Back
            </Button>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => setStep(2)}>
                Skip for now
              </Button>
              <Button type="button" onClick={() => void handleRunAnalyzer()} disabled={analyzing}>
                {analyzing ? "Analyzing..." : "Run Analyzer"}
              </Button>
            </div>
          </div>
        </section>
      ) : null}

      {step === 2 ? (
        <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
          <h2 className="text-base font-semibold">3. Apply suggestions and edit</h2>

          <div className="space-y-2">
            <label className="text-xs font-semibold text-muted-foreground">Workflow properties JSON</label>
            <Textarea
              value={workflowPropertiesJson}
              onChange={(event) => {
                setWorkflowPropertiesJson(event.target.value);
                setWorkflowSuggestionsSaved(false);
              }}
              rows={8}
              className="font-mono text-xs"
            />
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">Output node id</label>
              <Input value={outputNodeId} onChange={(event) => setOutputNodeId(event.target.value)} />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">Output extension</label>
              <Input value={outputExtension} onChange={(event) => setOutputExtension(event.target.value)} />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">Output mime type</label>
              <Input value={outputMimeType} onChange={(event) => setOutputMimeType(event.target.value)} />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">Workload kind</label>
              <select
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                value={workloadKind}
                onChange={(event) => {
                  const next = event.target.value;
                  if (next === "image" || next === "video" || next === "") {
                    setWorkloadKind(next);
                  }
                }}
              >
                <option value="">unset</option>
                <option value="image">image</option>
                <option value="video">video</option>
              </select>
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">Work units property key</label>
              <Input
                value={workUnitsPropertyKey}
                onChange={(event) => setWorkUnitsPropertyKey(event.target.value)}
                placeholder="duration"
              />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">SLO p95 wait seconds</label>
              <Input
                value={sloP95WaitSeconds}
                onChange={(event) => setSloP95WaitSeconds(event.target.value)}
                placeholder="45"
              />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">
                SLO video seconds per processing second p95
              </label>
              <Input
                value={sloVideoSecondsPerProcessingSecondP95}
                onChange={(event) => setSloVideoSecondsPerProcessingSecondP95(event.target.value)}
                placeholder="0.4"
              />
            </div>
          </div>

          <div className="rounded-md border border-border/60 bg-muted/20 p-3">
            <h3 className="mb-2 text-sm font-semibold">Effect draft</h3>
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground">Effect name</label>
                <Input value={effectName} onChange={(event) => setEffectName(event.target.value)} />
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground">Effect slug</label>
                <Input value={effectSlug} onChange={(event) => setEffectSlug(event.target.value)} />
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground">Type</label>
                <select
                  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  value={effectType}
                  onChange={(event) => setEffectType(event.target.value)}
                >
                  <option value="configurable">configurable</option>
                  <option value="simple">simple</option>
                </select>
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground">Credits cost</label>
                <Input value={effectCreditsCost} onChange={(event) => setEffectCreditsCost(event.target.value)} />
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground">Popularity score</label>
                <Input
                  value={effectPopularityScore}
                  onChange={(event) => setEffectPopularityScore(event.target.value)}
                />
              </div>
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground">Description</label>
                <Input value={effectDescription} onChange={(event) => setEffectDescription(event.target.value)} />
              </div>
            </div>
            <div className="mt-3 space-y-1.5">
              <label className="text-xs font-semibold text-muted-foreground">Property overrides JSON</label>
              <Textarea
                value={effectPropertyOverridesJson}
                onChange={(event) => setEffectPropertyOverridesJson(event.target.value)}
                rows={4}
                className="font-mono text-xs"
              />
            </div>
            <div className="mt-3 flex flex-wrap gap-3 text-xs text-muted-foreground">
              <label className="inline-flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={effectIsActive}
                  onChange={(event) => setEffectIsActive(event.target.checked)}
                />
                active
              </label>
              <label className="inline-flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={effectIsPremium}
                  onChange={(event) => setEffectIsPremium(event.target.checked)}
                />
                premium
              </label>
              <label className="inline-flex items-center gap-1">
                <input type="checkbox" checked={effectIsNew} onChange={(event) => setEffectIsNew(event.target.checked)} />
                new
              </label>
            </div>
          </div>

          <div className="flex justify-between gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => setStep(1)}>
              Back
            </Button>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => void handleSaveWorkflowSuggestions()}
                disabled={savingWorkflowSuggestions}
              >
                {savingWorkflowSuggestions
                  ? "Saving workflow..."
                  : workflowSuggestionsSaved
                    ? "Workflow saved"
                    : "Save workflow fields"}
              </Button>
              <Button type="button" onClick={() => void handleCreateEffect()} disabled={creatingEffect}>
                {creatingEffect ? "Creating effect..." : "Create effect"}
              </Button>
            </div>
          </div>
        </section>
      ) : null}

      {step === 3 ? (
        <section className="space-y-4 rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-4">
          <h2 className="text-base font-semibold text-emerald-200">4. Effect created</h2>
          {createdEffect ? (
            <div className="space-y-2 text-sm text-emerald-100">
              <p>
                Created effect <span className="font-semibold">{createdEffect.name || createdEffect.slug}</span> (#
                {createdEffect.id}).
              </p>
              <div className="flex flex-wrap gap-2">
                <Link href="/admin/effects" className="inline-flex items-center rounded-md border px-3 py-1.5 text-xs">
                  Open Effects
                </Link>
                <Link
                  href={`/admin/studio/effects/clone?effectId=${createdEffect.id}`}
                  className="inline-flex items-center rounded-md border px-3 py-1.5 text-xs"
                >
                  Clone this effect
                </Link>
                <Link
                  href={`/admin/effects`}
                  className="inline-flex items-center rounded-md border px-3 py-1.5 text-xs"
                >
                  Publish from Effects page
                </Link>
              </div>
            </div>
          ) : (
            <p className="text-sm text-emerald-100">Effect creation finished.</p>
          )}
        </section>
      ) : null}
    </div>
  );
}

