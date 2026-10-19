<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for importing passbook history from the previous system:
 * - members imported from old registers may lack date of birth and gender
 *   (their KYM review is due at once; the app form still requires both);
 * - voucher numbers on savings and share rows, and the old account number,
 *   so every imported row can be traced back to the old passbook;
 * - tax and charge rows deducted from savings in the old passbooks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->change();
            $table->enum('gender', ['female', 'male', 'other'])->nullable()->change();
        });
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->string('legacy_account_number', 30)->nullable()->after('account_number');
            $table->unique(['cooperative_id', 'savings_product_id', 'legacy_account_number'], 'savings_accounts_legacy_unique');
        });
        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->enum('type', ['deposit', 'withdrawal', 'interest', 'tax', 'charge', 'transfer_in', 'transfer_out', 'reversal', 'loan_disbursement', 'loan_repayment'])->change();
            $table->string('reference', 30)->nullable()->after('narration')->comment('voucher number');
        });
        Schema::table('share_transactions', function (Blueprint $table) {
            $table->string('reference', 30)->nullable()->after('amount')->comment('voucher number');
        });
    }

    public function down(): void
    {
        Schema::table('share_transactions', fn (Blueprint $table) => $table->dropColumn('reference'));
        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->dropColumn('reference');
            $table->enum('type', ['deposit', 'withdrawal', 'interest', 'transfer_in', 'transfer_out', 'reversal', 'loan_disbursement', 'loan_repayment'])->change();
        });
        Schema::table('savings_accounts', function (Blueprint $table) {
            // MySQL backs the (cooperative_id, savings_product_id) foreign key with the unique index; give it another first.
            $table->index(['cooperative_id', 'savings_product_id'], 'savings_accounts_product_fk_index');
            $table->dropUnique('savings_accounts_legacy_unique');
            $table->dropColumn('legacy_account_number');
        });
        Schema::table('members', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable(false)->change();
            $table->enum('gender', ['female', 'male', 'other'])->nullable(false)->change();
        });
    }
};
