<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Item complementar: o par só dá match quando um diz Sim a este item e o outro diz Sim ao complemento
        // (ex.: "ver o par com outra pessoa" x "transar com outra pessoa enquanto o par assiste"). Nulo = o próprio item.
        Schema::table('catalog_items', fn (Blueprint $t) => $t->unsignedBigInteger('counterpart_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('catalog_items', fn (Blueprint $t) => $t->dropColumn('counterpart_id'));
    }
};
