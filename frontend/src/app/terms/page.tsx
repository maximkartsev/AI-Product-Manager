import type { Metadata } from "next";
import TermsClient from "./TermsClient";

export const metadata: Metadata = {
  title: "Terms of Service | AI Video Effects Studio",
};

export default function TermsPage() {
  return <TermsClient />;
}
