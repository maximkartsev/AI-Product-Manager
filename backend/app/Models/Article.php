<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends BaseModel
{


    protected $fillable = [
        'title',
        'user_id',
        'sub_title',
        'state',
        'content',
        'published_at',
    ];

    protected $casts = [
    ];

    public static function getRules($id = null)
    {
        return [
            'title' => 'string|required',
            'user_id' => 'numeric|required|exists:users,id',
            'sub_title' => 'string|nullable',
            'state' => 'string|required',
            'content' => 'string|nullable',
            'published_at' => 'date_format:Y-m-d H:i:s|nullable',
        ];
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
    //
}
