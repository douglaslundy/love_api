<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Integrante que saiu de um match sem desfazê-lo para os outros (ex.: o par do representante do casal).
        Schema::create('match_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('match_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_exits');
    }
};
