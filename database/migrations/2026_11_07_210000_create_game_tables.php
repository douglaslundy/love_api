<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_cards', function (Blueprint $table) {
            $table->id();
            $table->string('game', 20); // quem | noite
            $table->unsignedSmallInteger('number');
            $table->char('level', 1); // L | M | I
            $table->string('text', 255);
            $table->json('extra')->nullable();
            $table->unique(['game', 'number']);
        });

        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('couple_id')->constrained()->cascadeOnDelete();
            $table->string('game', 8)->default('quem'); // quem | vod
            $table->unsignedBigInteger('turn_user_id')->nullable(); // vod: de quem é a vez
            $table->string('result', 255)->nullable(); // vod: resposta/ação
            $table->foreignId('game_card_id')->constrained('game_cards')->cascadeOnDelete();
            $table->string('status', 10)->default('open'); // open | done | closed
            $table->timestamps();
        });

        Schema::create('round_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('value', 8); // me | partner | both | none | skip
            $table->timestamps();
            $table->unique(['round_id', 'user_id']);
        });

        Schema::create('night_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('night');
            $table->string('status', 8); // done | passed
            $table->timestamps();
            $table->unique(['user_id', 'night']);
        });
    }

    public function down(): void
    {
        foreach (['night_progress', 'round_answers', 'rounds', 'game_cards'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
