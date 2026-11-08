<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Perfil de casal (RF-90): o representante liga, o par consente. Pedidos: await_out | await_in.
        Schema::table('public_profiles', function (Blueprint $table) {
            $table->boolean('as_couple')->default(false);
            $table->boolean('couple_partner_ok')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('public_profiles', function (Blueprint $table) {
            $table->dropColumn(['as_couple', 'couple_partner_ok']);
        });
    }
};
