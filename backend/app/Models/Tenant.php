<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opening_finalized_at' => 'datetime', 'access_until' => 'datetime', 'trial_ends_at' => 'datetime', 'tax_recording_enabled' => 'boolean'];
    }
}
