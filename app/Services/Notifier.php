<?php

namespace App\Services;

use App\Mail\NeutralNotice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos por e-mail (RF-53, RF-60). Sempre neutros: nunca dizem qual jogo,
 * pergunta ou resposta. Só para quem ligou o aviso, e no máximo um a cada 6 horas.
 */
class Notifier
{
    /** Avisa o par de $actor, sem dizer o que aconteceu. */
    public static function partnerActed(User $actor): void
    {
        $couple = $actor->currentCouple();
        $partner = $couple?->users()->where('users.id', '!=', $actor->id)->first();

        if ($partner && $partner->notify_email && self::canReceive($partner)
            && Cache::add("poke:{$partner->id}", 1, now()->addHours(6))) {
            Mail::to($partner->email)->send(new NeutralNotice);
        }
    }

    /**
     * Aviso neutro para uma pessoa (match público). Nunca passa pelo par do casal:
     * quem participa do match sem o par saber não deixa rastro para ele.
     */
    public static function nudge(?User $user): void
    {
        if ($user && $user->notify_email && self::canReceive($user)
            && Cache::add("poke:{$user->id}", 1, now()->addHours(6))) {
            Mail::to($user->email)->send(new NeutralNotice);
        }
    }

    /** Lembrete diário do horário escolhido pela própria pessoa. */
    public static function reminder(User $user): void
    {
        if (self::canReceive($user)) {
            Mail::to($user->email)->send(new NeutralNotice);
        }
    }

    private static function canReceive(User $user): bool
    {
        return ! config('love.require_email_verification') || $user->hasVerifiedEmail();
    }
}
