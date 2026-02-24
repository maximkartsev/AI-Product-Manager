"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView } from "@/components/ui/DataTable";
import { useDataTable } from "@/hooks/useDataTable";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { AdminDetailSheet, AdminDetailSection } from "@/components/admin/AdminDetailSheet";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";
import { extractErrorMessage } from "@/lib/apiErrors";
import {
  getAdminWorkers,
  getAdminWorker,
  updateAdminWorker,
  approveWorker,
  revokeWorker,
  rotateWorkerToken,
  assignWorkerWorkflows,
  getAdminWorkflows,
  type AdminWorker,
  type AdminWorkflow,
  type WorkerAuditLog,
} from "@/lib/api";

function relativeTime(dateStr?: string | null): string {
  if (!dateStr) return "Never";
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return "Just now";
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  return `${days}d ago`;
}

function Badge({ label, variant }: { label: string; variant: "green" | "red" | "yellow" | "gray" }) {
  const colors = {
    green: "bg-green-500/20 text-green-400",
    red: "bg-red-500/20 text-red-400",
    yellow: "bg-yellow-500/20 text-yellow-400",
    gray: "bg-zinc-500/20 text-zinc-400",
  };
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${colors[variant]}`}>
      {label}
    </span>
  );
}

export default function AdminWorkersPage() {
  const [detailWorker, setDetailWorker] = useState<AdminWorker | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [workflows, setWorkflows] = useState<AdminWorkflow[]>([]);
  const [assignedWorkflowIds, setAssignedWorkflowIds] = useState<number[]>([]);
  const [displayName, setDisplayName] = useState("");
  const [isDraining, setIsDraining] = useState(false);
  const [saving, setSaving] = useState(false);
  const [plainToken, setPlainToken] = useState("");
  const [auditLogs, setAuditLogs] = useState<WorkerAuditLog[]>([]);

  useEffect(() => {
    getAdminWorkflows({ perPage: 100 }).then((d) => setWorkflows(d.items ?? [])).catch(() => {});
  }, []);

  const openDetail = useCallback(async (worker: AdminWorker) => {
    try {
      const full = await getAdminWorker(worker.id);
      setDetailWorker(full);
      setDisplayName(full.display_name || "");
      setIsDraining(!!full.is_draining);
      setAssignedWorkflowIds((full.workflows ?? []).map((w) => w.id));
      setAuditLogs(full.recent_audit_logs ?? []);
      setDetailOpen(true);
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to load worker details"));
    }
  }, []);

  const refreshDetail = useCallback(async (id: number) => {
    try {
      const full = await getAdminWorker(id);
      setDetailWorker(full);
      setDisplayName(full.display_name || "");
      setIsDraining(!!full.is_draining);
      setAssignedWorkflowIds((full.workflows ?? []).map((w) => w.id));
      setAuditLogs(full.recent_audit_logs ?? []);
    } catch {
      // silently ignore refresh failures
    }
  }, []);

  const openDetailRef = useRef(openDetail);
  openDetailRef.current = openDetail;

  const actionsColumn = useMemo<ColumnDef<AdminWorker>[]>(
    () => [
      {
        id: "_actions",
        header: "Actions",
        enableSorting: false,
        enableHiding: false,
        enableResizing: false,
        size: 100,
        minSize: 100,
        cell: ({ row }: { row: { original: AdminWorker } }) => (
          <Button
            variant="outline"
            size="sm"
            className="text-sm px-3"
            onClick={(e) => {
              e.stopPropagation();
              openDetailRef.current(row.original);
            }}
          >
            Manage
          </Button>
        ),
      },
    ],
    [],
  );

  const state = useDataTable<AdminWorker>({
    entityClass: "ComfyUiWorker",
    entityName: "Worker",
    storageKey: "admin-workers-table-columns",
    settingsKey: "admin-workers",
    list: async (params: { page: number; perPage: number; search?: string; filters?: FilterValue[]; order?: string }) => {
      const data = await getAdminWorkers({
        page: params.page,
        perPage: params.perPage,
        search: params.search,
        filters: params.filters,
        order: params.order,
      });
      return { items: data.items, totalItems: data.totalItems, totalPages: data.totalPages };
    },
    getItemId: (item) => item.id,
    renderCellValue: (worker, columnKey) => {
      if (columnKey === "worker_id") return <span className="text-foreground font-mono text-xs">{worker.worker_id}</span>;
      if (columnKey === "display_name") return <span className="text-foreground">{worker.display_name || "-"}</span>;
      if (columnKey === "last_seen_at") return <span className="text-muted-foreground">{relativeTime(worker.last_seen_at)}</span>;
      if (columnKey === "is_approved") return worker.is_approved ? <Badge label="Approved" variant="green" /> : <Badge label="Pending" variant="red" />;
      if (columnKey === "is_draining") return worker.is_draining ? <Badge label="Draining" variant="yellow" /> : <Badge label="Active" variant="gray" />;
      if (columnKey === "registration_source") return worker.registration_source === "fleet" ? <Badge label="Fleet" variant="yellow" /> : <Badge label="Admin" variant="gray" />;
      if (columnKey === "current_load") return <span className="text-muted-foreground">{worker.current_load ?? 0}/{worker.max_concurrency ?? 1}</span>;
      if (columnKey === "workflows_count") return <span className="text-muted-foreground">{worker.workflows_count ?? 0}</span>;
      const value = worker[columnKey as keyof AdminWorker];
      if (value === null || value === undefined) return <span className="text-muted-foreground">-</span>;
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    extraColumns: actionsColumn,
  });

  const handleSave = async () => {
    if (!detailWorker) return;
    setSaving(true);
    try {
      await updateAdminWorker(detailWorker.id, { display_name: displayName, is_draining: isDraining });
      toast.success("Worker updated");
      state.loadItems();
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to update worker"));
    } finally {
      setSaving(false);
    }
  };

  const handleApproveToggle = async () => {
    if (!detailWorker) return;
    try {
      if (detailWorker.is_approved) {
        await revokeWorker(detailWorker.id);
        toast.success("Worker approval revoked");
      } else {
        await approveWorker(detailWorker.id);
        toast.success("Worker approved");
      }
      state.loadItems();
      await refreshDetail(detailWorker.id);
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to update approval status"));
    }
  };

  const handleRotateToken = async () => {
    if (!detailWorker) return;
    try {
      const result = await rotateWorkerToken(detailWorker.id);
      setPlainToken(result.token);
      await refreshDetail(detailWorker.id);
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to rotate token"));
    }
  };

  const handleAssignWorkflows = async () => {
    if (!detailWorker) return;
    try {
      await assignWorkerWorkflows(detailWorker.id, assignedWorkflowIds);
      toast.success("Workflows assigned");
      state.loadItems();
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to assign workflows"));
    }
  };

  const toggleWorkflow = (id: number) => {
    setAssignedWorkflowIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  return (
    <>
      <DataTableView
        state={state}
        options={{
          entityClass: "ComfyUiWorker",
          entityName: "Worker",
          title: "Workers",
          description: "Monitor and manage ComfyUI workers.",
        }}
        renderRowActions={(item) => (
          <Button variant="outline" size="sm" className="text-xs flex-1" onClick={() => openDetail(item)}>
            Manage
          </Button>
        )}
      />

      <AdminDetailSheet
        open={detailOpen}
        onOpenChange={(open) => { setDetailOpen(open); if (!open) setPlainToken(""); }}
        title={detailWorker?.display_name || detailWorker?.worker_id || "Worker"}
        description={detailWorker?.worker_id}
      >
        {detailWorker && (
          <>
            <AdminDetailSection title="General">
              <div className="flex flex-col gap-3">
                <div>
                  <label className="text-xs text-muted-foreground">Display Name</label>
                  <Input value={displayName} onChange={(e) => setDisplayName(e.target.value)} className="h-8 text-sm" />
                </div>
                <div className="flex items-center gap-2">
                  <Checkbox checked={isDraining} onCheckedChange={(c) => setIsDraining(!!c)} id="is_draining" />
                  <label htmlFor="is_draining" className="text-sm text-muted-foreground cursor-pointer">Draining</label>
                </div>
                <Button size="sm" onClick={handleSave} disabled={saving}>{saving ? "Saving..." : "Save"}</Button>
              </div>
            </AdminDetailSection>

            <AdminDetailSection title="Approval">
              <div className="flex items-center justify-between">
                <span className="text-sm">Status: {detailWorker.is_approved ? <Badge label="Approved" variant="green" /> : <Badge label="Pending" variant="red" />}</span>
                <Button size="sm" variant={detailWorker.is_approved ? "destructive" : "default"} onClick={handleApproveToggle}>
                  {detailWorker.is_approved ? "Revoke" : "Approve"}
                </Button>
              </div>
              <div className="flex items-center gap-2 mt-2">
                <span className="text-xs text-muted-foreground">Source:</span>
                {detailWorker.registration_source === "fleet" ? <Badge label="Fleet" variant="yellow" /> : <Badge label="Admin" variant="gray" />}
              </div>
            </AdminDetailSection>

            <AdminDetailSection title="Workflow Assignment">
              {assignedWorkflowIds.length > 1 && (
                <p className="text-xs text-yellow-400">Switching workflows takes time on the node and is not recommended due to performance issues</p>
              )}
              <div className="flex flex-col gap-1.5">
                {workflows.filter((w) => w.is_active).map((wf) => (
                  <div key={wf.id} className="flex items-center gap-2">
                    <Checkbox checked={assignedWorkflowIds.includes(wf.id)} onCheckedChange={() => toggleWorkflow(wf.id)} id={`wf-${wf.id}`} />
                    <label htmlFor={`wf-${wf.id}`} className="text-sm text-muted-foreground cursor-pointer">{wf.name || wf.slug}</label>
                  </div>
                ))}
              </div>
              <Button size="sm" className="mt-2" onClick={handleAssignWorkflows}>Save Workflows</Button>
            </AdminDetailSection>

            <AdminDetailSection title="Authentication">
              <Button size="sm" variant="outline" onClick={handleRotateToken}>Rotate Token</Button>
              {plainToken && (
                <div className="mt-3 space-y-2">
                  <p className="text-xs text-yellow-400">Save this token now. It will not be shown again.</p>
                  <div className="bg-muted rounded-md p-3 font-mono text-xs break-all select-all">{plainToken}</div>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        navigator.clipboard.writeText(plainToken);
                        toast.success("Token copied to clipboard");
                      }}
                    >
                      Copy
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => setPlainToken("")}>Dismiss</Button>
                  </div>
                </div>
              )}
            </AdminDetailSection>

            <AdminDetailSection title="Recent Audit Logs">
              {auditLogs.length === 0 ? (
                <p className="text-xs text-muted-foreground">No audit logs yet.</p>
              ) : (
                <div className="rounded-md border border-border overflow-hidden">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="bg-muted/50">
                        <th className="text-left px-2 py-1 font-medium">Event</th>
                        <th className="text-left px-2 py-1 font-medium">Dispatch</th>
                        <th className="text-left px-2 py-1 font-medium">IP</th>
                        <th className="text-left px-2 py-1 font-medium">Time</th>
                      </tr>
                    </thead>
                    <tbody>
                      {auditLogs.map((log) => (
                        <tr key={log.id} className="border-t border-border">
                          <td className="px-2 py-1">{log.event}</td>
                          <td className="px-2 py-1 text-muted-foreground">{log.dispatch_id ?? "-"}</td>
                          <td className="px-2 py-1 text-muted-foreground">{log.ip_address ?? "-"}</td>
                          <td className="px-2 py-1 text-muted-foreground">{relativeTime(log.created_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </AdminDetailSection>
          </>
        )}
      </AdminDetailSheet>

    </>
  );
}
