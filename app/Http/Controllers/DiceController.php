<?php

namespace App\Http\Controllers;

use App\Models\GameCard;
use App\Services\Entitlements;
use Illuminate\Http\Request;

class DiceController extends Controller
{
    /** Faces dos dados picantes por nível liberado, mais a versão à distância. A rolagem é feita no aparelho. */
    public function index(Request $request)
    {
        $levels = Entitlements::forUser($request->user());

        $face = fn ($c) => [
            'action' => $c->extra['action'],
            'region' => $c->extra['region'],
            'duration' => $c->extra['duration'],
            'seconds' => $c->extra['seconds'],
        ];

        $local = GameCard::where('game', 'dado')->whereIn('level', $levels)->orderBy('number')->get()
            ->groupBy('level')->map(fn ($g) => $g->map($face)->values());

        $remote = GameCard::where('game', 'dado_remoto')->orderBy('number')->get()->map($face)->values();

        return response()->json(['levels' => $local, 'remote' => $remote]);
    }
}
