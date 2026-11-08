<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Itens do mesmo grupo dão match entre si em qualquer combinação (inclusive o mesmo item dos dois lados).
        Schema::table('catalog_items', fn (Blueprint $t) => $t->string('group_key', 30)->nullable());
    }

    public function down(): void
    {
        Schema::table('catalog_items', fn (Blueprint $t) => $t->dropColumn('group_key'));
    }
};
