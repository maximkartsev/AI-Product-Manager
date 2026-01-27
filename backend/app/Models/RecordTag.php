<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordTag extends TenantModel
{


    protected $fillable = [
        'tag_id',
        'record_id',
    ];

    protected $casts = [
    ];

    public static function getRules($id = null)
    {
        $tenantId = tenant('id');
        $tagExists = $tenantId
            ? 'exists:tenant.tags,id,tenant_id,' . $tenantId
            : 'exists:tenant.tags,id';
        $recordExists = $tenantId
            ? 'exists:tenant.records,id,tenant_id,' . $tenantId
            : 'exists:tenant.records,id';

        return [
            'tag_id' => 'numeric|required|' . $tagExists,
            'record_id' => 'numeric|required|' . $recordExists,
        ];
    }

    public function tag()
    {
        return $this->belongsTo(\App\Models\Tag::class);
    }

    public function record()
    {
        return $this->belongsTo(\App\Models\Record::class);
    }
    //
}


