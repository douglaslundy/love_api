<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NightProgress extends Model
{
    protected $table = 'night_progress';

    protected $fillable = ['user_id', 'night', 'status'];
}
