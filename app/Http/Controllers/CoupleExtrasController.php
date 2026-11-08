<?php

namespace App\Http\Controllers;

use App\Models\Couple;
use App\Models\CustomCard;
use App\Models\Note;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Cartas próprias (RF-26), bilhetes (RF-32), reencontro (RF-33) e votos da roleta (RF-62). Tudo só do casal. */
class CoupleExtrasController extends Controller
{
    private const MAX_CARDS = 100;

    private function activeCouple(Request $request): Couple
    {
        $couple = $request->user()->currentCouple();
        if (! $couple || $couple->status !== 'active') {
            abort(422, 'Pareie com seu par para usar isto.');
        }

        return $couple;
    }

    public function cards(Request $request)
    {
        $couple = $this->activeCouple($request);
        $me = $request->user()->id;

        return response()->json(['cards' => CustomCard::where('couple_id', $couple->id)->latest('id')->get()
            ->map(fn ($c) => ['id' => $c->id, 'text' => $c->text, 'timer_seconds' => $c->timer_seconds, 'mine' => $c->author_id === $me])]);
    }

    public function storeCard(Request $request)
    {
        $couple = $this->activeCouple($request);
        $data = $request->validate([
            'text' => ['required', 'string', 'min:3', 'max:200'],
            'timer_seconds' => ['nullable', 'integer', 'min:10', 'max:1800'],
        ]);

        if (CustomCard::where('couple_id', $couple->id)->count() >= self::MAX_CARDS) {
            abort(422, 'Limite de cartas atingido. Apague alguma para criar outra.');
        }

        $card = CustomCard::create($data + ['couple_id' => $couple->id, 'author_id' => $request->user()->id]);

        return response()->json(['id' => $card->id], 201);
    }

    /** Só quem escreveu a carta pode apagá-la. */
    public function destroyCard(Request $request, CustomCard $card)
    {
        $couple = $this->activeCouple($request);
        abort_unless($card->couple_id === $couple->id && $card->author_id === $request->user()->id, 404);
        $card->delete();

        return response()->noContent();
    }

    public function notes(Request $request)
    {
        $couple = $this->activeCouple($request);
        $me = $request->user()->id;

        return response()->json(['notes' => Note::where('couple_id', $couple->id)->latest('id')->limit(50)->get()
            ->map(fn ($n) => ['id' => $n->id, 'text' => $n->text, 'mine' => $n->author_id === $me, 'at' => $n->created_at])]);
    }

    public function storeNote(Request $request)
    {
        $couple = $this->activeCouple($request);
        $data = $request->validate(['text' => ['required', 'string', 'min:1', 'max:280']]);
        $note = Note::create($data + ['couple_id' => $couple->id, 'author_id' => $request->user()->id]);
        Notifier::partnerActed($request->user());

        return response()->json(['id' => $note->id], 201);
    }

    /** Data do reencontro; qualquer um dos dois altera. */
    public function reunion(Request $request)
    {
        $couple = $this->activeCouple($request);
        $data = $request->validate(['date' => ['nullable', 'date', 'after_or_equal:today']]);
        $couple->update(['reunion_date' => $data['date'] ?? null]);

        return response()->noContent();
    }

    public function vote(Request $request)
    {
        $data = $request->validate([
            'idea' => ['required', 'string', 'max:120'],
            'value' => ['required', 'in:yes,maybe,no'],
        ]);

        DB::table('idea_votes')->updateOrInsert(
            ['user_id' => $request->user()->id, 'idea' => $data['idea']],
            ['value' => $data['value'], 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['ok' => true]);
    }

    /** Minhas respostas e só as ideias em que os dois votaram "sim". Nunca o voto do par. */
    public function ideas(Request $request)
    {
        $couple = $this->activeCouple($request);
        $me = $request->user()->id;
        $partner = $couple->users()->where('users.id', '!=', $me)->value('users.id');

        $mine = DB::table('idea_votes')->where('user_id', $me)->pluck('value', 'idea');
        $both = DB::table('idea_votes as a')
            ->join('idea_votes as b', fn ($j) => $j->on('b.idea', '=', 'a.idea')->where('b.user_id', $partner))
            ->where('a.user_id', $me)->where('a.value', 'yes')->where('b.value', 'yes')
            ->pluck('a.idea')->values();

        return response()->json(['mine' => $mine, 'matches' => $both]);
    }
}
