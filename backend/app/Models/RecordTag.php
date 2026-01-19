<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordTag extends BaseModel
{


    protected $fillable = [
        'tag_id',
        'record_id',
    ];

    protected $casts = [
    ];

    public static function getRules($id = null)
    {
        return [
            'tag_id' => 'numeric|required|exists:tags,id',
            'record_id' => 'numeric|required|exists:records,id',
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


