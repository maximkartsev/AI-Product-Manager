<?php

namespace App\Models;

class EffectRevision extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'effect_revisions';

    protected $fillable = [
        'effect_id',
        'workflow_id',
        'category_id',
        'publication_status',
        'property_overrides',
        'snapshot_json',
        'recommended_execution_environment_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'effect_id' => 'integer',
        'workflow_id' => 'integer',
        'category_id' => 'integer',
        'property_overrides' => 'array',
        'snapshot_json' => 'array',
        'recommended_execution_environment_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    public function effect()
    {
        return $this->belongsTo(Effect::class);
    }

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function recommendedExecutionEnvironment()
    {
        return $this->belongsTo(ExecutionEnvironment::class, 'recommended_execution_environment_id');
    }
}
