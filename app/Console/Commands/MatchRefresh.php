<?php

namespace App\Console\Commands;

use App\Services\MatchEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MatchRefresh extends Command
{
    protected $signature = 'love:match-refresh';

    protected $description = 'Recalcula as sugestões do match público e expira pedidos antigos.';

    public function handle(): int
    {
        DB::table('match_requests')->where('status', 'pending')
            ->where('created_at', '<', now()->subDays(14))->update(['status' => 'cancelled']);

        $n = MatchEngine::refresh();
        $this->info("Pares gravados: {$n}");

        return self::SUCCESS;
    }
}
