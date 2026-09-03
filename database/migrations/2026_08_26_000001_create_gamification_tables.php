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
        // 1. User Gamification Profile
        Schema::create('user_gamification', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('xp')->default(0);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_activity_date')->nullable();
            $table->unsignedInteger('streak_freezes_available')->default(1);
            $table->timestamp('streak_freeze_used_at')->nullable();
            $table->timestamps();
        });

        // 2. Master Achievements
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('category')->default('general'); // streak, budget, saving, transaction, security
            $table->string('tier')->default('bronze'); // bronze, silver, gold, platinum
            $table->unsignedInteger('xp_reward')->default(50);
            $table->string('icon')->default('Award');
            $table->unsignedInteger('required_count')->default(1);
            $table->timestamps();
        });

        // 3. User Achievements Progress & Unlock Status
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained('achievements')->cascadeOnDelete();
            $table->unsignedInteger('progress')->default(0);
            $table->boolean('is_unlocked')->default(false);
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'achievement_id']);
        });

        // 4. Financial Quests & Challenges
        Schema::create('financial_quests', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('type')->default('weekly'); // daily, weekly, monthly
            $table->string('target_type')->default('record_transactions'); // record_transactions, deposit_goal, create_budget, check_analytics
            $table->unsignedInteger('target_count')->default(1);
            $table->unsignedInteger('xp_reward')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 5. User Quests Progress
        Schema::create('user_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('quest_id')->constrained('financial_quests')->cascadeOnDelete();
            $table->string('period_key'); // e.g. 2026-W34 or 2026-08-26
            $table->unsignedInteger('current_count')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_claimed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'quest_id', 'period_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_quests');
        Schema::dropIfExists('financial_quests');
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('user_gamification');
    }
};
