<?php

namespace App\Http\Controllers;

use App\Services\Limits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntersectionController extends Controller
{
    /**
     * Itens em que os dois disseram Sim (match) ou Sim + Talvez (suave).
     * A consulta devolve só o item e o tipo, nunca a resposta de cada um.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $couple = $user->currentCouple();

        if (! $couple || $couple->status !== 'active') {
            return response()->json(['items' => [], 'counts' => ['match' => 0, 'soft' => 0]]);
        }

        $partnerId = $couple->users()->where('users.id', '!=', $user->id)->value('users.id');

        $rows = DB::table('catalog_items as c')
            ->join('answers as a', fn ($j) => $j->on('a.catalog_item_id', '=', 'c.id')->where('a.user_id', $user->id))
            ->join('answers as b', fn ($j) => $j->on('b.catalog_item_id', '=', 'c.id')->where('b.user_id', $partnerId))
            ->whereNotIn('c.category', Limits::blockedFor($user))
            ->whereIn('a.value', ['yes', 'maybe'])
            ->whereIn('b.value', ['yes', 'maybe'])
            ->where(fn ($q) => $q->where('a.value', 'yes')->orWhere('b.value', 'yes'))
            ->orderBy('c.number')
            ->get(['c.id', 'c.number', 'c.category', 'c.level', 'c.text', 'a.value as va', 'b.value as vb']);

        $items = $rows->map(fn ($r) => [
            'id' => $r->id,
            'number' => $r->number,
            'category' => $r->category,
            'level' => $r->level,
            'text' => $r->text,
            'kind' => ($r->va === 'yes' && $r->vb === 'yes') ? 'match' : 'soft',
        ])->values();

        return response()->json([
            'items' => $items,
            'counts' => [
                'match' => $items->where('kind', 'match')->count(),
                'soft' => $items->where('kind', 'soft')->count(),
            ],
        ]);
    }
}
