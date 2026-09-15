<?php

namespace App\Services\Health;

class FinancialHealthScorer
{
    /**
     * Hitung total skor kesehatan finansial (0-100) berdasarkan 5 pilar:
     *   30% — Cashflow Ratio   (income vs expense)
     *   25% — Budget Compliance (how well user stays under limits)
     *   20% — Savings Rate      (savings as % of income)
     *   15% — Goals Progress    (average completion % of active goals)
     *   10% — Recording Consistency (days with at least one transaction in last 30 days)
     */
    public function calculate(array $data): array
    {
        $cashflowScore    = $this->scoreCashflow($data['income_month'], $data['expense_month']);
        $budgetScore      = $this->scoreBudget($data['budgets']);
        $savingsScore     = $this->scoreSavings($data['income_month'], $data['savings']);
        $goalsScore       = $this->scoreGoals($data['goals']);
        $consistencyScore = min(100, ($data['active_days'] / 30) * 100);

        $total = round(
            $cashflowScore    * 0.30 +
            $budgetScore      * 0.25 +
            $savingsScore     * 0.20 +
            $goalsScore       * 0.15 +
            $consistencyScore * 0.10
        );

        $total = (int) max(0, min(100, $total));

        return [
            'total'             => $total,
            'cashflow_score'    => $cashflowScore,
            'budget_score'      => $budgetScore,
            'savings_score'     => $savingsScore,
            'goals_score'       => $goalsScore,
            'consistency_score' => $consistencyScore,
        ];
    }

    public function scoreCashflow(float $income, float $expense): float
    {
        if ($income <= 0) {
            return $expense > 0 ? 0 : 40;
        }
        $ratio = ($income - $expense) / $income;
        return min(100, max(0, $ratio * 100));
    }

    public function scoreBudget(array $budgets): float
    {
        if (empty($budgets)) {
            return 50;
        }
        $scores = array_map(function ($b) {
            return min(100, max(0, 100 - max(0, $b['percent'] - 100)));
        }, $budgets);

        return array_sum($scores) / count($scores);
    }

    public function scoreSavings(float $income, float $savings): float
    {
        if ($income <= 0) {
            return 50;
        }
        $rate = ($savings / $income) * 100;
        return min(100, max(0, ($rate / 20) * 100));
    }

    public function scoreGoals(array $goals): float
    {
        if (empty($goals)) {
            return 50;
        }
        $percents = array_column($goals, 'percent');
        return array_sum($percents) / count($percents);
    }
}
