<?php

namespace App\Services;

use App\Models\PublicProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rotina do match público (RF-82). Cruza só as respostas "Sim" das categorias que cada pessoa
 * aceitou publicar. Roda no servidor, por cron. Custo O(n²): serve para o início;
 * com muitos usuários, particionar por região.
 */
class MatchEngine
{
    public static function distanceKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?int
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * asin(min(1, sqrt($a))));
    }

    /** Faixa em vez do número exato, para não permitir descobrir a posição por triangulação. */
    public static function distanceLabel(?int $km): string
    {
        return match (true) {
            $km === null => 'distância não informada',
            $km <= 5 => 'até 5 km',
            $km <= 20 => '5 a 20 km',
            $km <= 50 => '20 a 50 km',
            $km <= 100 => '50 a 100 km',
            default => 'mais de 100 km',
        };
    }

    /** @return int quantidade de pares gravados (nos dois sentidos) */
    public static function refresh(): int
    {
        $started = now();
        $profiles = PublicProfile::where('enabled', true)
            ->whereHas('user', fn ($q) => $q->whereNull('suspended_at')->whereNotNull('birth_date'))
            ->where(fn ($q) => $q->where('as_couple', false)->orWhere('couple_partner_ok', true)) // casal só com o OK do par
            ->with('user')->get()->keyBy('user_id');

        if ($profiles->count() < 2) {
            DB::table('match_candidates')->delete();

            return 0;
        }

        $ids = $profiles->keys()->all();

        // Itens "Sim" publicados por pessoa: só das categorias que ela escolheu.
        $items = [];
        DB::table('answers as a')
            ->join('catalog_items as c', 'c.id', '=', 'a.catalog_item_id')
            ->join('public_categories as pc', fn ($j) => $j->on('pc.user_id', '=', 'a.user_id')->on('pc.category', '=', 'c.category'))
            ->where('c.status', 'approved') // sugestões em análise não entram no match público
            ->whereIn('a.user_id', $ids)->where('a.value', 'yes')
            ->get(['a.user_id', 'c.id', 'c.category'])
            ->each(function ($r) use (&$items) {
                $items[$r->user_id][$r->id] = $r->category;
            });

        $couple = DB::table('couple_user')->whereIn('user_id', $ids)->pluck('couple_id', 'user_id');
        $blocked = [];
        DB::table('blocks')->whereIn('user_id', $ids)->get()->each(function ($b) use (&$blocked) {
            $blocked[$b->user_id.'-'.$b->blocked_id] = $blocked[$b->blocked_id.'-'.$b->user_id] = true;
        });

        // Perfil de casal mostra as duas idades; as duas precisam caber no filtro do outro lado.
        $partnerOf = [];
        foreach (DB::table('couple_user as a')->join('couple_user as b', fn ($j) => $j->on('a.couple_id', '=', 'b.couple_id')->whereColumn('a.user_id', '!=', 'b.user_id'))
            ->whereIn('a.user_id', $ids)->get(['a.user_id as me', 'b.user_id as other']) as $r) {
            $partnerOf[$r->me] = $r->other;
        }
        $birth = User::whereIn('id', array_values($partnerOf))->pluck('birth_date', 'id');
        $ages = function ($p) use ($partnerOf, $birth) {
            $out = [$p->user->birth_date->age];
            if ($p->as_couple && ($b = $birth[$partnerOf[$p->user_id] ?? 0] ?? null)) $out[] = $b->age;

            return $out;
        };
        $fits = fn (array $list, $p) => collect($list)->every(fn ($x) => $x >= $p->min_age && $x <= $p->max_age);
        $min = (int) config('love.match_min_common');
        $written = 0;

        $list = $profiles->values();
        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                [$a, $b] = [$list[$i], $list[$j]];
                $ua = $a->user_id;
                $ub = $b->user_id;

                if (isset($couple[$ua], $couple[$ub]) && $couple[$ua] === $couple[$ub]) continue; // RF-96: o par nunca aparece
                if (isset($blocked["{$ua}-{$ub}"])) continue;
                if (! $fits($ages($b), $a) || ! $fits($ages($a), $b)) continue;

                $km = self::distanceKm($a->lat, $a->lng, $b->lat, $b->lng);
                if ($km !== null && ($km > $a->max_km || $km > $b->max_km)) continue;

                $sa = $items[$ua] ?? [];
                $sb = $items[$ub] ?? [];
                $common = array_intersect_key($sa, $sb);
                if (count($common) < $min || ! $sa || ! $sb) continue;

                $score = (int) round(100 * count($common) / sqrt(count($sa) * count($sb)));
                $payload = ['items' => array_keys($common), 'categories' => array_count_values(array_values($common))];

                foreach ([[$ua, $ub], [$ub, $ua]] as [$from, $to]) {
                    $row = DB::table('match_candidates')->where('user_id', $from)->where('candidate_id', $to)->first();
                    $data = ['score' => min(100, $score), 'common' => json_encode($payload), 'distance_km' => $km, 'updated_at' => now()];
                    $row
                        ? DB::table('match_candidates')->where('id', $row->id)->update($data)
                        : DB::table('match_candidates')->insert($data + ['public_id' => (string) Str::ulid(), 'user_id' => $from, 'candidate_id' => $to, 'created_at' => now()]);
                    $written++;
                }
            }
        }

        // Pares que deixaram de valer (limite, bloqueio, perfil desligado) somem.
        DB::table('match_candidates')->where('updated_at', '<', $started)->delete();

        return $written;
    }
}
