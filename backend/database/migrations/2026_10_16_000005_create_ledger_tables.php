<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry general ledger.
 *
 * Enforced by the database:
 * - every line is one-sided and positive (CHECK);
 * - lines, entries, accounts and branches all belong to the same cooperative
 *   (composite foreign keys);
 * - posted journal entries and lines can never be updated or deleted
 *   (triggers); corrections are reversal entries;
 * - an entry can be reversed at most once (unique reversal_of_id);
 * - one idempotency key = one entry per cooperative.
 * - an entry balances: the header stores line_count and total when it is
 *   inserted; a trigger refuses extra lines, running sums above the total,
 *   and a final line that leaves debits or credits different from the total.
 *   (MySQL has no deferred constraints, so this is checked per inserted line.)
 * `php artisan ledger:verify` re-checks everything from the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 32);
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->boolean('is_postable')->comment('false = group/heading account');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->datetimes();

            $table->unique(['cooperative_id', 'code']);
            $table->unique(['cooperative_id', 'id']);
            $table->foreign(['cooperative_id', 'parent_id'])->references(['cooperative_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
        });

        Schema::create('journal_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('fiscal_year_id');
            $table->unsignedInteger('last_number');

            $table->primary(['cooperative_id', 'fiscal_year_id']);
            $table->foreign(['cooperative_id', 'fiscal_year_id'])->references(['cooperative_id', 'id'])->on('fiscal_years')->restrictOnDelete();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('fiscal_year_id');
            $table->unsignedBigInteger('business_day_id');
            $table->unsignedInteger('entry_number')->comment('sequential per cooperative and fiscal year');
            $table->date('entry_date');
            $table->string('description', 500);
            $table->unsignedSmallInteger('line_count');
            $table->decimal('total', 18, 2)->comment('sum of debits = sum of credits');
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('source_type', 100)->nullable()->comment('domain record that caused the entry, e.g. a deposit (later)');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable()->comment('detects reuse of a key with a different request');
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete()->comment('NULL = system');
            $table->dateTime('posted_at', 6);
            $table->datetimes();

            $table->unique(['cooperative_id', 'fiscal_year_id', 'entry_number'], 'journal_entries_number_unique');
            $table->unique(['cooperative_id', 'idempotency_key']);
            $table->unique('reversal_of_id');
            $table->unique(['cooperative_id', 'id']);
            $table->index(['cooperative_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
            $table->foreign(['cooperative_id', 'fiscal_year_id'])->references(['cooperative_id', 'id'])->on('fiscal_years')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'business_day_id'])->references(['cooperative_id', 'id'])->on('business_days')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'reversal_of_id'])->references(['cooperative_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedSmallInteger('line_number');
            $table->unsignedBigInteger('ledger_account_id');
            $table->unsignedBigInteger('branch_id');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('description', 500)->nullable();

            $table->unique(['journal_entry_id', 'line_number']);
            $table->index(['cooperative_id', 'ledger_account_id']);
            $table->foreign(['cooperative_id', 'journal_entry_id'])->references(['cooperative_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'ledger_account_id'])->references(['cooperative_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'branch_id'])->references(['cooperative_id', 'id'])->on('branches')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_sided_positive
            CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');

        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_shape CHECK (line_count >= 2 AND total > 0)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_lines_balance BEFORE INSERT ON journal_lines FOR EACH ROW
            BEGIN
                DECLARE expected_lines INT;
                DECLARE expected_total DECIMAL(18,2);
                DECLARE existing_lines INT;
                DECLARE debits DECIMAL(20,2);
                DECLARE credits DECIMAL(20,2);

                SELECT line_count, total INTO expected_lines, expected_total
                    FROM journal_entries WHERE id = NEW.journal_entry_id;
                SELECT COUNT(*), COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                    INTO existing_lines, debits, credits
                    FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;

                IF existing_lines >= expected_lines THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal entry is complete; no lines can be added';
                END IF;
                IF debits + NEW.debit > expected_total OR credits + NEW.credit > expected_total THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal lines exceed the entry total';
                END IF;
                IF existing_lines + 1 = expected_lines
                    AND (debits + NEW.debit <> expected_total OR credits + NEW.credit <> expected_total) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal entry does not balance';
                END IF;
            END
            SQL);

        foreach (['journal_entries', 'journal_lines'] as $table) {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $event) {
                DB::unprepared("CREATE TRIGGER {$table}_no_{$name} BEFORE {$event} ON {$table} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted journals are immutable; post a reversal instead'");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_balance');
        foreach (['journal_lines', 'journal_entries'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('journal_sequences');
        Schema::dropIfExists('ledger_accounts');
    }
};
