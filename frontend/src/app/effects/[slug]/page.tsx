import EffectDetailClient from "./EffectDetailClient";

export default async function EffectDetailPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  return <EffectDetailClient slug={slug} />;
}

