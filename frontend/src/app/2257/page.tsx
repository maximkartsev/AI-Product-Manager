import type { Metadata } from "next";
import ComplianceClient from "./ComplianceClient";

export const metadata: Metadata = {
  title: "2257 Compliance | AI Video Effects Studio",
};

export default function CompliancePage() {
  return <ComplianceClient />;
}
