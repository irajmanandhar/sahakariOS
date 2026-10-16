<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles are defined per cooperative; permissions are fixed strings in code
 * (App\Authorization\Permission). A staff user gets roles per cooperative
 * membership, so access in one cooperative never carries over to another.
 *
 * audit_logs is append-only: triggers reject UPDATE and DELETE, and every row
 * carries a SHA-256 hash chained to the previous row of the same chain, so
 * edits made around the triggers are detectable (php artisan audit:verify).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->datetimes();

            $table->unique(['cooperative_id', 'key']);
            $table->unique(['cooperative_id', 'id']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('role_id');
            $table->string('permission', 100);
            $table->datetimes();

            $table->primary(['role_id', 'permission']);
            $table->foreign(['cooperative_id', 'role_id'])->references(['cooperative_id', 'id'])->on('roles')->restrictOnDelete();
        });

        Schema::create('cooperative_user_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->datetimes();

            $table->primary(['cooperative_id', 'user_id', 'role_id']);
            // Both keys include cooperative_id: a role can only be given to a member of the role's own cooperative.
            $table->foreign(['cooperative_id', 'user_id'])->references(['cooperative_id', 'user_id'])->on('cooperative_user')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'role_id'])->references(['cooperative_id', 'id'])->on('roles')->restrictOnDelete();
        });

        Schema::create('audit_chains', function (Blueprint $table) {
            $table->string('chain', 40)->primary()->comment("'cooperative:<id>' or 'global'");
            $table->unsignedBigInteger('last_sequence');
            $table->char('last_hash', 64);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('chain', 40);
            $table->unsignedBigInteger('sequence');
            $table->foreignId('cooperative_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('actor_type', ['user', 'platform_user', 'system']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Exact JSON text as hashed (a JSON column would re-format it and break verification).
            $table->text('data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->unique(['chain', 'sequence']);
            $table->index(['cooperative_id', 'id']);
            $table->index('action');
        });

        DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
        DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('audit_chains');
        Schema::dropIfExists('cooperative_user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
