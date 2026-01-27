<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends TenantModel
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
    //
}


