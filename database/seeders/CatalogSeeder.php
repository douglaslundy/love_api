<?php

namespace Database\Seeders;

use App\Models\CatalogItem;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /** Lê as tabelas dos catálogos em docs/conteudo (fonte única). */
    public function run(): void
    {
        foreach (config('love.catalog_files') as $file) {
            $this->import($file);
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
                CatalogItem::updateOrCreate(
                    ['number' => (int) $m[1]],
                    ['category' => $category, 'level' => $m[3], 'text' => $m[2], 'status' => 'approved'],
                );
            }
        }
    }
}
