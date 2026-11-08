<?php

namespace App\Http\Controllers;

use App\Models\Round;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Encerrar a sessão (RF-40) ou usar a palavra de segurança (RF-42).
     * Fecha todas as rodadas abertas dos dois, sem pedir justificativa.
     */
    public function stop(Request $request)
    {
        $data = $request->validate(['safety' => ['sometimes', 'boolean']]);
        $couple = $request->user()->currentCouple();

        if ($couple) {
            Round::where('couple_id', $couple->id)->whereIn('status', ['open', 'done'])->update(['status' => 'closed']);
            if ($data['safety'] ?? false) {
                $couple->update(['safety_at' => now()]);
            }
        }

        return response()->noContent();
    }
}
