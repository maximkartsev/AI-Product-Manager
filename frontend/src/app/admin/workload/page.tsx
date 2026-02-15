"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { toast } from "sonner";
import {
  getWorkload,
  assignWorkflowWorkers,
  type WorkloadData,
  type WorkloadWorkflow,
  type WorkloadWorker,
} from "@/lib/api";

const PERIODS = [
  { label: "24h", value: "24h" },
  { label: "7d", value: "7d" },
  { label: "30d", value: "30d" },
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

function isOnline(lastSeenAt: string | null): boolean {
  if (!lastSeenAt) return false;
  return Date.now() - new Date(lastSeenAt).getTime() < 5 * 60 * 1000;
}

export default function AdminWorkloadPage() {
  const [period, setPeriod] = useState<string>("24h");
  const [data, setData] = useState<WorkloadData | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  // Track local assignment changes: workflowId -> workerIds
  const [localAssignments, setLocalAssignments] = useState<Record<number, number[]>>({});
  const [dirty, setDirty] = useState(false);

  const originalAssignments = useRef<Record<number, number[]>>({});

  const loadData = useCallback(async (p: string) => {
    setLoading(true);
    try {
      const result = await getWorkload(p);
      setData(result);

      // Initialize assignments from server
      const assignments: Record<number, number[]> = {};
      for (const wf of result.workflows) {
        assignments[wf.id] = [...wf.worker_ids];
      }
      setLocalAssignments(assignments);
      originalAssignments.current = JSON.parse(JSON.stringify(assignments));
      setDirty(false);
    } catch {
      toast.error("Failed to load workload data");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData(period);
  }, [period, loadData]);

  const toggleAssignment = (workflowId: number, workerId: number) => {
    setLocalAssignments((prev) => {
      const current = prev[workflowId] ?? [];
      const next = current.includes(workerId)
        ? current.filter((id) => id !== workerId)
        : [...current, workerId];
      const updated = { ...prev, [workflowId]: next };

      // Check if dirty
      const orig = originalAssignments.current;
      const isDirty = Object.keys(updated).some((key) => {
        const id = Number(key);
        const origSet = new Set(orig[id] ?? []);
        const newSet = new Set(updated[id] ?? []);
        if (origSet.size !== newSet.size) return true;
        for (const v of origSet) if (!newSet.has(v)) return true;
        return false;
      });
      setDirty(isDirty);

      return updated;
    });
  };

  const handleSave = async () => {
    if (!data) return;
    setSaving(true);

    const orig = originalAssignments.current;
    const changedWorkflows = data.workflows.filter((wf) => {
      const origSet = new Set(orig[wf.id] ?? []);
      const newSet = new Set(localAssignments[wf.id] ?? []);
      if (origSet.size !== newSet.size) return true;
      for (const v of origSet) if (!newSet.has(v)) return true;
      return false;
    });

    try {
      await Promise.all(
        changedWorkflows.map((wf) =>
          assignWorkflowWorkers(wf.id, localAssignments[wf.id] ?? []),
        ),
      );
      toast.success("Assignments saved");
      await loadData(period);
    } catch {
      toast.error("Failed to save assignments");
    } finally {
      setSaving(false);
    }
  };

  const workflows = data?.workflows ?? [];
  const workers = data?.workers ?? [];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Workload &amp; Capacity</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Workflow execution stats and worker assignment matrix.
          </p>
        </div>

        <div className="flex items-center gap-2">
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
                    {workers.map((w) => (
                      <td key={w.id} className="text-center px-3 py-3">
                        <div className="flex items-center justify-center">
                          <Checkbox
                            checked={(localAssignments[wf.id] ?? []).includes(w.id)}
                            onCheckedChange={() => toggleAssignment(wf.id, w.id)}
                          />
                        </div>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Save bar */}
          {workers.length > 0 && (
            <div className="flex items-center justify-end border-t border-border px-4 py-3 bg-muted/20">
              <Button size="sm" disabled={!dirty || saving} onClick={handleSave}>
                {saving ? "Saving..." : "Save Assignments"}
              </Button>
            </div>
          )}
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
