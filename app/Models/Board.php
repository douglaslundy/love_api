<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Board extends Model
{
    protected $fillable = ['couple_id', 'status', 'turn_user_id', 'positions', 'pending_cell', 'last_roll', 'result'];

    protected $casts = ['positions' => 'array'];
}
