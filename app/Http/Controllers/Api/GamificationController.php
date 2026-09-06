<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\FinancialQuest;
use App\Models\UserAchievement;
use App\Models\UserQuest;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GamificationController extends Controller
{
    public function __construct(
        protected GamificationService $gamificationService
    ) {}

    /**
     * GET /api/gamification/summary
     * Ringkasan gamifikasi (Level, XP, Streak, Quests, Badges)
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        // Rekam aktivitas harian (check-in / streak)
        $this->gamificationService->recordActivity($user);

        $summary = $this->gamificationService->getSummary($user);

        return response()->json([
            'status' => 'success',
            'data'   => $summary,
        ]);
    }

    /**
     * GET /api/gamification/achievements
     * Daftar semua lencana & status progres user
     */
    public function achievements(Request $request): JsonResponse
    {
        $user = $request->user();

        $userAchievements = UserAchievement::where('user_id', $user->id)
            ->get()
            ->keyBy('achievement_id');

        $achievements = Achievement::all()->map(function ($ach) use ($userAchievements) {
            $ua = $userAchievements->get($ach->id);

            return [
                'id'             => $ach->id,
                'slug'           => $ach->slug,
                'title'          => $ach->title,
                'description'    => $ach->description,
                'category'       => $ach->category,
                'tier'           => $ach->tier,
                'xp_reward'      => $ach->xp_reward,
                'icon'           => $ach->icon,
                'required_count' => $ach->required_count,
                'progress'       => $ua ? min($ach->required_count, $ua->progress) : 0,
                'is_unlocked'    => $ua ? $ua->is_unlocked : false,
                'unlocked_at'    => $ua ? $ua->unlocked_at : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => $achievements,
        ]);
    }

    /**
     * GET /api/gamification/quests
     * Daftar misi harian & mingguan
     */
    public function quests(Request $request): JsonResponse
    {
        $user = $request->user();
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

        return response()->json([
            'status' => 'success',
            'data'   => $quests,
        ]);
    }

    /**
     * POST /api/gamification/quests/{id}/claim
     * Klaim hadiah XP dari misi yang sudah selesai
     */
    public function claimQuest(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $quest = FinancialQuest::findOrFail($id);

        $today = Carbon::today();
        $periodKey = $quest->type === 'daily' ? $today->toDateString() : $today->format('Y-\WW');

        $userQuest = UserQuest::where('user_id', $user->id)
            ->where('quest_id', $quest->id)
            ->where('period_key', $periodKey)
            ->first();

        if (!$userQuest || !$userQuest->is_completed) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Misi belum selesai atau belum memenuhi syarat.',
            ], 400);
        }

        if ($userQuest->is_claimed) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Hadiah misi ini sudah pernah diklaim.',
            ], 400);
        }

        // Compare-and-set on is_claimed: exactly one concurrent caller wins
        $claimed = UserQuest::whereKey($userQuest->getKey())
            ->where('is_claimed', false)
            ->update([
                'is_claimed' => true,
                'claimed_at' => Carbon::now(),
            ]);

        if ($claimed !== 1) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Hadiah misi ini sudah pernah diklaim.',
            ], 400);
        }

        $xpResult = $this->gamificationService->awardXP(
            $user, 
            $quest->xp_reward, 
            "Klaim Misi: {$quest->title}"
        );

        return response()->json([
            'status'     => 'success',
            'message'    => "Selamat! Anda mendapatkan +{$quest->xp_reward} XP!",
            'data'       => $xpResult,
            'summary'    => $this->gamificationService->getSummary($user),
        ]);
    }
}
