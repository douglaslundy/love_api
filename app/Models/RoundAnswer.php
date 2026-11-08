<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoundAnswer extends Model
{
    protected $fillable = ['round_id', 'user_id', 'value'];
}
