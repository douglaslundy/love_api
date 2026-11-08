<?php

namespace App\Http\Controllers;

use App\Models\GameCard;
use App\Models\NightProgress;
use App\Services\Entitlements;
use Illuminate\Http\Request;

class NightController extends Controller
{
    /** Baralho da rotina. O progresso é privado de cada pessoa. */
    public function index(Request $request)
    {
        $user = $request->user();

        $nights = GameCard::where('game', 'noite')
            ->whereIn('level', Entitlements::forUser($user))
            ->orderBy('number')
            ->get();

        $status = NightProgress::where('user_id', $user->id)->pluck('status', 'night');

        $list = $nights->map(fn ($n) => [
            'number' => $n->number,
            'title' => $n->text,
            'level' => $n->level,
            'in_person' => $n->extra['in_person'] ?? '',
            'remote' => $n->extra['remote'] ?? '',
            'status' => $status[$n->number] ?? null,
        ])->values();

        return response()->json([
            'nights' => $list,
            'next' => $list->first(fn ($n) => $n['status'] === null)['number'] ?? null,
            'done' => $list->where('status', 'done')->count(),
            'streak' => $this->streak($user->id),
        ]);
    }

    /** Dias seguidos com pelo menos uma noite vivida, até hoje ou ontem. Só a própria pessoa vê. */
    private function streak(int $userId): int
    {
        $days = NightProgress::where('user_id', $userId)->where('status', 'done')
            ->pluck('updated_at')->map(fn ($d) => $d->toDateString())->unique()->flip();

        $day = now()->toDateString();
        if (! $days->has($day)) {
            $day = now()->subDay()->toDateString();
        }

        $n = 0;
        while ($days->has($day)) {
            $n++;
            $day = \Illuminate\Support\Carbon::parse($day)->subDay()->toDateString();
        }

        return $n;
    }

    public function mark(Request $request, int $number)
    {
        $data = $request->validate(['status' => ['required', 'in:done,passed']]);

        $allowed = GameCard::where('game', 'noite')->where('number', $number)
            ->whereIn('level', Entitlements::forUser($request->user()))->exists();
        abort_unless($allowed, 404);

        NightProgress::updateOrCreate(
            ['user_id' => $request->user()->id, 'night' => $number],
            ['status' => $data['status']],
        );

        return response()->json(['ok' => true]);
    }
}
