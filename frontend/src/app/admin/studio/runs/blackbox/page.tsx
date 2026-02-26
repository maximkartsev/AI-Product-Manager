"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  createStudioBlackboxRun,
  createStudioTestInputSet,
  getAdminEffectRevisions,
  getAdminEffects,
  getStudioExecutionEnvironments,
  getStudioTestInputSets,
  type AdminEffect,
  type AdminEffectRevision,
  type StudioBlackboxRunData,
  type StudioExecutionEnvironment,
  type StudioTestInputSet,
} from "@/lib/api";
import { extractErrorMessage } from "@/lib/apiErrors";
import {
  extractBlackboxInputFromTestInputSet,
  parseBlackboxInputPayload,
  parseBlackboxRunCounts,
} from "@/lib/studio/blackboxRun";

const DEFAULT_INPUT_PAYLOAD = JSON.stringify(
  {
    prompt: "Blackbox run prompt",
  },
  null,
  2,
);

export default function StudioBlackboxRunsPage() {
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

  const [inputFileId, setInputFileId] = useState("");
  const [inputPayloadJson, setInputPayloadJson] = useState(DEFAULT_INPUT_PAYLOAD);
  const [count, setCount] = useState("1");
  const [runCountsCsv, setRunCountsCsv] = useState("1,10,100");

  const [newInputSetName, setNewInputSetName] = useState("");
  const [newInputSetDescription, setNewInputSetDescription] = useState("");
  const [runResult, setRunResult] = useState<StudioBlackboxRunData | null>(null);

  useEffect(() => {
    async function loadInitialData(): Promise<void> {
      setLoading(true);
      setErrorMessage(null);
      try {
        const [effectsResponse, environmentsResponse, inputSetsResponse] = await Promise.all([
          getAdminEffects({ page: 1, perPage: 200 }),
          getStudioExecutionEnvironments({ kind: "test_asg", is_active: true }),
          getStudioTestInputSets(),
        ]);
        setEffects(effectsResponse.items ?? []);
        setEnvironments(environmentsResponse.items ?? []);
        setInputSets(inputSetsResponse.items ?? []);
      } catch (error: unknown) {
        setErrorMessage(extractErrorMessage(error, "Failed to load blackbox run data."));
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
        setSelectedRevisionId(items.length > 0 ? String(items[0].id) : "");
      } catch (error: unknown) {
        setErrorMessage(extractErrorMessage(error, "Failed to load effect revisions."));
      } finally {
        setLoading(false);
      }
    }

    const effectId = Number(selectedEffectId);
    if (!Number.isFinite(effectId) || effectId <= 0) {
      setRevisions([]);
      setSelectedRevisionId("");
      return;
    }

    void loadRevisions(effectId);
  }, [selectedEffectId]);

  function applyInputSet(inputSetId: string): void {
    setSelectedInputSetId(inputSetId);
    const parsedId = Number(inputSetId);
    if (!Number.isFinite(parsedId) || parsedId <= 0) return;

    const inputSet = inputSets.find((item) => item.id === parsedId);
    if (!inputSet) return;

    const parsed = extractBlackboxInputFromTestInputSet(inputSet.input_json);
    if (!parsed) return;

    setInputFileId(String(parsed.input_file_id || ""));
    setInputPayloadJson(JSON.stringify(parsed.input_payload ?? {}, null, 2));
  }

  async function handleSaveInputSet(): Promise<void> {
    if (!newInputSetName.trim()) {
      setErrorMessage("Input set name is required.");
      return;
    }

    const fileId = Number(inputFileId);
    if (!Number.isFinite(fileId) || fileId <= 0) {
      setErrorMessage("Input file ID is required.");
      return;
    }

    const parsedPayload = parseBlackboxInputPayload(inputPayloadJson);
    if (!parsedPayload.ok) {
      setErrorMessage(parsedPayload.error);
      return;
    }

    setSavingInputSet(true);
    setErrorMessage(null);
    try {
      const created = await createStudioTestInputSet({
        name: newInputSetName.trim(),
        description: newInputSetDescription.trim() || null,
        input_json: {
          blackbox_input: {
            input_file_id: fileId,
            input_payload: parsedPayload.value,
          },
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

  async function handleRunBlackbox(): Promise<void> {
    const effectId = Number(selectedEffectId);
    if (!Number.isFinite(effectId) || effectId <= 0) {
      setErrorMessage("Select an effect.");
      return;
    }

    const revisionId = Number(selectedRevisionId);
    if (!Number.isFinite(revisionId) || revisionId <= 0) {
      setErrorMessage("Select an effect revision.");
      return;
    }

    const environmentId = Number(selectedEnvironmentId);
    if (!Number.isFinite(environmentId) || environmentId <= 0) {
      setErrorMessage("Select a test ASG execution environment.");
      return;
    }

    const fileId = Number(inputFileId);
    if (!Number.isFinite(fileId) || fileId <= 0) {
      setErrorMessage("Input file ID is required.");
      return;
    }

    const parsedPayload = parseBlackboxInputPayload(inputPayloadJson);
    if (!parsedPayload.ok) {
      setErrorMessage(parsedPayload.error);
      return;
    }

    const parsedCount = Number(count);
    if (!Number.isFinite(parsedCount) || parsedCount <= 0) {
      setErrorMessage("Count must be a positive number.");
      return;
    }

    setRunning(true);
    setErrorMessage(null);
    try {
      const result = await createStudioBlackboxRun({
        effect_id: effectId,
        effect_revision_id: revisionId,
        execution_environment_id: environmentId,
        input_file_id: fileId,
        input_payload: parsedPayload.value,
        count: Math.floor(parsedCount),
        run_counts: parseBlackboxRunCounts(runCountsCsv),
      });
      setRunResult(result);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Blackbox run failed."));
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold tracking-tight">Studio Blackbox Runs</h1>
        <p className="text-sm text-muted-foreground">
          Queue token-billed blackbox runs against staging-backed test ASG dispatch and inspect cost models.
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
            <label className="text-xs font-semibold text-muted-foreground">Test ASG execution environment</label>
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

        <div className="grid gap-3 md:grid-cols-3">
          <Input value={inputFileId} onChange={(event) => setInputFileId(event.target.value)} placeholder="Input file ID" />
          <Input value={count} onChange={(event) => setCount(event.target.value)} placeholder="Count (e.g. 1)" />
          <Input
            value={runCountsCsv}
            onChange={(event) => setRunCountsCsv(event.target.value)}
            placeholder="Cost run counts (e.g. 1,10,100)"
          />
        </div>

        <div className="space-y-2">
          <label className="text-xs font-semibold text-muted-foreground">Input payload JSON</label>
          <Textarea
            value={inputPayloadJson}
            onChange={(event) => setInputPayloadJson(event.target.value)}
            rows={8}
            className="font-mono text-xs"
          />
        </div>

        <div className="grid gap-3 md:grid-cols-3">
          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-muted-foreground">Reuse saved test input set</label>
            <select
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              value={selectedInputSetId}
              onChange={(event) => applyInputSet(event.target.value)}
            >
              <option value="">None (manual input)</option>
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
          <Button type="button" onClick={() => void handleRunBlackbox()} disabled={running}>
            {running ? "Running..." : "Run Blackbox"}
          </Button>
        </div>
      </section>

      <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
        <h2 className="text-base font-semibold">Run Result</h2>
        {!runResult ? (
          <p className="text-sm text-muted-foreground">No run executed yet.</p>
        ) : (
          <div className="space-y-3">
            <div className="rounded-md border border-border/60 bg-muted/20 p-3 text-sm">
              <p>
                Run <span className="font-semibold">#{runResult.run.id}</span> status:{" "}
                <span className="font-semibold">{runResult.run.status || "unknown"}</span>
              </p>
              <p className="text-xs text-muted-foreground">
                Dispatches: {runResult.dispatch_count} · Jobs: {runResult.job_ids.length}
              </p>
            </div>

            <div className="rounded-md border border-border/60 bg-muted/10 p-3">
              <h3 className="text-sm font-semibold">Job IDs</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                {runResult.job_ids.length > 0 ? runResult.job_ids.join(", ") : "none"}
              </p>
            </div>

            <div className="rounded-md border border-border/60 bg-muted/10 p-3">
              <h3 className="text-sm font-semibold">Cost report</h3>
              <pre className="mt-2 overflow-auto rounded-md bg-black/30 p-2 text-xs">
                {JSON.stringify(runResult.cost_report ?? {}, null, 2)}
              </pre>
            </div>
          </div>
        )}
      </section>
    </div>
  );
}

