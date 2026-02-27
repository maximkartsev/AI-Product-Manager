import * as fs from 'fs';
import * as path from 'path';

/**
 * Fleet configuration for GPU worker ASGs.
 *
 * Each fleet maps to a dedicated ASG + AMI alias. Workflows are assigned
 * to fleets at runtime by the backend (not via infra config).
 */
export interface FleetConfig {
  /** Fleet slug (used in ASG/SSM naming) */
  readonly slug: string;

  /** Display name for CloudWatch dashboard */
  readonly displayName: string;

  /** AMI ID (Packer-built, fleet-specific). Set via cdk.json context or SSM. */
  readonly amiId?: string;

  /** SSM parameter path storing the AMI ID (alternative to hardcoded amiId) */
  readonly amiSsmParameter?: string;

  /** GPU instance types in priority order for Spot allocation */
  readonly instanceTypes: string[];

  /** Maximum number of instances in the ASG */
  readonly maxSize: number;

  /** Default instance warmup in seconds */
  readonly warmupSeconds?: number;

  /** Target backlog per instance for scaling (default: 2) */
  readonly backlogTarget?: number;

  /** Minutes of zero queue depth before scaling to zero (default: 15) */
  readonly scaleToZeroMinutes?: number;
}

export interface FleetTemplate {
  readonly templateSlug: string;
  readonly displayName: string;
  readonly allowedInstanceTypes: string[];
  readonly maxSize: number;
  readonly warmupSeconds?: number;
  readonly backlogTarget?: number;
  readonly scaleToZeroMinutes?: number;
}

type FleetTemplateRecord = {
  template_slug: string;
  display_name: string;
  allowed_instance_types: string[];
  max_size: number;
  warmup_seconds?: number | null;
  backlog_target?: number | null;
  scale_to_zero_minutes?: number | null;
};

const TEMPLATES_PATH = path.resolve(
  __dirname,
  '../../../backend/resources/comfyui/fleet-templates.json'
);

function loadFleetTemplates(): FleetTemplate[] {
  if (!fs.existsSync(TEMPLATES_PATH)) {
    throw new Error(`Fleet templates file not found: ${TEMPLATES_PATH}`);
  }

  const raw = fs.readFileSync(TEMPLATES_PATH, 'utf-8');
  const parsed = JSON.parse(raw) as FleetTemplateRecord[];
  if (!Array.isArray(parsed)) {
    throw new Error('Fleet templates must be a JSON array.');
  }

  const seen = new Set<string>();
  return parsed.map((item) => {
    if (!item.template_slug || !item.display_name) {
      throw new Error('Fleet template missing template_slug or display_name.');
    }
    if (!Array.isArray(item.allowed_instance_types) || item.allowed_instance_types.length === 0) {
      throw new Error(`Fleet template ${item.template_slug} must define allowed_instance_types.`);
    }
    if (typeof item.max_size !== 'number') {
      throw new Error(`Fleet template ${item.template_slug} must define max_size.`);
    }
    if (seen.has(item.template_slug)) {
      throw new Error(`Duplicate fleet template slug: ${item.template_slug}`);
    }
    seen.add(item.template_slug);

    return {
      templateSlug: item.template_slug,
      displayName: item.display_name,
      allowedInstanceTypes: item.allowed_instance_types,
      maxSize: item.max_size,
      warmupSeconds: item.warmup_seconds ?? undefined,
      backlogTarget: item.backlog_target ?? undefined,
      scaleToZeroMinutes: item.scale_to_zero_minutes ?? undefined,
    };
  });
}

export const FLEET_TEMPLATES: FleetTemplate[] = loadFleetTemplates();

/**
 * Active fleet configurations.
 *
 * AMI IDs should be stored in SSM Parameter Store and updated by the
 * Packer build pipeline. If amiSsmParameter is not provided, the
 * Fleet ASG construct will default to: /bp/ami/fleets/<fleet_stage>/<fleet-slug>
 */
export const FLEETS: FleetConfig[] = FLEET_TEMPLATES.map((template) => ({
  slug: template.templateSlug,
  displayName: template.displayName,
  instanceTypes: template.allowedInstanceTypes,
  maxSize: template.maxSize,
  warmupSeconds: template.warmupSeconds,
  backlogTarget: template.backlogTarget,
  scaleToZeroMinutes: template.scaleToZeroMinutes,
}));

export function getFleetTemplateBySlug(slug: string): FleetTemplate | undefined {
  return FLEET_TEMPLATES.find((template) => template.templateSlug === slug);
}
