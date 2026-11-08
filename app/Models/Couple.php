<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Couple extends Model
{
    protected $fillable = ['status', 'safety_at', 'reunion_date'];

    protected $casts = ['safety_at' => 'datetime', 'reunion_date' => 'date:Y-m-d'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
