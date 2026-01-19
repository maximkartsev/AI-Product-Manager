<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rollout extends BaseModel
{


    protected $fillable = [
        'user_id',
        'commit_id',
        'date',
    ];

    protected $casts = [
        'date' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'user_id' => 'numeric|required|exists:users,id',
            'commit_id' => 'string|required',
            'date' => 'date|required',
        ];
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
    //
}


