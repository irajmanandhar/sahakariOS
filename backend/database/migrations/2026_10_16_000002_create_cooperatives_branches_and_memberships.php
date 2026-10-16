<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant foundation: cooperatives (tenants), their branches, and which
 * cooperative users (`users` table) work for which cooperative and branch.
 *
 * Rules enforced by the database itself:
 * - Tenant rows are never cascade-deleted (all FKs restrict). Cooperatives
 *   and branches are closed via `status`, not deleted.
 * - A membership's branch must belong to the same cooperative
 *   (composite FK to branches(cooperative_id, id)).
 * - At most one head office per cooperative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooperatives', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('Short internal identifier, never shown as a legal identifier');
            $table->string('name')->comment('Registered name, usually Nepali');
            $table->string('name_en')->nullable();
            $table->string('registration_number', 64)->nullable()->comment('Not unique: issued by different registrars');
            // licence_suspended blocks all transactions (Cooperatives Act s.16क(6), register G-01).
            $table->enum('status', ['active', 'licence_suspended', 'closed'])->default('active');
            $table->datetimes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            // 1 for the head office, NULL otherwise; unique with cooperative_id
            // gives "one head office per cooperative" (MySQL has no partial indexes).
            $table->unsignedTinyInteger('head_office_key')->nullable()->storedAs('if(is_head_office, 1, null)');
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->datetimes();

            $table->unique(['cooperative_id', 'code']);
            $table->unique(['cooperative_id', 'head_office_key']);
            // Target for composite FKs from tenant tables (same-tenant guarantee).
            $table->unique(['cooperative_id', 'id']);
        });

        Schema::create('cooperative_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Home branch. Access to several branches is a later, separate table.
            $table->unsignedBigInteger('branch_id');
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->datetimes();

            $table->unique(['cooperative_id', 'user_id']);
            $table->foreign(['cooperative_id', 'branch_id'])
                ->references(['cooperative_id', 'id'])->on('branches')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_user');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('cooperatives');
    }
};
