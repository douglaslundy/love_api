<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\PublicProfile;
use App\Models\User;
use App\Services\MatchEngine;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Match público (RF-80 a RF-98). Opt-in, anônimo até o aceite mútuo.
 * Regras de privacidade:
 *  - sugestões nunca trazem nome, foto nem o id do usuário (só um id opaco);
 *  - recusar nunca é informado ao solicitante;
 *  - o par do casal não aparece e nunca é avisado (sem rastro nas telas do casal);
 *  - nome, fotos e chat só depois que os dois aceitam.
 */
class PublicMatchController extends Controller
{
    private const DAILY_REQUESTS = 10;
    private const MAX_PHOTOS = 6;

    /* ---------- perfil ---------- */

    public function profile(Request $request)
    {
        return response()->json($this->profilePayload($request->user()));
    }

    public function saveProfile(Request $request)
    {
        $user = $request->user();
        $cats = CatalogItem::query()->pluck('category')->unique()->all();

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'visible' => ['sometimes', 'boolean'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'min_age' => ['sometimes', 'integer', 'between:18,99'],
            'max_age' => ['sometimes', 'integer', 'between:18,99'],
            'max_km' => ['sometimes', 'integer', 'between:1,20000'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string', 'in:'.implode(',', $cats)],
            'consent' => ['sometimes', 'boolean'],
            'as_couple' => ['sometimes', 'boolean'],
            'birth_date' => ['sometimes', 'date'],
        ]);

        // Ligar exige consentimento específico, separado do termo geral (RF-81).
        if (($data['enabled'] ?? false) && ! ($data['consent'] ?? false)) {
            $already = PublicProfile::where('user_id', $user->id)->where('enabled', true)->exists();
            abort_unless($already, 422, 'Para participar, confirme que entendeu o que fica visível para outras pessoas.');
        }

        // RF-89: maioridade reforçada. Reconfirma a data de nascimento e exige e-mail confirmado.
        $turningOn = ($data['enabled'] ?? false) && ! PublicProfile::where('user_id', $user->id)->where('enabled', true)->exists();
        if ($turningOn && config('love.match_age_check')) {
            abort_unless($user->hasVerifiedEmail(), 422, 'Confirme seu e-mail para participar do match público.');
            abort_unless(
                isset($data['birth_date']) && $user->birth_date && $user->birth_date->toDateString() === date('Y-m-d', strtotime($data['birth_date'])),
                422,
                'A data de nascimento não confere com a do seu cadastro.'
            );
            $data['age_confirmed_at'] = now();
        }

        // Coordenadas aproximadas: grade de 0,05° (cerca de 5 km) antes de gravar.
        foreach (['lat', 'lng'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = round(round($data[$k] / 0.05) * 0.05, 2);
            }
        }

        $profile = PublicProfile::firstOrNew(['user_id' => $user->id]);
        $wasCouple = (bool) $profile->as_couple;
        if (($data['as_couple'] ?? false) && ! $wasCouple) {
            // Só quem tem par ativo liga o perfil de casal, e só um dos dois representa. O par precisa consentir (RF-90).
            $partner = $this->partnerOf($user);
            abort_unless($partner, 422, 'O perfil de casal exige um par ativo.');
            abort_if(PublicProfile::where('user_id', $partner->id)->where('as_couple', true)->exists(), 422, 'Seu par já é o representante do casal.');
            $profile->couple_partner_ok = false;
            Notifier::nudge($partner);
        }
        $profile->fill(collect($data)->except(['categories', 'consent', 'birth_date'])->all());
        if (! $profile->as_couple) {
            $profile->couple_partner_ok = false;
        }
        abort_if(($profile->max_age ?? 99) < ($profile->min_age ?? 18), 422, 'A idade máxima não pode ser menor que a mínima.');
        $profile->save();

        if (isset($data['categories'])) {
            DB::table('public_categories')->where('user_id', $user->id)->delete();
            foreach (array_unique($data['categories']) as $c) {
                DB::table('public_categories')->insert(['user_id' => $user->id, 'category' => $c]);
            }
        }

        // Desligar o perfil (ou o modo casal) apaga pedidos em aberto e o que foi calculado (RF-81).
        $leftCouple = $wasCouple && ! $profile->as_couple;
        if ((array_key_exists('enabled', $data) && ! $data['enabled']) || $leftCouple) {
            DB::table('match_requests')->whereIn('status', ['pending', 'await_out', 'await_in'])
                ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
                ->update(['status' => 'cancelled']);
            DB::table('match_candidates')->where('user_id', $user->id)->orWhere('candidate_id', $user->id)->delete();
        }

        return response()->json($this->profilePayload($user));
    }

