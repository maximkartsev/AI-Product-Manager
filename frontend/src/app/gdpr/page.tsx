import type { Metadata } from "next";
import GdprClient from "./GdprClient";

export const metadata: Metadata = {
  title: "GDPR | AI Video Effects Studio",
};

export default function GdprPage() {
  return <GdprClient />;
}
