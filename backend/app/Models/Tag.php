<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends BaseModel
{
    protected $fillable = [
        'name',
    ];

    protected $casts = [
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required',
        ];
    }

    public function overlays(): MorphToMany
    {
        return $this->morphedByMany(Overlay::class, 'taggable', 'taggables')->withTimestamps();
    }

    public function watermarks(): MorphToMany
    {
        return $this->morphedByMany(Watermark::class, 'taggable', 'taggables')->withTimestamps();
    }
}
