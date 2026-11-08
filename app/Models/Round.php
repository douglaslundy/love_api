<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    protected $fillable = ['couple_id', 'game', 'turn_user_id', 'result', 'game_card_id', 'status'];

    public function card(): BelongsTo
    {
        return $this->belongsTo(GameCard::class, 'game_card_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RoundAnswer::class);
    }
}
