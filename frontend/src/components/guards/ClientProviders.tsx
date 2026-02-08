"use client";

import AppHeader from "@/components/layout/AppHeader";
import UiGuardsProvider from "./UiGuardsProvider";

export default function ClientProviders({ children }: { children: React.ReactNode }) {
  return (
    <UiGuardsProvider>
      <AppHeader />
      {children}
    </UiGuardsProvider>
  );
}
