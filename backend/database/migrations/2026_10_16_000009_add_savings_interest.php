<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Savings interest: unposted accrued interest per account (6 decimals) and
 * an append-only accrual log, one row per period with a constant balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->decimal('accrued_interest', 20, 6)->default(0)->comment('accrued, not yet posted to the account');
            $table->date('interest_accrued_through')->nullable();
        });

        Schema::create('savings_interest_accruals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('savings_account_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedSmallInteger('days');
            $table->decimal('balance', 18, 2);
            $table->decimal('rate', 9, 6);
            $table->decimal('amount', 20, 6);
            $table->datetimes();

            $table->unique(['savings_account_id', 'to_date']);
            $table->foreign(['cooperative_id', 'savings_account_id'], 'savings_accruals_account_foreign')->references(['cooperative_id', 'id'])->on('savings_accounts')->restrictOnDelete();
        });

        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $event) {
            DB::unprepared("CREATE TRIGGER savings_interest_accruals_no_{$name} BEFORE {$event} ON savings_interest_accruals FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'financial records are append-only'");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS savings_interest_accruals_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS savings_interest_accruals_no_delete');
        Schema::dropIfExists('savings_interest_accruals');
        Schema::table('savings_accounts', fn (Blueprint $table) => $table->dropColumn(['accrued_interest', 'interest_accrued_through']));
    }
};
