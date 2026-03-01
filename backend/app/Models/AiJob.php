<?php

namespace App\Models;

class AiJob extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'effect_id',
        'video_id',
        'input_file_id',
        'output_file_id',
        'status',
        'idempotency_key',
        'requested_tokens',
        'reserved_tokens',
        'consumed_tokens',
        'provider_job_id',
        'input_payload',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'effect_id' => 'integer',
        'video_id' => 'integer',
        'input_file_id' => 'integer',
        'output_file_id' => 'integer',
        'requested_tokens' => 'integer',
        'reserved_tokens' => 'integer',
        'consumed_tokens' => 'integer',
        'input_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'tenant_id' => 'string|required|max:255',
            'user_id' => 'numeric|required',
            'effect_id' => 'numeric|required',
            'video_id' => 'numeric|nullable',
            'input_file_id' => 'numeric|nullable',
            'output_file_id' => 'numeric|nullable',
            'status' => 'string|required|max:50',
            'idempotency_key' => 'string|required|max:255',
            'requested_tokens' => 'numeric|required',
            'reserved_tokens' => 'numeric|required',
            'consumed_tokens' => 'numeric|required',
            'provider_job_id' => 'string|nullable|max:255',
            'input_payload' => 'array|nullable',
            'error_message' => 'string|nullable',
            'started_at' => 'date_format:Y-m-d H:i:s|nullable',
            'completed_at' => 'date_format:Y-m-d H:i:s|nullable',
        ];
    }
}
