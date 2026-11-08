<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });

        Schema::table('couples', function (Blueprint $table) {
            $table->date('reunion_date')->nullable();
        });

        Schema::create('custom_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('couple_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('text', 200);
            $table->unsignedSmallInteger('timer_seconds')->nullable();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('couple_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('text', 280);
            $table->timestamps();
        });

        Schema::create('idea_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idea', 120);
            $table->string('value', 8); // yes | maybe | no
            $table->timestamps();
            $table->unique(['user_id', 'idea']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idea_votes');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('custom_cards');
        Schema::table('couples', fn (Blueprint $t) => $t->dropColumn('reunion_date'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('timezone'));
    }
};
