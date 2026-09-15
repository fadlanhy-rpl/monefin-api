<?php

namespace App\Services;

use App\Models\User;
use App\Services\Health\FinancialHealthDataCollector;
use App\Services\Health\FinancialHealthNarrativeGenerator;
use App\Services\Health\FinancialHealthScorer;

/**
 * Deterministic financial health scoring — no AI required.
 * Fully localized for 'id' and 'en'.
 *
 * Scoring Formula (0–100):
 *   30% — Cashflow Ratio   (income vs expense)
 *   25% — Budget Compliance (how well user stays under limits)
 *   20% — Savings Rate      (savings as % of income)
 *   15% — Goals Progress    (average completion % of active goals)
 *   10% — Recording Consistency (days with at least one transaction in last 30 days)
 */
class FinancialHealthService
{
    public function __construct(
        protected FinancialHealthDataCollector $dataCollector,
        protected FinancialHealthScorer $scorer,
        protected FinancialHealthNarrativeGenerator $narrativeGenerator
    ) {}

    public function insights(User $user, string $lang = 'id'): array
    {
        $lang = str_starts_with(strtolower($lang), 'en') ? 'en' : 'id';

        $data = $this->dataCollector->collect($user, $lang);
        $scoreResult = $this->scorer->calculate($data);
        $totalScore = $scoreResult['total'];

        [$scoreLabel, $summary, $positiveNote] = $this->narrativeGenerator->buildLabels(
            $totalScore,
            $data['income_month'],
            $data['expense_month'],
            $data['savings'],
            $data['expense_week'],
            $data['expense_last_wk'],
            $lang
        );

        $tips = $this->narrativeGenerator->buildTips(
            $data['income_month'],
            $data['expense_month'],
            $data['savings'],
            $data['budgets'],
            $data['goals'],
            $data['active_days'],
            $data['top_categories'],
            $lang
        );

        return [
            'health_score'   => $totalScore,
            'score_label'    => $scoreLabel,
            'weekly_summary' => $summary,
            'tips'           => $tips,
            'positive_note'  => $positiveNote,
            'source'         => 'engine',
        ];
    }
}
