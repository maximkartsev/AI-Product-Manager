<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends BaseModel
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
        return [
            'user_id' => 'numeric|required|exists:users,id',
            'record_id' => 'numeric|nullable',
            'rating' => 'string|nullable',
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
