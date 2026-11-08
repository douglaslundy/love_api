<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicProfile extends Model
{
    protected $fillable = ['user_id', 'enabled', 'visible', 'bio', 'city', 'lat', 'lng', 'min_age', 'max_age', 'max_km', 'as_couple', 'age_confirmed_at', 'couple_partner_ok'];

    protected $casts = ['enabled' => 'boolean', 'visible' => 'boolean', 'as_couple' => 'boolean', 'couple_partner_ok' => 'boolean', 'lat' => 'float', 'lng' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
