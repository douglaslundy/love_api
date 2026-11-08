<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    public const AREAS = ['descobrir', 'intersecoes', 'noite', 'quem', 'vod', 'gelo', 'conhece', 'dados', 'tabuleiro', 'baralho', 'nos-dois', 'publico', 'ajustes', 'outros'];

    /** Batimento do app aberto: soma segundos na área do dia. Só tempo, nunca conteúdo. */
    public function beat(Request $request)
    {
        $data = $request->validate([
            'area' => ['required', 'in:'.implode(',', self::AREAS)],
            'seconds' => ['required', 'integer', 'between:1,60'],
        ]);
        $u = $request->user();
        $key = ['user_id' => $u->id, 'day' => now()->toDateString(), 'area' => $data['area']];

        $n = DB::table('activity_logs')->where($key)->increment('seconds', $data['seconds']);
        if (! $n) {
            DB::table('activity_logs')->insert($key + ['seconds' => $data['seconds']]);
        }
        DB::table('users')->where('id', $u->id)->update(['last_seen_at' => now()]);

        return response()->noContent();
    }
}
