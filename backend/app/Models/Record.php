<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Record extends BaseModel
{



 protected $fillable=[
        'title',
        'description',
        'recorded_at',    ];

protected $casts = [
    ];

public static function getRules($id=null)
{
    return [
        'title' => 'string|required',
        'description' => 'string|nullable',
        'recorded_at' => 'date|required',
    ];
}
    //
}
