<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends TenantModel
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
            'title' => 'string|required|max:255',
            'user_id' => 'numeric|required|exists:users,id',
            'sub_title' => 'string|nullable|max:255',
            'state' => 'string|required|in:draft,published,archived',
            'content' => 'string|nullable|max:65535',
            'published_at' => 'date_format:Y-m-d H:i:s|nullable',
        ];
    }

    public static function getMessages()
    {
        return [
            'title.required' => 'Please enter a title so readers can identify your article.',
            'title.max' => 'Title must be under 255 characters for readability.',
            'state.in' => 'Article state must be one of: draft, published, or archived.',
            'content.max' => 'Article content exceeds the maximum allowed length.',
        ];
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
    //
}
