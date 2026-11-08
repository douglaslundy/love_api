<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * - Conta suspensa por moderação: bloqueada.
 * - Com REQUIRE_EMAIL_VERIFICATION=true, só quem confirmou o e-mail usa o app.
 */
class EnsureVerifiedIfRequired
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user?->suspended_at) {
            return response()->json(['message' => 'Conta suspensa.', 'code' => 'suspended'], 403);
        }
        if ($user?->deactivated_at) {
            return response()->json(['message' => 'Conta desativada.', 'code' => 'deactivated'], 403);
        }

        if (config('love.require_email_verification') && $user && ! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Confirme seu e-mail para continuar.', 'code' => 'email_unverified'], 403);
        }

        return $next($request);
    }
}
