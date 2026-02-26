"use client";

import { useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  createStudioDevNode,
  getStudioDevNodes,
  updateStudioDevNode,
  type StudioDevNode,
} from "@/lib/api";
import { extractErrorMessage } from "@/lib/apiErrors";

type Stage = "dev" | "test" | "staging" | "production";
type Lifecycle = "on-demand" | "spot";
type NodeStatus = "starting" | "ready" | "stopping" | "stopped" | "error";

export default function StudioDevNodesPage() {
  const [nodes, setNodes] = useState<StudioDevNode[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [search, setSearch] = useState("");
  const [stageFilter, setStageFilter] = useState<"" | Stage>("");
  const [statusFilter, setStatusFilter] = useState<"" | NodeStatus>("");

  const [newName, setNewName] = useState("");
  const [newInstanceType, setNewInstanceType] = useState("");
  const [newStage, setNewStage] = useState<Stage>("dev");
  const [newLifecycle, setNewLifecycle] = useState<Lifecycle>("on-demand");
  const [newStatus, setNewStatus] = useState<NodeStatus>("stopped");
  const [newPublicEndpoint, setNewPublicEndpoint] = useState("");
  const [newPrivateEndpoint, setNewPrivateEndpoint] = useState("");

  const [editingNodeId, setEditingNodeId] = useState<number | null>(null);
  const [editingStatus, setEditingStatus] = useState<NodeStatus>("stopped");
  const [editingPublicEndpoint, setEditingPublicEndpoint] = useState("");
  const [editingPrivateEndpoint, setEditingPrivateEndpoint] = useState("");

  const hasFilters = useMemo(() => Boolean(search || stageFilter || statusFilter), [search, stageFilter, statusFilter]);

  async function loadNodes(): Promise<void> {
    setLoading(true);
    setErrorMessage(null);
    try {
      const response = await getStudioDevNodes({
        search: search || undefined,
        stage: stageFilter || undefined,
        status: statusFilter || undefined,
      });
      setNodes(response.items ?? []);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to load dev nodes."));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadNodes();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleCreateNode(): Promise<void> {
    if (!newName.trim()) {
      setErrorMessage("Node name is required.");
      return;
    }

    setSaving(true);
    setErrorMessage(null);
    try {
      const created = await createStudioDevNode({
        name: newName.trim(),
        instance_type: newInstanceType.trim() || null,
        stage: newStage,
        lifecycle: newLifecycle,
        status: newStatus,
        public_endpoint: newPublicEndpoint.trim() || null,
        private_endpoint: newPrivateEndpoint.trim() || null,
      });
      setNodes((prev) => [created, ...prev]);
      setNewName("");
      setNewInstanceType("");
      setNewPublicEndpoint("");
      setNewPrivateEndpoint("");
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to create dev node."));
    } finally {
      setSaving(false);
    }
  }

  function beginEdit(node: StudioDevNode): void {
    setEditingNodeId(node.id);
    setEditingStatus((node.status as NodeStatus) || "stopped");
    setEditingPublicEndpoint(node.public_endpoint || "");
    setEditingPrivateEndpoint(node.private_endpoint || "");
  }

  async function handleSaveNode(nodeId: number): Promise<void> {
    setSaving(true);
    setErrorMessage(null);
    try {
      const updated = await updateStudioDevNode(nodeId, {
        status: editingStatus,
        public_endpoint: editingPublicEndpoint.trim() || null,
        private_endpoint: editingPrivateEndpoint.trim() || null,
      });
      setNodes((prev) => prev.map((item) => (item.id === nodeId ? updated : item)));
      setEditingNodeId(null);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to update dev node."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold tracking-tight">Studio Dev Nodes</h1>
        <p className="text-sm text-muted-foreground">
          Manage interactive-run DevNode endpoints and verify each node has an attached execution environment.
        </p>
      </header>

      {errorMessage ? (
        <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
          {errorMessage}
        </div>
      ) : null}

      <section className="space-y-3 rounded-lg border border-border/60 bg-card p-4">
        <h2 className="text-base font-semibold">Create Dev Node</h2>
        <div className="grid gap-3 md:grid-cols-3">
          <Input value={newName} onChange={(event) => setNewName(event.target.value)} placeholder="Node name" />
          <Input
            value={newInstanceType}
            onChange={(event) => setNewInstanceType(event.target.value)}
            placeholder="Instance type (g5.xlarge)"
          />
          <select
            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
            value={newStage}
            onChange={(event) => setNewStage(event.target.value as Stage)}
          >
            <option value="dev">dev</option>
            <option value="test">test</option>
            <option value="staging">staging</option>
            <option value="production">production</option>
          </select>
          <select
            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
            value={newLifecycle}
            onChange={(event) => setNewLifecycle(event.target.value as Lifecycle)}
          >
            <option value="on-demand">on-demand</option>
            <option value="spot">spot</option>
          </select>
          <select
            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
            value={newStatus}
            onChange={(event) => setNewStatus(event.target.value as NodeStatus)}
          >
            <option value="starting">starting</option>
            <option value="ready">ready</option>
            <option value="stopping">stopping</option>
            <option value="stopped">stopped</option>
            <option value="error">error</option>
          </select>
          <Input
            value={newPublicEndpoint}
            onChange={(event) => setNewPublicEndpoint(event.target.value)}
            placeholder="Public endpoint (http://node:8188)"
          />
          <Input
            value={newPrivateEndpoint}
            onChange={(event) => setNewPrivateEndpoint(event.target.value)}
            placeholder="Private endpoint"
          />
        </div>
        <div className="flex justify-end">
          <Button type="button" onClick={() => void handleCreateNode()} disabled={saving}>
            {saving ? "Saving..." : "Create Dev Node"}
          </Button>
        </div>
      </section>

      <section className="space-y-3 rounded-lg border border-border/60 bg-card p-4">
        <div className="flex flex-wrap items-center gap-2">
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search by name / instance / AWS id"
            className="max-w-sm"
          />
          <select
            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
            value={stageFilter}
            onChange={(event) => setStageFilter(event.target.value as "" | Stage)}
          >
            <option value="">All stages</option>
            <option value="dev">dev</option>
            <option value="test">test</option>
            <option value="staging">staging</option>
            <option value="production">production</option>
          </select>
          <select
            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
            value={statusFilter}
            onChange={(event) => setStatusFilter(event.target.value as "" | NodeStatus)}
          >
            <option value="">All statuses</option>
            <option value="starting">starting</option>
            <option value="ready">ready</option>
            <option value="stopping">stopping</option>
            <option value="stopped">stopped</option>
            <option value="error">error</option>
          </select>
          <Button type="button" variant="outline" onClick={() => void loadNodes()} disabled={loading}>
            {loading ? "Loading..." : "Refresh"}
          </Button>
          {hasFilters ? (
            <Button
              type="button"
              variant="ghost"
              onClick={() => {
                setSearch("");
                setStageFilter("");
                setStatusFilter("");
              }}
            >
              Clear filters
            </Button>
          ) : null}
        </div>

        <div className="space-y-3">
          {nodes.map((node) => {
            const isEditing = editingNodeId === node.id;
            const execution = node.execution_environment;

            return (
              <article key={node.id} className="rounded-md border border-border/60 bg-muted/10 p-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h3 className="text-sm font-semibold">
                      {node.name} <span className="text-muted-foreground">#{node.id}</span>
                    </h3>
                    <p className="text-xs text-muted-foreground">
                      {node.instance_type || "instance n/a"} · stage {node.stage || "n/a"} · lifecycle {node.lifecycle || "n/a"}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Env: {execution?.id ? `#${execution.id}` : "none"} · active {execution?.is_active ? "yes" : "no"} · status{" "}
                      {node.status || "n/a"}
                    </p>
                  </div>
                  {!isEditing ? (
                    <Button type="button" variant="outline" size="sm" onClick={() => beginEdit(node)}>
                      Edit
                    </Button>
                  ) : null}
                </div>

                {isEditing ? (
                  <div className="mt-3 grid gap-2 md:grid-cols-3">
                    <select
                      className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                      value={editingStatus}
                      onChange={(event) => setEditingStatus(event.target.value as NodeStatus)}
                    >
                      <option value="starting">starting</option>
                      <option value="ready">ready</option>
                      <option value="stopping">stopping</option>
                      <option value="stopped">stopped</option>
                      <option value="error">error</option>
                    </select>
                    <Input
                      value={editingPublicEndpoint}
                      onChange={(event) => setEditingPublicEndpoint(event.target.value)}
                      placeholder="Public endpoint"
                    />
                    <Input
                      value={editingPrivateEndpoint}
                      onChange={(event) => setEditingPrivateEndpoint(event.target.value)}
                      placeholder="Private endpoint"
                    />
                    <div className="md:col-span-3 flex justify-end gap-2">
                      <Button type="button" variant="ghost" onClick={() => setEditingNodeId(null)}>
                        Cancel
                      </Button>
                      <Button type="button" onClick={() => void handleSaveNode(node.id)} disabled={saving}>
                        {saving ? "Saving..." : "Save"}
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div className="mt-2 text-xs text-muted-foreground">
                    <p>Public endpoint: {node.public_endpoint || "—"}</p>
                    <p>Private endpoint: {node.private_endpoint || "—"}</p>
                  </div>
                )}
              </article>
            );
          })}

          {!loading && nodes.length === 0 ? (
            <p className="rounded-md border border-dashed border-border/80 p-4 text-sm text-muted-foreground">
              No dev nodes found. Create one to start interactive DevNode runs.
            </p>
          ) : null}
        </div>
      </section>
    </div>
  );
}

