<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Loans (ऋण): products, loans, guarantors, repayment schedules and the loan sub-ledger. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('collateral_type', ['savings', 'real_estate', 'unsecured']);
            $table->decimal('interest_rate', 9, 6)->comment('annual %, board decision');
            $table->decimal('penalty_percent', 5, 2)->default(0)->comment('% of overdue interest; at most 5 (Act s.50(5क))');
            $table->decimal('service_fee_percent', 5, 2)->default(0)->comment('admin service fee, deducted at disbursement (Std 5/2.2)');
            $table->enum('repayment_frequency', ['monthly', 'quarterly'])->comment('principal at least every 3 months (Std 3/1.8)');
            $table->enum('schedule_method', ['equal_installment', 'equal_principal']);
            $table->unsignedSmallInteger('max_term_months');
            $table->unsignedSmallInteger('max_grace_months')->default(0);
            $table->decimal('max_amount', 18, 2);
            $table->decimal('max_ltv_percent', 5, 2)->nullable()->comment('real-estate products: cooperative policy within Std 3/2.2');
            $table->boolean('is_productive_sector')->default(false)->comment('agriculture/industry/business (Std 3/1.7)');
            $table->unsignedBigInteger('ledger_account_id')->comment('asset: loans receivable');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->datetimes();

            $table->unique(['cooperative_id', 'code']);
            $table->unique(['cooperative_id', 'id']);
            $table->foreign(['cooperative_id', 'ledger_account_id'])->references(['cooperative_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE loan_products ADD CONSTRAINT loan_products_terms CHECK (
            interest_rate >= 0 AND penalty_percent BETWEEN 0 AND 5 AND service_fee_percent BETWEEN 0 AND 100
            AND max_term_months >= 1 AND max_amount > 0
            AND ((collateral_type = 'real_estate' AND max_ltv_percent > 0 AND max_ltv_percent <= 100) OR (collateral_type <> 'real_estate' AND max_ltv_percent IS NULL)))");

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('loan_product_id');
            $table->unsignedBigInteger('loan_number');
            $table->enum('status', ['applied', 'approved', 'rejected', 'disbursed', 'closed']);
            $table->decimal('principal', 18, 2);
            $table->decimal('interest_rate', 9, 6);
            $table->decimal('penalty_percent', 5, 2);
            $table->unsignedSmallInteger('term_months');
            $table->unsignedSmallInteger('grace_months')->default(0);
            $table->string('purpose', 300);
            $table->unsignedBigInteger('pledged_savings_account_id')->nullable()->comment('savings-secured loans: lien');
            $table->decimal('collateral_value', 18, 2)->nullable()->comment('real estate: valuation');
            $table->string('collateral_description', 500)->nullable();
            $table->string('cic_report_reference', 100)->nullable()->comment('Credit Information Centre report (Std 9/4)');
            $table->unsignedBigInteger('disbursement_savings_account_id')->nullable()->comment('borrower\'s own account (Std 8/10)');
            $table->decimal('outstanding_principal', 18, 2)->default(0);
            $table->date('interest_paid_through')->nullable()->comment('interest settled up to this date');
            $table->enum('classification', ['pass', 'substandard', 'doubtful', 'loss'])->default('pass');
            $table->date('applied_on');
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->date('disbursed_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->datetimes();

            $table->unique(['cooperative_id', 'loan_number']);
            $table->unique(['cooperative_id', 'id']);
            $table->index(['cooperative_id', 'member_id']);
            $table->foreign(['cooperative_id', 'member_id'])->references(['cooperative_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'loan_product_id'])->references(['cooperative_id', 'id'])->on('loan_products')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'pledged_savings_account_id'], 'loans_pledged_savings_foreign')->references(['cooperative_id', 'id'])->on('savings_accounts')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'disbursement_savings_account_id'], 'loans_disbursement_savings_foreign')->references(['cooperative_id', 'id'])->on('savings_accounts')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_amounts CHECK (principal > 0 AND outstanding_principal >= 0 AND outstanding_principal <= principal)');

        Schema::create('loan_guarantors', function (Blueprint $table) {
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('loan_id');
            $table->unsignedBigInteger('guarantor_member_id');
            $table->datetimes();

            $table->primary(['loan_id', 'guarantor_member_id']);
            $table->foreign(['cooperative_id', 'loan_id'])->references(['cooperative_id', 'id'])->on('loans')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'guarantor_member_id'])->references(['cooperative_id', 'id'])->on('members')->restrictOnDelete();
        });

        Schema::create('loan_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('loan_id');
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->decimal('principal_due', 18, 2);
            $table->decimal('interest_projected', 18, 2)->comment('plan only; actual interest is charged daily on the outstanding balance');
            $table->decimal('principal_paid', 18, 2)->default(0);
            $table->datetimes();

            $table->unique(['loan_id', 'installment_number']);
            $table->foreign(['cooperative_id', 'loan_id'])->references(['cooperative_id', 'id'])->on('loans')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE loan_schedules ADD CONSTRAINT loan_schedules_amounts CHECK (principal_due >= 0 AND principal_paid >= 0 AND principal_paid <= principal_due)');

        Schema::create('loan_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('loan_id');
            $table->enum('type', ['disbursement', 'repayment']);
            $table->decimal('amount', 18, 2);
            $table->decimal('principal', 18, 2)->default(0);
            $table->decimal('interest', 18, 2)->default(0);
            $table->decimal('penalty', 18, 2)->default(0);
            $table->decimal('fee', 18, 2)->default(0);
            $table->decimal('outstanding_after', 18, 2);
            $table->date('interest_to')->nullable();
            $table->unsignedBigInteger('journal_entry_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->index(['loan_id', 'id']);
            $table->foreign(['cooperative_id', 'loan_id'])->references(['cooperative_id', 'id'])->on('loans')->restrictOnDelete();
            $table->foreign(['cooperative_id', 'journal_entry_id'])->references(['cooperative_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });
        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $event) {
            DB::unprepared("CREATE TRIGGER loan_transactions_no_{$name} BEFORE {$event} ON loan_transactions FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'financial records are append-only'");
        }

        // Savings transactions can now come from loans.
        DB::statement("ALTER TABLE savings_transactions MODIFY type ENUM('deposit','withdrawal','interest','transfer_in','transfer_out','reversal','loan_disbursement','loan_repayment') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE savings_transactions MODIFY type ENUM('deposit','withdrawal','interest','transfer_in','transfer_out','reversal') NOT NULL");
        DB::unprepared('DROP TRIGGER IF EXISTS loan_transactions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS loan_transactions_no_delete');
        Schema::dropIfExists('loan_transactions');
        Schema::dropIfExists('loan_schedules');
        Schema::dropIfExists('loan_guarantors');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('loan_products');
    }
};
