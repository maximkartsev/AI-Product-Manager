import { Suspense } from "react";
import ExploreClient from "./ExploreClient";

export default function ExplorePage() {
  return (
    <Suspense>
      <ExploreClient />
    </Suspense>
  );
}
