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
import { extractErrorMessage } from "@/lib/apiErrors";
import {
  activateComfyUiFleetBundle,
  createComfyUiFleet,
  getComfyUiAssetBundles,
  getEconomicsSettings,
  getComfyUiFleets,
  getComfyUiFleetTemplates,
  updateComfyUiFleet,
  type ComfyUiAssetBundle,
  type ComfyUiFleetCreateRequest,
  type ComfyUiFleetTemplate,
  type ComfyUiGpuFleet,
  type EconomicsSettings,
} from "@/lib/api";

type FleetFormState = {
  stage: "staging" | "production";
  slug: string;
  name: string;
  template_slug: string;
  instance_type: string;
};

type InstanceTypeUiInfo = {
  readonly gpu: string;
  readonly vram: string;
};

const INSTANCE_TYPE_UI_INFO: Record<string, InstanceTypeUiInfo> = {
  "g4dn.xlarge": { gpu: "T4", vram: "16GB" },
  "g5.xlarge": { gpu: "A10G", vram: "24GB" },
  "g6e.2xlarge": { gpu: "L40S", vram: "48GB" },
  "p5.48xlarge": { gpu: "8× H100", vram: "640GB" },
};

function formatInstanceTypeLabel(instanceType: string, rate?: number | null): string {
  const info = INSTANCE_TYPE_UI_INFO[instanceType];
  if (!info) return instanceType;
  const normalizedRate = typeof rate === "number" && Number.isFinite(rate) ? rate : null;
  const priceLabel = normalizedRate !== null
    ? `~$${normalizedRate.toFixed(normalizedRate < 10 ? 2 : 0)}/hr`
    : undefined;
  const parts = [info.gpu, `${info.vram} VRAM`, priceLabel].filter(Boolean);
  return `${instanceType} (${parts.join(", ")})`;
}

function formatPercent(value: number | null | undefined): string {
  if (value === null || value === undefined) return "-";
  return `${(value * 100).toFixed(1)}%`;
}

function formatCurrency(value: number | null | undefined): string {
  if (value === null || value === undefined) return "-";
  return `$${value.toFixed(2)}/hr`;
}

function formatSeconds(value: number | null | undefined): string {
  if (value === null || value === undefined) return "-";
  if (value < 60) return `${value}s`;
  const minutes = Math.floor(value / 60);
  const seconds = Math.floor(value % 60);
  if (minutes < 60) return seconds > 0 ? `${minutes}m ${seconds}s` : `${minutes}m`;
  const hours = Math.floor(minutes / 60);
  const remainderMinutes = minutes % 60;
  return remainderMinutes > 0 ? `${hours}h ${remainderMinutes}m` : `${hours}h`;
}

