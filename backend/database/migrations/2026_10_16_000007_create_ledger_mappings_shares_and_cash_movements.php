<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - ledger_mappings: which of the cooperative's own ledger accounts plays each
 *   role (cash, share capital, ...). SahakariOS ships no chart of accounts.
 * - share_transactions: membership share register (append-only).
 * - member_cash_movements: every cash amount received from / paid to a member,
 *   per business date — source for threshold transaction reports (TTR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('cooperative_id');
            $table->string('key', 50);
            $table->unsignedBigInteger('ledger_account_id');
            $table->datetimes();

            $table->primary(['cooperative_id', 'key']);
            $table->foreign('cooperative_id')->references('id')->on('cooperatives')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'ledger_account_id'])->references(['cooperative_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
        });

        Schema::create('share_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('member_id');
            $table->enum('type', ['purchase', 'refund']);
            $table->unsignedInteger('units');
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('journal_entry_id');
            $table->string('source_of_funds', 500)->nullable()->comment('required at Rs 10 lakh or more (AML Instruction 8)');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->unique('journal_entry_id');
            $table->index(['cooperative_id', 'member_id']);
            $table->foreign(['cooperative_id', 'member_id'])->references(['cooperative_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'journal_entry_id'])->references(['cooperative_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });

        Schema::create('member_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('member_id');
            $table->date('business_date');
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 18, 2);
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->datetimes();

            $table->index(['cooperative_id', 'business_date', 'member_id'], 'cash_movements_date_member_index');
            $table->foreign(['cooperative_id', 'member_id'])->references(['cooperative_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'journal_entry_id'])->references(['cooperative_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });

        foreach (['share_transactions', 'member_cash_movements'] as $t) {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $event) {
                DB::unprepared("CREATE TRIGGER {$t}_no_{$name} BEFORE {$event} ON {$t} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'financial records are append-only'");
            }
        }
    }

    public function down(): void
    {
        foreach (['share_transactions', 'member_cash_movements'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_no_delete");
        }
        Schema::dropIfExists('member_cash_movements');
        Schema::dropIfExists('share_transactions');
        Schema::dropIfExists('ledger_mappings');
    }
};
