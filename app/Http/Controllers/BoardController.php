<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Couple;
use App\Models\GameCard;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Notifier;
use Illuminate\Http\Request;

/**
 * Tabuleiro a dois (RF-25): 40 casas, uma vez por pessoa, final cooperativo (sem placar).
 * A rolagem é feita no servidor. Cartas de nível não liberado viram uma pausa.
 */
class BoardController extends Controller
{
    private const LAST = 40;

    private function activeCouple(Request $request): Couple
    {
        $couple = $request->user()->currentCouple();
        if (! $couple || $couple->status !== 'active') {
            abort(422, 'Pareie com seu par para jogar.');
        }

        return $couple;
    }

    public function start(Request $request)
    {
        $couple = $this->activeCouple($request);
        $user = $request->user();

        Board::where('couple_id', $couple->id)->where('status', 'active')->update(['status' => 'closed']);

        $positions = $couple->users()->pluck('users.id')->mapWithKeys(fn ($id) => [(string) $id => 1])->all();
        $board = Board::create([
            'couple_id' => $couple->id,
            'turn_user_id' => $user->id,
            'positions' => $positions,
            // A casa 1 pede só um aceno; já fica pendente para quem começa.
            'pending_cell' => 1,
        ]);

        return response()->json($this->state($board, $user), 201);
    }

    public function current(Request $request)
    {
        $couple = $this->activeCouple($request);
        $board = Board::where('couple_id', $couple->id)->whereIn('status', ['active', 'done'])->latest('id')->first();

        return $board ? response()->json($this->state($board, $request->user())) : response()->noContent();
    }

    public function roll(Request $request, Board $board)
    {
        $user = $request->user();
        $this->authorizeTurn($request, $board);
        abort_if($board->pending_cell !== null, 422, 'Resolva a carta antes de rolar.');

        $roll = random_int(1, 6);
        $pos = min(self::LAST, $board->positions[(string) $user->id] + $roll);

        $card = $this->card($pos, $user);
        // Casas de sorte com efeito numérico movem a peça (nunca abaixo de 1 ou acima de 40).
        $luck = null;
        if (($card['type'] ?? '') === 'Sorte' && isset($card['delta'])) {
            $pos = max(1, min(self::LAST, $pos + $card['delta']));
            $luck = 'Casa de sorte: '.$card['text'];
        }

        $positions = $board->positions;
        $positions[(string) $user->id] = $pos;
        $board->update(['positions' => $positions, 'pending_cell' => $pos, 'last_roll' => $roll, 'result' => $luck]);
        Notifier::partnerActed($user);

        return response()->json($this->state($board->fresh(), $user));
    }

    /** Resolve a carta da casa. Passar uma ação não é avisado ao par. */
    public function resolve(Request $request, Board $board)
    {
        $user = $request->user();
        $this->authorizeTurn($request, $board);
        abort_if($board->pending_cell === null, 422, 'Nada a resolver.');

        $card = $this->card($board->pending_cell, $user);
        $result = null;

        if (! empty($card['options'])) {
            $data = $request->validate(['choice' => ['required', 'integer', 'min:0']]);
            abort_unless(isset($card['options'][$data['choice']]), 422, 'Opção inválida.');
            $result = $card['options'][$data['choice']];
        } elseif ($card['type'] === 'Ação') {
            $data = $request->validate(['action' => ['required', 'in:accept,pass']]);
            $result = $data['action'] === 'accept' ? 'Aceito.' : null;
        }

        $positions = $board->positions;
        $ids = array_keys($positions);
        $other = (string) collect($ids)->first(fn ($id) => (int) $id !== $user->id);
        $allDone = collect($positions)->every(fn ($p) => $p >= self::LAST);

        // Passa a vez, mas quem já chegou espera o outro sem rolar.
        $next = ($positions[$other] ?? self::LAST) >= self::LAST ? $user->id : (int) $other;

        $board->update([
            'pending_cell' => null,
            'result' => $result,
            'turn_user_id' => $next,
            'status' => $allDone ? 'done' : 'active',
        ]);
        Notifier::partnerActed($user);

        return response()->json($this->state($board->fresh(), $user));
    }

    private function authorizeTurn(Request $request, Board $board): void
    {
        $couple = $this->activeCouple($request);
        abort_unless($board->couple_id === $couple->id && $board->status === 'active', 404);
        abort_unless($board->turn_user_id === $request->user()->id, 403, 'Não é a sua vez.');
    }

    /** Carta da casa; se o nível não estiver liberado, vira uma pausa genérica. */
    private function card(int $cell, User $user): array
    {
        $card = GameCard::where('game', 'board')->where('number', $cell)->first();
        if (! $card) {
            return ['number' => $cell, 'type' => 'Pausa', 'text' => 'Respirem juntos por um minuto.'];
        }

        if (! in_array($card->level, Entitlements::forUser($user), true)) {
            return ['number' => $cell, 'type' => 'Pausa', 'text' => 'Pausa: respirem juntos por um minuto.'];
        }

        return [
            'number' => $cell,
            'type' => $card->extra['type'],
            'text' => $card->text,
            'options' => $card->extra['options'] ?? null,
            'timer_seconds' => $card->extra['timer_seconds'] ?? null,
            'delta' => $card->extra['delta'] ?? null,
        ];
    }

    private function state(Board $board, User $viewer): array
    {
        $positions = $board->positions;
        $partnerId = collect(array_keys($positions))->first(fn ($id) => (int) $id !== $viewer->id);

        return [
            'id' => $board->id,
            'status' => $board->status,
            'total' => self::LAST,
            'my_pos' => $positions[(string) $viewer->id] ?? 1,
            'partner_pos' => $partnerId !== null ? $positions[$partnerId] : null,
            'my_turn' => $board->turn_user_id === $viewer->id,
            'last_roll' => $board->last_roll,
            'pending' => $board->pending_cell !== null ? $this->card($board->pending_cell, $viewer) : null,
            'result' => $board->result,
        ];
    }
}