export default function AdminComfyUiFleetsPage() {
  const [showPanel, setShowPanel] = useState(false);
  const [editingItem, setEditingItem] = useState<{ id: number; data: Record<string, any> } | null>(null);
  const [detailFleet, setDetailFleet] = useState<ComfyUiGpuFleet | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [bundleOptions, setBundleOptions] = useState<ComfyUiAssetBundle[]>([]);
  const [fleetTemplates, setFleetTemplates] = useState<ComfyUiFleetTemplate[]>([]);
  const [selectedBundleId, setSelectedBundleId] = useState<string>("");
  const [activateNotes, setActivateNotes] = useState("");
  const [savingDetail, setSavingDetail] = useState(false);

  const [detailName, setDetailName] = useState("");
  const [detailTemplateSlug, setDetailTemplateSlug] = useState("");
  const [detailInstanceType, setDetailInstanceType] = useState("");
  const [economicsSettings, setEconomicsSettings] = useState<EconomicsSettings | null>(null);

  useEffect(() => {
    getComfyUiAssetBundles({ perPage: 200 }).then((data) => setBundleOptions(data.items ?? [])).catch(() => {});
  }, []);

  useEffect(() => {
    getComfyUiFleetTemplates().then((data) => setFleetTemplates(data.items ?? [])).catch(() => {});
  }, []);

  useEffect(() => {
    getEconomicsSettings().then(setEconomicsSettings).catch(() => {});
  }, []);

  const templatesBySlug = useMemo(() => {
    const map = new Map<string, ComfyUiFleetTemplate>();
    fleetTemplates.forEach((template) => {
      map.set(template.template_slug, template);
    });
    return map;
  }, [fleetTemplates]);

  const initialFormState: FleetFormState = useMemo(() => {
    const defaultTemplate = fleetTemplates[0];
    return {
      stage: "staging",
      slug: "",
      name: "",
      template_slug: defaultTemplate?.template_slug ?? "",
      instance_type: defaultTemplate?.allowed_instance_types?.[0] ?? "",
    };
  }, [fleetTemplates]);

  const openDetail = useCallback((fleet: ComfyUiGpuFleet) => {
    setDetailFleet(fleet);
    setDetailName(fleet.name || "");
    setDetailTemplateSlug(fleet.template_slug || "");
    setDetailInstanceType(fleet.instance_types?.[0] || "");
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
      key: "template_slug",
      label: "Template",
      type: "select",
      required: true,
      render: ({ value, onChange, formState, setFormState }) => (
        <Select
          value={value}
          onValueChange={(next) => {
            onChange(next);
            const template = templatesBySlug.get(next);
            const allowed = template?.allowed_instance_types ?? [];
            if (allowed.length > 0 && !allowed.includes(formState.instance_type)) {
              setFormState((prev) => ({ ...prev, instance_type: allowed[0] }));
            }
          }}
        >
          <SelectTrigger>
            <SelectValue placeholder="Select template" />
          </SelectTrigger>
          <SelectContent>
            {fleetTemplates.map((template) => (
              <SelectItem key={template.template_slug} value={template.template_slug}>
                {template.display_name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      ),
    },
    {
      key: "instance_type",
      label: "Instance Type",
      type: "select",
      required: true,
      render: ({ value, onChange, formState }) => {
        const template = templatesBySlug.get(formState.template_slug);
        const instanceTypes = template?.allowed_instance_types ?? [];
        return (
          <div className="space-y-1">
            <Select value={value} onValueChange={onChange} disabled={instanceTypes.length === 0}>
              <SelectTrigger>
                <SelectValue placeholder="Select instance type" />
              </SelectTrigger>
              <SelectContent>
                {instanceTypes.map((instanceType) => (
                  <SelectItem key={instanceType} value={instanceType}>
                    {formatInstanceTypeLabel(
                      instanceType,
                      economicsSettings?.instance_type_rates?.[instanceType] ?? null,
                    )}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              Pricing comes from Economics settings (on-demand, us-east-1). Spot pricing varies.
            </p>
          </div>
        );
      },
    },
  ];

  const validateForm = (formState: Record<string, any>): string | null => {
    if (!formState.stage) return "Stage is required.";
    if (!formState.slug?.trim()) return "Slug is required.";
    if (!formState.name?.trim()) return "Name is required.";
    if (!formState.template_slug?.trim()) return "Template is required.";
    if (!formState.instance_type?.trim()) return "Instance type is required.";
    return null;
  };

  const getCreatePayload = (formState: Record<string, any>): ComfyUiFleetCreateRequest => {
    return {
      stage: formState.stage,
      slug: String(formState.slug || "").trim(),
      name: String(formState.name || "").trim(),
      template_slug: String(formState.template_slug || "").trim(),
      instance_type: String(formState.instance_type || "").trim(),
    };
  };

  const handleUpdateDetail = async () => {
    if (!detailFleet) return;
    setSavingDetail(true);
    try {
      const updated = await updateComfyUiFleet(detailFleet.id, {
        name: detailName.trim(),
        template_slug: detailTemplateSlug.trim() || undefined,
        instance_type: detailInstanceType.trim() || undefined,
      });
      setDetailFleet(updated);
      toast.success("Fleet updated.");
      state.loadItems();
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to update fleet."));
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
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to activate bundle."));
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
        onDemandAsgName: `asg-${detailFleet.stage}-${detailFleet.slug}-od`,
        logGroup: `/gpu-workers/${detailFleet.slug}`,
        refreshCommand: `aws autoscaling start-instance-refresh --auto-scaling-group-name "asg-${detailFleet.stage}-${detailFleet.slug}" --preferences '{"MinHealthyPercentage":90,"InstanceWarmup":300}'`,
      }
    : null;

  const capacityMetrics = detailFleet
    ? (() => {
        const instanceType = detailFleet.instance_types?.[0] || "";
        const onDemandRate = instanceType
          ? economicsSettings?.instance_type_rates?.[instanceType] ?? null
          : null;
        const utilization = detailFleet.utilization ?? null;
        const effectiveOnDemand = utilization && onDemandRate
          ? onDemandRate / utilization
          : null;

        return {
          spotWorkers: detailFleet.spot_workers ?? 0,
          onDemandWorkers: detailFleet.on_demand_workers ?? 0,
          unknownWorkers: detailFleet.unknown_workers ?? 0,
          utilization,
          busySeconds: detailFleet.busy_seconds ?? null,
          runningSeconds: detailFleet.running_seconds ?? null,
          onDemandRate,
          effectiveOnDemand,
        };
      })()
    : null;

  return (
    <>
      <DataTableView
        state={state}
        options={{
          entityClass: "ComfyUiGpuFleet",
          entityName: "Fleet",
          title: "ComfyUI Fleets",
          description: "Manage fleet templates and instance types. Provisioning is done via GitHub Actions.",
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
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Template</label>
                  <Select
                    value={detailTemplateSlug}
                    onValueChange={(next) => {
                      setDetailTemplateSlug(next);
                      const template = templatesBySlug.get(next);
                      const allowed = template?.allowed_instance_types ?? [];
                      if (allowed.length > 0 && !allowed.includes(detailInstanceType)) {
                        setDetailInstanceType(allowed[0]);
                      }
                    }}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select template" />
                    </SelectTrigger>
                    <SelectContent>
                      {fleetTemplates.map((template) => (
                        <SelectItem key={template.template_slug} value={template.template_slug}>
                          {template.display_name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Instance Type</label>
                  <Select
                    value={detailInstanceType}
                    onValueChange={setDetailInstanceType}
                    disabled={!detailTemplateSlug}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select instance type" />
                    </SelectTrigger>
                    <SelectContent>
                      {(templatesBySlug.get(detailTemplateSlug)?.allowed_instance_types ?? []).map((instanceType) => (
                        <SelectItem key={instanceType} value={instanceType}>
                          {formatInstanceTypeLabel(
                            instanceType,
                            economicsSettings?.instance_type_rates?.[instanceType] ?? null,
                          )}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    Pricing comes from Economics settings (on-demand, us-east-1). Spot pricing varies.
                  </p>
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Max Size</label>
                  <Input value={String(detailFleet.max_size ?? 0)} disabled />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Warmup (seconds)</label>
                  <Input value={String(detailFleet.warmup_seconds ?? "")} disabled />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Backlog Target</label>
                  <Input value={String(detailFleet.backlog_target ?? "")} disabled />
                </div>
                <div className="md:col-span-2 space-y-2">
                  <label className="text-xs font-semibold uppercase text-muted-foreground">Scale to Zero (minutes)</label>
                  <Input value={String(detailFleet.scale_to_zero_minutes ?? "")} disabled />
                </div>
                <div className="md:col-span-2">
                  <Button onClick={handleUpdateDetail} disabled={savingDetail}>
                    Save Fleet
                  </Button>
                </div>
              </div>
            </AdminDetailSection>

            {capacityMetrics && (
              <AdminDetailSection title="Live Capacity & Economics">
                <div className="grid gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <label className="text-xs font-semibold uppercase text-muted-foreground">Spot Workers</label>
                    <Input value={String(capacityMetrics.spotWorkers)} disabled />
                  </div>
                  <div className="space-y-2">
                    <label className="text-xs font-semibold uppercase text-muted-foreground">On-demand Workers</label>
                    <Input value={String(capacityMetrics.onDemandWorkers)} disabled />
                  </div>
                  <div className="space-y-2">
                    <label className="text-xs font-semibold uppercase text-muted-foreground">Unknown Workers</label>
                    <Input value={String(capacityMetrics.unknownWorkers)} disabled />
                  </div>
                  <div className="space-y-2">
                    <label className="text-xs font-semibold uppercase text-muted-foreground">Utilization (24h)</label>
                    <Input value={formatPercent(capacityMetrics.utilization)} disabled />
                  </div>
                  <div className="space-y-2">
                    <label className="text-xs font-semibold uppercase text-muted-foreground">Busy Time (24h)</label>
                    <Input value={formatSeconds(capacityMetrics.busySeconds)} disabled />
                  </div>
                  <div className="space-y-2">
                    <label className="text-xs font-semibold uppercase text-muted-foreground">Running Time (24h)</label>
                    <Input value={formatSeconds(capacityMetrics.runningSeconds)} disabled />
                  </div>
                  <div className="space-y-2">
                    <label className="text-xs font-semibold uppercase text-muted-foreground">On-demand Rate</label>
                    <Input value={formatCurrency(capacityMetrics.onDemandRate)} disabled />
                  </div>
                  <div className="space-y-2">
                    <label className="text-xs font-semibold uppercase text-muted-foreground">Est. $/Busy Hr (On-demand)</label>
                    <Input value={formatCurrency(capacityMetrics.effectiveOnDemand)} disabled />
                  </div>
                  <p className="text-xs text-muted-foreground md:col-span-2">
                    Utilization and costs are derived from the last 24h of worker sessions. Spot pricing is not applied.
                  </p>
                </div>
              </AdminDetailSection>
            )}

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
                    <p className="text-xs uppercase text-muted-foreground">On-demand ASG Name</p>
                    <Input value={derivedOps.onDemandAsgName} disabled />
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
