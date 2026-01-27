<?php

return [
    /**
     * Default pool connection name used when a tenant has no explicit db_pool value.
     */
    'default' => env('TENANT_DEFAULT_POOL', 'tenant_pool_1'),

    /**
     * Ordered list of tenant pool connection names.
     *
     * Used by tooling (migrations) and validation.
     */
    'connections' => array_values(array_filter(array_map(
        static fn ($v) => trim((string) $v),
        explode(',', (string) env('TENANT_POOL_CONNECTIONS', 'tenant_pool_1,tenant_pool_2'))
    ))),
];

