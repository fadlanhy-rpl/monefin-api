<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'])) {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS spend_notif_budget_alert_unique ON spending_notifications (user_id, period_label) WHERE type = 'budget_alert'");
        } else {
            Schema::table('spending_notifications', function (Blueprint $table) {
                $table->index(['user_id', 'type', 'period_label'], 'spend_notif_budget_alert_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'])) {
            DB::statement("DROP INDEX IF EXISTS spend_notif_budget_alert_unique");
        } else {
            Schema::table('spending_notifications', function (Blueprint $table) {
                $table->dropIndex('spend_notif_budget_alert_idx');
            });
        }
    }
};
