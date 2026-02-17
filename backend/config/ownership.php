<?php

/**
 * Ownership enforcement configuration (portable baseline).
 *
 * This file is installed by `aios/v1/tools/bootstrap.sh`.
 */
return [
    'enabled' => env('OWNERSHIP_ENABLED', true),

    'user_key' => env('OWNERSHIP_USER_KEY', 'user_id'),

    'force_global_tables' => array_values(array_filter(array_map(
        static fn ($v) => trim((string) $v),
        explode(',', (string) env('OWNERSHIP_FORCE_GLOBAL_TABLES', ''))
    ))),

    'enforce_user_scope' => env('OWNERSHIP_ENFORCE_USER_SCOPE', true),
];

