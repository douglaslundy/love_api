<?php

namespace Database\Seeders;

use App\Models\CatalogItem;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /** Só cria o que falta: nunca sobrescreve o que o admin editou. Lê as tabelas dos catálogos em docs/conteudo (fonte única). */
    public function run(): void
    {
        foreach (config('love.catalog_files') as $file) {
            $this->import($file);
        }

        // Pares complementares (número => número do outro lado). Só preenche o que está vazio.
        foreach ([522 => 523, 529 => 530, 531 => 532] as $a => $b) {
            $ia = CatalogItem::where('number', $a)->first();
            $ib = CatalogItem::where('number', $b)->first();
            if ($ia && $ib) {
                CatalogItem::whereKey($ia->id)->whereNull('counterpart_id')->update(['counterpart_id' => $ib->id]);
                CatalogItem::whereKey($ib->id)->whereNull('counterpart_id')->update(['counterpart_id' => $ia->id]);
            }
        }
    }

    private function import(string $file): void
    {
        if (! is_file($file)) {
            throw new \RuntimeException("Catálogo não encontrado: {$file}");
        }

        $category = null;
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^## \d+\.\s*(.+)$/u', $line, $m)) {
                $category = trim($m[1]);
            } elseif (str_starts_with($line, '## ')) {
                $category = null; // seções sem número (observações) não têm itens
            } elseif ($category && preg_match('/^\|\s*(\d+)\s*\|\s*(.+?)\s*\|\s*([LMI])\s*\|$/u', $line, $m)) {
                CatalogItem::firstOrCreate(
                    ['number' => (int) $m[1]],
                    ['category' => $category, 'level' => $m[3], 'text' => $m[2], 'status' => 'approved'],
                );
            }
        }
    }
}
