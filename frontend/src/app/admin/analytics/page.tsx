"use client";

import { useCallback, useEffect, useState } from "react";
import { format, subDays } from "date-fns";
import { Loader2, Coins, TrendingUp, CalendarDays } from "lucide-react";
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from "recharts";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  getTokenSpendingAnalytics,
  type TokenSpendingData,
  type TokenSpendingByEffect,
} from "@/lib/api";

export default function AdminAnalyticsPage() {
  const [fromDate, setFromDate] = useState(() => format(subDays(new Date(), 30), "yyyy-MM-dd"));
  const [toDate, setToDate] = useState(() => format(new Date(), "yyyy-MM-dd"));
  const [granularity, setGranularity] = useState<"day" | "week" | "month">("day");
  const [data, setData] = useState<TokenSpendingData | null>(null);
  const [loading, setLoading] = useState(false);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const result = await getTokenSpendingAnalytics({
        from: fromDate,
        to: toDate,
        granularity,
      });
      setData(result);
    } catch (error) {
      console.error("Failed to load analytics:", error);
    } finally {
      setLoading(false);
    }
  }, [fromDate, toDate, granularity]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const topEffect = data?.byEffect?.[0];

  return (
    <div>
      <div className="space-y-6">
        <header className="space-y-1">
          <h1 className="text-2xl md:text-3xl font-semibold">Analytics</h1>
          <p className="text-sm text-muted-foreground">Token spending analytics across all tenants.</p>
        </header>

        {/* Controls */}
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
          <div className="space-y-1">
            <label className="text-xs text-muted-foreground">Granularity</label>
            <Select value={granularity} onValueChange={(v) => setGranularity(v as "day" | "week" | "month")}>
              <SelectTrigger className="w-32 bg-muted border-border">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="day">Day</SelectItem>
                <SelectItem value="week">Week</SelectItem>
                <SelectItem value="month">Month</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        {/* Summary Cards */}
        <div className="grid gap-4 md:grid-cols-3">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Total Tokens Spent</CardTitle>
              <Coins className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">
                {loading ? "-" : (data?.totalTokens ?? 0).toLocaleString()}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Top Effect</CardTitle>
              <TrendingUp className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold truncate">
                {loading ? "-" : topEffect?.effectName ?? "None"}
              </div>
              {topEffect && !loading && (
                <p className="text-xs text-muted-foreground">{topEffect.totalTokens.toLocaleString()} tokens</p>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Period</CardTitle>
              <CalendarDays className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-lg font-bold">
                {fromDate} &ndash; {toDate}
              </div>
              <p className="text-xs text-muted-foreground capitalize">{granularity} granularity</p>
            </CardContent>
          </Card>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="w-8 h-8 text-muted-foreground animate-spin" />
          </div>
        ) : (
          <>
            {/* Time Series Chart */}
            <div className="rounded-lg border border-border p-4">
              <h2 className="text-lg font-semibold mb-4">Token Spending Over Time</h2>
              {data?.timeSeries && data.timeSeries.length > 0 ? (
                <ResponsiveContainer width="100%" height={300}>
                  <BarChart data={data.timeSeries}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                    <XAxis
                      dataKey="bucket"
                      tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
                      axisLine={{ stroke: "var(--border)" }}
                    />
                    <YAxis
                      tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
                      axisLine={{ stroke: "var(--border)" }}
                    />
                    <Tooltip
                      contentStyle={{
                        backgroundColor: "var(--card)",
                        border: "1px solid var(--border)",
                        borderRadius: "0.5rem",
                        color: "var(--foreground)",
                      }}
                      formatter={(value: unknown) => [(Number(value) || 0).toLocaleString(), "Tokens"]}
                    />
                    <Bar dataKey="totalTokens" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div className="flex items-center justify-center py-16 text-muted-foreground">
                  No data for this period.
                </div>
              )}
            </div>

            {/* By Effect Chart */}
            <div className="rounded-lg border border-border p-4">
              <h2 className="text-lg font-semibold mb-4">Token Spending by Effect</h2>
              {data?.byEffect && data.byEffect.length > 0 ? (
                <ResponsiveContainer width="100%" height={Math.max(200, data.byEffect.length * 40)}>
                  <BarChart data={data.byEffect} layout="vertical">
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                    <XAxis
                      type="number"
                      tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
                      axisLine={{ stroke: "var(--border)" }}
                    />
                    <YAxis
                      type="category"
                      dataKey="effectName"
                      width={150}
                      tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
                      axisLine={{ stroke: "var(--border)" }}
                    />
                    <Tooltip
                      contentStyle={{
                        backgroundColor: "var(--card)",
                        border: "1px solid var(--border)",
                        borderRadius: "0.5rem",
                        color: "var(--foreground)",
                      }}
                      formatter={(value: unknown) => [(Number(value) || 0).toLocaleString(), "Tokens"]}
                    />
                    <Bar dataKey="totalTokens" fill="var(--chart-2)" radius={[0, 4, 4, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div className="flex items-center justify-center py-16 text-muted-foreground">
                  No effect data for this period.
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
