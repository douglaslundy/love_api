<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Soft delete: a linha fica (auditoria/denúncia), mas some do chat dos dois lados.
        Schema::table('match_messages', fn (Blueprint $t) => $t->timestamp('deleted_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('match_messages', fn (Blueprint $t) => $t->dropColumn('deleted_at'));
    }
};
