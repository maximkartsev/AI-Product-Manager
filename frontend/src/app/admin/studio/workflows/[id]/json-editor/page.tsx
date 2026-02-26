"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import {
  createStudioWorkflowRevision,
  getStudioWorkflowJson,
  getStudioWorkflowRevisions,
  updateStudioWorkflowJson,
  type StudioWorkflowRevision,
} from "@/lib/api";
import { extractErrorMessage } from "@/lib/apiErrors";
import { formatWorkflowJson, parseWorkflowJsonInput } from "@/lib/studio/workflowJson";

export default function StudioWorkflowJsonEditorPage() {
  const params = useParams<{ id: string }>();
  const rawId = params?.id;
  const workflowId = useMemo(() => {
    const value = Array.isArray(rawId) ? rawId[0] : rawId;
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }, [rawId]);

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [creatingSnapshot, setCreatingSnapshot] = useState(false);
  const [jsonText, setJsonText] = useState("{}");
  const [workflowPath, setWorkflowPath] = useState<string | null>(null);
  const [revisions, setRevisions] = useState<StudioWorkflowRevision[]>([]);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [validationMessage, setValidationMessage] = useState<string | null>(null);
  const [saveSuccessMessage, setSaveSuccessMessage] = useState<string | null>(null);

  async function loadData(id: number): Promise<void> {
    setLoading(true);
    setErrorMessage(null);
    setSaveSuccessMessage(null);
    try {
      const [workflowJsonResponse, revisionsResponse] = await Promise.all([
        getStudioWorkflowJson(id),
        getStudioWorkflowRevisions(id),
      ]);
      setWorkflowPath(workflowJsonResponse.comfyui_workflow_path || null);
      setJsonText(formatWorkflowJson(workflowJsonResponse.workflow_json || {}));
      setRevisions(revisionsResponse.items ?? []);
      setValidationMessage(null);
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to load workflow JSON editor data."));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (!workflowId) {
      setErrorMessage("Invalid workflow id.");
      return;
    }
    void loadData(workflowId);
  }, [workflowId]);

  function validateJson(): boolean {
    const parsed = parseWorkflowJsonInput(jsonText);
    if (!parsed.ok) {
      setValidationMessage(parsed.error);
      return false;
    }
    setValidationMessage("JSON is valid.");
    return true;
  }

  async function handleSave(): Promise<void> {
    if (!workflowId) return;
    const parsed = parseWorkflowJsonInput(jsonText);
    if (!parsed.ok) {
      setValidationMessage(parsed.error);
      return;
    }

    setSaving(true);
    setErrorMessage(null);
    setSaveSuccessMessage(null);
    try {
      const updated = await updateStudioWorkflowJson(workflowId, parsed.value);
      setWorkflowPath(updated.comfyui_workflow_path || null);
      setJsonText(formatWorkflowJson(updated.workflow_json || {}));
      const revisionsResponse = await getStudioWorkflowRevisions(workflowId);
      setRevisions(revisionsResponse.items ?? []);
      setValidationMessage("JSON is valid.");
      setSaveSuccessMessage("Workflow JSON saved and new revision created.");
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to save workflow JSON."));
    } finally {
      setSaving(false);
    }
  }

  async function handleCreateSnapshot(): Promise<void> {
    if (!workflowId) return;
    setCreatingSnapshot(true);
    setErrorMessage(null);
    setSaveSuccessMessage(null);
    try {
      await createStudioWorkflowRevision(workflowId);
      const revisionsResponse = await getStudioWorkflowRevisions(workflowId);
      setRevisions(revisionsResponse.items ?? []);
      setSaveSuccessMessage("Workflow snapshot created.");
    } catch (error: unknown) {
      setErrorMessage(extractErrorMessage(error, "Failed to create workflow snapshot."));
    } finally {
      setCreatingSnapshot(false);
    }
  }

  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold tracking-tight">Workflow JSON Editor</h1>
        <p className="text-sm text-muted-foreground">
          Edit workflow JSON with local validation and persist new revisions through Studio APIs.
        </p>
      </header>

      <section className="space-y-4 rounded-lg border border-border/60 bg-card p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="space-y-1 text-xs text-muted-foreground">
            <p>Workflow id: {workflowId ?? "invalid"}</p>
            <p>Current path: {workflowPath || "-"}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Link href="/admin/workflows" className="inline-flex items-center rounded-md border px-3 py-1.5 text-xs">
              Open Workflows
            </Link>
            <Button type="button" variant="outline" onClick={() => void validateJson()}>
              Validate JSON
            </Button>
            <Button type="button" variant="outline" onClick={() => void handleCreateSnapshot()} disabled={creatingSnapshot}>
              {creatingSnapshot ? "Creating..." : "Create Snapshot"}
            </Button>
            <Button type="button" onClick={() => void handleSave()} disabled={saving || loading}>
              {saving ? "Saving..." : "Save JSON"}
            </Button>
          </div>
        </div>

        <Textarea
          value={jsonText}
          onChange={(event) => {
            setJsonText(event.target.value);
            setValidationMessage(null);
            setSaveSuccessMessage(null);
          }}
          rows={20}
          className="font-mono text-xs"
          disabled={loading}
        />

        {validationMessage ? (
          <div
            className={[
              "rounded-md px-3 py-2 text-sm",
              validationMessage === "JSON is valid."
                ? "border border-emerald-500/40 bg-emerald-500/10 text-emerald-100"
                : "border border-amber-500/40 bg-amber-500/10 text-amber-100",
            ].join(" ")}
          >
            {validationMessage}
          </div>
        ) : null}

        {saveSuccessMessage ? (
          <div className="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-100">
            {saveSuccessMessage}
          </div>
        ) : null}

        {errorMessage ? (
          <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-200">
            {errorMessage}
          </div>
        ) : null}
      </section>

      <section className="space-y-3 rounded-lg border border-border/60 bg-card p-4">
        <h2 className="text-base font-semibold">Workflow revisions</h2>
        {revisions.length === 0 ? (
          <p className="text-sm text-muted-foreground">No revisions yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-left text-xs">
              <thead className="text-muted-foreground">
                <tr>
                  <th className="px-2 py-1">Revision</th>
                  <th className="px-2 py-1">Path</th>
                  <th className="px-2 py-1">Created by</th>
                  <th className="px-2 py-1">Created at</th>
                </tr>
              </thead>
              <tbody>
                {revisions.map((revision) => (
                  <tr key={revision.id} className="border-t border-border/50">
                    <td className="px-2 py-1">#{revision.id}</td>
                    <td className="px-2 py-1 break-all">{revision.comfyui_workflow_path || "-"}</td>
                    <td className="px-2 py-1">{revision.created_by_user_id ?? "-"}</td>
                    <td className="px-2 py-1">{revision.created_at || "-"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

