<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = ['couple_id', 'plan', 'origin', 'valid_until', 'provider_id'];

    protected $casts = ['valid_until' => 'datetime'];
}
