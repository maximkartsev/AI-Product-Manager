import Link from "next/link";
import type { ReactNode } from "react";

export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-screen bg-gray-950 text-white">
      <div className="flex min-h-screen">
        <aside className="w-56 border-r border-gray-800 bg-gray-900/60 p-4">
          <div className="text-xs uppercase tracking-wide text-gray-400">Admin</div>
          <nav className="mt-4 space-y-2">
            <Link
              href="/admin/effects"
              className="block rounded-lg px-3 py-2 text-sm font-medium text-gray-200 hover:bg-gray-800"
            >
              Effects
            </Link>
          </nav>
        </aside>
        <main className="flex-1">{children}</main>
      </div>
    </div>
  );
}
