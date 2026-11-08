<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'birth_date',
        'terms_accepted_at',
        'intense_enabled',
        'timezone',
        'notify_email',
        'reminder_time',
        'last_reminder_on',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'birth_date' => 'date',
            'intense_enabled' => 'boolean',
            'notify_email' => 'boolean',
            'last_reminder_on' => 'date',
            'password' => 'hashed',
        ];
    }

    public function activity(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ActivityLog::class);
    }

    public function couples(): BelongsToMany
    {
        return $this->belongsToMany(Couple::class);
    }

    public function currentCouple(): ?Couple
    {
        return $this->couples()->first();
    }
}
