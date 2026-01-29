<?php

namespace App\Models;

class PaymentEvent extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'purchase_id',
        'payment_id',
        'payload',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'purchase_id' => 'integer',
        'payment_id' => 'integer',
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
