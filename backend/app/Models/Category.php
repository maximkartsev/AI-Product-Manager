<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends CentralModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255',
            'description' => 'string|nullable',
            'deleted_at' => 'date_format:Y-m-d H:i:s|nullable',
        ];
    }

    public function effects()
    {
        return $this->hasMany(\App\Models\Effect::class);
    }
}
