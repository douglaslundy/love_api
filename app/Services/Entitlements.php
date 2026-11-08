<?php

namespace App\Services;

use App\Models\Couple;
use App\Models\User;

/**
 * Único lugar que decide o que um casal pode usar.
 * Com BILLING_ENABLED=false tudo está liberado.
 */
class Entitlements
{
    /**
     * Níveis que ESTA pessoa pode ver: plano + regra de que Intenso exige os dois ativos.
     *
     * @return list<string>
     */
    public static function forUser(User $user): array
    {
        $couple = $user->currentCouple();
        $levels = self::levels($couple);

        $intense = $couple
            ? ! $couple->users()->where('intense_enabled', false)->exists()
            : $user->intense_enabled;

        return $intense ? $levels : array_values(array_diff($levels, ['I']));
    }

    /** @return list<string> níveis liberados pelo plano: L, M, I */
    public static function levels(?Couple $couple): array
    {
        if (! config('love.billing_enabled')) {
            return ['L', 'M', 'I'];
        }

        $paid = $couple && $couple->subscriptions()
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->exists();

        return $paid ? ['L', 'M', 'I'] : ['L'];
    }
}
