<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('login_count')->default(0);
        });

        // Tempo de uso por pessoa, dia e área do app. Só segundos: nunca guarda conteúdo.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('area', 20);
            $table->unsignedInteger('seconds')->default(0);
            $table->unique(['user_id', 'day', 'area']);
            $table->index('day');
        });

        // Quem do painel abriu a ficha de quem (dados pessoais sensíveis).
        Schema::create('admin_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 20);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit');
        Schema::dropIfExists('activity_logs');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'deactivated_at', 'last_login_at', 'last_seen_at', 'login_count']));
    }
};
