<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Algorithm extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'category',
        'parameters',
        'default_values',
        'complexity_factor',
        'is_active',
        'is_gpu_required',
    ];

    protected $casts = [
        'parameters' => 'array',
        'default_values' => 'array',
        'complexity_factor' => 'decimal:2',
        'is_active' => 'boolean',
        'is_gpu_required' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:algorithms,slug,' . $id,
            'description' => 'string|nullable',
            'type' => 'string|nullable|max:50',
            'category' => 'string|nullable|max:100',
            'parameters' => 'array|nullable',
            'default_values' => 'array|nullable',
            'complexity_factor' => 'numeric|nullable|min:0',
            'is_active' => 'boolean|nullable',
            'is_gpu_required' => 'boolean|nullable',
        ];
    }

    public function effects(): BelongsToMany
    {
        return $this->belongsToMany(Effect::class, 'algorithm_effect')
            ->withPivot(['sort_order', 'config'])
            ->withTimestamps();
    }
}
