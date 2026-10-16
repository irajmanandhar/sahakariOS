<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an account IS for regulatory reports (liquidity Table 4.1, capital
 * Tables 6.1/6.2): e.g. bank deposit, government bond, loss compensation fund.
 * Many accounts can carry the same tag; the cooperative's accountant tags them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_account_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('cooperative_id');
            $table->unsignedBigInteger('ledger_account_id');
            $table->string('tag', 50);
            $table->datetimes();

            $table->primary(['ledger_account_id', 'tag']);
            $table->index(['cooperative_id', 'tag']);
            $table->foreign(['cooperative_id', 'ledger_account_id'])->references(['cooperative_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_account_tags');
    }
};
