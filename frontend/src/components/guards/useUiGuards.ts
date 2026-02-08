"use client";

import { useContext } from "react";
import { UiGuardsContext } from "./UiGuardsProvider";

export default function useUiGuards() {
  const ctx = useContext(UiGuardsContext);
  if (!ctx) {
    throw new Error("useUiGuards must be used within UiGuardsProvider");
  }
  return ctx;
}