    private function profilePayload(User $user): array
    {
        $p = PublicProfile::where('user_id', $user->id)->first();

        return [
            'enabled' => (bool) $p?->enabled,
            'as_couple' => (bool) $p?->as_couple,
            'couple_partner_ok' => (bool) $p?->couple_partner_ok,
            'can_couple' => (bool) $this->partnerOf($user),
            'visible' => $p?->visible ?? true,
            'bio' => $p?->bio,
            'city' => $p?->city,
            'has_location' => $p?->lat !== null,
            'min_age' => $p?->min_age ?? 18,
            'max_age' => $p?->max_age ?? 99,
            'max_km' => $p?->max_km ?? 100,
            'categories' => DB::table('public_categories')->where('user_id', $user->id)->pluck('category')->values(),
            'all_categories' => CatalogItem::query()->orderBy('number')->pluck('category')->unique()->values(),
            'photos' => DB::table('profile_photos')->where('user_id', $user->id)->pluck('id')->values(),
        ];
    }

    private function requireEnabled(Request $request): void
    {
        abort_unless(PublicProfile::where('user_id', $request->user()->id)->where('enabled', true)->exists(), 403, 'Ative o match público primeiro.');
    }

    /* ---------- sugestões ---------- */

    private function card(object $c): array
    {
        $other = User::find($c->candidate_id);
        $profile = PublicProfile::where('user_id', $c->candidate_id)->first();
        $common = json_decode($c->common, true);
        $items = CatalogItem::whereIn('id', array_slice($common['items'], 0, 5))->pluck('text');

        // Sem nome, sem foto e sem o id real.
        $partner = $profile?->as_couple ? $this->partnerOf($other) : null;

        return [
            'id' => $c->public_id,
            'kind' => $partner ? 'casal' : 'pessoa',
            'age' => $other->birth_date->age,
            'partner_age' => $partner?->birth_date?->age,
            'city' => $profile?->city,
            'distance' => MatchEngine::distanceLabel($c->distance_km),
            'bio' => $profile?->bio,
            'score' => $c->score,
            'categories' => collect($common['categories'])->map(fn ($n, $name) => ['name' => $name, 'count' => $n])->values(),
            'sample' => $items,
        ];
    }

    private function inactiveIds(int $me): array
    {
        // Quem já tem pedido (em qualquer sentido) ou bloqueio sai da lista de sugestões.
        $req = DB::table('match_requests')->where('from_user_id', $me)->orWhere('to_user_id', $me)->get()
            ->map(fn ($r) => $r->from_user_id === $me ? $r->to_user_id : $r->from_user_id);
        $blk = DB::table('blocks')->where('user_id', $me)->pluck('blocked_id');

        return $req->merge($blk)->unique()->values()->all();
    }

    public function suggestions(Request $request)
    {
        $this->requireEnabled($request);
        $me = $request->user()->id;

        $rows = DB::table('match_candidates')->where('user_id', $me)
            ->whereNotIn('candidate_id', $this->inactiveIds($me))
            ->orderByDesc('score')->limit(30)->get();

        return response()->json(['items' => $rows->map(fn ($c) => $this->card($c))->values()]);
    }

