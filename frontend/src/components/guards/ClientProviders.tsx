"use client";

import AppHeader from "@/components/layout/AppHeader";
import AppFooter from "@/components/layout/AppFooter";
import UiGuardsProvider from "./UiGuardsProvider";

export default function ClientProviders({ children }: { children: React.ReactNode }) {
  return (
    <UiGuardsProvider>
      <AppHeader />
      {children}
      <AppFooter />
    </UiGuardsProvider>
  );
}
