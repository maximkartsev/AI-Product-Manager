"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { type ColumnDef } from "@tanstack/react-table";
import { DataTableView, type DataTableFormField } from "@/components/ui/DataTable";
import { EntityFormSheet } from "@/components/ui/EntityFormSheet";
import { AdminDetailSheet, AdminDetailSection } from "@/components/admin/AdminDetailSheet";
import { useDataTable } from "@/hooks/useDataTable";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { toast } from "sonner";
import type { FilterValue } from "@/components/ui/SmartFilters";
import {
  activateComfyUiFleetBundle,
  createComfyUiFleet,
  getComfyUiAssetBundles,
  getComfyUiFleets,
  updateComfyUiFleet,
  type ComfyUiAssetBundle,
  type ComfyUiFleetCreateRequest,
  type ComfyUiGpuFleet,
} from "@/lib/api";

type FleetFormState = {
  stage: "staging" | "production";
  slug: string;
  name: string;
  instance_types: string;
  max_size: string;
  warmup_seconds: string;
  backlog_target: string;
  scale_to_zero_minutes: string;
  ami_ssm_parameter: string;
};

const initialFormState: FleetFormState = {
  stage: "staging",
  slug: "",
  name: "",
  instance_types: "g4dn.xlarge,g5.xlarge",
  max_size: "10",
  warmup_seconds: "300",
  backlog_target: "2",
  scale_to_zero_minutes: "15",
  ami_ssm_parameter: "",
};

function parseNumber(value: string): number | null {
  const num = Number(value);
  return Number.isFinite(num) ? num : null;
}

