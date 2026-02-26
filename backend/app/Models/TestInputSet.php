<?php

namespace App\Models;

class TestInputSet extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'test_input_sets';

    protected $fillable = [
        'name',
        'description',
        'input_json',
        'created_by_user_id',
    ];

    protected $casts = [
        'input_json' => 'array',
        'created_by_user_id' => 'integer',
    ];
}
