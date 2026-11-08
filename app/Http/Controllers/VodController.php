<?php

namespace App\Http\Controllers;

use App\Models\Couple;
use App\Models\GameCard;
use App\Models\Round;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Notifier;
use Illuminate\Http\Request;

class VodController extends Controller
{
    private const KINDS = ['verdade', 'desafio', 'surpresa'];

    private function activeCouple(Request $request): Couple
    {
        $couple = $request->user()->currentCouple();
        if (! $couple || $couple->status !== 'active') {
            abort(422, 'Pareie com seu par para jogar.');
        }

        return $couple;
    }

    /** Sorteia uma carta nova. A vez alterna entre os dois. */
    public function draw(Request $request)
    {
        $couple = $this->activeCouple($request);
        $user = $request->user();
        $data = $request->validate([
            'level' => ['required', 'in:L,M,I'],
            'kind' => ['required', 'in:'.implode(',', self::KINDS)],
        ]);

        if (! in_array($data['level'], Entitlements::forUser($user), true)) {
            abort(403, 'Este nível não está liberado para vocês.');
        }

        $game = "vod_{$data['kind']}";
        $last = Round::where('couple_id', $couple->id)->where('game', 'vod')->latest('id')->first();
        $turn = $last?->turn_user_id
            ? ($couple->users()->where('users.id', '!=', $last->turn_user_id)->value('users.id') ?? $user->id)
            : $user->id;

        Round::where('couple_id', $couple->id)->where('game', 'vod')->whereIn('status', ['open', 'done'])->update(['status' => 'closed']);

        $used = Round::where('couple_id', $couple->id)->where('game', 'vod')->pluck('game_card_id');
        $pool = GameCard::where('game', $game)->where('level', $data['level']);
        $card = (clone $pool)->whereNotIn('id', $used)->inRandomOrder()->first() ?? $pool->inRandomOrder()->first();
        if (! $card) {
            abort(404, 'Sem cartas neste nível.');
        }

        $round = Round::create([
            'couple_id' => $couple->id,
            'game' => 'vod',
            'game_card_id' => $card->id,
            'turn_user_id' => $turn,
        ]);

        Notifier::partnerActed($user);

        return response()->json($this->state($round, $user), 201);
    }

    public function current(Request $request)
    {
        $couple = $this->activeCouple($request);
        $round = Round::where('couple_id', $couple->id)->where('game', 'vod')
            ->whereIn('status', ['open', 'done'])->latest('id')->first();

        return $round ? response()->json($this->state($round, $request->user())) : response()->noContent();
    }

    /**
     * Só quem está na vez responde. Verdade: escolhe uma opção.
     * Desafio e surpresa: aceita ou passa. Passar fecha a carta sem avisar o par.
     */
    public function answer(Request $request, Round $round)
    {
        $couple = $this->activeCouple($request);
        abort_unless($round->couple_id === $couple->id && $round->game === 'vod' && $round->status === 'open', 404);
        abort_unless($round->turn_user_id === $request->user()->id, 403, 'Não é a sua vez.');

        $card = $round->card;

        if ($card->game === 'vod_verdade') {
            $data = $request->validate(['choice' => ['required', 'integer', 'min:0']]);
            $options = $card->extra['options'] ?? [];
            abort_unless(isset($options[$data['choice']]), 422, 'Opção inválida.');
            $round->update(['status' => 'done', 'result' => $options[$data['choice']]]);
        } else {
            $data = $request->validate(['action' => ['required', 'in:accept,pass']]);
            $data['action'] === 'accept'
                ? $round->update(['status' => 'done', 'result' => 'accepted'])
                : $round->update(['status' => 'closed', 'result' => 'passed']);
        }

        Notifier::partnerActed($request->user());

        return response()->json($this->state($round->fresh(), $request->user()));
    }

    private function state(Round $round, User $viewer): array
    {
        $card = $round->card;
        $kind = str_replace('vod_', '', $card->game);

        return [
            'id' => $round->id,
            'kind' => $kind,
            'level' => $card->level,
            'text' => $card->text,
            'options' => $kind === 'verdade' ? ($card->extra['options'] ?? []) : null,
            'remote' => $kind === 'desafio' ? ($card->extra['remote'] ?? null) : null,
            'timer_seconds' => $card->extra['timer_seconds'] ?? null,
            'my_turn' => $round->turn_user_id === $viewer->id,
            'done' => $round->status === 'done',
            'result' => $round->status === 'done' ? $round->result : null,
        ];
    }
}
