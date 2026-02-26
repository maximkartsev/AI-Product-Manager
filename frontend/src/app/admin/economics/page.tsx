"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { format, subDays } from "date-fns";
import { Coins, DollarSign, Info, Loader2, Settings2 } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { toast } from "sonner";
import { extractErrorMessage } from "@/lib/apiErrors";
import {
  getEconomicsSettings,
  getPartnerUsageAnalytics,
  getPartnerUsagePricing,
  updateEconomicsSettings,
  updatePartnerUsagePricing,
  getUnitEconomicsAnalytics,
  getAdminWorkflows,
  updateAdminWorkflow,
  type EconomicsSettings,
  type EconomicsSettingsPayload,
  type PartnerUsageAnalyticsData,
  type PartnerUsageByProviderNodeModel,
  type PartnerUsagePricingItem,
  type PartnerUsagePricingPayload,
  type UnitEconomicsByEffect,
  type UnitEconomicsData,
  type AdminWorkflow,
} from "@/lib/api";

type RateRow = {
  id: string;
  instanceType: string;
  rate: string;
};

type PartnerPricingDraftRow = {
  id: number;
  provider: string;
  nodeClassType: string;
  model: string;
  usdPer1mInputTokens: string;
  usdPer1mOutputTokens: string;
  usdPer1mTotalTokens: string;
  usdPerCredit: string;
  lastSeenAt?: string | null;
};

type InstanceTypeMeta = {
  readonly gpu: string;
  readonly vram: string;
};

const INSTANCE_TYPE_META: Record<string, InstanceTypeMeta> = {
  "g4dn.xlarge": { gpu: "T4", vram: "16GB" },
  "g5.xlarge": { gpu: "A10G", vram: "24GB" },
  "g6e.2xlarge": { gpu: "L40S", vram: "48GB" },
  "p5.48xlarge": { gpu: "8× H100", vram: "640GB" },
};

function formatCurrency(value: number | null | undefined): string {
  if (value === null || value === undefined) return "-";
  return `$${value.toFixed(2)}`;
}

function formatTokens(value: number | null | undefined): string {
  if (value === null || value === undefined) return "-";
  return value.toLocaleString();
}

function formatNumber(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined) return "-";
  return value.toLocaleString(undefined, { maximumFractionDigits: digits, minimumFractionDigits: 0 });
}

function formatHours(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return "-";
  const hours = seconds / 3600;
  if (hours >= 1) return `${hours.toFixed(2)}h`;
  const minutes = seconds / 60;
  return `${minutes.toFixed(1)}m`;
}

function parseOptionalNumber(raw: string): number | null {
  const trimmed = raw.trim();
  if (!trimmed) return null;
  const value = Number(trimmed);
  return Number.isFinite(value) ? value : null;
}

function buildRateRows(settings: EconomicsSettings | null): RateRow[] {
  if (!settings?.instance_type_rates) return [];
  return Object.entries(settings.instance_type_rates).map(([instanceType, rate], index) => ({
    id: `${instanceType}-${index}`,
    instanceType,
    rate: String(rate),
  }));
}

function toDraftNumber(value: number | null | undefined): string {
  if (value === null || value === undefined) return "";
  return String(value);
}

function buildPartnerPricingDraftRows(items: PartnerUsagePricingItem[]): PartnerPricingDraftRow[] {
  return items.map((item) => ({
    id: item.id,
    provider: item.provider,
    nodeClassType: item.nodeClassType,
    model: item.model ?? "",
    usdPer1mInputTokens: toDraftNumber(item.usdPer1mInputTokens),
    usdPer1mOutputTokens: toDraftNumber(item.usdPer1mOutputTokens),
    usdPer1mTotalTokens: toDraftNumber(item.usdPer1mTotalTokens),
    usdPerCredit: toDraftNumber(item.usdPerCredit),
    lastSeenAt: item.lastSeenAt ?? null,
  }));
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString();
}

