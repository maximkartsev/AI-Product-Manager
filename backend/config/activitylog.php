<?php

return [
    /*
     * When set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * Activity model used by the package.
     *
     * We override this so `tenant_id` is set automatically in pooled tenant DBs.
     */
    'activity_model' => \App\Models\TenantActivity::class,

    /*
     * The name of the table where the activities will be stored.
     */
    'table_name' => 'activity_log',

    /*
     * The database connection where the activity log table lives.
     *
     * In this project, activity logs are tenant-private and stored in pooled tenant DBs.
     */
    'database_connection' => env('ACTIVITYLOG_DB_CONNECTION', 'tenant'),
];

