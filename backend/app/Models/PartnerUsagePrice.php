<?php

namespace App\Models;

class PartnerUsagePrice extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'partner_usage_prices';

    protected $fillable = [
        'provider',
        'node_class_type',
        'model',
        'usd_per_1m_input_tokens',
        'usd_per_1m_output_tokens',
        'usd_per_1m_total_tokens',
        'usd_per_credit',
        'first_seen_at',
        'last_seen_at',
        'sample_ui_json',
    ];

    protected $casts = [
        'usd_per_1m_input_tokens' => 'float',
        'usd_per_1m_output_tokens' => 'float',
        'usd_per_1m_total_tokens' => 'float',
        'usd_per_credit' => 'float',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'sample_ui_json' => 'array',
    ];
}
