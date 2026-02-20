"use client";

import { useMemo, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView } from "@/components/ui/DataTable";
import { useDataTable } from "@/hooks/useDataTable";
import { getComfyUiAssetAuditLogs, type ComfyUiAssetAuditLog } from "@/lib/api";
import type { FilterValue } from "@/components/ui/SmartFilters";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from "@/components/ui/sheet";
import { Button } from "@/components/ui/button";

function EventBadge({ event }: { event: string }) {
  const green = ["asset_uploaded", "bundle_created", "fleet_bundle_activated", "asset_deleted", "bundle_deleted"];
  const red = ["asset_delete_failed", "bundle_delete_failed"];
  let variant = "bg-zinc-500/20 text-zinc-400";
  if (green.includes(event)) variant = "bg-green-500/20 text-green-400";
  else if (red.includes(event)) variant = "bg-red-500/20 text-red-400";
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${variant}`}>
      {event}
    </span>
  );
}

export default function AdminComfyUiAuditLogsPage() {
  const [selectedLog, setSelectedLog] = useState<ComfyUiAssetAuditLog | null>(null);

  const columns = useMemo<ColumnDef<ComfyUiAssetAuditLog>[]>(
    () => [
      {
        id: "created_at",
        accessorKey: "created_at",
        header: "Timestamp",
        enableSorting: true,
        size: 180,
        minSize: 150,
        cell: ({ row }) => {
          const value = row.original.created_at;
          if (!value) return <span className="text-muted-foreground">-</span>;
          const date = new Date(value);
          return <span className="text-foreground text-sm tabular-nums">{date.toLocaleString()}</span>;
        },
      },
      {
        id: "event",
        accessorKey: "event",
        header: "Event",
        enableSorting: true,
        size: 180,
        minSize: 140,
        cell: ({ row }) => <EventBadge event={row.original.event} />,
      },
      {
        id: "bundle_id",
        accessorKey: "bundle_id",
        header: "Bundle",
        enableSorting: true,
        size: 120,
        minSize: 100,
        cell: ({ row }) => {
          const value = row.original.bundle_id;
          return value != null ? <span className="text-foreground font-mono text-sm">#{value}</span> : <span className="text-muted-foreground">-</span>;
        },
      },
      {
        id: "asset_file_id",
        accessorKey: "asset_file_id",
        header: "Asset",
        enableSorting: true,
        size: 120,
        minSize: 100,
        cell: ({ row }) => {
          const value = row.original.asset_file_id;
          return value != null ? <span className="text-foreground font-mono text-sm">#{value}</span> : <span className="text-muted-foreground">-</span>;
        },
      },
      {
        id: "actor_email",
        accessorKey: "actor_email",
        header: "Actor",
        enableSorting: true,
        size: 200,
        minSize: 140,
        cell: ({ row }) => row.original.actor_email ? <span className="text-foreground">{row.original.actor_email}</span> : <span className="text-muted-foreground">-</span>,
      },
      {
        id: "notes",
        accessorKey: "notes",
        header: "Notes",
        enableSorting: false,
        size: 240,
        minSize: 180,
        cell: ({ row }) => {
          const text = row.original.notes || "";
          return <span className="text-muted-foreground">{text ? `${text.slice(0, 60)}${text.length > 60 ? "..." : ""}` : "-"}</span>;
        },
      },
    ],
    [],
  );

  const state = useDataTable<ComfyUiAssetAuditLog>({
    entityClass: "ComfyUiAssetAuditLog",
    entityName: "Asset Audit Log",
    storageKey: "admin-comfyui-asset-audit-logs",
    list: async (params: {
      page: number;
      perPage: number;
      search?: string;
      filters?: FilterValue[];
      order?: string;
    }) => {
      const data = await getComfyUiAssetAuditLogs({
        page: params.page,
        perPage: params.perPage,
        order: params.order ?? "created_at:desc",
      });
      return {
        items: data.items,
        totalItems: data.totalItems,
        totalPages: data.totalPages,
      };
    },
    getItemId: (item) => item.id,
    extraColumns: columns,
  });

  return (
    <>
      <DataTableView
        state={state}
        options={{
          entityClass: "ComfyUiAssetAuditLog",
          entityName: "Asset Audit Log",
          title: "ComfyUI Asset Audit Logs",
          description: "Track asset, bundle, and fleet activation events.",
          readOnly: true,
          onRowClick: (item: ComfyUiAssetAuditLog) => setSelectedLog(item),
        }}
      />

      <Sheet
        open={selectedLog !== null}
        onOpenChange={(open) => {
          if (!open) setSelectedLog(null);
        }}
      >
        <SheetContent side="right" className="overflow-y-auto">
          {selectedLog && (
            <>
              <SheetHeader className="pb-4 border-b border-border">
                <SheetTitle>ComfyUI Audit Log Detail</SheetTitle>
                <SheetDescription>Event #{selectedLog.id}</SheetDescription>
              </SheetHeader>

              <div className="mt-6 space-y-4">
                <DetailRow label="Timestamp">
                  {selectedLog.created_at ? new Date(selectedLog.created_at).toLocaleString() : "-"}
                </DetailRow>
                <DetailRow label="Event">
                  <EventBadge event={selectedLog.event} />
                </DetailRow>
                <DetailRow label="Bundle ID">{selectedLog.bundle_id ?? "-"}</DetailRow>
                <DetailRow label="Asset ID">{selectedLog.asset_file_id ?? "-"}</DetailRow>
                <DetailRow label="Actor">{selectedLog.actor_email ?? "-"}</DetailRow>
                <DetailRow label="Notes">{selectedLog.notes ?? "-"}</DetailRow>

                {selectedLog.artifact_download_url && (
                  <div className="pt-4 border-t border-border">
                    <Button
                      variant="outline"
                      onClick={() => window.open(selectedLog.artifact_download_url || "", "_blank", "noopener,noreferrer")}
                    >
                      Download Artifact
                    </Button>
                  </div>
                )}

                {selectedLog.metadata && Object.keys(selectedLog.metadata).length > 0 && (
                  <div className="pt-4 border-t border-border">
                    <h3 className="text-sm font-medium text-foreground mb-2">Metadata</h3>
                    <pre className="rounded-lg bg-muted p-3 text-xs text-foreground overflow-x-auto whitespace-pre-wrap break-all">
                      {JSON.stringify(selectedLog.metadata, null, 2)}
                    </pre>
                  </div>
                )}
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </>
  );
}

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="text-sm text-foreground">{children}</div>
    </div>
  );
}
