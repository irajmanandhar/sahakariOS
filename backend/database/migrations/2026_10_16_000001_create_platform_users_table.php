<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS operator staff. Deliberately a separate table from `users`
 * (cooperative staff) so the two identity spaces can never be confused:
 * no row here has any cooperative membership, and later they get their own guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->rememberToken();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_users');
    }
};
