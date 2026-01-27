<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends TenantModel
{


    protected $fillable = [
        'user_id',
        'record_id',
        'rating',
        'comment',
    ];

    protected $casts = [
    ];

    public static function getRules($id = null)
    {
        $tenantId = tenant('id');
        $recordExists = $tenantId
            ? 'exists:tenant.records,id,tenant_id,' . $tenantId
            : 'exists:tenant.records,id';

        return [
            'user_id' => 'numeric|required|exists:users,id',
            'record_id' => 'numeric|nullable|' . $recordExists,
            'rating' => 'integer|nullable|min:1|max:5',
            'comment' => 'string|nullable',
        ];
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function record()
    {
        return $this->belongsTo(\App\Models\Record::class);
    }
    //
}
