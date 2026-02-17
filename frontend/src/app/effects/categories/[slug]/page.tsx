import CategoryEffectsClient from "./CategoryEffectsClient";

export default async function CategoryEffectsPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  return <CategoryEffectsClient slug={slug} />;
}
