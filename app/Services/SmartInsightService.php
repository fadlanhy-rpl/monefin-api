<?php

namespace App\Services;

use App\Models\User;
use App\Services\Insights\DeterministicRuleEngine;
use App\Services\Insights\InsightContextBuilder;
use Illuminate\Support\Facades\Cache;

class SmartInsightService
{
    public function __construct(
        private readonly AiService $ai,
        private readonly InsightContextBuilder $contextBuilder,
        private readonly DeterministicRuleEngine $ruleEngine
    ) {}

    public static function invalidateUserCache(User $user): void
    {
        foreach (['dashboard', 'categories', 'budgets', 'accounts', 'goals'] as $p) {
            Cache::forget("smart_insight:{$user->id}:{$p}:id");
            Cache::forget("smart_insight:{$user->id}:{$p}:en");
        }
    }

    public function getInsight(User $user, string $page, string $lang = 'id'): array
    {
        $langNormalized = str_starts_with(strtolower($lang), 'en') ? 'en' : 'id';
        $cacheKey = "smart_insight:{$user->id}:{$page}:{$langNormalized}";

        return Cache::remember($cacheKey, 1800, function () use ($user, $page, $langNormalized) {
            $prefs     = $user->preferences ?? [];
            $aiEnabled = $prefs['ai_enabled'] ?? false;

            $context = $this->contextBuilder->buildPageContext($user, $page, $langNormalized);

            if ($aiEnabled) {
                $result = $this->getAiInsight($user, $page, $context, $prefs, $langNormalized);
                if ($result !== null) {
                    return $result;
                }
            }

            return $this->ruleEngine->generate($page, $context, $prefs, $langNormalized);
        });
    }

    private function getAiInsight(User $user, string $page, array $ctx, array $prefs, string $lang): ?array
    {
        // Fast-path Guard: Jika data halaman belum ada (misal belum ada budget),
        // langsung fallback ke MoneFin Engine secara instan tanpa membuang waktu panggil remote AI.
        if ($page === 'budgets' && empty($ctx['budgets'])) {
            return null;
        }
        if ($page === 'accounts' && empty($ctx['accounts'])) {
            return null;
        }
        if ($page === 'goals' && empty($ctx['goals'])) {
            return null;
        }

        $aiConfig = $prefs['ai_config'] ?? [];
        $provider = $aiConfig['provider'] ?? '';
        $model    = $aiConfig['model']    ?? '';

        if (empty($provider)) {
            return null;
        }

        $contextText = $this->contextBuilder->contextToText($ctx, $page);
        $pageLabel   = $this->contextBuilder->pageLabel($page, $lang);

        $prompt = $lang === 'en'
            ? "You are MoneFin AI financial advisor. Provide 1 specific, actionable, and personal financial insight for the '{$pageLabel}' page based on the following user data:\n\n{$contextText}\n\nRespond ONLY in JSON format:\n{\"title\": \"Short catchy title\", \"body\": \"1-2 actionable and personal sentences\", \"action_label\": \"Action button label\", \"action_url\": \"/target-path\", \"type\": \"expense|budget|goal|saving|alert\"}"
            : "Kamu adalah MoneFin AI financial advisor. Berikan 1 insight finansial yang spesifik, actionable, dan personal untuk halaman '{$pageLabel}' berdasarkan data berikut:\n\n{$contextText}\n\nBerikan respons dalam format JSON (HANYA JSON):\n{\"title\": \"Judul singkat\", \"body\": \"Penjelasan 1-2 kalimat yang actionable dan personal\", \"action_label\": \"Label tombol aksi\", \"action_url\": \"/halaman-tujuan\", \"type\": \"expense|budget|goal|saving|alert\"}";

        try {
            $raw = $this->ai->chat($user, $prompt, []);

            if ($this->ai->isQuotaError($raw)) {
                return null;
            }

            $clean  = trim(preg_replace('/```(?:json)?|```/', '', $raw));
            $parsed = json_decode($clean, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($parsed['title'], $parsed['body'])) {
                $providerLabel = $this->contextBuilder->providerLabel($provider, $model);
                return array_merge($parsed, [
                    'source'       => 'ai',
                    'source_label' => "Powered by {$providerLabel}",
                ]);
            }
        } catch (\Throwable $e) {
            // AI error — fall through to deterministic
        }

        return null;
    }
}