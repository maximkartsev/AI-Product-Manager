<?php

namespace App\Models;

class RunArtifact extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'run_artifacts';

    protected $fillable = [
        'effect_test_run_id',
        'load_test_run_id',
        'artifact_type',
        'storage_disk',
        'storage_path',
        'metadata_json',
    ];

    protected $casts = [
        'effect_test_run_id' => 'integer',
        'load_test_run_id' => 'integer',
        'metadata_json' => 'array',
    ];
}
