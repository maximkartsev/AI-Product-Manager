"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import {
  Sparkles,
  FolderOpen,
  Users,
  BarChart3,
  Menu,
  X,
  GitBranch,
  Server,
  ScrollText,
  Activity,
  Boxes,
  Package,
  Trash2,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Sheet, SheetContent } from "@/components/ui/sheet";

const NAV_GROUPS = [
  {
    label: "Application",
    items: [
      { label: "Effects", href: "/admin/effects", icon: Sparkles },
      { label: "Categories", href: "/admin/categories", icon: FolderOpen },
      { label: "Workflows", href: "/admin/workflows", icon: GitBranch },
    ],
  },
  {
    label: "ComfyUI Ops",
    items: [
      { label: "Assets", href: "/admin/comfyui/assets", icon: Boxes },
      { label: "Bundles", href: "/admin/comfyui/bundles", icon: Package },
      { label: "Fleets", href: "/admin/comfyui/fleets", icon: Server },
      { label: "Cleanup", href: "/admin/comfyui/cleanup", icon: Trash2 },
      { label: "Asset Audit Logs", href: "/admin/comfyui/audit-logs", icon: ScrollText },
    ],
  },
  {
    label: "Platform Ops",
    items: [
      { label: "Users", href: "/admin/users", icon: Users },
      { label: "Workload", href: "/admin/workload", icon: Activity },
      { label: "Workers", href: "/admin/workers", icon: Server },
      { label: "Audit Logs", href: "/admin/audit-logs", icon: ScrollText },
      { label: "Analytics", href: "/admin/analytics", icon: BarChart3 },
    ],
  },
];

function SidebarNav({ pathname, onNavigate }: { pathname: string; onNavigate?: () => void }) {
  return (
    <nav className="flex flex-col gap-1 px-3 py-4 min-h-0 overflow-y-auto">
      <h2 className="mb-2 px-3 text-xs font-semibold uppercase tracking-widest text-muted-foreground">
        Admin
      </h2>
      {NAV_GROUPS.map((group) => (
        <div key={group.label} className="mb-3 last:mb-0">
          <h3 className="px-3 py-2 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
            {group.label}
          </h3>
          <div className="flex flex-col gap-1">
            {group.items.map((item) => {
              const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
              const Icon = item.icon;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  onClick={onNavigate}
                  aria-current={active ? "page" : undefined}
                  className={cn(
                    "flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors",
                    "text-muted-foreground hover:bg-accent hover:text-accent-foreground",
                    active && "bg-accent text-accent-foreground",
                  )}
                >
                  <Icon className="h-4 w-4" />
                  {item.label}
                </Link>
              );
            })}
          </div>
        </div>
      ))}
    </nav>
  );
}

export default function AdminLayout({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => {
    document.body.classList.add("overflow-hidden", "h-dvh", "flex", "flex-col", "admin-theme");
    return () => {
      document.body.classList.remove("overflow-hidden", "h-dvh", "flex", "flex-col", "admin-theme");
    };
  }, []);

  return (
    <div className="admin-theme flex-1 min-h-0">
      <div className="flex h-full overflow-hidden bg-background text-foreground">
        {/* Desktop sidebar */}
        <aside className="hidden lg:flex w-56 shrink-0 flex-col border-r border-border bg-card overflow-y-auto">
          <SidebarNav pathname={pathname} />
        </aside>

        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Mobile top bar */}
          <div className="flex items-center border-b border-border bg-card px-4 py-3 lg:hidden">
            <button
              type="button"
              onClick={() => setMobileOpen(true)}
              className="rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
            >
              <Menu className="h-5 w-5" />
            </button>
            <span className="ml-3 text-sm font-semibold">Admin</span>
          </div>

          {/* Mobile sidebar sheet */}
          <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
            <SheetContent side="left" className="w-56 p-0 overflow-y-auto">
              <SidebarNav pathname={pathname} onNavigate={() => setMobileOpen(false)} />
            </SheetContent>
          </Sheet>

          {/* Main content — scrolls independently */}
          <main className="flex-1 overflow-y-auto">
            <div className="mx-auto max-w-6xl p-6">
              {children}
            </div>
          </main>
        </div>
      </div>
    </div>
  );
}
