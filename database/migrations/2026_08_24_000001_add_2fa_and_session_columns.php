<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add 2FA flag to users
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('two_factor_enabled')->default(false)->after('preferences');
        });

        // Enrich personal_access_tokens with device info
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_name')->nullable()->after('name');
            $table->string('ip_address', 45)->nullable()->after('device_name');
            $table->string('user_agent')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_enabled');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_name', 'ip_address', 'user_agent']);
        });
    }
};
