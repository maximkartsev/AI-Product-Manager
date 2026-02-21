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

/**
 * Active fleet configurations.
 *
 * AMI IDs should be stored in SSM Parameter Store and updated by the
 * Packer build pipeline. If amiSsmParameter is not provided, the
 * Fleet ASG construct will default to: /bp/ami/fleets/<stage>/<fleet-slug>
 */
export const FLEETS: FleetConfig[] = [
  {
    slug: 'gpu-default',
    displayName: 'Default GPU Fleet',
    instanceTypes: ['g4dn.xlarge', 'g5.xlarge'],
    maxSize: 10,
    warmupSeconds: 300,
    backlogTarget: 2,
    scaleToZeroMinutes: 15,
  },
];
