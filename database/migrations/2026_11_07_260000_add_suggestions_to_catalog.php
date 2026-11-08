<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // Sugestões dos usuários: pending (só o casal do autor vê), approved (todos), rejected (some).
            $table->string('status', 10)->default('approved');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->unsignedBigInteger('couple_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', fn (Blueprint $t) => $t->dropColumn(['status', 'author_id', 'couple_id']));
    }
};
