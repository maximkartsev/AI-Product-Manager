<?php

namespace App\Models;

/**
 * CentralModel
 *
 * Base model for entities stored in the central/shared database.
 */
abstract class CentralModel extends BaseModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $connection = 'central';
}

