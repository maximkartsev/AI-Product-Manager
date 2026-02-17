"use client";

import { useMemo, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView } from "@/components/ui/DataTable";
import { useDataTable } from "@/hooks/useDataTable";
import { getAdminAuditLogs, type WorkerAuditLog } from "@/lib/api";
import type { FilterValue } from "@/components/ui/SmartFilters";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from "@/components/ui/sheet";

function EventBadge({ event }: { event: string }) {
  const green = ["poll", "complete", "heartbeat", "approved"];
  const red = ["fail", "auth_failed", "revoked"];
  const yellow = ["token_rotated"];
  let variant = "bg-zinc-500/20 text-zinc-400";
  if (green.includes(event)) variant = "bg-green-500/20 text-green-400";
  else if (red.includes(event)) variant = "bg-red-500/20 text-red-400";
  else if (yellow.includes(event)) variant = "bg-yellow-500/20 text-yellow-400";
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${variant}`}
    >
      {event}
    </span>
  );
}

export default function AdminAuditLogsPage() {
  const [selectedLog, setSelectedLog] = useState<WorkerAuditLog | null>(null);

  const columns = useMemo<ColumnDef<WorkerAuditLog>[]>(
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
          return (
            <span className="text-foreground text-sm tabular-nums">
              {date.toLocaleString()}
            </span>
          );
        },
      },
      {
        id: "event",
        accessorKey: "event",
        header: "Event",
        enableSorting: true,
        size: 140,
        minSize: 120,
        cell: ({ row }) => <EventBadge event={row.original.event} />,
      },
      {
        id: "worker_identifier",
        accessorKey: "worker_identifier",
        header: "Worker ID",
        enableSorting: true,
        size: 160,
        minSize: 120,
        cell: ({ row }) => {
          const value = row.original.worker_identifier;
          return value ? (
            <span className="text-foreground font-mono text-sm">{value}</span>
          ) : (
            <span className="text-muted-foreground">-</span>
          );
        },
      },
      {
        id: "worker_display_name",
        accessorKey: "worker_display_name",
        header: "Worker Name",
        enableSorting: true,
        size: 180,
        minSize: 120,
        cell: ({ row }) => {
          const value = row.original.worker_display_name;
          return value ? (
            <span className="text-foreground">{value}</span>
          ) : (
            <span className="text-muted-foreground">-</span>
          );
        },
      },
      {
        id: "dispatch_id",
        accessorKey: "dispatch_id",
        header: "Dispatch",
        enableSorting: true,
        size: 100,
        minSize: 80,
        cell: ({ row }) => {
          const value = row.original.dispatch_id;
          return value != null ? (
            <span className="text-foreground font-mono text-sm">#{value}</span>
          ) : (
            <span className="text-muted-foreground">-</span>
          );
        },
      },
      {
        id: "ip_address",
        accessorKey: "ip_address",
        header: "IP Address",
        enableSorting: true,
        size: 140,
        minSize: 120,
        cell: ({ row }) => {
          const value = row.original.ip_address;
          return value ? (
            <span className="text-foreground font-mono text-sm">{value}</span>
          ) : (
            <span className="text-muted-foreground">-</span>
          );
        },
      },
    ],
    [],
  );

  const state = useDataTable<WorkerAuditLog>({
    entityClass: "WorkerAuditLog",
    entityName: "Audit Log",
    storageKey: "admin-audit-logs-table-columns",
    list: async (params: {
      page: number;
      perPage: number;
      search?: string;
      filters?: FilterValue[];
      order?: string;
    }) => {
      const data = await getAdminAuditLogs({
        page: params.page,
        perPage: params.perPage,
        search: params.search,
        filters: params.filters,
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
          entityClass: "WorkerAuditLog",
          entityName: "Audit Log",
          title: "Audit Logs",
          description: "Worker activity and security event log.",
          readOnly: true,
          onRowClick: (item: WorkerAuditLog) => setSelectedLog(item),
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
                <SheetTitle>Audit Log Detail</SheetTitle>
                <SheetDescription>
                  Event #{selectedLog.id}
                </SheetDescription>
              </SheetHeader>

              <div className="mt-6 space-y-4">
                <DetailRow label="Timestamp">
                  {new Date(selectedLog.created_at).toLocaleString()}
                </DetailRow>
                <DetailRow label="Event">
                  <EventBadge event={selectedLog.event} />
                </DetailRow>
                <DetailRow label="Worker Identifier">
                  {selectedLog.worker_identifier ?? "-"}
                </DetailRow>
                <DetailRow label="Worker Name">
                  {selectedLog.worker_display_name ?? "-"}
                </DetailRow>
                <DetailRow label="Dispatch ID">
                  {selectedLog.dispatch_id != null
                    ? `#${selectedLog.dispatch_id}`
                    : "-"}
                </DetailRow>
                <DetailRow label="IP Address">
                  {selectedLog.ip_address ?? "-"}
                </DetailRow>

                {selectedLog.metadata &&
                  Object.keys(selectedLog.metadata).length > 0 && (
                    <div className="pt-4 border-t border-border">
                      <h3 className="text-sm font-medium text-foreground mb-2">
                        Metadata
                      </h3>
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

function DetailRow({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      <span className="text-sm text-foreground">{children}</span>
    </div>
  );
}
