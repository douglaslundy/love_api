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

/**
 * Jogos de pergunta com opções fechadas:
 *  - ice (quebra-gelo): os dois respondem em segredo e a revelação é simultânea.
 *  - yk (você me conhece?): quem está na vez responde sobre si, o outro adivinha.
 * Nunca sai a resposta de um antes de os dois terem respondido.
 */
class ChoiceController extends Controller
{
    private const SKIP = 'Prefiro não responder';

    private function activeCouple(Request $request): Couple
    {
        $couple = $request->user()->currentCouple();
        if (! $couple || $couple->status !== 'active') {
            abort(422, 'Pareie com seu par para jogar.');
        }

        return $couple;
    }

    public function draw(Request $request, string $game)
    {
        $couple = $this->activeCouple($request);
        $user = $request->user();

        $level = 'L';
        if ($game === 'yk') {
            $level = $request->validate(['level' => ['required', 'in:L,M,I']])['level'];
            if (! in_array($level, Entitlements::forUser($user), true)) {
                abort(403, 'Este nível não está liberado para vocês.');
            }
        }

        $last = Round::where('couple_id', $couple->id)->where('game', $game)->latest('id')->first();
        $turn = null;
        if ($game === 'yk') {
            $turn = $last?->turn_user_id
                ? ($couple->users()->where('users.id', '!=', $last->turn_user_id)->value('users.id') ?? $user->id)
                : $user->id;
        }

        Round::where('couple_id', $couple->id)->where('game', $game)->where('status', 'open')->update(['status' => 'closed']);

        $used = Round::where('couple_id', $couple->id)->where('game', $game)->pluck('game_card_id');
        $pool = GameCard::where('game', $game)->where('level', $level);
        $card = (clone $pool)->whereNotIn('id', $used)->inRandomOrder()->first() ?? $pool->inRandomOrder()->first();
        if (! $card) {
            abort(404, 'Sem perguntas neste nível.');
        }

        $round = Round::create([
            'couple_id' => $couple->id,
            'game' => $game,
            'game_card_id' => $card->id,
            'turn_user_id' => $turn,
        ]);

        return response()->json($this->state($round, $user), 201);
    }

    public function current(Request $request, string $game)
    {
        $couple = $this->activeCouple($request);
        $round = Round::where('couple_id', $couple->id)->where('game', $game)->where('status', 'open')->latest('id')->first();

        return $round ? response()->json($this->state($round, $request->user())) : response()->noContent();
    }

    public function answer(Request $request, string $game, Round $round)
    {
        $couple = $this->activeCouple($request);
        abort_unless($round->couple_id === $couple->id && $round->game === $game && $round->status === 'open', 404);

        $options = $round->card->extra['options'] ?? [];
        $data = $request->validate(['choice' => ['required', 'integer', 'min:0']]);
        abort_unless(isset($options[$data['choice']]), 422, 'Opção inválida.');

        if (RoundAnswer::where('round_id', $round->id)->where('user_id', $request->user()->id)->exists()) {
            abort(422, 'Você já respondeu esta rodada.');
        }

        RoundAnswer::create(['round_id' => $round->id, 'user_id' => $request->user()->id, 'value' => (string) $data['choice']]);
        Notifier::partnerActed($request->user());

        return response()->json($this->state($round, $request->user()));
    }

    private function state(Round $round, User $viewer): array
    {
        $card = $round->card;
        $options = $card->extra['options'] ?? [];
        $answers = $round->answers()->get()->keyBy('user_id');
        $mine = $answers->has($viewer->id) ? (int) $answers[$viewer->id]->value : null;
        $other = $answers->first(fn ($a) => $a->user_id !== $viewer->id);

        $state = [
            'id' => $round->id,
            'game' => $round->game,
            'level' => $card->level,
            'text' => $card->text,
            'options' => $options,
            'role' => $round->game === 'yk' ? ($round->turn_user_id === $viewer->id ? 'owner' : 'guesser') : null,
            'my_answer' => $mine,
            'partner_answered' => $other !== null,
            'revealed' => false,
        ];

        if ($mine === null || $other === null) {
            return $state;
        }

        $theirs = (int) $other->value;
        $state['revealed'] = true;

        if ($round->game === 'ice') {
            $state['mine'] = $mine;
            $state['theirs'] = $theirs;
            $state['agree'] = $mine === $theirs;

            return $state;
        }

        // yk: owner = quem responde sobre si; guesser = quem adivinha.
        $ownerIdx = $round->turn_user_id === $viewer->id ? $mine : $theirs;
        $guessIdx = $round->turn_user_id === $viewer->id ? $theirs : $mine;
        $state['owner_answer'] = $ownerIdx;
        $state['guess'] = $guessIdx;
        $state['skipped'] = ($options[$ownerIdx] ?? '') === self::SKIP || ($options[$guessIdx] ?? '') === self::SKIP;
        $state['correct'] = ! $state['skipped'] && $ownerIdx === $guessIdx;

        return $state;
    }
}
