<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Limites (RF-44): temas que alguém marcou como "nunca mostrar".
 * Valem para o casal inteiro e em silêncio: o par não é avisado.
 */
class Limits
{
    /** @return list<string> categorias bloqueadas por qualquer pessoa do casal */
    public static function blockedFor(User $user): array
    {
        $couple = $user->currentCouple();
        $ids = $couple ? $couple->users()->pluck('users.id')->all() : [$user->id];

        return DB::table('limits')->whereIn('user_id', $ids)->pluck('category')->unique()->values()->all();
    }
}
