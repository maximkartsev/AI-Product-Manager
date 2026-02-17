<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends TenantModel
{

    protected $table = 'activity_log';

    protected $fillable = [
        'log_name',
        'description',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'changed_fields',
        'properties_diff',
        'batch_uuid',
        'event',
    ];

    protected $casts = [
    ];

    public static function getRules($id = null)
    {
        return [
            'log_name' => 'string|nullable|max:255',
            'description' => 'string|required|max:65535',
            'subject_type' => 'string|nullable|max:255',
            'subject_id' => 'numeric|nullable',
            'causer_type' => 'string|nullable|max:255',
            'causer_id' => 'numeric|nullable',
            'properties' => 'string|nullable|max:65535',
            'changed_fields' => 'string|nullable|max:65535',
            'properties_diff' => 'string|nullable|max:65535',
            'batch_uuid' => 'string|nullable|max:36',
            'event' => 'string|nullable|max:255',
        ];
    }

    public function causer()
    {
        return $this->morphTo(\App\Models\User::class);
    }
    //
}
