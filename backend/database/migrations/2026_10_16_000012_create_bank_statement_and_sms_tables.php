<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - bank_statement_lines: imported bank statements, matched one-to-one to
 *   journal lines on the bank's ledger account (reconciliation).
 * - sms_messages: outbox; written in the same transaction as the event, sent
 *   afterwards by `php artisan sms:send` (no message for a rolled-back event).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('ledger_account_id');
            $table->date('statement_date');
            $table->string('description', 300);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2)->comment('+ money into the bank account, - money out');
            $table->unsignedBigInteger('matched_journal_line_id')->nullable();
            $table->string('import_batch', 40);
            $table->foreignId('imported_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->unique('matched_journal_line_id');
            $table->index(['cooperative_id', 'ledger_account_id', 'statement_date'], 'bank_lines_account_date_index');
            $table->foreign(['cooperative_id', 'ledger_account_id'], 'bank_lines_account_foreign')->references(['cooperative_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('matched_journal_line_id')->references('id')->on('journal_lines')->restrictOnDelete();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('phone', 20);
            $table->string('body', 480);
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->datetimes();

            $table->index(['status', 'id']);
            $table->foreign('cooperative_id')->references('id')->on('cooperatives')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'member_id'])->references(['cooperative_id', 'id'])->on('members')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('bank_statement_lines');
    }
};
