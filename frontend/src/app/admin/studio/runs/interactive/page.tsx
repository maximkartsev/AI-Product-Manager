"use client";

import { useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  createStudioDevNodeRun,
  createStudioTestInputSet,
  getAdminEffectRevisions,
  getAdminEffects,
  getStudioExecutionEnvironments,
  getStudioTestInputSets,
  type AdminEffect,
  type AdminEffectRevision,
  type StudioDevNodeRunData,
  type StudioExecutionEnvironment,
  type StudioRunArtifact,
  type StudioTestInputSet,
} from "@/lib/api";
import { extractErrorMessage } from "@/lib/apiErrors";
import {
  extractInteractiveRunInputFromTestInputSet,
  parseInteractiveRunInput,
} from "@/lib/studio/interactiveRun";

const DEFAULT_INPUT_PAYLOAD = JSON.stringify(
  {
    input_path: "inputs/source.mp4",
    input_disk: "s3",
    input_name: "source.mp4",
    input_mime_type: "video/mp4",
    properties: {
      prompt: "Cinematic output",
    },
  },
  null,
  2,
);

function detectPreviewKind(artifact: StudioRunArtifact): "video" | "image" | "none" {
  const path = (artifact.storage_path || "").toLowerCase();
  const metadata = artifact.metadata_json;
  const mimeType = metadata && typeof metadata === "object" && !Array.isArray(metadata)
    ? String((metadata as Record<string, unknown>).output_mime_type || "").toLowerCase()
    : "";

  if (
    mimeType.startsWith("video/")
    || path.endsWith(".mp4")
    || path.endsWith(".webm")
    || path.endsWith(".mov")
    || path.endsWith(".mkv")
  ) {
    return "video";
  }
  if (mimeType.startsWith("image/") || path.endsWith(".png") || path.endsWith(".jpg") || path.endsWith(".jpeg") || path.endsWith(".webp")) {
    return "image";
  }
  return "none";
}

