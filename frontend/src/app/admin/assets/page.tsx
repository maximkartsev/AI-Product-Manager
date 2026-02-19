"use client";

import { useEffect, useMemo, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { toast } from "sonner";
import {
  getAdminWorkflows,
  initComfyUiAssetUpload,
  createComfyUiAssetFile,
  getComfyUiAssetFiles,
  getComfyUiAssetBundles,
  getComfyUiActiveBundles,
  getComfyUiCleanupCandidates,
  createComfyUiAssetBundle,
  activateComfyUiAssetBundle,
  getComfyUiAssetBundleManifest,
  getComfyUiAssetAuditLogs,
  exportComfyUiAssetAuditLogs,
  type AdminWorkflow,
  type ComfyUiAssetFile,
  type ComfyUiAssetBundle,
  type ComfyUiWorkflowActiveBundle,
  type ComfyUiAssetCleanupCandidate,
  type ComfyUiAssetAuditLog,
} from "@/lib/api";

const ASSET_KINDS = [
  { value: "checkpoint", label: "Checkpoint" },
  { value: "lora", label: "LoRA" },
  { value: "vae", label: "VAE" },
  { value: "embedding", label: "Embedding" },
  { value: "controlnet", label: "ControlNet" },
  { value: "custom_node", label: "Custom Node" },
  { value: "other", label: "Other" },
];

export default function AdminAssetsPage() {
  const [workflows, setWorkflows] = useState<AdminWorkflow[]>([]);
  const [assetFiles, setAssetFiles] = useState<ComfyUiAssetFile[]>([]);
  const [bundles, setBundles] = useState<ComfyUiAssetBundle[]>([]);
  const [activeBundles, setActiveBundles] = useState<ComfyUiWorkflowActiveBundle[]>([]);
  const [cleanupCandidates, setCleanupCandidates] = useState<ComfyUiAssetCleanupCandidate[]>([]);
  const [auditLogs, setAuditLogs] = useState<ComfyUiAssetAuditLog[]>([]);
  const [loading, setLoading] = useState(false);

  const [uploadWorkflowId, setUploadWorkflowId] = useState<string>("");
  const [uploadKind, setUploadKind] = useState<string>("checkpoint");
  const [uploadSha, setUploadSha] = useState<string>("");

  const [bundleWorkflowId, setBundleWorkflowId] = useState<string>("");
  const [bundleNotes, setBundleNotes] = useState<string>("");
  const [selectedAssetIds, setSelectedAssetIds] = useState<Set<number>>(new Set());
  const [bundleTargetWorkflows, setBundleTargetWorkflows] = useState<Record<number, string>>({});

  useEffect(() => {
    const load = async () => {
      setLoading(true);
      try {
        const workflowsData = await getAdminWorkflows({ perPage: 200 });
        const wfItems = workflowsData.items ?? [];
        setWorkflows(wfItems);
        if (wfItems.length > 0) {
          setUploadWorkflowId((prev) => prev || String(wfItems[0].id));
          setBundleWorkflowId((prev) => prev || String(wfItems[0].id));
        }
        await Promise.all([loadAssetFiles(), loadBundles(), loadActiveBundles(), loadCleanupCandidates(), loadAuditLogs()]);
      } catch (error) {
        toast.error("Failed to load assets data.");
      } finally {
        setLoading(false);
      }
    };
    void load();
  }, []);

  const loadAssetFiles = async () => {
    const filesData = await getComfyUiAssetFiles({ perPage: 200 });
    setAssetFiles(filesData.items ?? []);
  };

  const loadBundles = async () => {
    const bundleData = await getComfyUiAssetBundles({ perPage: 200 });
    setBundles(bundleData.items ?? []);
  };

  const loadActiveBundles = async () => {
    const activeData = await getComfyUiActiveBundles({ perPage: 200 });
    setActiveBundles(activeData.items ?? []);
  };

  const loadCleanupCandidates = async () => {
    const cleanupData = await getComfyUiCleanupCandidates();
    setCleanupCandidates(cleanupData.items ?? []);
  };

  const loadAuditLogs = async () => {
    const logsData = await getComfyUiAssetAuditLogs({ perPage: 50 });
    setAuditLogs(logsData.items ?? []);
  };

  const workflowOptions = useMemo(
    () => workflows.map((wf) => ({ value: String(wf.id), label: `${wf.name} (${wf.slug})` })),
    [workflows],
  );

  const filteredAssets = useMemo(() => {
    if (!bundleWorkflowId) return [];
    return assetFiles.filter((file) => file.workflow_id === Number(bundleWorkflowId));
  }, [assetFiles, bundleWorkflowId]);

  useEffect(() => {
    setSelectedAssetIds(new Set());
  }, [bundleWorkflowId]);

  const handleFileUpload = async (file: File) => {
    if (!uploadWorkflowId) {
      toast.error("Select a workflow before uploading.");
      return;
    }

    setLoading(true);
    try {
      const init = await initComfyUiAssetUpload({
        workflow_id: Number(uploadWorkflowId),
        kind: uploadKind,
        mime_type: file.type || "application/octet-stream",
        size_bytes: file.size,
        original_filename: file.name,
        sha256: uploadSha || undefined,
      });

      const headers: Record<string, string> = {};
      Object.entries(init.upload_headers ?? {}).forEach(([key, value]) => {
        if (Array.isArray(value)) {
          if (value[0]) headers[key] = value[0];
        } else if (value) {
          headers[key] = value;
        }
      });
      if (!headers["Content-Type"]) {
        headers["Content-Type"] = file.type || "application/octet-stream";
      }

      const response = await fetch(init.upload_url, {
        method: "PUT",
        headers,
        body: file,
      });

      if (!response.ok) {
        throw new Error(`Upload failed (${response.status}).`);
      }

      await createComfyUiAssetFile({
        workflow_id: Number(uploadWorkflowId),
        kind: uploadKind,
        original_filename: file.name,
        s3_key: init.path,
        content_type: file.type || "application/octet-stream",
        size_bytes: file.size,
        sha256: uploadSha || undefined,
      });

      toast.success("Asset uploaded.");
      setUploadSha("");
      await loadAssetFiles();
    } catch (error) {
      toast.error("Failed to upload asset.");
    } finally {
      setLoading(false);
    }
  };

  const toggleAssetSelection = (id: number) => {
    setSelectedAssetIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const handleCreateBundle = async () => {
    if (!bundleWorkflowId) {
      toast.error("Select a workflow for the bundle.");
      return;
    }
    if (selectedAssetIds.size === 0) {
      toast.error("Select at least one asset file.");
      return;
    }

    setLoading(true);
    try {
      await createComfyUiAssetBundle({
        workflow_id: Number(bundleWorkflowId),
        asset_file_ids: Array.from(selectedAssetIds),
        notes: bundleNotes || undefined,
      });
      toast.success("Bundle created.");
      setBundleNotes("");
      setSelectedAssetIds(new Set());
      await Promise.all([loadBundles(), loadCleanupCandidates()]);
    } catch (error) {
      toast.error("Failed to create bundle.");
    } finally {
      setLoading(false);
    }
  };

  const handleActivateBundle = async (bundle: ComfyUiAssetBundle, stage: "staging" | "production") => {
    const targetWorkflowId = bundleTargetWorkflows[bundle.id] ?? String(bundle.workflow?.id ?? bundle.workflow_id ?? "");
    if (!targetWorkflowId) {
      toast.error("Select a target workflow.");
      return;
    }
    const notes = window.prompt(`Notes for activating bundle ${bundle.bundle_id} (${stage})?`) ?? undefined;
    setLoading(true);
    try {
      await activateComfyUiAssetBundle(bundle.id, {
        stage,
        notes: notes || undefined,
        target_workflow_id: Number(targetWorkflowId),
      });
      toast.success(`Bundle activated for ${stage}.`);
      await Promise.all([loadBundles(), loadActiveBundles(), loadCleanupCandidates(), loadAuditLogs()]);
    } catch (error) {
      toast.error("Failed to activate bundle.");
    } finally {
      setLoading(false);
    }
  };

  const handleDownloadManifest = async (bundle: ComfyUiAssetBundle) => {
    try {
      const manifest = await getComfyUiAssetBundleManifest(bundle.id);
      window.open(manifest.download_url, "_blank", "noopener,noreferrer");
    } catch (error) {
      toast.error("Failed to fetch manifest.");
    }
  };

  const handleExportAuditLogs = async () => {
    setLoading(true);
    try {
      const data = await exportComfyUiAssetAuditLogs();
      const blob = new Blob([JSON.stringify(data.items, null, 2)], { type: "application/json" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = "comfyui-asset-audit-logs.json";
      link.click();
      URL.revokeObjectURL(url);
    } catch (error) {
      toast.error("Failed to export audit logs.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold">ComfyUI Assets</h1>
        <p className="text-sm text-muted-foreground">
          Upload models/LoRAs/VAEs, assemble bundles, and promote them to staging or production.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Upload Asset</CardTitle>
          <CardDescription>Upload a model file to the central assets bucket.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-3">
          <div className="space-y-2">
            <label className="text-xs font-semibold uppercase text-muted-foreground">Workflow</label>
            <Select value={uploadWorkflowId} onValueChange={setUploadWorkflowId}>
              <SelectTrigger>
                <SelectValue placeholder="Select workflow" />
              </SelectTrigger>
              <SelectContent>
                {workflowOptions.map((wf) => (
                  <SelectItem key={wf.value} value={wf.value}>
                    {wf.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <label className="text-xs font-semibold uppercase text-muted-foreground">Asset Kind</label>
            <Select value={uploadKind} onValueChange={setUploadKind}>
              <SelectTrigger>
                <SelectValue placeholder="Select kind" />
              </SelectTrigger>
              <SelectContent>
                {ASSET_KINDS.map((kind) => (
                  <SelectItem key={kind.value} value={kind.value}>
                    {kind.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <label className="text-xs font-semibold uppercase text-muted-foreground">SHA256 (optional)</label>
            <Input value={uploadSha} onChange={(e) => setUploadSha(e.target.value)} placeholder="sha256 hash" />
          </div>
          <div className="md:col-span-3">
            <Input
              type="file"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void handleFileUpload(file);
                e.target.value = "";
              }}
              disabled={loading}
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Create Bundle</CardTitle>
          <CardDescription>Combine uploaded assets into a versioned bundle.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-3">
            <div className="space-y-2">
              <label className="text-xs font-semibold uppercase text-muted-foreground">Workflow</label>
              <Select value={bundleWorkflowId} onValueChange={setBundleWorkflowId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select workflow" />
                </SelectTrigger>
                <SelectContent>
                  {workflowOptions.map((wf) => (
                    <SelectItem key={wf.value} value={wf.value}>
                      {wf.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="md:col-span-2 space-y-2">
              <label className="text-xs font-semibold uppercase text-muted-foreground">Notes</label>
              <Textarea value={bundleNotes} onChange={(e) => setBundleNotes(e.target.value)} placeholder="Bundle notes" />
            </div>
          </div>

          <div className="space-y-2">
            <h3 className="text-sm font-semibold">Assets for selected workflow</h3>
            <div className="max-h-56 overflow-y-auto rounded-lg border border-border">
              <table className="w-full text-sm">
                <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="p-2 text-left">Select</th>
                    <th className="p-2 text-left">Kind</th>
                    <th className="p-2 text-left">Filename</th>
                    <th className="p-2 text-left">Uploaded</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredAssets.length === 0 ? (
                    <tr>
                      <td className="p-3 text-muted-foreground" colSpan={4}>
                        Select a workflow to see assets.
                      </td>
                    </tr>
                  ) : (
                    filteredAssets.map((asset) => (
                      <tr key={asset.id} className="border-t border-border">
                        <td className="p-2">
                          <input
                            type="checkbox"
                            checked={selectedAssetIds.has(asset.id)}
                            onChange={() => toggleAssetSelection(asset.id)}
                          />
                        </td>
                        <td className="p-2">{asset.kind}</td>
                        <td className="p-2">{asset.original_filename}</td>
                        <td className="p-2">{asset.uploaded_at ? new Date(asset.uploaded_at).toLocaleString() : "—"}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>

          <Button onClick={handleCreateBundle} disabled={loading}>
            Create Bundle
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Bundles</CardTitle>
          <CardDescription>Activate bundles for staging or production.</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="p-2 text-left">Workflow</th>
                  <th className="p-2 text-left">Bundle ID</th>
                  <th className="p-2 text-left">Notes</th>
                  <th className="p-2 text-left">Activate For</th>
                  <th className="p-2 text-left">Active (Staging)</th>
                  <th className="p-2 text-left">Active (Prod)</th>
                  <th className="p-2 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                {bundles.length === 0 ? (
                  <tr>
                    <td className="p-3 text-muted-foreground" colSpan={6}>
                      No bundles created yet.
                    </td>
                  </tr>
                ) : (
                  bundles.map((bundle) => (
                    <tr key={bundle.id} className="border-t border-border">
                      <td className="p-2">{bundle.workflow?.slug ?? bundle.workflow_id}</td>
                      <td className="p-2 font-mono text-xs">{bundle.bundle_id}</td>
                      <td className="p-2">{bundle.notes || "—"}</td>
                      <td className="p-2">
                        <Select
                          value={
                            bundleTargetWorkflows[bundle.id]
                              ?? String(bundle.workflow?.id ?? bundle.workflow_id ?? "")
                          }
                          onValueChange={(value) =>
                            setBundleTargetWorkflows((prev) => ({ ...prev, [bundle.id]: value }))
                          }
                        >
                          <SelectTrigger className="min-w-[220px]">
                            <SelectValue placeholder="Select workflow" />
                          </SelectTrigger>
                          <SelectContent>
                            {workflowOptions.map((wf) => (
                              <SelectItem key={wf.value} value={wf.value}>
                                {wf.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </td>
                      <td className="p-2">
                        {bundle.active_staging_at ? new Date(bundle.active_staging_at).toLocaleString() : "—"}
                      </td>
                      <td className="p-2">
                        {bundle.active_production_at ? new Date(bundle.active_production_at).toLocaleString() : "—"}
                      </td>
                      <td className="p-2 flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" onClick={() => handleActivateBundle(bundle, "staging")}>
                          Activate Staging
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => handleActivateBundle(bundle, "production")}>
                          Activate Prod
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => handleDownloadManifest(bundle)}>
                          Manifest
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Active Bundles</CardTitle>
          <CardDescription>Current bundle mapped to each workflow + stage.</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="p-2 text-left">Workflow</th>
                  <th className="p-2 text-left">Stage</th>
                  <th className="p-2 text-left">Bundle ID</th>
                  <th className="p-2 text-left">S3 Prefix</th>
                  <th className="p-2 text-left">Activated</th>
                </tr>
              </thead>
              <tbody>
                {activeBundles.length === 0 ? (
                  <tr>
                    <td className="p-3 text-muted-foreground" colSpan={5}>
                      No active bundle mappings yet.
                    </td>
                  </tr>
                ) : (
                  activeBundles.map((active) => (
                    <tr key={active.id} className="border-t border-border">
                      <td className="p-2">{active.workflow?.slug ?? active.workflow_id}</td>
                      <td className="p-2">{active.stage}</td>
                      <td className="p-2 font-mono text-xs">{active.bundle?.bundle_id ?? active.bundle_id}</td>
                      <td className="p-2 font-mono text-xs">{active.bundle_s3_prefix}</td>
                      <td className="p-2">
                        {active.activated_at ? new Date(active.activated_at).toLocaleString() : "—"}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Cleanup Candidates</CardTitle>
          <CardDescription>Bundles not active anywhere (consider deleting from S3).</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="p-2 text-left">Workflow</th>
                  <th className="p-2 text-left">Bundle ID</th>
                  <th className="p-2 text-left">S3 Prefix</th>
                  <th className="p-2 text-left">Reason</th>
                  <th className="p-2 text-left">Delete Command</th>
                </tr>
              </thead>
              <tbody>
                {cleanupCandidates.length === 0 ? (
                  <tr>
                    <td className="p-3 text-muted-foreground" colSpan={5}>
                      No cleanup candidates found.
                    </td>
                  </tr>
                ) : (
                  cleanupCandidates.map((candidate) => (
                    <tr key={candidate.id} className="border-t border-border">
                      <td className="p-2">
                        {candidate.workflow ? `${candidate.workflow.name} (${candidate.workflow.slug})` : "—"}
                      </td>
                      <td className="p-2 font-mono text-xs">{candidate.bundle_id}</td>
                      <td className="p-2 font-mono text-xs">{candidate.s3_prefix}</td>
                      <td className="p-2">{candidate.reason}</td>
                      <td className="p-2 font-mono text-xs">
                        aws s3 rm s3://&lt;MODELS_BUCKET&gt;/{candidate.s3_prefix}/ --recursive
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Asset Audit Logs</CardTitle>
          <CardDescription>Download or review asset change history.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <Button size="sm" variant="outline" onClick={handleExportAuditLogs} disabled={loading}>
            Export Logs (JSON)
          </Button>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="p-2 text-left">Event</th>
                  <th className="p-2 text-left">Notes</th>
                  <th className="p-2 text-left">Created</th>
                  <th className="p-2 text-left">Artifact</th>
                </tr>
              </thead>
              <tbody>
                {auditLogs.length === 0 ? (
                  <tr>
                    <td className="p-3 text-muted-foreground" colSpan={4}>
                      No audit logs yet.
                    </td>
                  </tr>
                ) : (
                  auditLogs.map((log) => (
                    <tr key={log.id} className="border-t border-border">
                      <td className="p-2">{log.event}</td>
                      <td className="p-2">{log.notes || "—"}</td>
                      <td className="p-2">{log.created_at ? new Date(log.created_at).toLocaleString() : "—"}</td>
                      <td className="p-2">
                        {log.artifact_download_url ? (
                          <a
                            className="text-primary underline"
                            href={log.artifact_download_url}
                            target="_blank"
                            rel="noreferrer"
                          >
                            Download
                          </a>
                        ) : (
                          "—"
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
