<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Match aceito = conversa anônima. Nome e fotos só depois que os dois lados aprovam a revelação.
        Schema::table('match_requests', function (Blueprint $t) {
            $t->timestamp('revealed_at')->nullable();
            $t->string('reveal_status', 10)->default('none'); // none | pending | declined | done
            $t->unsignedBigInteger('reveal_by')->nullable();   // quem pediu a revelação
            $t->string('reveal_note', 300)->nullable();        // justificativa de quem recusou
            $t->timestamp('reveal_at')->nullable();            // último pedido ou resposta
        });
        Schema::table('match_messages', fn (Blueprint $t) => $t->string('kind', 16)->default('text')); // text | reveal_ask | reveal_no | reveal_yes
    }

    public function down(): void
    {
        Schema::table('match_messages', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::table('match_requests', fn (Blueprint $t) => $t->dropColumn(['revealed_at', 'reveal_status', 'reveal_by', 'reveal_note', 'reveal_at']));
    }
};
