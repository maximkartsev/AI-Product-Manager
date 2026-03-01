"use client";

import { useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  cancelStudioLoadTestRun,
  createStudioLoadTestRun,
  getAdminEffectRevisions,
  getAdminEffects,
  getStudioExecutionEnvironments,
  getStudioLoadTestRunStatus,
  getStudioLoadTestRuns,
  getStudioLoadTestScenarios,
  startStudioLoadTestRun,
  type AdminEffect,
  type AdminEffectRevision,
  type StudioExecutionEnvironment,
  type StudioLoadTestRun,
  type StudioLoadTestRunStatusData,
  type StudioLoadTestScenario,
} from "@/lib/api";
import { extractErrorMessage } from "@/lib/apiErrors";

const DEFAULT_INPUT_PAYLOAD = JSON.stringify(
  {
    prompt: "Scenario run payload",
  },
  null,
  2,
);

const TERMINAL_STATUSES = new Set(["completed", "failed", "cancelled"]);

function formatDateTime(value?: string | null): string {
  if (!value) return "n/a";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString();
}

function parseOptionalJsonObject(raw: string): { ok: true; value: Record<string, unknown> } | { ok: false; error: string } {
  const trimmed = raw.trim();
  if (!trimmed) return { ok: true, value: {} };
  try {
    const parsed = JSON.parse(trimmed);
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
      return { ok: false, error: "Input payload must be a JSON object." };
    }
    return { ok: true, value: parsed as Record<string, unknown> };
  } catch {
    return { ok: false, error: "Input payload JSON is invalid." };
  }
}

