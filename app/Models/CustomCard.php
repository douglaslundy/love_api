<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomCard extends Model
{
    protected $fillable = ['couple_id', 'author_id', 'text', 'timer_seconds'];
}
