<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameCard extends Model
{
    public $timestamps = false;

    protected $fillable = ['game', 'number', 'level', 'text', 'extra'];

    protected $casts = ['extra' => 'array'];
}
