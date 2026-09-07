<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;

/**
 * A persisted application setting stored as a key/value pair.
 */
class Setting extends Model
{
    protected $guarded = ['id'];
}
