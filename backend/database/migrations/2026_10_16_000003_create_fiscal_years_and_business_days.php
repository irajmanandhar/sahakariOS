<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal years (Shrawan 1 .. last day of Asar, Bikram Sambat) and business
 * days per cooperative. Dates are stored in AD; BS is derived from the
 * verified calendar table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('start_bs_year')->comment('e.g. 2083 for FY 2083/84');
            $table->date('start_date');
            // NULL until the official calendar covering the following Asar is loaded.
            $table->date('end_date')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->datetimes();

            $table->unique(['cooperative_id', 'start_bs_year']);
            $table->unique(['cooperative_id', 'id']);
        });

        Schema::create('business_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('fiscal_year_id');
            $table->date('business_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            // 1 while open, NULL when closed: unique with cooperative_id gives
            // "at most one open business day per cooperative".
            $table->unsignedTinyInteger('open_key')->nullable()->storedAs("if(status = 'open', 1, null)");
            $table->foreignId('opened_by')->nullable()->constrained('users')->restrictOnDelete()->comment('NULL = system/seeder');
            $table->dateTime('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->datetimes();

            $table->unique(['cooperative_id', 'business_date']);
            $table->unique(['cooperative_id', 'open_key']);
            $table->unique(['cooperative_id', 'id']);
            $table->foreign(['cooperative_id', 'fiscal_year_id'])
                ->references(['cooperative_id', 'id'])->on('fiscal_years')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_days');
        Schema::dropIfExists('fiscal_years');
    }
};
