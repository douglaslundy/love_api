<?php

namespace App\Http\Controllers;

use App\Models\Couple;
use App\Models\Invite;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoupleController extends Controller
{
    /** Cria o casal (pendente) e um código de convite. */
    public function invite(Request $request)
    {
        $user = $request->user();

        $couple = $user->currentCouple();
        if ($couple && $couple->status === 'active') {
            abort(422, 'Você já está pareado.');
        }

        $invite = DB::transaction(function () use ($user, $couple) {
            if (! $couple) {
                $couple = Couple::create(['status' => 'pending']);
                $couple->users()->attach($user->id);
                // Acesso completo de lançamento (ver Entitlements).
                Subscription::create([
                    'couple_id' => $couple->id,
                    'plan' => 'casal_cortesia',
                    'origin' => 'lancamento',
                ]);
            }

            $couple->invites()->delete();

            return $couple->invites()->create([
                'code' => strtoupper(Str::random(8)),
                'expires_at' => now()->addHours(config('love.invite_hours')),
            ]);
        });

        return response()->json(['code' => $invite->code, 'expires_at' => $invite->expires_at], 201);
    }

    public function accept(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:8']]);
        $user = $request->user();

        if ($user->currentCouple()) {
            abort(422, 'Você já tem um casal. Desfaça o pareamento antes.');
        }

        $invite = Invite::where('code', strtoupper($data['code']))->where('expires_at', '>', now())->first();
        if (! $invite) {
            abort(404, 'Convite inválido ou expirado.');
        }

        $couple = Couple::findOrFail($invite->couple_id);
        if ($couple->users()->count() >= 2) {
            abort(422, 'Este convite já foi usado.');
        }

        DB::transaction(function () use ($couple, $user, $invite) {
            $couple->users()->attach($user->id);
            $couple->update(['status' => 'active']);
            $invite->delete();
        });

        return response()->json(['status' => 'active']);
    }

    /** Qualquer um dos dois desfaz o pareamento. As respostas de cada um continuam só deles. */
    public function leave(Request $request)
    {
        $couple = $request->user()->currentCouple();
        if ($couple) {
            $couple->delete();
        }

        return response()->noContent();
    }
}
