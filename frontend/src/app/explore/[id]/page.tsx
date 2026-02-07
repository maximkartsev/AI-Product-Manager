import ExploreDetailClient from "./ExploreDetailClient";

export default async function ExploreDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const numericId = Number(id);

  if (!Number.isFinite(numericId) || numericId <= 0) {
    return <ExploreDetailClient id={0} />;
  }

  return <ExploreDetailClient id={Math.trunc(numericId)} />;
}
