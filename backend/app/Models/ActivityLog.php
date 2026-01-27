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
            'log_name' => 'string|nullable',
            'description' => 'string|required',
            'subject_type' => 'string|nullable',
            'subject_id' => 'numeric|nullable',
            'causer_type' => 'string|nullable',
            'causer_id' => 'numeric|nullable',
            'properties' => 'string|nullable',
            'changed_fields' => 'string|nullable',
            'properties_diff' => 'string|nullable',
            'batch_uuid' => 'string|nullable',
            'event' => 'string|nullable',
        ];
    }

    public function causer()
    {
        return $this->morphTo(\App\Models\User::class);
    }
    //
}
