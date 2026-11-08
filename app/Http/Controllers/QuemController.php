<?php

namespace App\Http\Controllers;

use App\Models\Couple;
use App\Models\GameCard;
use App\Models\Round;
use App\Models\RoundAnswer;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Notifier;
use Illuminate\Http\Request;

class QuemController extends Controller
{
    private function activeCouple(Request $request): Couple
    {
        $couple = $request->user()->currentCouple();
        if (! $couple || $couple->status !== 'active') {
            abort(422, 'Pareie com seu par para jogar.');
        }

        return $couple;
    }

    /** Nova rodada: encerra a aberta e sorteia uma pergunta ainda não usada. */
    public function start(Request $request)
    {
        $couple = $this->activeCouple($request);
        $data = $request->validate(['level' => ['required', 'in:L,M,I']]);

        if (! in_array($data['level'], Entitlements::forUser($request->user()), true)) {
            abort(403, 'Este nível não está liberado para vocês.');
        }

        Round::where('couple_id', $couple->id)->where('game', 'quem')->where('status', 'open')->update(['status' => 'closed']);

        $used = Round::where('couple_id', $couple->id)->where('game', 'quem')->pluck('game_card_id');
        $pool = GameCard::where('game', 'quem')->where('level', $data['level']);
        $card = (clone $pool)->whereNotIn('id', $used)->inRandomOrder()->first()
            ?? $pool->inRandomOrder()->first();

        if (! $card) {
            abort(404, 'Sem perguntas neste nível.');
        }

        $round = Round::create(['couple_id' => $couple->id, 'game' => 'quem', 'game_card_id' => $card->id]);

        return response()->json($this->state($round, $request->user()), 201);
    }

    public function current(Request $request)
    {
        $couple = $this->activeCouple($request);
        $round = Round::where('couple_id', $couple->id)->where('game', 'quem')->where('status', 'open')->latest('id')->first();

        return $round ? response()->json($this->state($round, $request->user())) : response()->noContent();
    }

    public function answer(Request $request, Round $round)
    {
        $couple = $this->activeCouple($request);
        abort_unless($round->couple_id === $couple->id && $round->game === 'quem' && $round->status === 'open', 404);

        $data = $request->validate(['value' => ['required', 'in:me,partner,both,none,skip']]);

        if (RoundAnswer::where('round_id', $round->id)->where('user_id', $request->user()->id)->exists()) {
            abort(422, 'Você já respondeu esta rodada.');
        }

        RoundAnswer::create(['round_id' => $round->id, 'user_id' => $request->user()->id, 'value' => $data['value']]);
        Notifier::partnerActed($request->user());

        return response()->json($this->state($round, $request->user()));
    }

    /**
     * Antes de os dois responderem, só sai a resposta do próprio usuário.
     * Depois, a do par é traduzida para o ponto de vista de quem lê ("Eu" do par vira "Meu par").
     */
    private function state(Round $round, User $viewer): array
    {
        $answers = $round->answers()->get()->keyBy('user_id');
        $mine = $answers->get($viewer->id)?->value;
        $other = $answers->first(fn ($a) => $a->user_id !== $viewer->id);

        $state = [
            'id' => $round->id,
            'level' => $round->card->level,
            'text' => $round->card->text,
            'my_answer' => $mine,
            'partner_answered' => $other !== null,
            'revealed' => false,
        ];

        if ($mine === null || $other === null) {
            return $state;
        }

        $state['revealed'] = true;

        if ($mine === 'skip' || $other->value === 'skip') {
            $state['skipped'] = true; // quem pulou não é identificado

            return $state;
        }

        $theirs = ['me' => 'partner', 'partner' => 'me'][$other->value] ?? $other->value;
        $state['mine'] = $mine;
        $state['theirs'] = $theirs;
        $state['agree'] = $mine === $theirs;

        return $state;
    }
}
