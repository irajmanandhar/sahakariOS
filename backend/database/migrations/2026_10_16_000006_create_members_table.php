<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Members (सदस्य) of a cooperative: Cooperatives Act s.30-32 and NCRA AML/CFT
 * Instructions 2082 (natural persons, KYM form Schedule-1).
 *
 * - members: searchable identity, decision, risk level, KYM review dates, and
 *   keyed hashes ("blind indexes") of ID numbers for duplicate detection.
 * - member_kyc_versions: the full KYM form, ENCRYPTED, one append-only row per
 *   change (history kept for the 5-year record rule).
 * - member_documents: photo, ID copies, thumbprints; append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('cooperative_id')->primary();
            $table->unsignedInteger('last_number');
            $table->foreign('cooperative_id')->references('id')->on('cooperatives')->restrictOnDelete();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->comment('branch that registered the member');
            $table->unsignedInteger('member_number')->nullable()->comment('assigned on approval');
            $table->enum('status', ['applied', 'active', 'rejected', 'exited'])->default('applied');

            $table->string('full_name', 150)->comment('as on citizenship certificate, Latin script');
            $table->string('full_name_ne', 150)->nullable()->comment('Devanagari');
            $table->date('date_of_birth');
            $table->enum('gender', ['female', 'male', 'other']);
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();

            // HMAC-SHA256 of normalised ID numbers: finds duplicates without storing the number.
            $table->char('citizenship_hash', 64)->nullable();
            $table->char('national_id_hash', 64)->nullable();
            $table->char('passport_hash', 64)->nullable();
            // 1 while applied/active, NULL once rejected/exited: identity numbers are unique
            // among current applicants and members only, so a rejected person may re-apply.
            $table->unsignedTinyInteger('identity_key')->nullable()->storedAs("if(status in ('applied', 'active'), 1, null)");

            // AML/CFT risk (Instruction 4, 3(1)(घ)): PEP => high.
            $table->boolean('is_pep')->default(false)->comment('self or family is a politically exposed person');
            $table->enum('risk_level', ['low', 'medium', 'high'])->nullable()->comment('NULL = not yet assessed');
            $table->string('risk_reason', 500)->nullable();
            $table->date('kyc_reviewed_on')->nullable();
            $table->date('next_kyc_review_on')->nullable()->comment('1/2/3 years for high/medium/low (Instruction 4(1))');

            // Act s.30 / s.32 eligibility declarations, recorded as given by the applicant.
            $table->boolean('declares_nepali_citizen');
            $table->boolean('declares_resides_in_working_area');
            $table->boolean('declares_no_other_same_type_membership');

            $table->date('applied_on');
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable()->comment('required for rejection (Act s.31)');
            $table->datetimes();

            $table->unique(['cooperative_id', 'member_number']);
            $table->unique(['cooperative_id', 'citizenship_hash', 'identity_key'], 'members_citizenship_unique');
            $table->unique(['cooperative_id', 'national_id_hash', 'identity_key'], 'members_national_id_unique');
            $table->unique(['cooperative_id', 'passport_hash', 'identity_key'], 'members_passport_unique');
            $table->index(['cooperative_id', 'next_kyc_review_on']);
            $table->unique(['cooperative_id', 'id']);
            $table->index(['cooperative_id', 'status']);
            $table->foreign(['cooperative_id', 'branch_id'])->references(['cooperative_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('member_kyc_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('member_id');
            $table->unsignedInteger('version');
            $table->longText('payload')->comment('KYM form (Schedule-1), encrypted with the application key');
            $table->dateTime('verified_in_person_at')->comment('Instruction 1(1)(ख): applicant present in person');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->unique(['member_id', 'version']);
            $table->foreign(['cooperative_id', 'member_id'])->references(['cooperative_id', 'id'])->on('members')->restrictOnDelete();
        });

        Schema::create('member_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('member_id');
            $table->enum('type', [
                'photo', 'citizenship', 'national_id', 'voter_id', 'passport', 'driving_licence', 'pan',
                'staff_id', 'thumbprint_right', 'thumbprint_left', 'signature', 'kym_form', 'other',
            ]);
            $table->string('path', 255)->comment('private disk; never public');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->index(['member_id', 'type']);
            $table->foreign(['cooperative_id', 'member_id'])->references(['cooperative_id', 'id'])->on('members')->restrictOnDelete();
        });

        foreach (['member_kyc_versions', 'member_documents'] as $t) {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $event) {
                DB::unprepared("CREATE TRIGGER {$t}_no_{$name} BEFORE {$event} ON {$t} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'KYC records are append-only'");
            }
        }
    }

    public function down(): void
    {
        foreach (['member_kyc_versions', 'member_documents'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_no_delete");
        }
        Schema::dropIfExists('member_documents');
        Schema::dropIfExists('member_kyc_versions');
        Schema::dropIfExists('members');
        Schema::dropIfExists('member_sequences');
    }
};
