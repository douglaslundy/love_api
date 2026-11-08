<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use Illuminate\Console\Command;

class ModerateSuggestions extends Command
{
    protected $signature = 'love:suggestions {--approve=* : ids para aprovar} {--reject=* : ids para recusar}';

    protected $description = 'Lista as fantasias sugeridas pelos usuários e aprova ou recusa.';

    public function handle(): int
    {
        foreach ($this->option('approve') as $id) {
            CatalogItem::whereKey($id)->where('status', 'pending')->update(['status' => 'approved']);
        }
        foreach ($this->option('reject') as $id) {
            CatalogItem::whereKey($id)->where('status', 'pending')->update(['status' => 'rejected']);
        }

        $rows = CatalogItem::where('status', 'pending')->orderBy('id')->get(['id', 'category', 'level', 'text']);
        $this->table(['id', 'categoria', 'nível', 'texto'], $rows->map(fn ($r) => [$r->id, $r->category, $r->level, $r->text])->all());

        return self::SUCCESS;
    }
}
