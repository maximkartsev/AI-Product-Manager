<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'parent_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:categories,slug,' . $id,
            'description' => 'string|nullable',
            'icon' => 'string|nullable|max:255',
            'color' => 'string|nullable|max:50',
            'parent_id' => 'integer|nullable|exists:categories,id',
            'sort_order' => 'integer|nullable',
            'is_active' => 'boolean|nullable',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function effects(): BelongsToMany
    {
        return $this->belongsToMany(Effect::class, 'category_effect')->withTimestamps();
    }
}
