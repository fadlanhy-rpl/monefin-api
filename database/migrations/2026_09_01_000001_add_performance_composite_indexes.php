<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Speed up date range filtering by type (e.g. monthly expense/income summaries)
            $table->index(['user_id', 'type', 'transaction_date'], 'tx_user_type_date_idx');

            // Speed up category breakdown queries
            $table->index(['user_id', 'category_id', 'transaction_date'], 'tx_user_cat_date_idx');

            // Speed up account transactions queries
            $table->index(['user_id', 'account_id', 'transaction_date'], 'tx_user_acc_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('tx_user_type_date_idx');
            $table->dropIndex('tx_user_cat_date_idx');
            $table->dropIndex('tx_user_acc_date_idx');
        });
    }
};
