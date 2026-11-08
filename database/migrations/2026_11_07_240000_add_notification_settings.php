<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_email')->default(false); // aviso neutro quando o par age
            $table->string('reminder_time', 5)->nullable(); // HH:MM no fuso da pessoa
            $table->date('last_reminder_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_email', 'reminder_time', 'last_reminder_on']);
        });
    }
};
