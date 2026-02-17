"use client";

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Loader2, Coins, ShoppingCart, Calendar } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DataTableView } from "@/components/ui/DataTable";
import { useDataTable } from "@/hooks/useDataTable";
import {
  getAdminUser,
  getAdminUserPurchases,
  getAdminUserTokens,
  type AdminUserDetail,
  type AdminPurchase,
  type AdminTokenTransaction,
} from "@/lib/api";
import type { FilterValue } from "@/components/ui/SmartFilters";

function PurchasesTable({ userId, userName }: { userId: number; userName: string }) {
  const state = useDataTable<AdminPurchase>({
    entityClass: "Purchase",
    entityName: "Purchase",
    storageKey: `admin-user-${userId}-purchases-columns`,
    list: async (params: {
      page: number;
      perPage: number;
      search?: string;
      filters?: FilterValue[];
      order?: string;
    }) => {
      const data = await getAdminUserPurchases(userId, {
        page: params.page,
        perPage: params.perPage,
      });
      return {
        items: data.items,
        totalItems: data.totalItems,
        totalPages: data.totalPages,
      };
    },
    getItemId: (item) => item.id,
    renderCellValue: (purchase, columnKey) => {
      if (columnKey === "status") {
        const color = purchase.status === "completed" ? "text-green-400" : purchase.status === "pending" ? "text-yellow-400" : "text-muted-foreground";
        return <span className={color}>{purchase.status}</span>;
      }
      if (columnKey === "total_amount") {
        return <span className="text-foreground">${Number(purchase.total_amount).toFixed(2)}</span>;
      }
      if (columnKey === "payment") {
        return <span className="text-muted-foreground">{purchase.payment?.payment_gateway ?? "-"}</span>;
      }
      const value = purchase[columnKey as keyof AdminPurchase];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
  });

  return (
    <DataTableView
      state={state}
      options={{
        entityClass: "Purchase",
        entityName: "Purchase",
        title: "Purchases",
        description: `Purchase history for ${userName}`,
        readOnly: true,
      }}
    />
  );
}

function TokensTable({ userId, userName, onBalanceUpdate }: { userId: number; userName: string; onBalanceUpdate: (balance: number) => void }) {
  const state = useDataTable<AdminTokenTransaction>({
    entityClass: "TokenTransaction",
    entityName: "Token Transaction",
    storageKey: `admin-user-${userId}-tokens-columns`,
    list: async (params: {
      page: number;
      perPage: number;
      search?: string;
      filters?: FilterValue[];
      order?: string;
    }) => {
      const data = await getAdminUserTokens(userId, {
        page: params.page,
        perPage: params.perPage,
      });
      onBalanceUpdate(data.balance);
      return {
        items: data.items,
        totalItems: data.totalItems,
        totalPages: data.totalPages,
      };
    },
    getItemId: (item) => item.id,
    renderCellValue: (tx, columnKey) => {
      if (columnKey === "amount") {
        const color = tx.amount >= 0 ? "text-green-400" : "text-red-400";
        const prefix = tx.amount >= 0 ? "+" : "";
        return <span className={color}>{prefix}{tx.amount}</span>;
      }
      if (columnKey === "type") {
        return (
          <span className="inline-flex items-center rounded-full bg-muted/50 px-2 py-0.5 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">
            {tx.type}
          </span>
        );
      }
      const value = tx[columnKey as keyof AdminTokenTransaction];
      if (value === null || value === undefined || value === "") {
        return <span className="text-muted-foreground">-</span>;
      }
      return <span className="text-muted-foreground">{String(value)}</span>;
    },
  });

  return (
    <DataTableView
      state={state}
      options={{
        entityClass: "TokenTransaction",
        entityName: "Token Transaction",
        title: "Token Transactions",
        description: `Token transaction history for ${userName}`,
        readOnly: true,
      }}
    />
  );
}

export default function AdminUserDetailPage() {
  const params = useParams();
  const router = useRouter();
  const userId = Number(params.id);

  const [user, setUser] = useState<AdminUserDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [tokenBalance, setTokenBalance] = useState<number>(0);
  const [totalPurchases, setTotalPurchases] = useState<number>(0);

  useEffect(() => {
    const load = async () => {
      setLoading(true);
      try {
        const [userData, purchasesData, tokensData] = await Promise.all([
          getAdminUser(userId),
          getAdminUserPurchases(userId, { page: 1, perPage: 1 }),
          getAdminUserTokens(userId, { page: 1, perPage: 1 }),
        ]);
        setUser(userData);
        setTotalPurchases(purchasesData.totalItems);
        setTokenBalance(tokensData.balance);
      } catch (error) {
        console.error("Failed to load user:", error);
      } finally {
        setLoading(false);
      }
    };
    load();
  }, [userId]);

  if (loading) {
    return (
      <div className="flex items-center justify-center py-32">
        <Loader2 className="w-8 h-8 text-muted-foreground animate-spin" />
      </div>
    );
  }

  if (!user) {
    return (
      <div className="flex items-center justify-center py-32">
        <p className="text-muted-foreground">User not found.</p>
      </div>
    );
  }

  return (
    <div>
      <div className="space-y-6">
        <header className="space-y-4">
          <Button
            variant="ghost"
            size="sm"
            className="text-muted-foreground hover:text-foreground"
            onClick={() => router.push("/admin/users")}
          >
            <ArrowLeft className="w-4 h-4 mr-2" />
            Back to Users
          </Button>
          <div>
            <h1 className="text-2xl md:text-3xl font-semibold">{user.name}</h1>
            <p className="text-sm text-muted-foreground">{user.email}</p>
          </div>
        </header>

        <div className="grid gap-4 md:grid-cols-3">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Token Balance</CardTitle>
              <Coins className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{tokenBalance.toLocaleString()}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Total Purchases</CardTitle>
              <ShoppingCart className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{totalPurchases}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Member Since</CardTitle>
              <Calendar className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">
                {user.created_at ? new Date(user.created_at).toLocaleDateString() : "-"}
              </div>
            </CardContent>
          </Card>
        </div>

        <Tabs defaultValue="purchases" className="space-y-4">
          <TabsList>
            <TabsTrigger value="purchases">Purchases</TabsTrigger>
            <TabsTrigger value="tokens">Tokens</TabsTrigger>
          </TabsList>

          <TabsContent value="purchases">
            <PurchasesTable userId={userId} userName={user.name} />
          </TabsContent>

          <TabsContent value="tokens">
            <div className="mb-4">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">Current Balance</CardTitle>
                  <Coins className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{tokenBalance.toLocaleString()} tokens</div>
                </CardContent>
              </Card>
            </div>
            <TokensTable userId={userId} userName={user.name} onBalanceUpdate={setTokenBalance} />
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
}
