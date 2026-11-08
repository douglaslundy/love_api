<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\CatalogItem;
use App\Services\Entitlements;
use App\Services\Limits;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /** Próximos itens que o usuário ainda não respondeu. Sugestões do casal vêm primeiro. */
    public function next(Request $request)
    {
        $user = $request->user();
        $levels = Entitlements::forUser($user);

        $answered = Answer::where('user_id', $user->id)->pluck('catalog_item_id');
        $query = CatalogItem::visibleTo($user)
            ->whereIn('level', $levels)
            ->whereNotIn('id', $answered)
            ->whereNotIn('category', Limits::blockedFor($user));

        return response()->json([
            'items' => (clone $query)
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->orderBy('number')->limit(20)
                ->get(['id', 'number', 'category', 'level', 'text', 'status']),
            'remaining' => $query->count(),
        ]);
    }

    /** Minhas respostas, só as minhas, para poder mudar de ideia (RF-15). */
    public function answered(Request $request)
    {
        $rows = Answer::where('user_id', $request->user()->id)
            ->join('catalog_items as c', 'c.id', '=', 'answers.catalog_item_id')
            ->orderBy('c.number')
            ->get(['c.id', 'c.number', 'c.category', 'c.level', 'c.text', 'answers.value']);

        return response()->json(['items' => $rows]);
    }

    /** Desfazer uma resposta: o item volta para "por descobrir" e a interseção some. */
    public function undo(Request $request, CatalogItem $item)
    {
        Answer::where('user_id', $request->user()->id)->where('catalog_item_id', $item->id)->delete();

        return response()->noContent();
    }

    public function answer(Request $request, CatalogItem $item)
    {
        $data = $request->validate(['value' => ['required', 'in:yes,maybe,no,skip']]);

        // Só responde o que pode ver (aprovado, ou sugestão do próprio casal).
        abort_unless(CatalogItem::visibleTo($request->user())->whereKey($item->id)->exists(), 404);

        Answer::updateOrCreate(
            ['user_id' => $request->user()->id, 'catalog_item_id' => $item->id],
            ['value' => $data['value']],
        );

        // Resposta nunca devolve nada sobre o parceiro.
        return response()->json(['ok' => true]);
    }

    /**
     * O usuário cadastra uma fantasia que não está na lista. Fica em análise e só o casal
     * dele vê; depois de aprovada entra para todos e no match público.
     */
    public function suggest(Request $request)
    {
        $user = $request->user();
        $categories = CatalogItem::where('status', 'approved')->pluck('category')->unique()->all();

        $data = $request->validate([
            'text' => ['required', 'string', 'min:8', 'max:140'],
            'category' => ['required', 'in:'.implode(',', $categories)],
            'level' => ['required', 'in:L,M,I'],
        ]);

        $lower = mb_strtolower($data['text']);
        foreach (config('love.forbidden_terms') as $term) {
            abort_if(str_contains($lower, $term), 422, 'Este tema não é permitido no app. Veja as regras de consentimento.');
        }

        $today = CatalogItem::where('author_id', $user->id)->count();
        abort_if($today >= config('love.suggestions_total_limit'), 429, 'Limite de sugestões atingido.');
        abort_if(CatalogItem::whereRaw('lower(text) = ?', [$lower])->whereIn('status', ['approved', 'pending'])->exists(), 422, 'Essa fantasia já existe na lista.');

        $item = CatalogItem::create([
            'number' => (int) CatalogItem::max('number') + 1,
            'category' => $data['category'],
            'level' => $data['level'],
            'text' => trim($data['text']),
            'status' => 'pending',
            'author_id' => $user->id,
            'couple_id' => $user->currentCouple()?->id,
        ]);

        return response()->json(['id' => $item->id], 201);
    }
}