export default function StudioLoadTestRunsPage() {
  const [effects, setEffects] = useState<AdminEffect[]>([]);
  const [revisions, setRevisions] = useState<AdminEffectRevision[]>([]);
  const [environments, setEnvironments] = useState<StudioExecutionEnvironment[]>([]);
  const [scenarios, setScenarios] = useState<StudioLoadTestScenario[]>([]);
  const [runs, setRuns] = useState<StudioLoadTestRun[]>([]);
  const [statuses, setStatuses] = useState<Record<number, StudioLoadTestRunStatusData>>({});

  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [actionRunId, setActionRunId] = useState<number | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [selectedEffectId, setSelectedEffectId] = useState("");
  const [selectedRevisionId, setSelectedRevisionId] = useState("");
  const [selectedScenarioId, setSelectedScenarioId] = useState("");
  const [selectedEnvironmentId, setSelectedEnvironmentId] = useState("");
  const [inputFileId, setInputFileId] = useState("");
  const [benchmarkContextId, setBenchmarkContextId] = useState("");
  const [startMode, setStartMode] = useState<"ecs" | "inline">("ecs");
  const [inputPayloadJson, setInputPayloadJson] = useState(DEFAULT_INPUT_PAYLOAD);

  const scenarioOptions = useMemo(
    () => scenarios.filter((item) => item.is_active !== false),
    [scenarios],
  );

  async function loadRuns(): Promise<void> {
    const runsResponse = await getStudioLoadTestRuns();
    setRuns(runsResponse.items ?? []);
  }

  useEffect(() => {
    async function loadInitialData(): Promise<void> {
      setLoading(true);
      setErrorMessage(null);
      try {
        const [effectsResponse, environmentsResponse, scenariosResponse] = await Promise.all([
          getAdminEffects({ page: 1, perPage: 200 }),
          getStudioExecutionEnvironments({ kind: "test_asg", is_active: true }),
          getStudioLoadTestScenarios(),
        ]);
        setEffects(effectsResponse.items ?? []);
        setEnvironments(environmentsResponse.items ?? []);
        setScenarios(scenariosResponse.items ?? []);
        await loadRuns();
      } catch (error: unknown) {
        setErrorMessage(extractErrorMessage(error, "Failed to load load-test data."));
      } finally {
        setLoading(false);
      }
    }

    void loadInitialData();
  }, []);

  useEffect(() => {
    async function loadRevisions(effectId: number): Promise<void> {
      setErrorMessage(null);
      try {
        const response = await getAdminEffectRevisions(effectId);
        const items = response.items ?? [];
        setRevisions(items);
        setSelectedRevisionId(items.length > 0 ? String(items[0].id) : "");
      } catch (error: unknown) {
        setErrorMessage(extractErrorMessage(error, "Failed to load effect revisions."));
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

  useEffect(() => {
    const activeRunIds = runs
      .filter((run) => !TERMINAL_STATUSES.has(String(run.status || "")))
      .map((run) => run.id);
    if (activeRunIds.length === 0) {
      return;
    }

    let cancelled = false;

    const refreshStatuses = async () => {
      try {
        const entries = await Promise.all(
          activeRunIds.map(async (runId) => {
            const status = await getStudioLoadTestRunStatus(runId);
            return [runId, status] as const;
          }),
        );
        if (cancelled) return;
        setStatuses((prev) => {
          const next = { ...prev };
          for (const [runId, payload] of entries) {
            next[runId] = payload;
          }
          return next;
        });
      } catch {
        // Polling errors should not interrupt operator workflow.
      }
    };

    void refreshStatuses();
    const timer = window.setInterval(() => {
      void refreshStatuses();
    }, 5000);

    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [runs]);

  async function handleCreateRun(): Promise<void> {
    const scenarioId = Number(selectedScenarioId);
    const effectRevisionId = Number(selectedRevisionId);
    const environmentId = Number(selectedEnvironmentId);
    const parsedInputFileId = Number(inputFileId);
    const parsedPayload = parseOptionalJsonObject(inputPayloadJson);

    if (!Number.isFinite(scenarioId) || scenarioId <= 0) {
      setErrorMessage("Select a scenario.");
      return;
    }
    if (!Number.isFinite(effectRevisionId) || effectRevisionId <= 0) {
      setErrorMessage("Select an effect revision.");
      return;
    }
    if (!Number.isFinite(environmentId) || environmentId <= 0) {
      setErrorMessage("Select a test ASG execution environment.");
      return;
    }
    if (!Number.isFinite(parsedInputFileId) || parsedInputFileId <= 0) {
      setErrorMessage("Input file ID must be a positive number.");
      return;
    }
    if (!parsedPayload.ok) {
      setErrorMessage(parsedPayload.error);
      return;
    }

    setSubmitting(true);
    setErrorMessage(null);
    try {
      const created = await createStudioLoadTestRun({
        load_test_scenario_id: scenarioId,
        execution_environment_id: environmentId,
        effect_revision_id: effectRevisionId,
        input_file_id: parsedInputFileId,
        input_payload: parsedPayload.value,
        benchmark_context_id: benchmarkContextId.trim() || undefined,
      });
      setRuns((prev) => [created, ...prev]);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to create load test run."));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleStartRun(run: StudioLoadTestRun): Promise<void> {
    setActionRunId(run.id);
    setErrorMessage(null);
    try {
      await startStudioLoadTestRun(run.id, {
        mode: startMode,
        allow_inline_fallback: true,
      });
      await loadRuns();
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, `Failed to start run #${run.id}.`));
    } finally {
      setActionRunId(null);
    }
  }

  async function handleCancelRun(run: StudioLoadTestRun): Promise<void> {
    setActionRunId(run.id);
    setErrorMessage(null);
    try {
      await cancelStudioLoadTestRun(run.id);
      await loadRuns();
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, `Failed to cancel run #${run.id}.`));
    } finally {
      setActionRunId(null);
    }
  }

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold tracking-tight">Studio Load Test Runs</h1>
        <p className="text-sm text-muted-foreground">
          Create scenario runs, launch runner execution, cancel active runs, and poll live progress metrics.
        </p>
      </header>

      {errorMessage ? (
        <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
          {errorMessage}
        </div>
      ) : null}

      <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
        <h2 className="text-base font-semibold">Create Run</h2>
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
            <label className="text-xs font-semibold text-muted-foreground">Load-test scenario</label>
            <select
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              value={selectedScenarioId}
              onChange={(event) => setSelectedScenarioId(event.target.value)}
              disabled={loading}
            >
              <option value="">Select scenario</option>
              {scenarioOptions.map((scenario) => (
                <option key={scenario.id} value={scenario.id}>
                  #{scenario.id} {scenario.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="grid gap-3 md:grid-cols-3">
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
          <Input
            value={inputFileId}
            onChange={(event) => setInputFileId(event.target.value)}
            placeholder="Input file ID"
          />
          <Input
            value={benchmarkContextId}
            onChange={(event) => setBenchmarkContextId(event.target.value)}
            placeholder="Benchmark context ID (optional)"
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

        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <span>Start mode:</span>
            <select
              className="rounded-md border border-input bg-background px-2 py-1 text-xs"
              value={startMode}
              onChange={(event) => setStartMode(event.target.value as "ecs" | "inline")}
            >
              <option value="ecs">ecs</option>
              <option value="inline">inline</option>
            </select>
          </div>
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={() => void loadRuns()} disabled={loading}>
              Refresh Runs
            </Button>
            <Button type="button" onClick={() => void handleCreateRun()} disabled={submitting}>
              {submitting ? "Creating..." : "Create Load Test Run"}
            </Button>
          </div>
        </div>
      </section>

      <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
        <h2 className="text-base font-semibold">Run Queue</h2>
        {runs.length === 0 ? (
          <p className="text-sm text-muted-foreground">No load-test runs found.</p>
        ) : (
          <div className="space-y-3">
            {runs.map((run) => {
              const status = statuses[run.id];
              const effectiveStatus = status?.status || run.status || "queued";
              const isStartable = effectiveStatus === "queued";
              const isCancellable = effectiveStatus === "running";
              const isActionBusy = actionRunId === run.id;

              return (
                <article key={run.id} className="rounded-md border border-border/60 bg-muted/10 p-3">
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold">
                        Run #{run.id} <span className="text-muted-foreground">({effectiveStatus})</span>
                      </p>
                      <p className="text-xs text-muted-foreground">
                        Scenario #{run.load_test_scenario_id || "n/a"} · Revision #{run.effect_revision_id || "n/a"} ·
                        Env #{run.execution_environment_id || "n/a"}
                      </p>
                    </div>
                    <div className="flex gap-2">
                      {isStartable ? (
                        <Button
                          type="button"
                          size="sm"
                          onClick={() => void handleStartRun(run)}
                          disabled={isActionBusy}
                        >
                          {isActionBusy ? "Starting..." : "Start"}
                        </Button>
                      ) : null}
                      {isCancellable ? (
                        <Button
                          type="button"
                          size="sm"
                          variant="destructive"
                          onClick={() => void handleCancelRun(run)}
                          disabled={isActionBusy}
                        >
                          {isActionBusy ? "Cancelling..." : "Cancel"}
                        </Button>
                      ) : null}
                    </div>
                  </div>

                  <div className="mt-3 grid gap-2 text-xs text-muted-foreground md:grid-cols-2">
                    <p>Submitted: {status?.submitted_count ?? "n/a"}</p>
                    <p>Queued: {status?.queued_count ?? "n/a"}</p>
                    <p>Leased: {status?.leased_count ?? "n/a"}</p>
                    <p>Completed: {status?.completed_count ?? run.success_count ?? "n/a"}</p>
                    <p>Failed: {status?.failed_count ?? run.failure_count ?? "n/a"}</p>
                    <p>P95 latency (ms): {status?.p95_latency_ms ?? run.p95_latency_ms ?? "n/a"}</p>
                    <p>Queue wait p95 (s): {status?.queue_wait_p95_seconds ?? run.queue_wait_p95_seconds ?? "n/a"}</p>
                    <p>Processing p95 (s): {status?.processing_p95_seconds ?? run.processing_p95_seconds ?? "n/a"}</p>
                    <p>Started: {formatDateTime(status?.started_at ?? run.started_at)}</p>
                    <p>Completed: {formatDateTime(status?.completed_at ?? run.completed_at)}</p>
                  </div>

                  {status?.ecs_task_arn ? (
                    <p className="mt-2 text-xs text-muted-foreground">
                      ECS task: <span className="font-mono">{status.ecs_task_arn}</span>
                    </p>
                  ) : null}

                  {(status?.fault_events?.length ?? 0) > 0 ? (
                    <details className="mt-2">
                      <summary className="cursor-pointer text-xs text-muted-foreground">
                        Fault events ({status?.fault_events?.length ?? 0})
                      </summary>
                      <pre className="mt-2 overflow-auto rounded-md bg-black/30 p-2 text-xs">
                        {JSON.stringify(status?.fault_events ?? [], null, 2)}
                      </pre>
                    </details>
                  ) : null}
                </article>
              );
            })}
          </div>
        )}
      </section>
    </div>
  );
}
