import type { Metadata } from "next";
import ContentPolicyClient from "./ContentPolicyClient";

export const metadata: Metadata = {
  title: "Acceptable Use Policy | AI Video Effects Studio",
};

export default function ContentPolicyPage() {
  return <ContentPolicyClient />;
}
