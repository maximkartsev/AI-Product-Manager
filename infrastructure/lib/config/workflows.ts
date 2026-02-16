/**
 * Workflow configuration for GPU worker ASGs.
 *
 * Each workflow gets a dedicated ASG with a Packer-built AMI containing
 * pre-loaded neural network models. Add entries here and run `cdk deploy`
 * to provision new per-workflow ASGs automatically.
 */
export interface WorkflowConfig {
  /** Workflow slug (must match backend workflows.slug column) */
  readonly slug: string;

  /** Display name for CloudWatch dashboard */
  readonly displayName: string;

  /** AMI ID (Packer-built, workflow-specific). Set via cdk.json context or SSM. */
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
 * Active workflow configurations.
 *
 * AMI IDs should be stored in SSM Parameter Store and updated by the
 * Packer build pipeline (build-ami.yml). The amiSsmParameter path follows
 * the pattern: /bp/ami/<workflow-slug>
 */
export const WORKFLOWS: WorkflowConfig[] = [
  {
    slug: 'image-to-video',
    displayName: 'Image to Video',
    amiSsmParameter: '/bp/ami/image-to-video',
    instanceTypes: ['g4dn.xlarge', 'g5.xlarge'],
    maxSize: 10,
    warmupSeconds: 300,
    backlogTarget: 2,
    scaleToZeroMinutes: 15,
  },
  // Add more workflows as needed:
  // {
  //   slug: 'face-swap',
  //   displayName: 'Face Swap',
  //   amiSsmParameter: '/bp/ami/face-swap',
  //   instanceTypes: ['g4dn.xlarge'],
  //   maxSize: 5,
  // },
];
