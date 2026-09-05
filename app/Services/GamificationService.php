<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserGamification;
use App\Models\Achievement;
use App\Models\UserAchievement;
use App\Models\FinancialQuest;
use App\Models\UserQuest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GamificationService
{
    /**
     * Invalidate cached gamification summary for a user
     */
    public function invalidateUserCache(User $user): void
    {
        Cache::forget("gamification_summary:{$user->id}");
    }
    /**
     * Dapatkan profil gamifikasi user, buat baru jika belum ada
     */
    public function getOrCreateProfile(User $user): UserGamification
    {
        return UserGamification::firstOrCreate(
            ['user_id' => $user->id],
            [
                'xp'                       => 0,
                'level'                    => 1,
                'current_streak'           => 0,
                'longest_streak'           => 0,
                'last_activity_date'       => null,
                'streak_freezes_available' => 1,
            ]
        );
    }

    /**
     * Hitung ambang batas total XP untuk mencapai level tertentu
     */
    public function getXpThresholdForLevel(int $level): int
    {
        if ($level <= 1) return 0;
        // Total cumulative XP needed to reach Level L: 150 * (L - 1) * L / 2
        return (int) (150 * ($level - 1) * $level / 2);
    }

    /**
     * Hitung level saat ini berdasarkan total XP
     */
    public function calculateLevel(int $totalXp): int
    {
        $level = 1;
        while ($totalXp >= $this->getXpThresholdForLevel($level + 1)) {
            $level++;
        }
        return $level;
    }

    /**
     * Dapatkan detail level, progres persentase, dan gelar rank
     */
    public function getLevelDetails(int $totalXp): array
    {
        $currentLevel = $this->calculateLevel($totalXp);
        $currentLevelBaseXp = $this->getXpThresholdForLevel($currentLevel);
        $nextLevelBaseXp = $this->getXpThresholdForLevel($currentLevel + 1);

        $xpInCurrentLevel = $totalXp - $currentLevelBaseXp;
        $xpNeededForNext = $nextLevelBaseXp - $currentLevelBaseXp;

        $progressPercent = $xpNeededForNext > 0 
            ? min(100, max(0, round(($xpInCurrentLevel / $xpNeededForNext) * 100))) 
            : 100;

        $rankTitle = match(true) {
            $currentLevel >= 50 => 'Financial Grandmaster',
            $currentLevel >= 35 => 'Wealth Architect',
            $currentLevel >= 20 => 'Money Strategist',
            $currentLevel >= 10 => 'Wealth Builder',
            $currentLevel >= 5  => 'Budget Explorer',
            default             => 'Financial Novice',
        };

        $rankTitleId = match(true) {
            $currentLevel >= 50 => 'Grandmaster Finansial',
            $currentLevel >= 35 => 'Arsitek Kekayaan',
            $currentLevel >= 20 => 'Ahli Strategi Finansial',
            $currentLevel >= 10 => 'Pembangun Aset',
            $currentLevel >= 5  => 'Penjelajah Anggaran',
            default             => 'Pemula Finansial',
        };

        return [
            'level'               => $currentLevel,
            'current_xp'          => $totalXp,
            'xp_in_current_level' => $xpInCurrentLevel,
            'xp_needed_for_next'  => $xpNeededForNext,
            'progress_percent'    => $progressPercent,
            'rank_title'          => $rankTitle,
            'rank_title_id'       => $rankTitleId,
        ];
    }

    /**
     * Tambahkan XP ke user & evaluasi level up
     */
    public function awardXP(User $user, int $amount, string $reason = ''): array
    {
        if ($amount <= 0) return ['awarded' => 0];

        return DB::transaction(function () use ($user, $amount, $reason) {
            $profile = UserGamification::where('user_id', $user->id)->lockForUpdate()->first()
                ?? $this->getOrCreateProfile($user);

            $oldLevel = $profile->level;
            $newXp = $profile->xp + $amount;
            $newLevel = $this->calculateLevel($newXp);

            $leveledUp = $newLevel > $oldLevel;

            $profile->update([
                'xp'    => $newXp,
                'level' => $newLevel,
            ]);

            // Berikan bonus Streak Freeze jika mencapai kelipatan Level 5
            if ($leveledUp && $newLevel % 5 === 0) {
                $profile->increment('streak_freezes_available', 1);
            }

            $this->invalidateUserCache($user);

            return [
                'awarded_xp'  => $amount,
                'total_xp'    => $newXp,
                'old_level'   => $oldLevel,
                'level'       => $newLevel,
                'leveled_up'  => $leveledUp,
                'reason'      => $reason,
            ];
        });
    }

    /**
     * Catat aktivitas harian & kelola Daily Habit Streak
     */
    public function recordActivity(User $user): array
    {
        $profile = $this->getOrCreateProfile($user);
        $today = Carbon::today();
        $lastDate = $profile->last_activity_date ? Carbon::parse($profile->last_activity_date)->startOfDay() : null;

        $streakUpdated = false;
        $freezeUsed = false;

        if (!$lastDate) {
            // Aktivitas pertama
            $profile->current_streak = 1;
            $profile->longest_streak = 1;
            $profile->last_activity_date = $today;
            $streakUpdated = true;
            $this->awardXP($user, 15, 'Aktivitas Harian Pertama');
        } elseif ($lastDate->equalTo($today)) {
            // Sudah aktif hari ini, lewati update untuk performa instan
            return [
                'current_streak' => $profile->current_streak,
                'longest_streak' => $profile->longest_streak,
                'streak_updated' => false,
                'freeze_used'    => false,
                'freezes_left'   => $profile->streak_freezes_available,
            ];
        } elseif ($lastDate->equalTo($today->copy()->subDay())) {
            // Aktif kemarin (streak berlanjut)
            $profile->current_streak += 1;
            if ($profile->current_streak > $profile->longest_streak) {
                $profile->longest_streak = $profile->current_streak;
            }
            $profile->last_activity_date = $today;
            $streakUpdated = true;
            $this->awardXP($user, 15, "Streak Hari ke-{$profile->current_streak}");
        } elseif ($lastDate->equalTo($today->copy()->subDays(2)) && $profile->streak_freezes_available > 0) {
            // Terlewat 1 hari kemarin, gunakan Streak Freeze jika tersedia
            $profile->streak_freezes_available -= 1;
            $profile->streak_freeze_used_at = Carbon::now();
            $profile->current_streak += 1;
            if ($profile->current_streak > $profile->longest_streak) {
                $profile->longest_streak = $profile->current_streak;
            }
            $profile->last_activity_date = $today;
            $streakUpdated = true;
            $freezeUsed = true;
            $this->awardXP($user, 15, "Streak Terselamatkan (Saver Shield)");
        } else {
            // Streak terputus (terlewat > 1 hari atau tidak ada freeze)
            $profile->current_streak = 1;
            $profile->last_activity_date = $today;
            $streakUpdated = true;
            $this->awardXP($user, 15, 'Memulai Streak Baru');
        }

        $profile->save();

        // Evaluasi Achievement Streak hanya jika ada perubahan
        if ($streakUpdated) {
            $this->evaluateStreakAchievements($user, $profile->current_streak);
        }

        return [
            'current_streak' => $profile->current_streak,
            'longest_streak' => $profile->longest_streak,
            'streak_updated' => $streakUpdated,
            'freeze_used'    => $freezeUsed,
            'freezes_left'   => $profile->streak_freezes_available,
        ];
    }

    /**
     * Evaluasi Achievement Streak
     */
    private function evaluateStreakAchievements(User $user, int $currentStreak): void
    {
        $streakSlugs = [
            'streak_3'   => 3,
            'streak_7'   => 7,
            'streak_30'  => 30,
            'streak_100' => 100,
        ];

        foreach ($streakSlugs as $slug => $required) {
            $this->updateAchievementProgress($user, $slug, $currentStreak);
        }
    }

    /**
     * Update progres achievement & buka lencana jika target tercapai
     */
    public function updateAchievementProgress(User $user, string $slug, int $currentCount): ?array
    {
        $achievement = Achievement::where('slug', $slug)->first();
        if (!$achievement) return null;

        $userAch = UserAchievement::firstOrCreate(
            ['user_id' => $user->id, 'achievement_id' => $achievement->id],
            ['progress' => 0, 'is_unlocked' => false]
        );

        if ($userAch->is_unlocked) {
            return null; // Sudah terbuka sebelumnya
        }

        $userAch->progress = max($userAch->progress, $currentCount);

        if ($userAch->progress >= $achievement->required_count) {
            $userAch->is_unlocked = true;
            $userAch->unlocked_at = Carbon::now();
            $userAch->save();

            // Beri hadiah XP dari lencana
            $this->awardXP($user, $achievement->xp_reward, "Lencana Terbuka: {$achievement->title}");

            return [
                'unlocked'    => true,
                'achievement' => $achievement,
                'xp_awarded'  => $achievement->xp_reward,
            ];
        }

        $userAch->save();
        return null;
    }

    /**
     * Rekam progres pada Quests aktif (Daily / Weekly)
     */
    public function recordQuestAction(User $user, string $targetType, int $increment = 1): void
    {
        $today = Carbon::today();
        $dailyKey = $today->toDateString();
        $weeklyKey = $today->format('Y-\WW');

        $activeQuests = FinancialQuest::where('is_active', true)
            ->where('target_type', $targetType)
            ->get();

        foreach ($activeQuests as $quest) {
            $periodKey = $quest->type === 'daily' ? $dailyKey : $weeklyKey;

            $userQuest = UserQuest::firstOrCreate(
                [
                    'user_id'    => $user->id,
                    'quest_id'   => $quest->id,
                    'period_key' => $periodKey,
                ],
                [
                    'current_count' => 0,
                    'is_completed'  => false,
                    'is_claimed'    => false,
                ]
            );

            if ($userQuest->is_completed) {
                continue;
            }

            $userQuest->current_count += $increment;

            if ($userQuest->current_count >= $quest->target_count) {
                $userQuest->is_completed = true;
                $userQuest->completed_at = Carbon::now();
            }

            $userQuest->save();
        }

        $this->invalidateUserCache($user);
    }

    /**
     * Dapatkan ringkasan lengkap data gamifikasi user (Cached for 60s)
     */
    public function getSummary(User $user): array
    {
        return Cache::remember("gamification_summary:{$user->id}", 60, function () use ($user) {
            $profile = $this->getOrCreateProfile($user);
            $levelDetails = $this->getLevelDetails($profile->xp);

            $unlockedBadgesCount = UserAchievement::where('user_id', $user->id)
                ->where('is_unlocked', true)
                ->count();

            $totalBadgesCount = Achievement::count();

            // Ambil 3 badge terbaru yang berhasil dibuka
            $recentBadges = UserAchievement::where('user_id', $user->id)
                ->where('is_unlocked', true)
                ->with('achievement')
                ->latest('unlocked_at')
                ->take(3)
                ->get()
                ->map(fn($ua) => $ua->achievement);

            // Ambil quest yang aktif dan belum diklaim
            $today = Carbon::today();
            $dailyKey = $today->toDateString();
            $weeklyKey = $today->format('Y-\WW');

            $quests = FinancialQuest::where('is_active', true)->get()->map(function ($q) use ($user, $dailyKey, $weeklyKey) {
                $periodKey = $q->type === 'daily' ? $dailyKey : $weeklyKey;
                $uq = UserQuest::where('user_id', $user->id)
                    ->where('quest_id', $q->id)
                    ->where('period_key', $periodKey)
                    ->first();

                return [
                    'id'            => $q->id,
                    'slug'          => $q->slug,
                    'title'         => $q->title,
                    'description'   => $q->description,
                    'type'          => $q->type,
                    'target_type'   => $q->target_type,
                    'target_count'  => $q->target_count,
                    'xp_reward'     => $q->xp_reward,
                    'current_count' => $uq ? min($q->target_count, $uq->current_count) : 0,
                    'is_completed'  => $uq ? $uq->is_completed : false,
                    'is_claimed'    => $uq ? $uq->is_claimed : false,
                ];
            });

            return [
                'level'               => $levelDetails['level'],
                'total_xp'            => $levelDetails['current_xp'],
                'xp_in_current_level' => $levelDetails['xp_in_current_level'],
                'xp_needed_for_next'  => $levelDetails['xp_needed_for_next'],
                'progress_percent'    => $levelDetails['progress_percent'],
                'rank_title'          => $levelDetails['rank_title'],
                'rank_title_id'       => $levelDetails['rank_title_id'],
                'current_streak'      => $profile->current_streak,
                'longest_streak'      => $profile->longest_streak,
                'streak_freezes'      => $profile->streak_freezes_available,
                'unlocked_badges'     => $unlockedBadgesCount,
                'total_badges'        => $totalBadgesCount,
                'recent_badges'       => $recentBadges->values()->toArray(),
                'quests'              => $quests->values()->toArray(),
            ];
        });
    }
}
