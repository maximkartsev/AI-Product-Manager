<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Reward extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'trigger_event',
        'value',
        'credits_awarded',
        'icon',
        'badge_image',
        'points_required',
        'max_claims',
        'is_active',
        'is_recurring',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'credits_awarded' => 'integer',
        'points_required' => 'integer',
        'max_claims' => 'integer',
        'is_active' => 'boolean',
        'is_recurring' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:rewards,slug,' . $id,
            'description' => 'string|nullable',
            'type' => 'string|nullable|max:50',
            'trigger_event' => 'string|required|max:100',
            'value' => 'numeric|required|min:0',
            'credits_awarded' => 'integer|nullable|min:0',
            'icon' => 'string|nullable|max:255',
            'badge_image' => 'string|nullable|max:255',
            'points_required' => 'integer|nullable|min:0',
            'max_claims' => 'integer|nullable|min:0',
            'is_active' => 'boolean|nullable',
            'is_recurring' => 'boolean|nullable',
        ];
    }
}
