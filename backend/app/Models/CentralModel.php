<?php

namespace App\Models;

/**
 * CentralModel
 *
 * Base model for entities stored in the central/shared database.
 */
abstract class CentralModel extends BaseModel
{
    protected $connection = 'central';
}

