"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { extractErrorMessage } from "@/lib/apiErrors";
import { getWorkload, type WorkloadData, type WorkloadWorkflow, type WorkloadWorker } from "@/lib/api";

const PERIODS = [
  { label: "24h", value: "24h" },
  { label: "7d", value: "7d" },
  { label: "30d", value: "30d" },
] as const;

const STAGES = [
  { label: "Production", value: "production" },
  { label: "Staging", value: "staging" },
] as const;

function formatDuration(seconds: number | null): string {
  if (seconds === null || seconds === undefined) return "-";
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s > 0 ? `${m}m ${s}s` : `${m}m`;
  }
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function countColor(count: number): string {
  if (count === 0) return "text-green-400";
  if (count <= 5) return "text-yellow-400";
  return "text-red-400";
}

function formatNumber(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined) return "-";
  return value.toFixed(digits);
}

function pressureColor(value: number | null | undefined): string {
  if (value === null || value === undefined) return "text-muted-foreground";
  if (value > 1.2) return "text-red-400";
  if (value > 1) return "text-yellow-400";
  return "text-green-400";
}

function formatSlo(stats: WorkloadWorkflow["stats"]): string {
  if (stats.workload_kind === "video") {
    if (stats.slo_video_seconds_per_processing_second_p95 === null) return "-";
    return `${formatNumber(stats.slo_video_seconds_per_processing_second_p95, 2)}x`;
  }
  return formatDuration(stats.slo_p95_wait_seconds ?? null);
}

function isOnline(lastSeenAt: string | null): boolean {
  if (!lastSeenAt) return false;
  return Date.now() - new Date(lastSeenAt).getTime() < 5 * 60 * 1000;
}

