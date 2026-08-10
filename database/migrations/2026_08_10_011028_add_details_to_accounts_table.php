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
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('account_number')->nullable()->after('type');
            $table->string('account_holder')->nullable()->after('account_number');
            $table->string('color_theme')->nullable()->after('account_holder')->comment('Tema warna kartu di frontend, contoh: bank-primary, bank-dark, wallet, cash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['account_number', 'account_holder', 'color_theme']);
        });
    }
};
