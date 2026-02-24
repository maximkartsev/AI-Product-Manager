"use client";

import { useMemo, useRef, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView } from "@/components/ui/DataTable";
import { DeleteConfirmDialog } from "@/components/ui/DeleteConfirmDialog";
import { useDataTable } from "@/hooks/useDataTable";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";
import { extractErrorMessage } from "@/lib/apiErrors";
import {
  deleteComfyUiAssetBundle,
  deleteComfyUiAssetFile,
  getComfyUiCleanupCandidates,
  getComfyUiAssetFileCleanupCandidates,
  type ComfyUiAssetCleanupCandidate,
  type ComfyUiAssetFileCleanupCandidate,
  type ComfyUiAssetBundle,
  type ComfyUiAssetFile,
} from "@/lib/api";

export default function AdminComfyUiCleanupPage() {
  const [bundleToDelete, setBundleToDelete] = useState<ComfyUiAssetCleanupCandidate | null>(null);
  const [assetToDelete, setAssetToDelete] = useState<ComfyUiAssetFileCleanupCandidate | null>(null);

  const bundleActionsColumn = useMemo<ColumnDef<ComfyUiAssetCleanupCandidate & { reason?: string }>[]>(
    () => [
      {
        id: "reason",
        header: "Reason",
        size: 180,
        cell: ({ row }) => <span className="text-muted-foreground">{row.original.reason || "-"}</span>,
      },
      {
        id: "_actions",
        header: "Actions",
        enableSorting: false,
        enableHiding: false,
        enableResizing: false,
        size: 220,
        minSize: 220,
        cell: ({ row }) => {
          const item = row.original;
          const command = `aws s3 rm s3://<MODELS_BUCKET>/${item.s3_prefix}/ --recursive`;
          return (
            <div className="flex items-center justify-end gap-2">
              <Button
                variant="outline"
                size="sm"
                className="text-sm px-3"
                onClick={(event) => {
                  event.stopPropagation();
                  navigator.clipboard.writeText(command);
                  toast.success("Delete command copied.");
                }}
              >
                Copy Cmd
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-sm px-3"
                onClick={(event) => {
                  event.stopPropagation();
                  setBundleToDelete(item);
                }}
              >
                Delete
              </Button>
            </div>
          );
        },
      },
    ],
    [],
  );

  const assetActionsColumn = useMemo<ColumnDef<ComfyUiAssetFileCleanupCandidate & { reason?: string }>[]>(
    () => [
      {
        id: "reason",
        header: "Reason",
        size: 180,
        cell: ({ row }) => <span className="text-muted-foreground">{row.original.reason || "-"}</span>,
      },
      {
        id: "_actions",
        header: "Actions",
        enableSorting: false,
        enableHiding: false,
        enableResizing: false,
        size: 220,
        minSize: 220,
        cell: ({ row }) => {
          const item = row.original;
          const command = `aws s3 rm s3://<MODELS_BUCKET>/${item.s3_key}`;
          return (
            <div className="flex items-center justify-end gap-2">
              <Button
                variant="outline"
                size="sm"
                className="text-sm px-3"
                onClick={(event) => {
                  event.stopPropagation();
                  navigator.clipboard.writeText(command);
                  toast.success("Delete command copied.");
                }}
              >
                Copy Cmd
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="border-red-500/60 text-red-400 hover:bg-red-500/10 text-sm px-3"
                onClick={(event) => {
                  event.stopPropagation();
                  setAssetToDelete(item);
                }}
              >
                Delete
              </Button>
            </div>
          );
        },
      },
    ],
    [],
  );

  const bundleState = useDataTable<ComfyUiAssetCleanupCandidate>({
    entityClass: "ComfyUiAssetBundle",
    entityName: "Bundle",
    storageKey: "admin-comfyui-cleanup-bundles",
    settingsKey: "admin-comfyui-cleanup-bundles",
    list: async (_params: { page: number; perPage: number; search?: string; filters?: FilterValue[]; order?: string }) => {
      const data = await getComfyUiCleanupCandidates();
      return {
        items: data.items ?? [],
        totalItems: data.totalItems ?? (data.items?.length || 0),
        totalPages: 1,
      };
    },
    getItemId: (item) => item.id,
    renderCellValue: (bundle, columnKey) => {
      if (columnKey === "bundle_id") {
        return <span className="text-foreground font-mono text-xs">{bundle.bundle_id}</span>;
      }
      if (columnKey === "s3_prefix") {
        return <span className="text-muted-foreground font-mono text-xs">{bundle.s3_prefix}</span>;
      }
      const value = (bundle as ComfyUiAssetBundle)[columnKey as keyof ComfyUiAssetBundle];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    extraColumns: bundleActionsColumn,
  });

  const assetState = useDataTable<ComfyUiAssetFileCleanupCandidate>({
    entityClass: "ComfyUiAssetFile",
    entityName: "Asset",
    storageKey: "admin-comfyui-cleanup-assets",
    settingsKey: "admin-comfyui-cleanup-assets",
    list: async (_params: { page: number; perPage: number; search?: string; filters?: FilterValue[]; order?: string }) => {
      const data = await getComfyUiAssetFileCleanupCandidates();
      return {
        items: data.items ?? [],
        totalItems: data.totalItems ?? (data.items?.length || 0),
        totalPages: 1,
      };
    },
    getItemId: (item) => item.id,
    renderCellValue: (asset, columnKey) => {
      if (columnKey === "kind") {
        return <span className="text-foreground">{asset.kind}</span>;
      }
      if (columnKey === "original_filename") {
        return <span className="text-foreground">{asset.original_filename}</span>;
      }
      if (columnKey === "sha256") {
        return <span className="text-foreground font-mono text-xs">{asset.sha256}</span>;
      }
      if (columnKey === "s3_key") {
        return <span className="text-muted-foreground font-mono text-xs">{asset.s3_key}</span>;
      }
      const value = (asset as ComfyUiAssetFile)[columnKey as keyof ComfyUiAssetFile];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    extraColumns: assetActionsColumn,
  });

  const handleConfirmBundleDelete = async () => {
    if (!bundleToDelete) return;
    try {
      await deleteComfyUiAssetBundle(bundleToDelete.id);
      toast.success("Bundle deleted.");
      setBundleToDelete(null);
      bundleState.loadItems();
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to delete bundle."));
    }
  };

  const handleConfirmAssetDelete = async () => {
    if (!assetToDelete) return;
    try {
      await deleteComfyUiAssetFile(assetToDelete.id);
      toast.success("Asset deleted.");
      setAssetToDelete(null);
      assetState.loadItems();
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to delete asset."));
    }
  };

  return (
    <div className="space-y-10">
      <DataTableView
        state={bundleState}
        options={{
          entityClass: "ComfyUiAssetBundle",
          entityName: "Bundle",
          title: "Bundle Cleanup Candidates",
          description: "Bundles not active in any fleet. Delete from S3 + DB when safe.",
        }}
      />

      <DataTableView
        state={assetState}
        options={{
          entityClass: "ComfyUiAssetFile",
          entityName: "Asset",
          title: "Asset Cleanup Candidates",
          description: "Assets not referenced by any bundle. Delete from S3 + DB when safe.",
        }}
      />

      <DeleteConfirmDialog
        entityName="Bundle"
        open={Boolean(bundleToDelete)}
        onOpenChange={(open) => {
          if (!open) setBundleToDelete(null);
        }}
        itemTitle={bundleToDelete?.bundle_id}
        itemId={bundleToDelete?.id}
        onConfirm={handleConfirmBundleDelete}
      />

      <DeleteConfirmDialog
        entityName="Asset"
        open={Boolean(assetToDelete)}
        onOpenChange={(open) => {
          if (!open) setAssetToDelete(null);
        }}
        itemTitle={assetToDelete?.original_filename}
        itemId={assetToDelete?.id}
        onConfirm={handleConfirmAssetDelete}
      />
    </div>
  );
}
