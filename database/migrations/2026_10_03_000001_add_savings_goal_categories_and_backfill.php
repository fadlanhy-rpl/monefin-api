<?php

use App\Models\Category;
use App\Models\Transaction;
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
        // 1. Ensure default Tabungan Impian categories exist
        $expenseTabungan = Category::firstOrCreate(
            ['user_id' => null, 'name' => 'Tabungan Impian', 'type' => 'expense'],
            ['icon' => 'piggy-bank', 'color' => '#00685F', 'description' => 'Alokasi setoran untuk target tabungan & impian']
        );

        $incomeTabungan = Category::firstOrCreate(
            ['user_id' => null, 'name' => 'Tabungan Impian', 'type' => 'income'],
            ['icon' => 'piggy-bank', 'color' => '#00685F', 'description' => 'Penarikan dana dari target tabungan & impian']
        );

        // 2. Backfill existing goal transactions that have category_id = null
        Transaction::whereNotNull('goal_id')
            ->whereNull('category_id')
            ->where('type', 'expense')
            ->update(['category_id' => $expenseTabungan->id]);

        Transaction::whereNotNull('goal_id')
            ->whereNull('category_id')
            ->where('type', 'income')
            ->update(['category_id' => $incomeTabungan->id]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversal does not delete categories to avoid orphan foreign keys
    }
};
