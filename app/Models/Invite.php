<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invite extends Model
{
    protected $fillable = ['code', 'couple_id', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
