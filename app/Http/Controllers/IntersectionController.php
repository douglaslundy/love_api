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

        // O par responde ao próprio item, ao lado complementar (counterpart_id) ou a qualquer item do mesmo grupo (group_key).
        // Cada um vê o item que escolheu.
        $rows = DB::table('catalog_items as c')
            ->join('answers as a', fn ($j) => $j->on('a.catalog_item_id', '=', 'c.id')->where('a.user_id', $user->id))
            ->join('catalog_items as bc', fn ($j) => $j->where(fn ($w) => $w
                ->whereColumn('bc.id', 'c.counterpart_id')
                ->orWhere(fn ($x) => $x->whereNull('c.counterpart_id')->where(fn ($y) => $y
                    ->whereColumn('bc.id', 'c.id')
                    ->orWhere(fn ($g) => $g->whereNotNull('c.group_key')->whereColumn('bc.group_key', 'c.group_key'))))))
            ->join('answers as b', fn ($j) => $j->on('b.catalog_item_id', '=', 'bc.id')->where('b.user_id', $partnerId))
            ->whereNotIn('c.category', Limits::blockedFor($user))
            ->whereIn('a.value', ['yes', 'maybe'])
            ->whereIn('b.value', ['yes', 'maybe'])
            ->where(fn ($q) => $q->where('a.value', 'yes')->orWhere('b.value', 'yes'))
            ->orderBy('c.number')
            ->get(['c.id', 'c.number', 'c.category', 'c.level', 'c.text', 'a.value as va', 'b.value as vb']);

        // Um item pode casar com dois do par (grupo): fica uma linha, "match" se algum casou com Sim dos dois.
        $items = $rows->groupBy('id')->map(fn ($g) => [
            'id' => $g[0]->id,
            'number' => $g[0]->number,
            'category' => $g[0]->category,
            'level' => $g[0]->level,
            'text' => $g[0]->text,
            'kind' => $g->contains(fn ($r) => $r->va === 'yes' && $r->vb === 'yes') ? 'match' : 'soft',
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
