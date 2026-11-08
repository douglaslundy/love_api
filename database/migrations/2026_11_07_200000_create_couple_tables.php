<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couples', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->default('pending'); // pending | active
            $table->timestamp('safety_at')->nullable(); // última sessão encerrada por 'Parar'
            $table->timestamps();
        });

        Schema::create('couple_user', function (Blueprint $table) {
            $table->foreignId('couple_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->primary(['couple_id', 'user_id']);
        });

        Schema::create('invites', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->foreignId('couple_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('number')->unique();
            $table->string('category', 60);
            $table->char('level', 1); // L | M | I
            $table->string('text', 255);
        });

        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('value', 8); // yes | maybe | no | skip
            $table->timestamps();
            $table->unique(['user_id', 'catalog_item_id']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('couple_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 32);
            $table->string('origin', 32);
            $table->timestamp('valid_until')->nullable();
            $table->string('provider_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['subscriptions', 'answers', 'catalog_items', 'invites', 'couple_user', 'couples'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
