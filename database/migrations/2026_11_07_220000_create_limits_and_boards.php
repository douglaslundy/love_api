<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 60);
            $table->unique(['user_id', 'category']);
        });

        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('couple_id')->constrained()->cascadeOnDelete();
            $table->string('status', 8)->default('active'); // active | done | closed
            $table->unsignedBigInteger('turn_user_id');
            $table->json('positions'); // {"<user_id>": casa}
            $table->unsignedSmallInteger('pending_cell')->nullable();
            $table->unsignedTinyInteger('last_roll')->nullable();
            $table->string('result', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
        Schema::dropIfExists('limits');
    }
};
