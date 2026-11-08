<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u?->is_admin || $u->suspended_at || $u->deactivated_at) {
            abort(403, 'Acesso restrito.');
        }

        return $next($request);
    }
}
