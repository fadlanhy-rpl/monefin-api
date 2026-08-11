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
        Schema::table('goals', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('name');
            $table->string('color', 20)->nullable()->after('description');
            $table->string('icon', 50)->nullable()->after('color');
            $table->string('layout_type', 20)->default('linear')->after('icon');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn(['description', 'color', 'icon', 'layout_type']);
        });
    }
};
