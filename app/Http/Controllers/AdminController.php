<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\GameCard;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ambiente administrativo. Mostra contas, métricas agregadas e o catálogo.
 * Nunca expõe respostas íntimas, mensagens ou fotos de ninguém.
 */
class AdminController extends Controller
{
    /** Jogos do app: rótulo, tipos de carta no banco e como contar as partidas. */
    private const GAMES = [
        'quem' => ['label' => 'Quem do casal', 'cards' => ['quem']],
        'vod' => ['label' => 'Verdade ou Desafio', 'cards' => ['vod_verdade', 'vod_desafio', 'vod_surpresa']],
        'ice' => ['label' => 'Quebra-gelo', 'cards' => ['ice']],
        'yk' => ['label' => 'Você me conhece?', 'cards' => ['yk']],
        'dados' => ['label' => 'Dados picantes', 'cards' => ['dado', 'dado_remoto']],
        'board' => ['label' => 'Tabuleiro', 'cards' => ['board']],
        'noite' => ['label' => 'Baralho da rotina', 'cards' => ['noite']],
    ];

    private const AREA_LABEL = [
        'descobrir' => 'Descobrir', 'intersecoes' => 'Interseções', 'noite' => 'A noite', 'quem' => 'Quem do casal',
        'vod' => 'Verdade ou Desafio', 'gelo' => 'Quebra-gelo', 'conhece' => 'Você me conhece?', 'dados' => 'Dados',
        'tabuleiro' => 'Tabuleiro', 'baralho' => 'Baralho', 'nos-dois' => 'Nós dois', 'publico' => 'Match', 'ajustes' => 'Ajustes', 'outros' => 'Outros',
    ];

    private function plays(string $key): ?int
    {
        return match ($key) {
            'quem', 'vod', 'ice', 'yk' => DB::table('rounds')->where('game', $key)->count(),
            'board' => DB::table('boards')->count(),
            'noite' => DB::table('night_progress')->count(),
            default => null, // dados: sem estado no servidor
        };
    }

    public function dashboard()
    {
        $now = now();
        $day = fn (int $d) => $now->copy()->subDays($d)->toDateString();
        $active = fn (int $d) => DB::table('users')->where('last_seen_at', '>=', $now->copy()->subDays($d))->count();

        $users = [
            'total' => User::count(),
            'new_7d' => User::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'new_30d' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'active_1d' => $active(1),
            'active_7d' => $active(7),
            'active_30d' => $active(30),
            'deactivated' => User::whereNotNull('deactivated_at')->count(),
            'blocked' => User::whereNotNull('suspended_at')->count(),
            'never_returned' => User::where('login_count', '<=', 1)->where('created_at', '<', $now->copy()->subDays(7))->count(),
        ];

        $couples = [
            'active' => DB::table('couples')->where('status', 'active')->count(),
            'pending' => DB::table('couples')->where('status', 'pending')->count(),
            'with_session_30d' => DB::table('rounds')->where('created_at', '>=', $now->copy()->subDays(30))->distinct()->count('couple_id'),
        ];

        $areas = DB::table('activity_logs')->where('day', '>=', $day(30))
            ->selectRaw('area, sum(seconds) as seconds, count(distinct user_id) as users')->groupBy('area')->orderByDesc('seconds')->get()
            ->map(fn ($r) => ['area' => $r->area, 'label' => self::AREA_LABEL[$r->area] ?? $r->area, 'seconds' => (int) $r->seconds, 'users' => (int) $r->users])->all();

        $totalSeconds = array_sum(array_column($areas, 'seconds'));
        $activeUsers30 = DB::table('activity_logs')->where('day', '>=', $day(30))->distinct()->count('user_id');

        $daily = [];
        $newByDay = User::where('created_at', '>=', $day(13))->selectRaw('date(created_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');
        $useByDay = DB::table('activity_logs')->where('day', '>=', $day(13))->selectRaw('day as d, sum(seconds) as s, count(distinct user_id) as u')->groupBy('d')->get()->keyBy('d');
        for ($i = 13; $i >= 0; $i--) {
            $d = $day($i);
            $daily[] = ['day' => $d, 'new_users' => (int) ($newByDay[$d] ?? 0), 'active_users' => (int) ($useByDay[$d]->u ?? 0), 'seconds' => (int) ($useByDay[$d]->s ?? 0)];
        }

        $games = [];
        foreach (self::GAMES as $key => $g) {
            $games[] = ['key' => $key, 'label' => $g['label'], 'plays' => $this->plays($key)];
        }
        usort($games, fn ($a, $b) => ($b['plays'] ?? -1) <=> ($a['plays'] ?? -1));

        $topCards = DB::table('rounds')->join('game_cards', 'game_cards.id', '=', 'rounds.game_card_id')
            ->selectRaw('game_cards.game, game_cards.number, game_cards.text, count(*) as plays')
            ->groupBy('game_cards.id', 'game_cards.game', 'game_cards.number', 'game_cards.text')->orderByDesc('plays')->limit(10)->get();

        $topExercises = DB::table('answers')->join('catalog_items', 'catalog_items.id', '=', 'answers.catalog_item_id')
            ->whereIn('answers.value', ['yes', 'maybe'])
            ->selectRaw('catalog_items.number, catalog_items.category, catalog_items.text, count(*) as yes')
            ->groupBy('catalog_items.id', 'catalog_items.number', 'catalog_items.category', 'catalog_items.text')->orderByDesc('yes')->limit(10)->get();

        $match = [
            'profiles' => DB::table('public_profiles')->where('enabled', true)->count(),
            'requests' => DB::table('match_requests')->count(),
            'matches' => DB::table('match_requests')->where('status', 'accepted')->count(),
            'photos_pending' => DB::table('profile_photos')->where('status', 'pending')->count(),
            'reports_open' => DB::table('reports')->where('status', 'open')->count(),
        ];

        return [
            'users' => $users,
            'couples' => $couples,
            'audience' => [
                'total_seconds_30d' => $totalSeconds,
                'avg_seconds_per_active_user_30d' => $activeUsers30 ? intdiv($totalSeconds, $activeUsers30) : 0,
                'by_area' => $areas,
            ],
            'daily' => $daily,
            'games' => $games,
            'top_cards' => $topCards,
            'top_exercises' => $topExercises,
            'catalog' => [
                'exercises' => CatalogItem::where('status', 'approved')->count(),
                'suggestions_pending' => CatalogItem::where('status', 'pending')->count(),
                'cards' => GameCard::count(),
                'answers' => DB::table('answers')->count(),
            ],
            'match' => $match,
        ];
    }

