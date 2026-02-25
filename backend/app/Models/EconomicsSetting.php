<?php

namespace App\Models;

class EconomicsSetting extends CentralModel
{
    protected $table = 'economics_settings';

    protected $fillable = [
        'token_usd_rate',
        'spot_multiplier',
        'instance_type_rates',
    ];

    protected $casts = [
        'token_usd_rate' => 'float',
        'spot_multiplier' => 'float',
        'instance_type_rates' => 'array',
    ];

    public static function defaultAttributes(): array
    {
        return [
            'token_usd_rate' => 0.01,
            'spot_multiplier' => null,
            'instance_type_rates' => [
                'g4dn.xlarge' => 0.526,
                'g5.xlarge' => 1.006,
                'g6e.2xlarge' => 2.2421,
                'p5.48xlarge' => 55.04,
            ],
        ];
    }
}
