import { Suspense } from "react";
import LandingHome from "./_components/landing/LandingHome";

export default function HomePage() {
  return (
    <Suspense>
      <LandingHome />
    </Suspense>
  );
}
