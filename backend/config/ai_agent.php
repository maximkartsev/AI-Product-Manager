<?php

/**
 * AI Agent Command Registry (portable baseline).
 *
 * This file is installed by `aios/v1/tools/bootstrap.sh`.
 */
return [
    'commands' => [
        [
            'name' => 'make:migration',
            'category' => 'db',
            'purpose' => 'Generate a new Laravel migration file.',
            'usage' => 'php artisan make:migration {name}',
            'notes' => [
                'Use before running `php artisan migrate`.',
                'Prefer descriptive names like: create_videos_table, add_user_id_to_exports_table.',
            ],
        ],
        [
            'name' => 'migrate',
            'category' => 'db',
            'purpose' => 'Run database migrations.',
            'usage' => 'php artisan migrate {--force}',
            'notes' => [
                'Use --force for non-interactive execution in automated environments.',
                'This project uses pooled tenant DBs. Prefer running: `php artisan tenancy:pools-migrate`.',
            ],
        ],
        [
            'name' => 'tenancy:install',
            'category' => 'db',
            'purpose' => 'Install tenancy scaffolding (config, migrations, provider, tenant routes).',
            'usage' => 'php artisan tenancy:install',
            'notes' => [
                'Run once when adding stancl/tenancy to a project.',
                'In pooled-DB mode, disable per-tenant DB creation jobs and use a custom DB-pool bootstrapper.',
            ],
        ],
        [
            'name' => 'tenants:list',
            'category' => 'verification',
            'purpose' => 'List tenants and their domains.',
            'usage' => 'php artisan tenants:list',
            'notes' => [
                'Useful to verify domain → tenant mapping exists.',
            ],
        ],
        [
            'name' => 'make:seeder',
            'category' => 'db',
            'purpose' => 'Generate a new Laravel seeder class.',
            'usage' => 'php artisan make:seeder {name}',
            'notes' => [
                'Use for local/dev demo data (see AI OS prompt P08).',
                'Do not make tests depend on db:seed; tests should create their own data.',
            ],
        ],
        [
            'name' => 'db:seed',
            'category' => 'db',
            'purpose' => 'Seed the database (local/dev demo data).',
            'usage' => 'php artisan db:seed {--class=YourSeederClass} {--force}',
            'notes' => [
                'Prefer seeding only in local/dev environments.',
                'Use --class to run a specific seeder.',
            ],
        ],
        [
            'name' => 'make:factory',
            'category' => 'db',
            'purpose' => 'Generate a model factory (used by seeders and tests).',
            'usage' => 'php artisan make:factory {name} {--model=ModelClass}',
            'notes' => [
                'Factories are preferred for tests (deterministic setup).',
            ],
        ],
        [
            'name' => 'create:model-controller',
            'category' => 'codegen',
            'purpose' => 'Generate Model + Controller + Resource + Route + Postman doc from an existing DB table.',
            'usage' => 'php artisan create:model-controller {table} {entity} {--only=model,controller,resource,doc,route,translations} {--dry-run}',
            'notes' => [
                'Run after the migration exists (table must exist).',
                'Generated controllers must follow MVC and extend BaseController.',
                'After generation, run translations scan (auto-triggered by the command).',
                'Use --dry-run to see planned outputs without writing files.',
            ],
        ],
        [
            'name' => 'translations:scan',
            'category' => 'i18n',
            'purpose' => 'Scan backend for trans() phrases and update translation JSON (uk).',
            'usage' => 'php artisan translations:scan {--check}',
            'notes' => [
                'Used after adding new controllers/models/resources with trans() phrases.',
                'Use --check to fail (without writing) if new phrases are detected.',
            ],
        ],
        [
            'name' => 'route:list',
            'category' => 'verification',
            'purpose' => 'List registered routes (useful to verify generated CRUD endpoints exist).',
            'usage' => 'php artisan route:list',
            'notes' => [
                'Use after running codegen to verify routes were registered in routes/api.php.',
            ],
        ],
        [
            'name' => 'test',
            'category' => 'verification',
            'purpose' => 'Run automated tests (backend).',
            'usage' => 'php artisan test',
            'notes' => [
                'Prefer running via: make preflight (includes docs + migrate + i18n check + frontend build).',
            ],
        ],
        [
            'name' => 'ai:commands',
            'category' => 'ai-agent',
            'purpose' => 'List the curated AI agent command registry (this file).',
            'usage' => 'php artisan ai:commands {--json}',
            'notes' => [
                'Use --json for machine-readable output.',
                'Extend the registry by editing backend/config/ai_agent.php.',
            ],
        ],
    ],
];

