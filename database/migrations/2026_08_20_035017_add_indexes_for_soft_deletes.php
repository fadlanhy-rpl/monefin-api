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
        $tables = ['accounts', 'transactions', 'categories', 'goals', 'budgets'];
        
        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // Laravel's softDeletes() does not add an index by default.
                // Since almost all queries filter by user_id and deleted_at (IS NULL),
                // a compound index significantly speeds up queries.
                $table->index(['user_id', 'deleted_at'], $tableName . '_user_id_deleted_at_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['accounts', 'transactions', 'categories', 'goals', 'budgets'];
        
        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName . '_user_id_deleted_at_index');
            });
        }
    }
};
