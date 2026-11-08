<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Só conteúdo (catálogo e cartas). Nunca cria usuário de teste: a conta admin sai de `php artisan love:admin`. */
    public function run(): void
    {
        $this->call([CatalogSeeder::class, GameCardSeeder::class]);
    }
}
