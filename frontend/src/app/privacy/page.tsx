import type { Metadata } from "next";
import PrivacyClient from "./PrivacyClient";

export const metadata: Metadata = {
  title: "Privacy Policy | AI Video Effects Studio",
};

export default function PrivacyPage() {
  return <PrivacyClient />;
}
