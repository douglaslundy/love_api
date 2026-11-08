<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_profiles', fn (Blueprint $t) => $t->boolean('show_photo')->default(false));

        Schema::table('match_candidates', function (Blueprint $t) {
            $t->unsignedTinyInteger('profile_score')->default(0);
            $t->unsignedSmallInteger('common_count')->default(0);
        });

        // Perfis que a pessoa descartou ao deslizar para a esquerda.
        Schema::create('match_passes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('candidate_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['user_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_passes');
        Schema::table('match_candidates', fn (Blueprint $t) => $t->dropColumn(['profile_score', 'common_count']));
        Schema::table('public_profiles', fn (Blueprint $t) => $t->dropColumn('show_photo'));
    }
};