export default function StudioInteractiveRunsPage() {
  const [effects, setEffects] = useState<AdminEffect[]>([]);
  const [revisions, setRevisions] = useState<AdminEffectRevision[]>([]);
  const [environments, setEnvironments] = useState<StudioExecutionEnvironment[]>([]);
  const [inputSets, setInputSets] = useState<StudioTestInputSet[]>([]);

  const [loading, setLoading] = useState(false);
  const [savingInputSet, setSavingInputSet] = useState(false);
  const [running, setRunning] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [selectedEffectId, setSelectedEffectId] = useState("");
  const [selectedRevisionId, setSelectedRevisionId] = useState("");
  const [selectedEnvironmentId, setSelectedEnvironmentId] = useState("");
  const [selectedInputSetId, setSelectedInputSetId] = useState("");

  const [inputPayloadJson, setInputPayloadJson] = useState(DEFAULT_INPUT_PAYLOAD);
  const [newInputSetName, setNewInputSetName] = useState("");
  const [newInputSetDescription, setNewInputSetDescription] = useState("");
  const [runResult, setRunResult] = useState<StudioDevNodeRunData | null>(null);

  const selectedRevision = useMemo(() => {
    const id = Number(selectedRevisionId);
    if (!Number.isFinite(id) || id <= 0) return null;
    return revisions.find((item) => item.id === id) ?? null;
  }, [selectedRevisionId, revisions]);

  useEffect(() => {
    async function loadInitialData(): Promise<void> {
      setLoading(true);
      setErrorMessage(null);
      try {
        const [effectsResponse, environmentsResponse, inputSetsResponse] = await Promise.all([
          getAdminEffects({ page: 1, perPage: 200 }),
          getStudioExecutionEnvironments({ kind: "dev_node", is_active: true }),
          getStudioTestInputSets(),
        ]);
        setEffects(effectsResponse.items ?? []);
        setEnvironments(environmentsResponse.items ?? []);
        setInputSets(inputSetsResponse.items ?? []);
      } catch (error: unknown) {
        setErrorMessage(extractErrorMessage(error, "Failed to load interactive run data."));
      } finally {
        setLoading(false);
      }
    }

    void loadInitialData();
  }, []);

  useEffect(() => {
    async function loadRevisions(effectId: number): Promise<void> {
      setLoading(true);
      setErrorMessage(null);
      try {
        const response = await getAdminEffectRevisions(effectId);
        const items = response.items ?? [];
        setRevisions(items);
        if (items.length > 0) {
          setSelectedRevisionId(String(items[0].id));
        } else {
          setSelectedRevisionId("");
        }
      } catch (error: unknown) {
        setErrorMessage(extractErrorMessage(error, "Failed to load effect revisions."));
      } finally {
        setLoading(false);
      }
    }

    const parsedEffectId = Number(selectedEffectId);
    if (!Number.isFinite(parsedEffectId) || parsedEffectId <= 0) {
      setRevisions([]);
      setSelectedRevisionId("");
      return;
    }

    void loadRevisions(parsedEffectId);
  }, [selectedEffectId]);

  function applyInputSet(setId: string): void {
    setSelectedInputSetId(setId);
    const parsedSetId = Number(setId);
    if (!Number.isFinite(parsedSetId) || parsedSetId <= 0) return;

    const inputSet = inputSets.find((item) => item.id === parsedSetId);
    if (!inputSet) return;
    const payload = extractInteractiveRunInputFromTestInputSet(inputSet.input_json);
    if (!payload) return;
    setInputPayloadJson(JSON.stringify(payload, null, 2));
  }

  async function handleSaveInputSet(): Promise<void> {
    const parsed = parseInteractiveRunInput(inputPayloadJson);
    if (!parsed.ok) {
      setErrorMessage(parsed.error);
      return;
    }
    if (!newInputSetName.trim()) {
      setErrorMessage("Input set name is required.");
      return;
    }

    setSavingInputSet(true);
    setErrorMessage(null);
    try {
      const created = await createStudioTestInputSet({
        name: newInputSetName.trim(),
        description: newInputSetDescription.trim() || null,
        input_json: {
          input_payload: parsed.value,
        },
      });
      setInputSets((prev) => [created, ...prev]);
      setSelectedInputSetId(String(created.id));
      setNewInputSetName("");
      setNewInputSetDescription("");
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to save test input set."));
    } finally {
      setSavingInputSet(false);
    }
  }

  async function handleRunInteractive(): Promise<void> {
    const parsed = parseInteractiveRunInput(inputPayloadJson);
    if (!parsed.ok) {
      setErrorMessage(parsed.error);
      return;
    }

    const revisionId = Number(selectedRevisionId);
    if (!Number.isFinite(revisionId) || revisionId <= 0) {
      setErrorMessage("Select an effect revision.");
      return;
    }

    const environmentId = Number(selectedEnvironmentId);
    if (!Number.isFinite(environmentId) || environmentId <= 0) {
      setErrorMessage("Select a DevNode execution environment.");
      return;
    }

    setRunning(true);
    setErrorMessage(null);
    try {
      const result = await createStudioDevNodeRun({
        effect_revision_id: revisionId,
        execution_environment_id: environmentId,
        test_input_set_id: Number.isFinite(Number(selectedInputSetId)) && Number(selectedInputSetId) > 0
          ? Number(selectedInputSetId)
          : undefined,
        input_payload: parsed.value,
      });
      setRunResult(result);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Interactive DevNode run failed."));
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold tracking-tight">Studio Interactive Runs</h1>
        <p className="text-sm text-muted-foreground">
          Execute a selected effect revision directly on a ready DevNode endpoint and inspect run artifacts.
        </p>
      </header>

      {errorMessage ? (
        <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
          {errorMessage}
        </div>
      ) : null}

      <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
        <h2 className="text-base font-semibold">Run Configuration</h2>
        <div className="grid gap-3 md:grid-cols-3">
          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-muted-foreground">Effect</label>
            <select
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              value={selectedEffectId}
              onChange={(event) => setSelectedEffectId(event.target.value)}
              disabled={loading}
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
            <label className="text-xs font-semibold text-muted-foreground">Effect revision</label>
            <select
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              value={selectedRevisionId}
              onChange={(event) => setSelectedRevisionId(event.target.value)}
              disabled={loading || revisions.length === 0}
            >
              <option value="">Select revision</option>
              {revisions.map((revision) => (
                <option key={revision.id} value={revision.id}>
                  Revision #{revision.id}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-muted-foreground">DevNode execution environment</label>
            <select
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              value={selectedEnvironmentId}
              onChange={(event) => setSelectedEnvironmentId(event.target.value)}
              disabled={loading}
            >
              <option value="">Select environment</option>
              {environments.map((environment) => (
                <option key={environment.id} value={environment.id}>
                  #{environment.id} {environment.name} ({environment.stage || "n/a"})
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="space-y-2">
          <label className="text-xs font-semibold text-muted-foreground">Input payload JSON</label>
          <Textarea
            value={inputPayloadJson}
            onChange={(event) => setInputPayloadJson(event.target.value)}
            rows={12}
            className="font-mono text-xs"
          />
          <p className="text-xs text-muted-foreground">
            Expected shape: input_path/input_disk/input_name/input_mime_type/properties.
          </p>
        </div>

        <div className="grid gap-3 md:grid-cols-3">
          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-muted-foreground">Reuse saved test input set</label>
            <select
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              value={selectedInputSetId}
              onChange={(event) => applyInputSet(event.target.value)}
            >
              <option value="">None (manual payload)</option>
              {inputSets.map((set) => (
                <option key={set.id} value={set.id}>
                  #{set.id} {set.name}
                </option>
              ))}
            </select>
          </div>
          <Input
            value={newInputSetName}
            onChange={(event) => setNewInputSetName(event.target.value)}
            placeholder="New input set name"
          />
          <Input
            value={newInputSetDescription}
            onChange={(event) => setNewInputSetDescription(event.target.value)}
            placeholder="Description (optional)"
          />
        </div>

        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => void handleSaveInputSet()} disabled={savingInputSet}>
            {savingInputSet ? "Saving..." : "Save as Test Input Set"}
          </Button>
          <Button type="button" onClick={() => void handleRunInteractive()} disabled={running}>
            {running ? "Running..." : "Run on DevNode"}
          </Button>
        </div>
      </section>

      <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
        <h2 className="text-base font-semibold">Run Result</h2>
        {!runResult ? (
          <p className="text-sm text-muted-foreground">No run executed yet.</p>
        ) : (
          <div className="space-y-4">
            <div className="rounded-md border border-border/60 bg-muted/20 p-3 text-sm">
              <p>
                Run <span className="font-semibold">#{runResult.run.id}</span> status:{" "}
                <span className="font-semibold">{runResult.run.status || "unknown"}</span>
              </p>
              <p className="text-xs text-muted-foreground">
                Revision #{selectedRevision?.id || runResult.run.effect_revision_id || "n/a"} · Environment #
                {runResult.run.execution_environment_id || "n/a"}
              </p>
            </div>

            <div className="space-y-3">
              {runResult.artifacts?.map((artifact) => {
                const previewKind = detectPreviewKind(artifact);
                return (
                  <article key={artifact.id} className="rounded-md border border-border/60 bg-muted/10 p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <h3 className="text-sm font-semibold">
                        {artifact.artifact_type || "artifact"} <span className="text-muted-foreground">#{artifact.id}</span>
                      </h3>
                      {artifact.preview_url ? (
                        <a
                          href={artifact.preview_url}
                          target="_blank"
                          rel="noreferrer"
                          className="text-xs text-blue-300 underline"
                        >
                          Open artifact URL
                        </a>
                      ) : null}
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {artifact.storage_disk || "disk n/a"}: {artifact.storage_path || "path n/a"}
                    </p>

                    {artifact.preview_url && previewKind === "video" ? (
                      <video className="mt-3 w-full rounded-md border border-border/60" controls src={artifact.preview_url} />
                    ) : null}
                    {artifact.preview_url && previewKind === "image" ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={artifact.preview_url} alt="Run artifact preview" className="mt-3 max-h-96 rounded-md border border-border/60" />
                    ) : null}

                    <details className="mt-3">
                      <summary className="cursor-pointer text-xs text-muted-foreground">Raw metadata</summary>
                      <pre className="mt-2 overflow-auto rounded-md bg-black/30 p-2 text-xs">
                        {JSON.stringify(artifact.metadata_json ?? {}, null, 2)}
                      </pre>
                    </details>
                  </article>
                );
              })}
            </div>
          </div>
        )}
      </section>
    </div>
  );
}

