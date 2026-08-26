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
        Schema::table('income_settings', function (Blueprint $table) {
            $table->string('type')->default('income')->after('user_id'); // 'income' or 'expense'
            $table->string('title')->nullable()->after('type'); // e.g. "Gaji Bulanan" or "Langganan Netflix"
            $table->foreignId('account_id')->nullable()->after('title')->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->date('last_processed_date')->nullable()->after('effective_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('income_settings', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['type', 'title', 'account_id', 'category_id', 'last_processed_date']);
        });
    }
};
