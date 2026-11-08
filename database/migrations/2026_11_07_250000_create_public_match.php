<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
        });

        Schema::create('public_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('visible')->default(true); // botão visível ou oculto no app
            $table->string('bio', 500)->nullable();
            $table->string('city', 80)->nullable();
            $table->decimal('lat', 5, 2)->nullable(); // já arredondado no servidor
            $table->decimal('lng', 6, 2)->nullable();
            $table->unsignedTinyInteger('min_age')->default(18);
            $table->unsignedTinyInteger('max_age')->default(99);
            $table->unsignedSmallInteger('max_km')->default(100);
            $table->timestamps();
        });

        // Categorias do Sim Duplo que a pessoa aceita publicar no match público (RF-93).
        Schema::create('public_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 60);
            $table->unique(['user_id', 'category']);
        });

        Schema::create('profile_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('status', 10)->default('approved'); // approved | pending | rejected
            $table->timestamps();
        });

        Schema::create('match_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique(); // identificador opaco: nunca expõe o id do usuário
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->json('common');
            $table->unsignedSmallInteger('distance_km')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'candidate_id']);
        });

        Schema::create('match_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10)->default('pending'); // pending | accepted | declined | cancelled
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['from_user_id', 'to_user_id']);
        });

        Schema::create('match_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('match_requests')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 1000);
            $table->timestamps();
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['user_id', 'blocked_id']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 20); // assedio | falso | menor | foto | outro
            $table->string('details', 500)->nullable();
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['reports', 'blocks', 'match_messages', 'match_requests', 'match_candidates', 'profile_photos', 'public_categories', 'public_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('suspended_at'));
    }
};
