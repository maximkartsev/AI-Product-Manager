"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { format, subDays } from "date-fns";
import { CalendarDays, Coins, DollarSign, Loader2 } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { toast } from "sonner";
import { extractErrorMessage } from "@/lib/apiErrors";
import {
  getEconomicsSettings,
  getUnitEconomicsAnalytics,
  type EconomicsSettings,
  type UnitEconomicsByEffect,
  type UnitEconomicsData,
} from "@/lib/api";

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

function formatTokens(value: number | null | undefined): string {
  if (value === null || value === undefined) return "-";
  return value.toLocaleString();
}

function formatNumber(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined) return "-";
  return value.toLocaleString(undefined, { maximumFractionDigits: digits, minimumFractionDigits: 0 });
}

function formatCurrency(value: number | null | undefined): string {
  if (value === null || value === undefined) return "-";
  return `$${value.toFixed(2)}`;
}

function formatHours(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return "-";
  const hours = seconds / 3600;
  if (hours >= 1) return `${hours.toFixed(2)}h`;
  const minutes = seconds / 60;
  return `${minutes.toFixed(1)}m`;
}

function getOnDemandRate(instanceTypes: string[] | null | undefined, settings: EconomicsSettings | null): number | null {
  if (!instanceTypes || instanceTypes.length === 0) return null;
  const primary = instanceTypes[0];
  return settings?.instance_type_rates?.[primary] ?? null;
}

function computeComputeCost(effect: UnitEconomicsByEffect, settings: EconomicsSettings | null): number | null {
  const rate = getOnDemandRate(effect.fleetInstanceTypes, settings);
  if (!rate) return null;
  const multiplier = settings?.spot_multiplier ?? 1;
  return (effect.totalProcessingSeconds / 3600) * rate * multiplier;
}

function computeMarginUsd(effect: UnitEconomicsByEffect, settings: EconomicsSettings | null): number | null {
  const computeCost = computeComputeCost(effect, settings);
  if (computeCost === null) return null;
  const revenueUsd = effect.totalTokens * (settings?.token_usd_rate ?? 0);
  const partnerCost = effect.partnerCostUsd ?? 0;
  return revenueUsd - computeCost - partnerCost;
}

export default function AdminUnitEconomicsPage() {
  const [fromDate, setFromDate] = useState(() => format(subDays(new Date(), 30), "yyyy-MM-dd"));
  const [toDate, setToDate] = useState(() => format(new Date(), "yyyy-MM-dd"));
  const [data, setData] = useState<UnitEconomicsData | null>(null);
  const [loading, setLoading] = useState(false);
  const [settings, setSettings] = useState<EconomicsSettings | null>(null);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const result = await getUnitEconomicsAnalytics({ from: fromDate, to: toDate });
      setData(result);
    } catch (error) {
      console.error("Failed to load unit economics:", error);
      toast.error(extractErrorMessage(error, "Failed to load unit economics."));
    } finally {
      setLoading(false);
    }
  }, [fromDate, toDate]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    getEconomicsSettings().then(setSettings).catch(() => {});
  }, []);

  const summary = useMemo(() => {
    if (!data) {
      return {
        totalTokens: 0,
        computeCostUsd: null as number | null,
        partnerCostUsd: null as number | null,
        marginUsd: null as number | null,
      };
    }
    const computeCosts = data.byEffect.map((effect) => computeComputeCost(effect, settings));
    const hasMissingCompute = computeCosts.some((value) => value === null);
    const computeCostUsd = hasMissingCompute
      ? null
      : computeCosts.reduce((sum, value) => sum + (value ?? 0), 0);
    const partnerCostUsd = data.totals.totalPartnerCostUsd ?? null;
    const revenueUsd = data.totals.totalTokens * (settings?.token_usd_rate ?? 0);
    const marginUsd = computeCostUsd !== null ? revenueUsd - computeCostUsd - (partnerCostUsd ?? 0) : null;

    return {
      totalTokens: data.totals.totalTokens,
      computeCostUsd,
      partnerCostUsd,
      marginUsd,
    };
  }, [data, settings]);

  return (
    <div>
      <div className="space-y-6">
        <header className="space-y-1">
          <h1 className="text-2xl md:text-3xl font-semibold">Unit Economics</h1>
          <p className="text-sm text-muted-foreground">
            Tokens vs. compute and partner costs. Pricing comes from the Economics settings.
          </p>
        </header>

        <div className="flex flex-wrap items-end gap-4">
          <div className="space-y-1">
            <label className="text-xs text-muted-foreground">From</label>
            <Input
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
              className="w-40 bg-muted border-border"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs text-muted-foreground">To</label>
            <Input
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
              className="w-40 bg-muted border-border"
            />
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Total Tokens</CardTitle>
              <Coins className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{loading ? "-" : formatTokens(summary.totalTokens)}</div>
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
                  !loading && summary.computeCostUsd === null && data?.byEffect?.length ? "text-amber-400" : ""
                }`}
              >
                {loading ? "-" : formatCurrency(summary.computeCostUsd)}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Partner Cost (USD)</CardTitle>
              <DollarSign className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{loading ? "-" : formatCurrency(summary.partnerCostUsd)}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Margin (USD)</CardTitle>
              <CalendarDays className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{loading ? "-" : formatCurrency(summary.marginUsd)}</div>
            </CardContent>
          </Card>
        </div>

        {loading ? (
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
                {data?.byEffect?.length ? (
                  data.byEffect.map((effect) => {
                    const computeCostUsd = computeComputeCost(effect, settings);
                    const partnerCostUsd = effect.partnerCostUsd ?? null;
                    const marginUsd = computeMarginUsd(effect, settings);
                    const computeMissing = computeCostUsd === null && Boolean(settings);
                    const instanceType = effect.fleetInstanceTypes?.[0];
                    const fleetLabel = instanceType
                      ? `${instanceType} (${INSTANCE_TYPE_UI_INFO[instanceType]?.gpu ?? "GPU"})`
                      : effect.fleetSlugs?.[0] ?? "-";
                    const unitLabel = effect.workUnitKind ? `${effect.workUnitKind}` : "unit";
                    const marginClass =
                      marginUsd === null
                        ? "text-muted-foreground"
                        : marginUsd >= 0
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
                          {formatCurrency(computeCostUsd)}
                        </TableCell>
                        <TableCell className="text-right">{formatCurrency(partnerCostUsd)}</TableCell>
                        <TableCell className={`text-right font-medium ${marginClass}`}>
                          {formatCurrency(marginUsd)}
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
      </div>
    </div>
  );
}
