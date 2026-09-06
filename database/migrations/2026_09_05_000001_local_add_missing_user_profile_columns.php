<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOCAL TEST-INSTANCE ENABLEMENT MIGRATION (not upstream application logic).
 *
 * Upstream migration 2026_08_17_032213_add_profile_details_and_preferences_to_users_table
 * ships with an EMPTY Schema::table() closure, so users.phone / users.bio /
 * users.preferences are never created, even though app/Models/User.php lists them in
 * $fillable/$casts and AuthController::updateProfile, UserApiKeyService, AiService,
 * SmartInsightService and TransactionController all read/write them.
 *
 * Without these columns every profile update and every AI/insight/transaction request
 * fails with "no such column". This adds exactly those three columns so the API is
 * exercisable for dynamic security validation. No application logic is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 50)->nullable();
            }
            if (!Schema::hasColumn('users', 'bio')) {
                $table->string('bio', 500)->nullable();
            }
            if (!Schema::hasColumn('users', 'preferences')) {
                $table->json('preferences')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['phone', 'bio', 'preferences'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