export default function AdminComfyUiFleetsPage() {
  const [showPanel, setShowPanel] = useState(false);
  const [editingItem, setEditingItem] = useState<{ id: number; data: Record<string, any> } | null>(null);
  const [detailFleet, setDetailFleet] = useState<ComfyUiGpuFleet | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [bundleOptions, setBundleOptions] = useState<ComfyUiAssetBundle[]>([]);
  const [selectedBundleId, setSelectedBundleId] = useState<string>("");
  const [activateNotes, setActivateNotes] = useState("");
  const [savingDetail, setSavingDetail] = useState(false);

  const [detailName, setDetailName] = useState("");
  const [detailInstanceTypes, setDetailInstanceTypes] = useState("");
  const [detailMaxSize, setDetailMaxSize] = useState("");
  const [detailWarmup, setDetailWarmup] = useState("");
  const [detailBacklogTarget, setDetailBacklogTarget] = useState("");
  const [detailScaleToZero, setDetailScaleToZero] = useState("");
  const [detailAmiParam, setDetailAmiParam] = useState("");

  useEffect(() => {
    getComfyUiAssetBundles({ perPage: 200 }).then((data) => setBundleOptions(data.items ?? [])).catch(() => {});
  }, []);

  const openDetail = useCallback((fleet: ComfyUiGpuFleet) => {
    setDetailFleet(fleet);
    setDetailName(fleet.name || "");
    setDetailInstanceTypes((fleet.instance_types || []).join(", "));
    setDetailMaxSize(String(fleet.max_size ?? 0));
    setDetailWarmup(fleet.warmup_seconds !== null && fleet.warmup_seconds !== undefined ? String(fleet.warmup_seconds) : "");
    setDetailBacklogTarget(fleet.backlog_target !== null && fleet.backlog_target !== undefined ? String(fleet.backlog_target) : "");
    setDetailScaleToZero(
      fleet.scale_to_zero_minutes !== null && fleet.scale_to_zero_minutes !== undefined
        ? String(fleet.scale_to_zero_minutes)
        : "",
    );
    setDetailAmiParam(fleet.ami_ssm_parameter || "");
    setSelectedBundleId(fleet.active_bundle_id ? String(fleet.active_bundle_id) : "");
    setActivateNotes("");
    setDetailOpen(true);
  }, []);

  const openDetailRef = useRef(openDetail);
  openDetailRef.current = openDetail;

  const actionsColumn = useMemo<ColumnDef<ComfyUiGpuFleet>[]>(
    () => [
      {
        id: "_actions",
        header: "Actions",
        enableSorting: false,
        enableHiding: false,
        enableResizing: false,
        size: 140,
        minSize: 140,
        cell: ({ row }: { row: { original: ComfyUiGpuFleet } }) => (
          <Button
            variant="outline"
            size="sm"
            className="text-sm px-3"
            onClick={(event) => {
              event.stopPropagation();
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

  const state = useDataTable<ComfyUiGpuFleet>({
    entityClass: "ComfyUiGpuFleet",
    entityName: "Fleet",
    storageKey: "admin-comfyui-fleets-table-columns",
    settingsKey: "admin-comfyui-fleets",
    list: async (params: { page: number; perPage: number; search?: string; filters?: FilterValue[]; order?: string }) => {
      const data = await getComfyUiFleets({
        page: params.page,
        perPage: params.perPage,
        search: params.search,
        filters: params.filters,
        order: params.order,
      });
      return {
        items: data.items,
        totalItems: data.totalItems,
        totalPages: data.totalPages,
      };
    },
    getItemId: (item) => item.id,
    renderCellValue: (fleet, columnKey) => {
      if (columnKey === "stage") return <span className="text-foreground">{fleet.stage}</span>;
      if (columnKey === "slug") return <span className="text-foreground font-mono text-xs">{fleet.slug}</span>;
      if (columnKey === "name") return <span className="text-foreground font-medium">{fleet.name}</span>;
      if (columnKey === "instance_types") {
        return <span className="text-muted-foreground">{(fleet.instance_types || []).join(", ") || "-"}</span>;
      }
      if (columnKey === "active_bundle_id") {
        return (
          <span className="text-muted-foreground">
            {fleet.active_bundle?.name || fleet.active_bundle?.bundle_id || fleet.active_bundle_s3_prefix || "-"}
          </span>
        );
      }
      const value = fleet[columnKey as keyof ComfyUiGpuFleet];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
    extraColumns: actionsColumn,
  });

  const formFields: DataTableFormField[] = [
    {
      key: "stage",
      label: "Stage",
      type: "select",
      required: true,
      options: [
        { value: "staging", label: "staging" },
        { value: "production", label: "production" },
      ],
      render: ({ value, onChange }) => (
        <Select value={value} onValueChange={onChange} disabled={Boolean(editingItem)}>
          <SelectTrigger>
            <SelectValue placeholder="Select stage" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="staging">staging</SelectItem>
            <SelectItem value="production">production</SelectItem>
          </SelectContent>
        </Select>
      ),
    },
    {
      key: "slug",
      label: "Slug",
      type: "text",
      required: true,
      placeholder: "gpu-default",
      render: ({ value, onChange }) => (
        <Input id="slug" value={value} onChange={(e) => onChange(e.target.value)} disabled={Boolean(editingItem)} />
      ),
    },
    { key: "name", label: "Name", type: "text", required: true, placeholder: "Default GPU Fleet" },
    {
      key: "instance_types",
      label: "Instance Types (comma separated)",
      type: "text",
      fullWidth: true,
      placeholder: "g4dn.xlarge,g5.xlarge",
    },
    { key: "max_size", label: "Max Size", type: "number", required: true, placeholder: "10" },
    { key: "warmup_seconds", label: "Warmup (seconds)", type: "number", placeholder: "300" },
    { key: "backlog_target", label: "Backlog Target", type: "number", placeholder: "2" },
    { key: "scale_to_zero_minutes", label: "Scale to Zero (minutes)", type: "number", placeholder: "15" },
    {
      key: "ami_ssm_parameter",
      label: "AMI SSM Parameter (optional)",
      type: "text",
      fullWidth: true,
      placeholder: "/bp/ami/fleets/staging/gpu-default",
    },
  ];

  const validateForm = (formState: Record<string, any>): string | null => {
    if (!formState.stage) return "Stage is required.";
    if (!formState.slug?.trim()) return "Slug is required.";
    if (!formState.name?.trim()) return "Name is required.";
    if (parseNumber(String(formState.max_size || "")) === null) return "Max size must be a number.";
    return null;
  };

  const getCreatePayload = (formState: Record<string, any>): ComfyUiFleetCreateRequest => {
    const instanceTypes = String(formState.instance_types || "")
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean);
    return {
      stage: formState.stage,
      slug: String(formState.slug || "").trim(),
      name: String(formState.name || "").trim(),
      instance_types: instanceTypes.length > 0 ? instanceTypes : undefined,
      max_size: parseNumber(String(formState.max_size || "")) ?? 0,
      warmup_seconds: parseNumber(String(formState.warmup_seconds || "")) ?? undefined,
      backlog_target: parseNumber(String(formState.backlog_target || "")) ?? undefined,
      scale_to_zero_minutes: parseNumber(String(formState.scale_to_zero_minutes || "")) ?? undefined,
      ami_ssm_parameter: String(formState.ami_ssm_parameter || "").trim() || undefined,
    };
  };

  const handleUpdateDetail = async () => {
    if (!detailFleet) return;
    const instanceTypes = detailInstanceTypes
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean);
    setSavingDetail(true);
    try {
      const updated = await updateComfyUiFleet(detailFleet.id, {
        name: detailName.trim(),
        instance_types: instanceTypes.length > 0 ? instanceTypes : null,
        max_size: parseNumber(detailMaxSize) ?? detailFleet.max_size,
        warmup_seconds: parseNumber(detailWarmup) ?? null,
        backlog_target: parseNumber(detailBacklogTarget) ?? null,
        scale_to_zero_minutes: parseNumber(detailScaleToZero) ?? null,
        ami_ssm_parameter: detailAmiParam.trim() || null,
      });
      setDetailFleet(updated);
      toast.success("Fleet updated.");
      state.loadItems();
    } catch {
      toast.error("Failed to update fleet.");
    } finally {
      setSavingDetail(false);
    }
  };

  const handleActivateBundle = async () => {
    if (!detailFleet || !selectedBundleId) {
      toast.error("Select a bundle to activate.");
      return;
    }
    setSavingDetail(true);
    try {
      const updated = await activateComfyUiFleetBundle(detailFleet.id, {
        bundle_id: Number(selectedBundleId),
        notes: activateNotes.trim() || undefined,
      });
      setDetailFleet(updated);
      toast.success("Bundle activated.");
      setActivateNotes("");
      state.loadItems();
    } catch {
      toast.error("Failed to activate bundle.");
    } finally {
      setSavingDetail(false);
    }
  };

  const renderMobileRowActions = (item: ComfyUiGpuFleet) => (
    <Button
      variant="outline"
      size="sm"
      className="text-xs flex-1"
      onClick={(event) => {
        event.stopPropagation();
        openDetail(item);
      }}
    >
      Manage
    </Button>
  );

  const derivedOps = detailFleet
    ? {
        stage: detailFleet.stage,
        slug: detailFleet.slug,
        activeBundleParam: `/bp/${detailFleet.stage}/fleets/${detailFleet.slug}/active_bundle`,
        amiParam: detailFleet.ami_ssm_parameter || `/bp/ami/fleets/${detailFleet.stage}/${detailFleet.slug}`,
        asgName: `asg-${detailFleet.stage}-${detailFleet.slug}`,
        logGroup: `/gpu-workers/${detailFleet.slug}`,
        refreshCommand: `aws autoscaling start-instance-refresh --auto-scaling-group-name "asg-${detailFleet.stage}-${detailFleet.slug}" --preferences '{"MinHealthyPercentage":90,"InstanceWarmup":300}'`,
      }
    : null;

  return (
    <>
      <DataTableView
        state={state}
        options={{
          entityClass: "ComfyUiGpuFleet",
          entityName: "Fleet",
          title: "ComfyUI Fleets",
          description: "Manage GPU fleet configuration, activation, and ops pointers.",
        }}
        renderRowActions={renderMobileRowActions}
        toolbarActions={
          <Button
            className="flex-1 sm:flex-none"
            onClick={() => {
              setEditingItem(null);
              setShowPanel(true);
            }}
          >
            Create Fleet
          </Button>
        }
      />

      <EntityFormSheet<ComfyUiFleetCreateRequest, never>
        entityName="Fleet"
        formFields={formFields}
        initialFormState={initialFormState}
        formSchema={undefined}
        availableColumns={state.availableColumns}
        fkOptions={state.fkOptions}
        fkLoading={state.fkLoading}
        open={showPanel}
        onOpenChange={setShowPanel}
        editingItem={editingItem}
        getFormData={getCreatePayload}
        validateForm={validateForm}
        onCreate={(payload) => createComfyUiFleet(payload)}
        onUpdate={undefined}
        onSaved={() => {
          setShowPanel(false);
          state.loadItems();
        }}
      />

      <AdminDetailSheet
        open={detailOpen}
        onOpenChange={setDetailOpen}
        title={detailFleet ? `Fleet: ${detailFleet.name}` : "Fleet"}
        description={detailFleet ? `${detailFleet.slug} • ${detailFleet.stage}` : undefined}
      >
        {detailFleet && (
          <>
            <AdminDetailSection title="Fleet Details">
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Name</label>
                  <Input value={detailName} onChange={(e) => setDetailName(e.target.value)} />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Instance Types</label>
                  <Input value={detailInstanceTypes} onChange={(e) => setDetailInstanceTypes(e.target.value)} />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Max Size</label>
                  <Input value={detailMaxSize} onChange={(e) => setDetailMaxSize(e.target.value)} />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Warmup (seconds)</label>
                  <Input value={detailWarmup} onChange={(e) => setDetailWarmup(e.target.value)} />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Backlog Target</label>
                  <Input value={detailBacklogTarget} onChange={(e) => setDetailBacklogTarget(e.target.value)} />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Scale to Zero (minutes)</label>
                  <Input value={detailScaleToZero} onChange={(e) => setDetailScaleToZero(e.target.value)} />
                </div>
                <div className="md:col-span-2 space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">AMI SSM Parameter</label>
                  <Input value={detailAmiParam} onChange={(e) => setDetailAmiParam(e.target.value)} />
                </div>
                <div className="md:col-span-2">
                  <Button onClick={handleUpdateDetail} disabled={savingDetail}>
                    Save Fleet
                  </Button>
                </div>
              </div>
            </AdminDetailSection>

            <AdminDetailSection title="Activate Bundle">
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Bundle</label>
                  <Select value={selectedBundleId} onValueChange={setSelectedBundleId}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select bundle" />
                    </SelectTrigger>
                    <SelectContent>
                      {bundleOptions.map((bundle) => (
                        <SelectItem key={bundle.id} value={String(bundle.id)}>
                          {bundle.name || bundle.bundle_id}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="md:col-span-2 space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Notes</label>
                  <Textarea value={activateNotes} onChange={(e) => setActivateNotes(e.target.value)} />
                </div>
                <div>
                  <Button onClick={handleActivateBundle} disabled={savingDetail || !selectedBundleId}>
                    Activate Bundle
                  </Button>
                </div>
              </div>
            </AdminDetailSection>

            {derivedOps && (
              <AdminDetailSection title="Operational Pointers">
                <div className="space-y-3 text-sm">
                  <div>
                    <p className="text-xs uppercase text-muted-foreground">Active Bundle Param</p>
                    <Input value={derivedOps.activeBundleParam} disabled />
                  </div>
                  <div>
                    <p className="text-xs uppercase text-muted-foreground">AMI SSM Parameter</p>
                    <Input value={derivedOps.amiParam} disabled />
                  </div>
                  <div>
                    <p className="text-xs uppercase text-muted-foreground">ASG Name</p>
                    <Input value={derivedOps.asgName} disabled />
                  </div>
                  <div>
                    <p className="text-xs uppercase text-muted-foreground">Log Group</p>
                    <Input value={derivedOps.logGroup} disabled />
                  </div>
                  <div>
                    <p className="text-xs uppercase text-muted-foreground">Instance Refresh Command</p>
                    <Input value={derivedOps.refreshCommand} disabled />
                  </div>
                </div>
              </AdminDetailSection>
            )}
          </>
        )}
      </AdminDetailSheet>
    </>
  );
}
