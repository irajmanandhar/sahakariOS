<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Savings (बचत): products, member accounts and the savings sub-ledger.
 * Database guarantees: balances never negative and holds never exceed the
 * balance (CHECK; overdraft is prohibited, NCRA Std 9/8); transactions are
 * append-only; everything stays inside one cooperative (composite FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('cooperative_id');
            $table->string('name', 30);
            $table->unsignedBigInteger('last_number');
            $table->primary(['cooperative_id', 'name']);
            $table->foreign('cooperative_id')->references('id')->on('cooperatives')->restrictOnDelete();
        });

        Schema::create('savings_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('type', ['general', 'regular', 'term'])->comment('NCRA Std 2/3.1');
            $table->decimal('interest_rate', 9, 6)->comment('annual %, decided by the board (Std 5/2.1)');
            $table->enum('interest_posting', ['monthly', 'quarterly', 'half_yearly', 'yearly', 'at_maturity']);
            $table->decimal('minimum_balance', 18, 2)->default(0);
            $table->unsignedSmallInteger('term_months')->nullable()->comment('term products only; at most 36 (Std 2/3.1)');
            $table->unsignedBigInteger('ledger_account_id')->comment('liability account holding these savings');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->datetimes();

            $table->unique(['cooperative_id', 'code']);
            $table->unique(['cooperative_id', 'id']);
            $table->foreign(['cooperative_id', 'ledger_account_id'])->references(['cooperative_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE savings_products ADD CONSTRAINT savings_products_terms CHECK (
            interest_rate >= 0 AND minimum_balance >= 0
            AND ((type = 'term' AND term_months BETWEEN 1 AND 36) OR (type <> 'term' AND term_months IS NULL)))");

        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('savings_product_id');
            $table->unsignedBigInteger('account_number');
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
            $table->decimal('balance', 18, 2)->default(0);
            $table->decimal('hold_amount', 18, 2)->default(0)->comment('e.g. lien for a loan secured by savings');
            $table->date('opened_on');
            $table->date('maturity_date')->nullable();
            $table->date('closed_on')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->unique(['cooperative_id', 'account_number']);
            $table->unique(['cooperative_id', 'id']);
            $table->index(['cooperative_id', 'member_id']);
            $table->foreign(['cooperative_id', 'member_id'])->references(['cooperative_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'savings_product_id'])->references(['cooperative_id', 'id'])->on('savings_products')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE savings_accounts ADD CONSTRAINT savings_accounts_no_overdraft
            CHECK (balance >= 0 AND hold_amount >= 0 AND hold_amount <= balance)');

        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('savings_account_id');
            $table->enum('type', ['deposit', 'withdrawal', 'interest', 'transfer_in', 'transfer_out', 'reversal']);
            $table->enum('channel', ['cash', 'transfer', 'system']);
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->unsignedBigInteger('journal_entry_id');
            $table->string('narration', 300)->nullable();
            $table->string('source_of_funds', 500)->nullable();
            $table->string('large_cash_reason', 500)->nullable()->comment('cash withdrawal above Rs 5 lakh');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->index(['savings_account_id', 'id']);
            $table->index('journal_entry_id');
            $table->foreign(['cooperative_id', 'savings_account_id'])->references(['cooperative_id', 'id'])->on('savings_accounts')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'journal_entry_id'])->references(['cooperative_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE savings_transactions ADD CONSTRAINT savings_transactions_amounts CHECK (amount > 0 AND balance_after >= 0)');

        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $event) {
            DB::unprepared("CREATE TRIGGER savings_transactions_no_{$name} BEFORE {$event} ON savings_transactions FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'financial records are append-only'");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS savings_transactions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS savings_transactions_no_delete');
        Schema::dropIfExists('savings_transactions');
        Schema::dropIfExists('savings_accounts');
        Schema::dropIfExists('savings_products');
        Schema::dropIfExists('number_sequences');
    }
};