function InfoIconButton({ title, ariaLabel }: { title: string; ariaLabel: string }) {
  return (
    <TooltipProvider delayDuration={150}>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            aria-label={ariaLabel}
          >
            <Info className="h-3 w-3" />
          </button>
        </TooltipTrigger>
        <TooltipContent side="top" align="center">
          {title}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}

export default function AdminEconomicsPage() {
  const [settings, setSettings] = useState<EconomicsSettings | null>(null);
  const [tokenRate, setTokenRate] = useState("0.01");
  const [spotMultiplier, setSpotMultiplier] = useState("");
  const [rateRows, setRateRows] = useState<RateRow[]>([]);
  const [savingSettings, setSavingSettings] = useState(false);
  const [loadingSettings, setLoadingSettings] = useState(true);

  const [workflows, setWorkflows] = useState<AdminWorkflow[]>([]);
  const [partnerDrafts, setPartnerDrafts] = useState<Record<number, string>>({});
  const [savingPartnerCosts, setSavingPartnerCosts] = useState(false);

  const [fromDate, setFromDate] = useState(() => format(subDays(new Date(), 30), "yyyy-MM-dd"));
  const [toDate, setToDate] = useState(() => format(new Date(), "yyyy-MM-dd"));
  const [unitData, setUnitData] = useState<UnitEconomicsData | null>(null);
  const [loadingUnitData, setLoadingUnitData] = useState(false);
  const [partnerUsageData, setPartnerUsageData] = useState<PartnerUsageAnalyticsData | null>(null);
  const [loadingPartnerUsage, setLoadingPartnerUsage] = useState(false);
  const [partnerPricingDraftRows, setPartnerPricingDraftRows] = useState<PartnerPricingDraftRow[]>([]);
  const [loadingPartnerPricing, setLoadingPartnerPricing] = useState(true);
  const [savingPartnerPricing, setSavingPartnerPricing] = useState(false);

  const loadSettings = useCallback(async () => {
    setLoadingSettings(true);
    try {
      const result = await getEconomicsSettings();
      setSettings(result);
      setTokenRate(String(result.token_usd_rate ?? 0.01));
      setSpotMultiplier(result.spot_multiplier !== null && result.spot_multiplier !== undefined ? String(result.spot_multiplier) : "");
      setRateRows(buildRateRows(result));
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to load economics settings."));
    } finally {
      setLoadingSettings(false);
    }
  }, []);

  const loadWorkflows = useCallback(async () => {
    try {
      const result = await getAdminWorkflows({ perPage: 200 });
      setWorkflows(result.items ?? []);
      const drafts: Record<number, string> = {};
      (result.items ?? []).forEach((workflow) => {
        if (workflow.id !== undefined && workflow.id !== null) {
          drafts[workflow.id] =
            workflow.partner_cost_per_work_unit !== null && workflow.partner_cost_per_work_unit !== undefined
              ? String(workflow.partner_cost_per_work_unit)
              : "";
        }
      });
      setPartnerDrafts(drafts);
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to load workflows."));
    }
  }, []);

  const loadPartnerPricing = useCallback(async () => {
    setLoadingPartnerPricing(true);
    try {
      const result = await getPartnerUsagePricing();
      setPartnerPricingDraftRows(buildPartnerPricingDraftRows(result.items ?? []));
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to load partner pricing."));
    } finally {
      setLoadingPartnerPricing(false);
    }
  }, []);

  const loadPartnerUsage = useCallback(async () => {
    setLoadingPartnerUsage(true);
    try {
      const result = await getPartnerUsageAnalytics({ from: fromDate, to: toDate });
      setPartnerUsageData(result);
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to load partner usage."));
    } finally {
      setLoadingPartnerUsage(false);
    }
  }, [fromDate, toDate]);

  const loadUnitEconomics = useCallback(async () => {
    setLoadingUnitData(true);
    try {
      const result = await getUnitEconomicsAnalytics({ from: fromDate, to: toDate });
      setUnitData(result);
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to load unit economics."));
    } finally {
      setLoadingUnitData(false);
    }
  }, [fromDate, toDate]);

  useEffect(() => {
    loadSettings();
    loadWorkflows();
    loadPartnerPricing();
  }, [loadSettings, loadWorkflows, loadPartnerPricing]);

  useEffect(() => {
    loadUnitEconomics();
    loadPartnerUsage();
  }, [loadUnitEconomics, loadPartnerUsage]);

  const refreshEconomicsPreview = useCallback(async () => {
    await Promise.all([loadUnitEconomics(), loadPartnerUsage()]);
  }, [loadPartnerUsage, loadUnitEconomics]);

  const handleSaveSettings = async () => {
    const tokenValue = parseOptionalNumber(tokenRate);
    if (tokenValue === null) {
      toast.error("Token USD rate must be a valid number.");
      return;
    }

    const spotValue = parseOptionalNumber(spotMultiplier);
    const instanceRates: Record<string, number> = {};

    rateRows.forEach((row) => {
      const key = row.instanceType.trim();
      const value = parseOptionalNumber(row.rate);
      if (key && value !== null) {
        instanceRates[key] = value;
      }
    });

    const payload: EconomicsSettingsPayload = {
      token_usd_rate: tokenValue,
      spot_multiplier: spotValue,
      instance_type_rates: instanceRates,
    };

    setSavingSettings(true);
    try {
      const result = await updateEconomicsSettings(payload);
      setSettings(result);
      setTokenRate(String(result.token_usd_rate ?? tokenValue));
      setSpotMultiplier(result.spot_multiplier !== null && result.spot_multiplier !== undefined ? String(result.spot_multiplier) : "");
      setRateRows(buildRateRows(result));
      toast.success("Economics settings updated.");
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to update settings."));
    } finally {
      setSavingSettings(false);
    }
  };

  const handleSavePartnerCosts = async () => {
    if (workflows.length === 0) return;
    setSavingPartnerCosts(true);
    try {
      for (const workflow of workflows) {
        if (!workflow.id) continue;
        const draftValue = partnerDrafts[workflow.id] ?? "";
        const parsed = parseOptionalNumber(draftValue);
        const current =
          workflow.partner_cost_per_work_unit !== null && workflow.partner_cost_per_work_unit !== undefined
            ? workflow.partner_cost_per_work_unit
            : null;
        if (parsed === current || (parsed === null && current === null)) {
          continue;
        }
        const updated = await updateAdminWorkflow(workflow.id, {
          partner_cost_per_work_unit: parsed,
        });
        setWorkflows((prev) => prev.map((item) => (item.id === updated.id ? updated : item)));
      }
      toast.success("Partner costs updated.");
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to update partner costs."));
    } finally {
      setSavingPartnerCosts(false);
    }
  };

  const updatePartnerPricingDraftRow = (id: number, patch: Partial<PartnerPricingDraftRow>) => {
    setPartnerPricingDraftRows((prev) => prev.map((row) => (row.id === id ? { ...row, ...patch } : row)));
  };

  const handleSavePartnerUsagePricing = async () => {
    const items = partnerPricingDraftRows
      .map((row) => ({
        provider: row.provider.trim(),
        nodeClassType: row.nodeClassType.trim(),
        model: row.model.trim() || null,
        usdPer1mInputTokens: parseOptionalNumber(row.usdPer1mInputTokens),
        usdPer1mOutputTokens: parseOptionalNumber(row.usdPer1mOutputTokens),
        usdPer1mTotalTokens: parseOptionalNumber(row.usdPer1mTotalTokens),
        usdPerCredit: parseOptionalNumber(row.usdPerCredit),
      }))
      .filter((row) => row.provider && row.nodeClassType);

    if (items.length === 0) {
      toast.error("No partner pricing rows available to save.");
      return;
    }

    const payload: PartnerUsagePricingPayload = { items };
    setSavingPartnerPricing(true);
    try {
      const result = await updatePartnerUsagePricing(payload);
      setPartnerPricingDraftRows(buildPartnerPricingDraftRows(result.items ?? []));
      await Promise.all([loadPartnerUsage(), loadUnitEconomics()]);
      toast.success("Partner token/credit pricing updated.");
    } catch (error) {
      toast.error(extractErrorMessage(error, "Failed to update partner pricing."));
    } finally {
      setSavingPartnerPricing(false);
    }
  };

  const addRateRow = () => {
    setRateRows((prev) => [
      ...prev,
      { id: `new-${Date.now()}`, instanceType: "", rate: "" },
    ]);
  };

  const removeRateRow = (id: string) => {
    setRateRows((prev) => prev.filter((row) => row.id !== id));
  };

  const updateRateRow = (id: string, patch: Partial<RateRow>) => {
    setRateRows((prev) => prev.map((row) => (row.id === id ? { ...row, ...patch } : row)));
  };

  const draftInstanceRates = useMemo(() => {
    const rates: Record<string, number> = {};
    rateRows.forEach((row) => {
      const key = row.instanceType.trim();
      const value = parseOptionalNumber(row.rate);
      if (key && value !== null) {
        rates[key] = value;
      }
    });
    return rates;
  }, [rateRows]);

  const settingsSummary = useMemo(() => {
    if (!settings) return null;
    const useDraftRates = rateRows.length > 0 ? draftInstanceRates : settings.instance_type_rates ?? {};
    return {
      defaultsApplied: Boolean(settings.defaults_applied),
      tokenUsdRate: parseOptionalNumber(tokenRate) ?? settings.token_usd_rate,
      spotMultiplier: parseOptionalNumber(spotMultiplier),
      instanceRates: useDraftRates,
    };
  }, [settings, tokenRate, spotMultiplier, draftInstanceRates, rateRows.length]);

  const workflowPartnerCost = useMemo(() => {
    const map = new Map<number, number>();
    workflows.forEach((workflow) => {
      if (!workflow.id) return;
      const raw = partnerDrafts[workflow.id] ?? "";
      const parsed = parseOptionalNumber(raw);
      if (parsed !== null) {
        map.set(workflow.id, parsed);
      } else if (workflow.partner_cost_per_work_unit !== null && workflow.partner_cost_per_work_unit !== undefined) {
        map.set(workflow.id, workflow.partner_cost_per_work_unit);
      }
    });
    return map;
  }, [workflows, partnerDrafts]);

  const computeCostUsd = useCallback(
    (effect: UnitEconomicsByEffect) => {
      if (!settingsSummary) return null;
      const instanceType = effect.fleetInstanceTypes?.[0];
      if (!instanceType) return null;
      const baseRate = settingsSummary.instanceRates?.[instanceType];
      if (!baseRate) return null;
      const multiplier = settingsSummary.spotMultiplier ?? 1;
      return (effect.totalProcessingSeconds / 3600) * baseRate * multiplier;
    },
    [settingsSummary],
  );

  const revenueUsd = useCallback(
    (effect: UnitEconomicsByEffect) => {
      if (!settingsSummary) return null;
      return effect.totalTokens * settingsSummary.tokenUsdRate;
    },
    [settingsSummary],
  );

  const partnerWorkflowCostUsd = useCallback(
    (effect: UnitEconomicsByEffect) => {
      if (!effect.workflowId) return effect.partnerCostUsd ?? null;
      const cost = workflowPartnerCost.get(effect.workflowId);
      if (cost === undefined) {
        return effect.partnerCostUsd ?? null;
      }
      return cost * effect.totalWorkUnits;
    },
    [workflowPartnerCost],
  );

  const partnerUsageCostUsd = useCallback((effect: UnitEconomicsByEffect) => effect.partnerUsageCostUsd ?? null, []);

  const partnerCostUsd = useCallback(
    (effect: UnitEconomicsByEffect) => {
      const workflowCost = partnerWorkflowCostUsd(effect);
      const usageCost = partnerUsageCostUsd(effect);
      if (workflowCost === null && usageCost === null) {
        return null;
      }
      return (workflowCost ?? 0) + (usageCost ?? 0);
    },
    [partnerUsageCostUsd, partnerWorkflowCostUsd],
  );

  const marginUsd = useCallback(
    (effect: UnitEconomicsByEffect) => {
      const revenue = revenueUsd(effect);
      const compute = computeCostUsd(effect);
      if (revenue === null || compute === null) return null;
      const partner = partnerCostUsd(effect) ?? 0;
      return revenue - compute - partner;
    },
    [computeCostUsd, partnerCostUsd, revenueUsd],
  );

  const summary = useMemo(() => {
    if (!unitData || !settingsSummary) {
      return {
        totalTokens: 0,
        computeCostUsd: null as number | null,
        partnerCostUsd: null as number | null,
        marginUsd: null as number | null,
      };
    }
    const computeCosts = unitData.byEffect.map((effect) => computeCostUsd(effect));
    const hasMissingCompute = computeCosts.some((value) => value === null);
    const computeCostUsdTotal = hasMissingCompute
      ? null
      : computeCosts.reduce<number>((sum, value) => sum + (value ?? 0), 0);
    const partnerCostUsdTotal = unitData.byEffect.reduce<number>(
      (sum, effect) => sum + (partnerCostUsd(effect) ?? 0),
      0,
    );
    const revenueUsdTotal = unitData.byEffect.reduce<number>((sum, effect) => sum + (revenueUsd(effect) ?? 0), 0);
    const marginUsdTotal =
      computeCostUsdTotal !== null ? revenueUsdTotal - computeCostUsdTotal - partnerCostUsdTotal : null;

    return {
      totalTokens: unitData.totals.totalTokens,
      computeCostUsd: computeCostUsdTotal,
      partnerCostUsd: partnerCostUsdTotal > 0 ? partnerCostUsdTotal : null,
      marginUsd: marginUsdTotal,
    };
  }, [unitData, settingsSummary, computeCostUsd, partnerCostUsd, revenueUsd]);

  const partnerUsageRows = useMemo<PartnerUsageByProviderNodeModel[]>(
    () => partnerUsageData?.byProviderNodeModel ?? [],
    [partnerUsageData],
  );

  return (
    <div>
      <div className="space-y-6">
        <header className="space-y-1">
          <h1 className="text-2xl md:text-3xl font-semibold">Economics</h1>
          <p className="text-sm text-muted-foreground">
            Configure pricing assumptions and review unit economics in one place.
          </p>
        </header>

        <section className="space-y-4 rounded-lg border border-border p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-lg font-semibold">Pricing Settings</h2>
              <p className="text-xs text-muted-foreground">
                Update token revenue and compute cost assumptions. Spot multiplier applies to compute estimates when set.
              </p>
            </div>
            <Button onClick={handleSaveSettings} disabled={savingSettings || loadingSettings}>
              {savingSettings ? "Saving..." : "Save Settings"}
            </Button>
          </div>

          {settings?.defaults_applied ? (
            <div className="rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200">
              Default pricing assumptions were loaded. Save this page to persist changes.
            </div>
          ) : null}

          <div className="grid gap-4 md:grid-cols-3">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <label className="text-xs text-muted-foreground">Token USD Rate</label>
                <InfoIconButton
                  ariaLabel="Token USD rate info"
                  title={
                    "Converts tokens to USD for unit economics.\n" +
                    "Revenue (USD) = tokens × token_usd_rate.\n" +
                    "This does not change billing/charging logic—it's used for analytics."
                  }
                />
              </div>
              <Input value={tokenRate} onChange={(e) => setTokenRate(e.target.value)} />
            </div>
            <div className="space-y-1">
              <label className="text-xs text-muted-foreground">Spot Multiplier (optional)</label>
              <Input value={spotMultiplier} onChange={(e) => setSpotMultiplier(e.target.value)} placeholder="0.65" />
            </div>
            <div className="space-y-1">
              <label className="text-xs text-muted-foreground">Effective Compute Basis</label>
              <div className="rounded-md border border-border bg-muted px-3 py-2 text-sm text-muted-foreground">
                {settingsSummary?.spotMultiplier ? `On-demand × ${settingsSummary.spotMultiplier}` : "On-demand"}
              </div>
            </div>
          </div>

          <div className="rounded-lg border border-border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Instance Type</TableHead>
                  <TableHead>GPU</TableHead>
                  <TableHead>VRAM</TableHead>
                  <TableHead className="text-right">On-demand $/hr</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rateRows.length > 0 ? (
                  rateRows.map((row) => {
                    const meta = INSTANCE_TYPE_META[row.instanceType];
                    return (
                      <TableRow key={row.id}>
                        <TableCell className="w-[220px]">
                          <Input
                            value={row.instanceType}
                            onChange={(event) => updateRateRow(row.id, { instanceType: event.target.value })}
                            placeholder="g5.xlarge"
                          />
                        </TableCell>
                        <TableCell>{meta?.gpu ?? "-"}</TableCell>
                        <TableCell>{meta?.vram ?? "-"}</TableCell>
                        <TableCell className="text-right">
                          <Input
                            value={row.rate}
                            onChange={(event) => updateRateRow(row.id, { rate: event.target.value })}
                            className="text-right"
                            placeholder="1.006"
                          />
                        </TableCell>
                        <TableCell className="text-right">
                          <Button variant="outline" size="sm" onClick={() => removeRateRow(row.id)}>
                            Remove
                          </Button>
                        </TableCell>
                      </TableRow>
                    );
                  })
                ) : (
                  <TableRow>
                    <TableCell colSpan={5} className="text-center text-muted-foreground py-8">
                      No instance rates configured.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>

          <div>
            <Button variant="outline" size="sm" onClick={addRateRow}>
              Add Instance Type
            </Button>
          </div>
        </section>

        <section className="space-y-4 rounded-lg border border-border p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-lg font-semibold">Partner Costs</h2>
              <p className="text-xs text-muted-foreground">
                Configure partner cost per work unit by workflow.
              </p>
            </div>
            <Button onClick={handleSavePartnerCosts} disabled={savingPartnerCosts}>
              {savingPartnerCosts ? "Saving..." : "Save Partner Costs"}
            </Button>
          </div>

          <div className="rounded-lg border border-border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Workflow</TableHead>
                  <TableHead>Workload</TableHead>
                  <TableHead className="text-right">
                    <div className="flex items-center justify-end gap-2">
                      <span>Partner Cost / Unit (USD)</span>
                      <InfoIconButton
                        ariaLabel="Partner cost per unit info"
                        title={
                          "Cost charged by a remote partner per work unit.\n" +
                          "Unit = work_units.\n" +
                          "Images: 1 unit per job.\n" +
                          "Videos: video seconds (or the workflow's configured units).\n" +
                          "Partner cost (USD) = cost_per_unit × total_units."
                        }
                      />
                    </div>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {workflows.length > 0 ? (
                  workflows.map((workflow) => (
                    <TableRow key={workflow.id}>
                      <TableCell>
                        <div className="font-medium">{workflow.name ?? "Workflow"}</div>
                        <div className="text-xs text-muted-foreground">{workflow.slug ?? "-"}</div>
                      </TableCell>
                      <TableCell>{workflow.workload_kind ?? "-"}</TableCell>
                      <TableCell className="text-right">
                        <Input
                          value={partnerDrafts[workflow.id] ?? ""}
                          onChange={(event) =>
                            setPartnerDrafts((prev) => ({ ...prev, [workflow.id]: event.target.value }))
                          }
                          className="text-right"
                          placeholder="0.05"
                        />
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={3} className="text-center text-muted-foreground py-8">
                      No workflows available.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
        </section>

        <section className="space-y-4 rounded-lg border border-border p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-lg font-semibold">Partner Token/Credit Pricing</h2>
              <p className="text-xs text-muted-foreground">
                Auto-discovered from real executions. Configure token (per 1M) and credit prices for unit economics.
              </p>
            </div>
            <Button onClick={handleSavePartnerUsagePricing} disabled={savingPartnerPricing || loadingPartnerPricing}>
              {savingPartnerPricing ? "Saving..." : "Save Partner Pricing"}
            </Button>
          </div>

          {loadingPartnerPricing ? (
            <div className="flex items-center justify-center py-10">
              <Loader2 className="w-6 h-6 text-muted-foreground animate-spin" />
            </div>
          ) : (
            <div className="rounded-lg border border-border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Provider</TableHead>
                    <TableHead>Node</TableHead>
                    <TableHead>Model</TableHead>
                    <TableHead className="text-right">Input $ / 1M</TableHead>
                    <TableHead className="text-right">Output $ / 1M</TableHead>
                    <TableHead className="text-right">Total $ / 1M</TableHead>
                    <TableHead className="text-right">$ / Credit</TableHead>
                    <TableHead className="text-right">Last Seen</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {partnerPricingDraftRows.length > 0 ? (
                    partnerPricingDraftRows.map((row) => (
                      <TableRow key={row.id}>
                        <TableCell className="font-medium">{row.provider}</TableCell>
                        <TableCell>{row.nodeClassType}</TableCell>
                        <TableCell>{row.model || "-"}</TableCell>
                        <TableCell className="text-right">
                          <Input
                            value={row.usdPer1mInputTokens}
                            onChange={(event) =>
                              updatePartnerPricingDraftRow(row.id, { usdPer1mInputTokens: event.target.value })
                            }
                            className="text-right"
                            placeholder="1.25"
                          />
                        </TableCell>
                        <TableCell className="text-right">
                          <Input
                            value={row.usdPer1mOutputTokens}
                            onChange={(event) =>
                              updatePartnerPricingDraftRow(row.id, { usdPer1mOutputTokens: event.target.value })
                            }
                            className="text-right"
                            placeholder="5.00"
                          />
                        </TableCell>
                        <TableCell className="text-right">
                          <Input
                            value={row.usdPer1mTotalTokens}
                            onChange={(event) =>
                              updatePartnerPricingDraftRow(row.id, { usdPer1mTotalTokens: event.target.value })
                            }
                            className="text-right"
                            placeholder="2.00"
                          />
                        </TableCell>
                        <TableCell className="text-right">
                          <Input
                            value={row.usdPerCredit}
                            onChange={(event) =>
                              updatePartnerPricingDraftRow(row.id, { usdPerCredit: event.target.value })
                            }
                            className="text-right"
                            placeholder="0.01"
                          />
                        </TableCell>
                        <TableCell className="text-right text-xs text-muted-foreground">
                          {formatDateTime(row.lastSeenAt)}
                        </TableCell>
                      </TableRow>
                    ))
                  ) : (
                    <TableRow>
                      <TableCell colSpan={8} className="text-center text-muted-foreground py-10">
                        No partner nodes discovered yet. Run workflows with partner nodes to populate this table.
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          )}
        </section>

        <section className="space-y-4 rounded-lg border border-border p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-lg font-semibold">Partner Usage (Real)</h2>
              <p className="text-xs text-muted-foreground">
                Aggregated usage by provider/node/model for the selected period.
              </p>
            </div>
            <Button variant="outline" onClick={loadPartnerUsage} disabled={loadingPartnerUsage}>
              {loadingPartnerUsage ? "Refreshing..." : "Refresh Partner Usage"}
            </Button>
          </div>

          <div className="grid gap-3 md:grid-cols-3">
            <div className="rounded-md border border-border bg-muted px-3 py-2">
              <div className="text-xs text-muted-foreground">Partner Usage Tokens</div>
              <div className="text-lg font-semibold">
                {formatTokens(partnerUsageData?.totals.totalTokens ?? unitData?.totals.totalPartnerUsageTokens ?? 0)}
              </div>
            </div>
            <div className="rounded-md border border-border bg-muted px-3 py-2">
              <div className="text-xs text-muted-foreground">Partner Credits</div>
              <div className="text-lg font-semibold">{formatNumber(partnerUsageData?.totals.credits ?? 0, 4)}</div>
            </div>
            <div className="rounded-md border border-border bg-muted px-3 py-2">
              <div className="text-xs text-muted-foreground">Reported Partner Cost (USD)</div>
              <div className="text-lg font-semibold">
                {formatCurrency(partnerUsageData?.totals.costUsdReported ?? unitData?.totals.totalPartnerUsageCostUsdReported)}
              </div>
            </div>
          </div>

          {loadingPartnerUsage ? (
            <div className="flex items-center justify-center py-10">
              <Loader2 className="w-6 h-6 text-muted-foreground animate-spin" />
            </div>
          ) : (
            <div className="rounded-lg border border-border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Provider</TableHead>
                    <TableHead>Node</TableHead>
                    <TableHead>Model</TableHead>
                    <TableHead className="text-right">Events</TableHead>
                    <TableHead className="text-right">Input Tokens</TableHead>
                    <TableHead className="text-right">Output Tokens</TableHead>
                    <TableHead className="text-right">Total Tokens</TableHead>
                    <TableHead className="text-right">Credits</TableHead>
                    <TableHead className="text-right">Reported $</TableHead>
                    <TableHead className="text-right">Last Seen</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {partnerUsageRows.length > 0 ? (
                    partnerUsageRows.map((row, index) => (
                      <TableRow key={`${row.provider}-${row.nodeClassType}-${row.model ?? "none"}-${index}`}>
                        <TableCell className="font-medium">{row.provider}</TableCell>
                        <TableCell>{row.nodeClassType}</TableCell>
                        <TableCell>{row.model ?? "-"}</TableCell>
                        <TableCell className="text-right">{formatNumber(row.eventsCount, 0)}</TableCell>
                        <TableCell className="text-right">{formatTokens(row.inputTokens)}</TableCell>
                        <TableCell className="text-right">{formatTokens(row.outputTokens)}</TableCell>
                        <TableCell className="text-right">{formatTokens(row.totalTokens)}</TableCell>
                        <TableCell className="text-right">{formatNumber(row.credits, 4)}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.costUsdReported)}</TableCell>
                        <TableCell className="text-right text-xs text-muted-foreground">
                          {formatDateTime(row.lastSeenAt)}
                        </TableCell>
                      </TableRow>
                    ))
                  ) : (
                    <TableRow>
                      <TableCell colSpan={10} className="text-center text-muted-foreground py-10">
                        No partner usage for this period.
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          )}
        </section>

        <section className="space-y-4">
          <header className="flex flex-wrap items-center justify-between gap-2">
            <div className="space-y-1">
              <h2 className="text-lg font-semibold">Unit Economics Preview</h2>
              <p className="text-xs text-muted-foreground">
                Metrics update with the pricing settings above.
              </p>
            </div>
            <div className="flex flex-wrap items-end gap-2">
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">From</label>
                <Input
                  type="date"
                  value={fromDate}
                  onChange={(event) => setFromDate(event.target.value)}
                  className="w-36 bg-muted border-border"
                />
              </div>
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">To</label>
                <Input
                  type="date"
                  value={toDate}
                  onChange={(event) => setToDate(event.target.value)}
                  className="w-36 bg-muted border-border"
                />
              </div>
              <Button variant="outline" onClick={refreshEconomicsPreview} disabled={loadingUnitData || loadingPartnerUsage}>
                {loadingUnitData || loadingPartnerUsage ? "Refreshing..." : "Refresh"}
              </Button>
            </div>
          </header>

          <div className="grid gap-4 md:grid-cols-4">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Total Tokens</CardTitle>
                <Coins className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{loadingUnitData ? "-" : formatTokens(summary.totalTokens)}</div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Compute Cost (USD)</CardTitle>
                <DollarSign className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div
                  className={`text-2xl font-bold ${
                    !loadingUnitData && summary.computeCostUsd === null && unitData?.byEffect?.length
                      ? "text-amber-400"
                      : ""
                  }`}
                >
                  {loadingUnitData ? "-" : formatCurrency(summary.computeCostUsd)}
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Partner Cost (USD)</CardTitle>
                <DollarSign className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{loadingUnitData ? "-" : formatCurrency(summary.partnerCostUsd)}</div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Margin (USD)</CardTitle>
                <Settings2 className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{loadingUnitData ? "-" : formatCurrency(summary.marginUsd)}</div>
              </CardContent>
            </Card>
          </div>

          {loadingUnitData ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="w-8 h-8 text-muted-foreground animate-spin" />
            </div>
          ) : (
            <div className="rounded-lg border border-border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Effect</TableHead>
                    <TableHead>Workflow</TableHead>
                    <TableHead>Fleet</TableHead>
                    <TableHead className="text-right">Units</TableHead>
                    <TableHead className="text-right">Tokens</TableHead>
                    <TableHead className="text-right">Processing</TableHead>
                    <TableHead className="text-right">Compute $</TableHead>
                    <TableHead className="text-right">Partner $</TableHead>
                    <TableHead className="text-right">Margin $</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {unitData?.byEffect?.length ? (
                    unitData.byEffect.map((effect) => {
                      const computeCost = computeCostUsd(effect);
                      const partnerCost = partnerCostUsd(effect);
                      const margin = marginUsd(effect);
                      const computeMissing = computeCost === null && Boolean(settingsSummary);
                      const instanceType = effect.fleetInstanceTypes?.[0];
                      const fleetLabel = instanceType
                        ? `${instanceType} (${INSTANCE_TYPE_META[instanceType]?.gpu ?? "GPU"})`
                        : effect.fleetSlugs?.[0] ?? "-";
                      const unitLabel = effect.workUnitKind ? `${effect.workUnitKind}` : "unit";
                      const marginClass =
                        margin === null
                          ? "text-muted-foreground"
                          : margin >= 0
                            ? "text-emerald-500"
                            : "text-red-500";

                      return (
                        <TableRow key={effect.effectId}>
                          <TableCell>
                            <div className="font-medium">{effect.effectName}</div>
                            <div className="text-xs text-muted-foreground">#{effect.effectId}</div>
                          </TableCell>
                          <TableCell>
                            <div className="font-medium">{effect.workflowName ?? "Unassigned"}</div>
                            {effect.workloadKind ? (
                              <div className="text-xs text-muted-foreground">{effect.workloadKind}</div>
                            ) : null}
                          </TableCell>
                          <TableCell>
                            <div className="font-medium">{fleetLabel}</div>
                            {effect.fleetSlugs?.length ? (
                              <div className="text-xs text-muted-foreground">{effect.fleetSlugs.join(", ")}</div>
                            ) : null}
                          </TableCell>
                          <TableCell className="text-right">
                            <div className="font-medium">{formatNumber(effect.totalWorkUnits, 2)}</div>
                            <div className="text-xs text-muted-foreground">{unitLabel}</div>
                          </TableCell>
                          <TableCell className="text-right">{formatTokens(effect.totalTokens)}</TableCell>
                          <TableCell className="text-right">{formatHours(effect.totalProcessingSeconds)}</TableCell>
                          <TableCell className={`text-right ${computeMissing ? "text-amber-400" : ""}`}>
                            {formatCurrency(computeCost)}
                          </TableCell>
                          <TableCell className="text-right">{formatCurrency(partnerCost)}</TableCell>
                          <TableCell className={`text-right font-medium ${marginClass}`}>
                            {formatCurrency(margin)}
                          </TableCell>
                        </TableRow>
                      );
                    })
                  ) : (
                    <TableRow>
                      <TableCell colSpan={9} className="text-center text-muted-foreground py-12">
                        No data for this period.
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          )}
        </section>
      </div>
    </div>
  );
}