    private function status(User $u): string
    {
        return $u->suspended_at ? 'bloqueado' : ($u->deactivated_at ? 'desativado' : 'ativo');
    }

    public function users(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:ativo,desativado,bloqueado'],
            'sort' => ['nullable', 'in:created_at,last_login_at,last_seen_at,name'],
        ]);

        $q = User::query()->withSum('activity as seconds', 'seconds')
            ->when($data['q'] ?? null, fn ($w, $s) => $w->where(fn ($x) => $x->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when(($data['status'] ?? null) === 'bloqueado', fn ($w) => $w->whereNotNull('suspended_at'))
            ->when(($data['status'] ?? null) === 'desativado', fn ($w) => $w->whereNull('suspended_at')->whereNotNull('deactivated_at'))
            ->when(($data['status'] ?? null) === 'ativo', fn ($w) => $w->whereNull('suspended_at')->whereNull('deactivated_at'));

        $sort = $data['sort'] ?? 'created_at';
        $page = $q->orderBy($sort, $sort === 'name' ? 'asc' : 'desc')->orderByDesc('id')->paginate(20);

        return [
            'data' => $page->getCollection()->map(fn (User $u) => $this->userRow($u))->all(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    private function userRow(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $this->status($u),
            'is_admin' => (bool) $u->is_admin,
            'email_verified' => $u->email_verified_at !== null,
            'created_at' => $u->created_at?->toIso8601String(),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'last_seen_at' => $u->last_seen_at?->toIso8601String(),
            'login_count' => $u->login_count,
            'seconds' => (int) ($u->seconds ?? 0),
        ];
    }

    public function user(Request $request, User $user)
    {
        DB::table('admin_audit')->insert(['admin_id' => $request->user()->id, 'user_id' => $user->id, 'action' => 'view']);

        $user->loadSum('activity as seconds', 'seconds');
        $couple = $user->currentCouple();
        $name = fn ($id) => DB::table('users')->where('id', $id)->value('name');
        $profile = DB::table('public_profiles')->where('user_id', $user->id)->first();

        $answers = DB::table('answers')->join('catalog_items', 'catalog_items.id', '=', 'answers.catalog_item_id')
            ->where('answers.user_id', $user->id)->orderBy('catalog_items.number')
            ->get(['catalog_items.number', 'catalog_items.category', 'catalog_items.level', 'catalog_items.text', 'answers.value']);

        $partner = $couple ? $couple->users()->where('users.id', '!=', $user->id)->first(['users.id', 'users.name', 'users.email']) : null;
        $games = $couple ? DB::table('rounds')->where('couple_id', $couple->id)->selectRaw('game, count(*) as plays, max(created_at) as last_at')->groupBy('game')->get() : collect();

        $requests = DB::table('match_requests')->where(fn ($w) => $w->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))->orderByDesc('id')->limit(100)->get()
            ->map(fn ($r) => [
                'direction' => $r->from_user_id === $user->id ? 'enviou' : 'recebeu',
                'with' => $name($r->from_user_id === $user->id ? $r->to_user_id : $r->from_user_id),
                'with_id' => $r->from_user_id === $user->id ? $r->to_user_id : $r->from_user_id,
                'status' => $r->status,
                'messages' => DB::table('match_messages')->where('request_id', $r->id)->count(),
                'chat' => DB::table('match_messages')->where('request_id', $r->id)->orderBy('id')->get(['sender_id', 'body', 'created_at', 'deleted_at'])
                    ->map(fn ($m) => ['from' => $name($m->sender_id), 'text' => $m->body, 'at' => $m->created_at, 'deleted' => $m->deleted_at !== null])->all(),
                'at' => $r->created_at,
            ]);

        return $this->userRow($user) + [
            'birth_date' => $user->birth_date?->toDateString(),
            'timezone' => $user->timezone,
            'intense_enabled' => (bool) $user->intense_enabled,
            'notify_email' => (bool) $user->notify_email,
            'reminder_time' => $user->reminder_time,
            'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
            'couple' => $couple ? ['status' => $couple->status, 'partner' => $partner] : null,
            'public_profile' => $profile ? [
                'enabled' => (bool) $profile->enabled, 'visible' => (bool) $profile->visible, 'bio' => $profile->bio, 'city' => $profile->city,
                'lat' => $profile->lat, 'lng' => $profile->lng, 'min_age' => $profile->min_age, 'max_age' => $profile->max_age, 'max_km' => $profile->max_km,
                'as_couple' => (bool) $profile->as_couple,
                'categories' => DB::table('public_categories')->where('user_id', $user->id)->pluck('category'),
            ] : null,
            'limits' => DB::table('limits')->where('user_id', $user->id)->pluck('category'),
            'answers' => $answers,
            'games' => $games,
            'requests' => $requests,
            'by_area' => DB::table('activity_logs')->where('user_id', $user->id)->selectRaw('area, sum(seconds) as seconds')->groupBy('area')->orderByDesc('seconds')->get()
                ->map(fn ($r) => ['label' => self::AREA_LABEL[$r->area] ?? $r->area, 'seconds' => (int) $r->seconds])->all(),
            'reports_against' => DB::table('reports')->where('reported_id', $user->id)->count(),
        ];
    }

    /** ação: deactivate | activate | block | unblock */
    public function userAction(Request $request, User $user, string $action)
    {
        abort_if($user->id === $request->user()->id, 422, 'Você não pode alterar a própria conta.');
        abort_if($user->is_admin, 422, 'Conta administrativa não é alterada por aqui.');

        match ($action) {
            'deactivate' => $user->forceFill(['deactivated_at' => now()])->save(),
            'activate' => $user->forceFill(['deactivated_at' => null])->save(),
            'block' => $user->forceFill(['suspended_at' => now()])->save(),
            'unblock' => $user->forceFill(['suspended_at' => null])->save(),
        };

        if (in_array($action, ['deactivate', 'block'], true)) {
            // Some do Match público e derruba as sessões abertas.
            DB::table('public_profiles')->where('user_id', $user->id)->update(['enabled' => false]);
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return $this->userRow($user->fresh());
    }

    public function games()
    {
        $out = [];
        foreach (self::GAMES as $key => $g) {
            $cards = GameCard::whereIn('game', $g['cards']);
            $byLevel = (clone $cards)->selectRaw('level, count(*) as c')->groupBy('level')->pluck('c', 'level');
            $out[] = [
                'key' => $key,
                'label' => $g['label'],
                'cards' => (clone $cards)->count(),
                'levels' => ['L' => (int) ($byLevel['L'] ?? 0), 'M' => (int) ($byLevel['M'] ?? 0), 'I' => (int) ($byLevel['I'] ?? 0)],
                'plays' => $this->plays($key),
            ];
        }

        return $out;
    }

    public function gameCards(Request $request, string $key)
    {
        abort_unless(isset(self::GAMES[$key]), 404);
        $data = $request->validate(['level' => ['nullable', 'in:L,M,I'], 'q' => ['nullable', 'string', 'max:100']]);

        $q = GameCard::whereIn('game', self::GAMES[$key]['cards'])
            ->when($data['level'] ?? null, fn ($w, $l) => $w->where('level', $l))
            ->when($data['q'] ?? null, fn ($w, $s) => $w->where('text', 'like', "%{$s}%"));

        $uses = DB::table('rounds')->selectRaw('game_card_id, count(*) as c')->groupBy('game_card_id')->pluck('c', 'game_card_id');
        $page = $q->orderBy('game')->orderBy('number')->paginate(30);

        return [
            'game' => ['key' => $key, 'label' => self::GAMES[$key]['label']],
            'data' => $page->getCollection()->map(fn (GameCard $c) => [
                'id' => $c->id, 'kind' => $c->game, 'number' => $c->number, 'level' => $c->level, 'text' => $c->text,
                'extra' => $c->extra, 'plays' => (int) ($uses[$c->id] ?? 0),
            ])->all(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    public function exercises(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:60'],
            'level' => ['nullable', 'in:L,M,I'],
            'status' => ['nullable', 'in:approved,pending'],
        ]);

        $q = CatalogItem::query()
            ->when($data['q'] ?? null, fn ($w, $s) => $w->where('text', 'like', "%{$s}%"))
            ->when($data['category'] ?? null, fn ($w, $c) => $w->where('category', $c))
            ->when($data['level'] ?? null, fn ($w, $l) => $w->where('level', $l))
            ->when($data['status'] ?? null, fn ($w, $s) => $w->where('status', $s));

        $page = $q->orderBy('number')->paginate(30);
        $ids = $page->getCollection()->pluck('id');
        $counts = DB::table('answers')->whereIn('catalog_item_id', $ids)->selectRaw('catalog_item_id, value, count(*) as c')->groupBy('catalog_item_id', 'value')->get()->groupBy('catalog_item_id');

        return [
            'categories' => CatalogItem::distinct()->orderBy('category')->pluck('category'),
            'data' => $page->getCollection()->map(function (CatalogItem $i) use ($counts) {
                $c = ($counts[$i->id] ?? collect())->pluck('c', 'value');

                return [
                    'id' => $i->id, 'number' => $i->number, 'category' => $i->category, 'level' => $i->level, 'text' => $i->text, 'status' => $i->status,
                    'yes' => (int) ($c['yes'] ?? 0), 'maybe' => (int) ($c['maybe'] ?? 0), 'no' => (int) ($c['no'] ?? 0),
                ];
            })->all(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    private function rules(bool $category): array
    {
        return ['text' => ['required', 'string', 'max:255'], 'level' => ['required', 'in:L,M,I']] + ($category ? ['category' => ['required', 'string', 'max:60'], 'status' => ['required', 'in:approved,pending']] : []);
    }

    public function updateExercise(Request $request, CatalogItem $item)
    {
        $item->update($request->validate($this->rules(true)));

        return $item->fresh();
    }

    public function storeExercise(Request $request)
    {
        $data = $request->validate($this->rules(true));

        return response()->json(CatalogItem::create($data + ['number' => (int) CatalogItem::max('number') + 1])->fresh(), 201);
    }

    /** Só sugestões pendentes podem ser apagadas: item aprovado já tem respostas de casais. */
    public function destroyExercise(CatalogItem $item)
    {
        abort_unless($item->status === 'pending', 422, 'Só sugestões pendentes podem ser apagadas. Para tirar um item do ar, edite-o.');
        $item->delete();

        return response()->noContent();
    }

    public function updateCard(Request $request, GameCard $card)
    {
        $card->update($request->validate($this->rules(false)));

        return $card->fresh();
    }
}
