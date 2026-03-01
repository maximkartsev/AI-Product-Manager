<?php

namespace App\Models;

class EffectVariantBinding extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'effect_variant_bindings';

    protected $fillable = [
        'effect_id',
        'effect_revision_id',
        'variant_id',
        'workflow_id',
        'execution_environment_id',
        'stage',
        'is_active',
        'rollback_of_binding_id',
        'reason_json',
        'created_by_user_id',
        'applied_at',
    ];

    protected $casts = [
        'effect_id' => 'integer',
        'effect_revision_id' => 'integer',
        'workflow_id' => 'integer',
        'execution_environment_id' => 'integer',
        'is_active' => 'boolean',
        'rollback_of_binding_id' => 'integer',
        'reason_json' => 'array',
        'created_by_user_id' => 'integer',
        'applied_at' => 'datetime',
    ];
}