    /* ---------- pedidos ---------- */

    public function request(Request $request)
    {
        $this->requireEnabled($request);
        $me = $request->user();
        $data = $request->validate(['candidate' => ['required', 'string', 'size:26']]);

        $cand = DB::table('match_candidates')->where('user_id', $me->id)->where('public_id', $data['candidate'])->first();
        abort_unless($cand, 404, 'Perfil não encontrado.');
        abort_if(in_array($cand->candidate_id, $this->inactiveIds($me->id), true), 422, 'Você já interagiu com este perfil.');

        $today = DB::table('match_requests')->where('from_user_id', $me->id)->where('created_at', '>=', now()->startOfDay())->count();
        abort_if($today >= self::DAILY_REQUESTS, 429, 'Limite diário de pedidos atingido. Tente amanhã.');

        // Casal só pede match com o OK do par (RF-91): o pedido espera a revisão dele.
        $asCouple = PublicProfile::where('user_id', $me->id)->where('as_couple', true)->exists();
        DB::table('match_requests')->insert([
            'from_user_id' => $me->id, 'to_user_id' => $cand->candidate_id, 'status' => $asCouple ? 'await_out' : 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Notifier::nudge($asCouple ? $this->partnerOf($me) : User::find($cand->candidate_id));

        return response()->json(['ok' => true], 201);
    }

    /** Pedidos recebidos (anônimos) e enviados (sem dizer se foi recusado). */
    public function requests(Request $request)
    {
        $this->requireEnabled($request);
        $me = $request->user()->id;
        $fresh = now()->subDays(14);

        $incoming = DB::table('match_requests as r')
            ->join('match_candidates as c', fn ($j) => $j->on('c.candidate_id', '=', 'r.from_user_id')->where('c.user_id', $me))
            ->where('r.to_user_id', $me)->where('r.status', 'pending')->where('r.created_at', '>=', $fresh)
            ->get(['c.*', 'r.id as request_id']);

        $sent = DB::table('match_requests')->where('from_user_id', $me)->whereIn('status', ['pending', 'declined', 'cancelled'])->count();

        return response()->json([
            'incoming' => $incoming->map(fn ($c) => $this->card($c) + ['request_id' => $c->request_id])->values(),
            'sent_waiting' => $sent, // recusado, expirado e sem resposta aparecem igual
        ]);
    }

    public function respond(Request $request, int $id)
    {
        $me = $request->user()->id;
        $data = $request->validate(['action' => ['required', 'in:accept,decline,block']]);

        $req = DB::table('match_requests')->where('id', $id)->where('to_user_id', $me)->where('status', 'pending')->first();
        abort_unless($req, 404);

        if ($data['action'] === 'accept') {
            // Perfil de casal: o aceite do representante ainda precisa do OK do par.
            $asCouple = PublicProfile::where('user_id', $me)->where('as_couple', true)->exists();
            DB::table('match_requests')->where('id', $id)->update(['status' => $asCouple ? 'await_in' : 'accepted', 'responded_at' => now(), 'updated_at' => now()]);
            Notifier::nudge($asCouple ? $this->partnerOf($request->user()) : User::find($req->from_user_id));
        } else {
            DB::table('match_requests')->where('id', $id)->update(['status' => 'declined', 'responded_at' => now(), 'updated_at' => now()]);
            if ($data['action'] === 'block') {
                $this->block($me, $req->from_user_id);
            }
        }

        return response()->noContent();
    }

    /* ---------- perfil de casal: o lado do par (RF-90 a RF-92) ---------- */

    private function partnerOf(User $user): ?User
    {
        $couple = $user->currentCouple();

        return $couple?->status === 'active' ? $couple->users()->where('users.id', '!=', $user->id)->first() : null;
    }

    /** O representante do casal, visto pelo par. Sem perfil de casal ligado, nada é revelado. */
    private function repOf(User $me): ?PublicProfile
    {
        $partner = $this->partnerOf($me);

        return $partner ? PublicProfile::where('user_id', $partner->id)->where('as_couple', true)->first() : null;
    }

    public function couple(Request $request)
    {
        $rep = $this->repOf($request->user());
        if (! $rep) {
            return response()->json(['offer' => false]);
        }

        $reviews = [];
        if ($rep->couple_partner_ok) {
            $reqs = DB::table('match_requests')->whereIn('status', ['await_out', 'await_in'])
                ->where(fn ($q) => $q->where('from_user_id', $rep->user_id)->orWhere('to_user_id', $rep->user_id))->get();
            foreach ($reqs as $r) {
                $out = $r->status === 'await_out';
                $c = DB::table('match_candidates')->where('user_id', $rep->user_id)->where('candidate_id', $out ? $r->to_user_id : $r->from_user_id)->first();
                if ($c) {
                    $reviews[] = ['request_id' => $r->id, 'direction' => $out ? 'enviar' : 'aceitar', 'card' => $this->card($c)];
                }
            }
        }

        return response()->json(['offer' => true, 'consented' => $rep->couple_partner_ok, 'reviews' => $reviews]);
    }

    public function coupleConsent(Request $request)
    {
        $data = $request->validate(['ok' => ['required', 'boolean']]);
        $rep = $this->repOf($request->user());
        abort_unless($rep, 404);

        if ($data['ok']) {
            $rep->update(['couple_partner_ok' => true]);
        } else { // revogar tira o perfil do casal na hora
            $rep->update(['as_couple' => false, 'couple_partner_ok' => false]);
            DB::table('match_requests')->whereIn('status', ['pending', 'await_out', 'await_in'])
                ->where(fn ($q) => $q->where('from_user_id', $rep->user_id)->orWhere('to_user_id', $rep->user_id))->update(['status' => 'cancelled']);
            DB::table('match_candidates')->where('user_id', $rep->user_id)->orWhere('candidate_id', $rep->user_id)->delete();
        }

        return response()->noContent();
    }

    public function coupleReview(Request $request, int $id)
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $rep = $this->repOf($request->user());
        abort_unless($rep && $rep->couple_partner_ok, 404);

        $r = DB::table('match_requests')->where('id', $id)->whereIn('status', ['await_out', 'await_in'])
            ->where(fn ($q) => $q->where('from_user_id', $rep->user_id)->orWhere('to_user_id', $rep->user_id))->first();
        abort_unless($r, 404);

        $out = $r->status === 'await_out';
        $status = $data['approve'] ? ($out ? 'pending' : 'accepted') : ($out ? 'cancelled' : 'declined');
        DB::table('match_requests')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        if ($data['approve']) {
            Notifier::nudge(User::find($out ? $r->to_user_id : $r->from_user_id));
        }

        return response()->noContent();
    }

    /* ---------- matches (após o aceite mútuo) ---------- */

    /**
     * Contas cujos pedidos eu enxergo: a minha e, se meu par é o representante do casal com o meu OK,
     * a dele. Nunca o contrário: o que o par faz como pessoa (RF-94) não é visto por mim.
     */
    private function visibleIds(int $uid): array
    {
        $user = User::find($uid);
        $partner = $user ? $this->partnerOf($user) : null;
        if ($partner && PublicProfile::where('user_id', $partner->id)->where('as_couple', true)->where('couple_partner_ok', true)->exists()) {
            return [$uid, $partner->id];
        }

        return [$uid];
    }

    /** O lado de um representante: ele e, se for casal ativo, o par. */
    private function sideIds(int $rep): array
    {
        $user = User::find($rep);
        $partner = $user ? $this->partnerOf($user) : null;
        if ($partner && PublicProfile::where('user_id', $rep)->where('as_couple', true)->where('couple_partner_ok', true)->exists()) {
            return [$rep, $partner->id];
        }

        return [$rep];
    }

    private function acceptedFor(int $me, int $id): object
    {
        $party = $this->visibleIds($me);
        $r = DB::table('match_requests')->where('id', $id)->where('status', 'accepted')
            ->where(fn ($q) => $q->whereIn('from_user_id', $party)->orWhereIn('to_user_id', $party))->first();
        abort_unless($r, 404);

        return $r;
    }

    /** O representante do outro lado (quem fez ou recebeu o pedido). */
    private function otherSide(object $r, int $me): int
    {
        return in_array($r->from_user_id, $this->visibleIds($me), true) ? $r->to_user_id : $r->from_user_id;
    }

    public function matches(Request $request)
    {
        $me = $request->user()->id;
        $party = $this->visibleIds($me);
        $rows = DB::table('match_requests')->where('status', 'accepted')
            ->where(fn ($q) => $q->whereIn('from_user_id', $party)->orWhereIn('to_user_id', $party))
            ->orderByDesc('responded_at')->get();

        $out = $rows->map(function ($r) use ($me) {
            $otherId = $this->otherSide($r, $me);
            $p = PublicProfile::where('user_id', $otherId)->first();
            // Casal do outro lado: os dois aparecem, com nome, idade e fotos.
            $people = collect($this->sideIds($otherId))->map(fn ($uid) => User::find($uid));

            return [
                'id' => $r->id,
                'name' => $people->pluck('name')->join(' e '),
                'age' => $people->first()->birth_date?->age,
                'partner_age' => $people->count() > 1 ? $people->last()->birth_date?->age : null,
                'kind' => $people->count() > 1 ? 'casal' : 'pessoa',
                'city' => $p?->city,
                'bio' => $p?->bio,
                'photos' => DB::table('profile_photos')->whereIn('user_id', $people->pluck('id'))->where('status', 'approved')->pluck('id')->values(),
            ];
        })->values();

        return response()->json(['items' => $out]);
    }

    public function messages(Request $request, int $id)
    {
        $me = $request->user()->id;
        $this->acceptedFor($me, $id);
        $names = User::pluck('name', 'id');

        return response()->json(['items' => DB::table('match_messages')->where('request_id', $id)->orderByDesc('id')->limit(100)->get()
            ->reverse()->map(fn ($m) => ['id' => $m->id, 'text' => $m->body, 'mine' => $m->sender_id === $me, 'from' => $names[$m->sender_id] ?? '', 'at' => $m->created_at])->values()]);
    }

    public function send(Request $request, int $id)
    {
        $me = $request->user()->id;
        $r = $this->acceptedFor($me, $id);
        $data = $request->validate(['text' => ['required', 'string', 'min:1', 'max:1000']]);

        DB::table('match_messages')->insert(['request_id' => $id, 'sender_id' => $me, 'body' => $data['text'], 'created_at' => now(), 'updated_at' => now()]);
        // Avisa o outro lado inteiro, mais o par de quem escreveu quando é casal.
        $notify = array_merge($this->sideIds($this->otherSide($r, $me)), array_diff(array_merge($this->visibleIds($me), $this->sideIds($me)), [$me]));
        foreach ($notify as $uid) {
            Notifier::nudge(User::find($uid));
        }

        return response()->json(['ok' => true], 201);
    }

    /** Desfazer o match: fecha o chat para todos. Com block=true, também bloqueia o outro lado. */
    public function unmatch(Request $request, int $id)
    {
        $me = $request->user()->id;
        $r = $this->acceptedFor($me, $id);
        $data = $request->validate(['block' => ['sometimes', 'boolean']]);

        DB::table('match_requests')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        if ($data['block'] ?? false) {
            foreach ($this->sideIds($this->otherSide($r, $me)) as $uid) {
                $this->block($me, $uid);
            }
        }

        return response()->noContent();
    }

    private function block(int $me, int $other): void
    {
        DB::table('blocks')->insertOrIgnore(['user_id' => $me, 'blocked_id' => $other]);
        DB::table('match_requests')->where('status', 'pending')
            ->where(fn ($q) => $q->where(fn ($x) => $x->where('from_user_id', $me)->where('to_user_id', $other))
                ->orWhere(fn ($x) => $x->where('from_user_id', $other)->where('to_user_id', $me)))
            ->update(['status' => 'cancelled']);
        DB::table('match_candidates')->where(fn ($q) => $q->where('user_id', $me)->where('candidate_id', $other))
            ->orWhere(fn ($q) => $q->where('user_id', $other)->where('candidate_id', $me))->delete();
    }

    /* ---------- bloqueio e denúncia (por perfil anônimo ou por match) ---------- */

    private function resolveTarget(Request $request): int
    {
        $me = $request->user()->id;
        $d = $request->validate(['candidate' => ['sometimes', 'string', 'size:26'], 'match_id' => ['sometimes', 'integer']]);

        if (isset($d['candidate'])) {
            $c = DB::table('match_candidates')->where('user_id', $me)->where('public_id', $d['candidate'])->first();
            abort_unless($c, 404);

            return $c->candidate_id;
        }
        abort_unless(isset($d['match_id']), 422, 'Informe o perfil.');
        $r = $this->acceptedFor($me, $d['match_id']);

        return $this->otherSide($r, $me);
    }

    public function blockUser(Request $request)
    {
        $this->block($request->user()->id, $this->resolveTarget($request));

        return response()->noContent();
    }

    public function report(Request $request)
    {
        $data = $request->validate([
            'reason' => ['required', 'in:assedio,falso,menor,foto,outro'],
            'details' => ['nullable', 'string', 'max:500'],
        ]);
        $target = $this->resolveTarget($request);

        DB::table('reports')->insert([
            'reporter_id' => $request->user()->id, 'reported_id' => $target, 'reason' => $data['reason'],
            'details' => $data['details'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Quem denuncia não vê mais o perfil.
        $this->block($request->user()->id, $target);

        return response()->noContent();
    }

    /* ---------- fotos ---------- */

    public function uploadPhoto(Request $request)
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']]);
        $me = $request->user()->id;
        abort_if(DB::table('profile_photos')->where('user_id', $me)->count() >= self::MAX_PHOTOS, 422, 'Limite de fotos atingido.');

        $path = $request->file('photo')->store("photos/{$me}", 'local'); // fora de public/
        $id = DB::table('profile_photos')->insertGetId([
            'user_id' => $me, 'path' => $path,
            'status' => config('love.photo_requires_approval') ? 'pending' : 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function deletePhoto(Request $request, int $id)
    {
        $photo = DB::table('profile_photos')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($photo, 404);
        Storage::disk('local')->delete($photo->path);
        DB::table('profile_photos')->where('id', $id)->delete();

        return response()->noContent();
    }

    /** Só o dono ou quem tem match aceito com ele vê a foto. */
    public function photo(Request $request, int $id)
    {
        $me = $request->user()->id;
        $photo = DB::table('profile_photos')->where('id', $id)->first();
        abort_unless($photo, 404);

        $mine = $this->visibleIds($me);
        $theirs = $this->visibleIds($photo->user_id);
        $allowed = $photo->user_id === $me
            || ($photo->status === 'approved' && DB::table('match_requests')->where('status', 'accepted')
                ->where(fn ($q) => $q->where(fn ($x) => $x->whereIn('from_user_id', $mine)->whereIn('to_user_id', $theirs))
                    ->orWhere(fn ($x) => $x->whereIn('from_user_id', $theirs)->whereIn('to_user_id', $mine)))->exists());
        abort_unless($allowed, 404);

        return Storage::disk('local')->response($photo->path, null, ['Cache-Control' => 'private, max-age=300']);
    }
}
