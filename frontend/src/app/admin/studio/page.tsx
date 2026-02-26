import Link from "next/link";

const studioSections = [
  {
    title: "Create Effect Wizard",
    description: "Run the Phase 1 stepper: choose workflow source, analyze, apply suggestions, and create effect.",
    href: "/admin/studio/effects/new",
    cta: "Open Create Wizard",
  },
  {
    title: "Clone Effect",
    description: "Clone an effect only, or clone effect + workflow in one Studio operation.",
    href: "/admin/studio/effects/clone",
    cta: "Open Clone Flow",
  },
  {
    title: "Workflow JSON Editor",
    description: "Open workflows and jump into /admin/studio/workflows/{id}/json-editor for revision-based JSON editing.",
    href: "/admin/workflows",
    cta: "Open Workflows",
  },
  {
    title: "Dev Nodes",
    description: "Manage test-stage dev nodes and inspect attached execution environments.",
    href: "/admin/workers",
    cta: "Open Workers",
  },
  {
    title: "Load Test Scenarios",
    description: "Define and tune staged load-test patterns for effect validation.",
    href: "/admin/effects",
    cta: "Open Effects",
  },
  {
    title: "Studio Cost Model",
    description: "Compare compute and partner cost assumptions for scenario planning.",
    href: "/admin/economics",
    cta: "Open Economics",
  },
];

export default function AdminStudioPage() {
  return (
    <div className="space-y-6 p-4 sm:p-6">
      <div className="space-y-2">
        <h1 className="text-2xl font-semibold tracking-tight">Effects Design Studio</h1>
        <p className="text-sm text-muted-foreground">
          Phase 1 entry points for create/clone flows and workflow JSON editing, plus Phase 0 studio surfaces.
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        {studioSections.map((section) => (
          <div
            key={section.title}
            className="rounded-lg border border-border/60 bg-card p-4 shadow-sm"
          >
            <h2 className="text-base font-semibold">{section.title}</h2>
            <p className="mt-2 text-sm text-muted-foreground">{section.description}</p>
            <div className="mt-4">
              <Link
                href={section.href}
                className="inline-flex items-center rounded-md border border-border px-3 py-1.5 text-sm font-medium hover:bg-muted"
              >
                {section.cta}
              </Link>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