export default function AdminWorkloadPage() {
  const [period, setPeriod] = useState<string>("24h");
  const [stage, setStage] = useState<string>("production");
  const [data, setData] = useState<WorkloadData | null>(null);
  const [loading, setLoading] = useState(true);

  const loadData = useCallback(async (p: string, s: string) => {
    setLoading(true);
    try {
      const result = await getWorkload({ period: p, stage: s });
      setData(result);
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to load workload data"));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData(period, stage);
  }, [period, stage, loadData]);

  const workflows = data?.workflows ?? [];
  const workers = data?.workers ?? [];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Workload &amp; Capacity</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Workflow execution stats and fleet-routed capacity.
          </p>
          <p className="text-xs text-muted-foreground mt-1">
            Assignments come from Workflow → ComfyUI Routing (fleets).
          </p>
        </div>

        <div className="flex items-center gap-2">
          <div className="inline-flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-2 py-1">
            <span className="text-xs text-muted-foreground">Stage</span>
            <div className="inline-flex items-center rounded-md border border-border bg-background/60 p-0.5">
              {STAGES.map((s) => (
                <button
                  key={s.value}
                  onClick={() => setStage(s.value)}
                  className={`rounded-md px-2 py-1 text-xs font-medium transition-colors ${
                    stage === s.value
                      ? "bg-background text-foreground shadow-sm"
                      : "text-muted-foreground hover:text-foreground"
                  }`}
                >
                  {s.label}
                </button>
              ))}
            </div>
          </div>
          {/* Period pills */}
          <div className="inline-flex items-center rounded-lg border border-border bg-muted/40 p-0.5">
            {PERIODS.map((p) => (
              <button
                key={p.value}
                onClick={() => setPeriod(p.value)}
                className={`rounded-md px-3 py-1 text-sm font-medium transition-colors ${
                  period === p.value
                    ? "bg-background text-foreground shadow-sm"
                    : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {p.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Matrix */}
      {loading ? (
        <div className="flex items-center justify-center py-20">
          <div className="h-6 w-6 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
        </div>
      ) : workflows.length === 0 ? (
        <div className="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground">
          No workflows found.
        </div>
      ) : (
        <div className="rounded-lg border border-border bg-card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/30">
                  <th className="sticky left-0 z-10 bg-muted/30 text-left px-4 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    Workflow
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    Queue
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    Active
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    Done
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    Fail
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    Avg Time
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    GPU Time
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    p95 Wait
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    SLO
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    Pressure
                  </th>
                  <th className="text-center px-3 py-3 font-medium text-muted-foreground whitespace-nowrap">
                    Rec
                  </th>
                  {workers.map((w) => (
                    <th
                      key={w.id}
                      className="text-center px-3 py-2 font-medium whitespace-nowrap min-w-[100px]"
                    >
                      <WorkerColumnHeader worker={w} />
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {workflows.map((wf) => (
                  <tr
                    key={wf.id}
                    className={`border-b border-border last:border-b-0 hover:bg-muted/10 ${
                      !wf.is_active ? "opacity-50" : ""
                    }`}
                  >
                    <td className="sticky left-0 z-10 bg-card px-4 py-3 font-medium whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <span>{wf.name}</span>
                        {!wf.is_active && (
                          <span className="text-xs text-muted-foreground bg-muted px-1.5 py-0.5 rounded">
                            inactive
                          </span>
                        )}
                      </div>
                    </td>
                    <td className={`text-center px-3 py-3 font-mono tabular-nums ${countColor(wf.stats.queued)}`}>
                      {wf.stats.queued}
                    </td>
                    <td className={`text-center px-3 py-3 font-mono tabular-nums ${countColor(wf.stats.processing)}`}>
                      {wf.stats.processing}
                    </td>
                    <td className="text-center px-3 py-3 font-mono tabular-nums text-muted-foreground">
                      {wf.stats.completed}
                    </td>
                    <td className={`text-center px-3 py-3 font-mono tabular-nums ${wf.stats.failed > 0 ? "text-red-400" : "text-muted-foreground"}`}>
                      {wf.stats.failed}
                    </td>
                    <td className="text-center px-3 py-3 text-muted-foreground whitespace-nowrap">
                      {formatDuration(wf.stats.avg_duration_seconds)}
                    </td>
                    <td className="text-center px-3 py-3 text-muted-foreground whitespace-nowrap">
                      {formatDuration(wf.stats.total_duration_seconds)}
                    </td>
                    <td className="text-center px-3 py-3 text-muted-foreground whitespace-nowrap">
                      {formatDuration(wf.stats.estimated_wait_seconds_p95)}
                    </td>
                    <td className="text-center px-3 py-3 text-muted-foreground whitespace-nowrap">
                      {formatSlo(wf.stats)}
                    </td>
                    <td className={`text-center px-3 py-3 font-mono tabular-nums ${pressureColor(wf.stats.slo_pressure)}`}>
                      {wf.stats.slo_pressure === null || wf.stats.slo_pressure === undefined
                        ? "-"
                        : formatNumber(wf.stats.slo_pressure, 2)}
                    </td>
                    <td className="text-center px-3 py-3 font-mono tabular-nums text-muted-foreground">
                      {wf.stats.recommended_workers ?? "-"}
                    </td>
                    {workers.map((w) => {
                      const assigned = wf.worker_ids.includes(w.id);
                      return (
                        <td key={w.id} className="text-center px-3 py-3">
                          <div className="flex items-center justify-center">
                            <span
                              className={`inline-block h-2 w-2 rounded-full ${
                                assigned ? "bg-emerald-400" : "bg-muted-foreground/30"
                              }`}
                              title={assigned ? "Assigned" : "Not assigned"}
                            />
                          </div>
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

        </div>
      )}
    </div>
  );
}

function WorkerColumnHeader({ worker }: { worker: WorkloadWorker }) {
  const online = isOnline(worker.last_seen_at);
  const name = worker.display_name || worker.worker_id;

  return (
    <div className="flex flex-col items-center gap-1">
      <div className="flex items-center gap-1.5">
        <span
          className={`h-2 w-2 rounded-full ${online ? "bg-green-500" : "bg-red-500"}`}
          title={online ? "Online" : "Offline"}
        />
        <span className="text-xs font-medium text-foreground truncate max-w-[80px]" title={name}>
          {name}
        </span>
      </div>
      <span className="text-[10px] text-muted-foreground font-mono">
        {worker.current_load}/{worker.max_concurrency}
      </span>
      {(!worker.is_approved || worker.is_draining) && (
        <div className="flex gap-1">
          {!worker.is_approved && (
            <span className="text-[10px] bg-yellow-500/20 text-yellow-400 rounded px-1">
              unapproved
            </span>
          )}
          {worker.is_draining && (
            <span className="text-[10px] bg-orange-500/20 text-orange-400 rounded px-1">
              draining
            </span>
          )}
        </div>
      )}
    </div>
  );
}
