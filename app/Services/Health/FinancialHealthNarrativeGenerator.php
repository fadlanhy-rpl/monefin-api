<?php

namespace App\Services\Health;

class FinancialHealthNarrativeGenerator
{
    /**
     * Membangun label skor, ringkasan mingguan, dan catatan positif dalam bahasa target (ID/EN).
     */
    public function buildLabels(
        float $score,
        float $income,
        float $expense,
        float $savings,
        float $expenseWeek,
        float $expenseLastWk,
        string $lang
    ): array {
        $incomeFmt  = $this->formatRupiah($income);
        $expenseFmt = $this->formatRupiah($expense);
        $savingsFmt = $this->formatRupiah(max(0, $savings));

        if ($lang === 'en') {
            if ($score >= 80) {
                $label   = 'Excellent';
                $summary = "Your finances are in excellent condition! Monthly income {$incomeFmt} with savings of {$savingsFmt}. Keep up these great habits.";
                $note    = "Your consistency in managing money is commendable — a score above 80 is achieved by fewer than 20% of users!";
            } elseif ($score >= 60) {
                $label   = 'Healthy';
                $summary = "Your finances are considered healthy. Income {$incomeFmt}, expenses {$expenseFmt}. There is room to increase your savings further.";
                $note    = "You're on the right track. Minor optimizations in non-essential spending can boost your score to 'Excellent'.";
            } elseif ($score >= 40) {
                $label   = 'Fair';
                $summary = "Your financial status is fairly stable, but a few areas need attention. Expenses this month {$expenseFmt} from income of {$incomeFmt}.";
                $note    = "Best next step: create at least 1 category budget and 1 savings goal to improve your score significantly.";
            } elseif ($score >= 20) {
                $label   = 'Needs Attention';
                $summary = "Your finances need closer attention. " . ($expense > $income && $income > 0 ? "Expenses ({$expenseFmt}) exceed income ({$incomeFmt}) this month." : "Record income and expenses regularly for a more accurate financial picture.");
                $note    = "Start small: record 1 transaction every day for a week and see the difference!";
            } else {
                $label   = 'Critical';
                $summary = "Your finances need immediate evaluation. " . ($income <= 0 ? "No income recorded yet." : "Expenses far exceed income this month.");
                $note    = "Don't worry — every small improvement counts. Start by recording your expenses today.";
            }
        } else {
            if ($score >= 80) {
                $label   = 'Sangat Sehat';
                $summary = "Keuangan Anda dalam kondisi sangat prima! Pemasukan bulan ini {$incomeFmt} dengan tabungan {$savingsFmt}. Pertahankan kebiasaan baik ini.";
                $note    = "Konsistensi Anda dalam mengelola keuangan patut diapresiasi — skor di atas 80 dicapai oleh kurang dari 20% pengguna!";
            } elseif ($score >= 60) {
                $label   = 'Sehat';
                $summary = "Keuangan Anda tergolong sehat. Pemasukan {$incomeFmt}, pengeluaran {$expenseFmt}. Ada ruang untuk meningkatkan tabungan lebih jauh.";
                $note    = "Anda sudah di jalur yang tepat. Sedikit optimasi pada pengeluaran non-esensial bisa mendorong skor Anda ke 'Sangat Sehat'.";
            } elseif ($score >= 40) {
                $label   = 'Cukup';
                $summary = "Kondisi keuangan Anda cukup stabil, namun ada beberapa area yang perlu perhatian. Pengeluaran bulan ini {$expenseFmt} dari pemasukan {$incomeFmt}.";
                $note    = "Langkah terbaik sekarang: buat minimal 1 anggaran kategori dan 1 target tabungan untuk meningkatkan skor secara signifikan.";
            } elseif ($score >= 20) {
                $label   = 'Perlu Perhatian';
                $summary = "Keuangan Anda membutuhkan perhatian lebih. " . ($expense > $income && $income > 0 ? "Pengeluaran ({$expenseFmt}) melebihi pemasukan ({$incomeFmt}) bulan ini." : "Catat pemasukan dan pengeluaran secara rutin untuk gambaran yang lebih akurat.");
                $note    = "Mulai dari hal kecil: catat 1 transaksi setiap hari selama seminggu dan lihat perbedaannya!";
            } else {
                $label   = 'Kritis';
                $summary = "Kondisi keuangan Anda perlu segera dievaluasi. " . ($income <= 0 ? "Belum ada pemasukan yang tercatat." : "Pengeluaran jauh melampaui pemasukan bulan ini.");
                $note    = "Jangan khawatir — setiap perbaikan sekecil apapun sangat berarti. Mulai dengan mencatat pengeluaran hari ini.";
            }
        }

        return [$label, $summary, $note];
    }

    /**
     * Membangun daftar rekomendasi tips aksi maksimal 3 item berdasarkan prioritas finansial.
     */
    public function buildTips(
        float $income,
        float $expense,
        float $savings,
        array $budgets,
        array $goals,
        int $activeDays,
        array $topCategories,
        string $lang
    ): array {
        $tips = [];

        // Priority 1 — Cashflow alert
        if ($income > 0 && $expense > $income) {
            $expenseFmt = $this->formatRupiah($expense);
            $incomeFmt  = $this->formatRupiah($income);
            $tips[] = [
                'type'         => 'alert',
                'title'        => $lang === 'en' ? 'Expenses Exceed Income' : 'Pengeluaran Melebihi Pemasukan',
                'body'         => $lang === 'en'
                    ? "This month's expenses ({$expenseFmt}) exceed income ({$incomeFmt}). Review non-essential expenses promptly."
                    : "Pengeluaran bulan ini ({$expenseFmt}) melebihi pemasukan ({$incomeFmt}). Evaluasi pengeluaran non-esensial segera.",
                'action_label' => $lang === 'en' ? 'View Transactions' : 'Lihat Transaksi',
                'action_url'   => '/transactions',
            ];
        }

        // Priority 2 — Budget nearing limit
        $criticalBudgets = array_filter($budgets, fn($b) => $b['percent'] >= 85);
        if (!empty($criticalBudgets)) {
            $worst = array_reduce($criticalBudgets, fn($carry, $b) => (!$carry || $b['percent'] > $carry['percent']) ? $b : $carry, null);
            if ($worst) {
                $categoryName = $worst['category'];
                $percentValue = $worst['percent'];
                $tips[] = [
                    'type'         => 'budget',
                    'title'        => $lang === 'en' ? "Budget '{$categoryName}' Almost Depleted" : "Anggaran '{$categoryName}' Hampir Habis",
                    'body'         => $lang === 'en'
                        ? "Category '{$categoryName}' has reached {$percentValue}% of its budget limit. Consider trimming spending here."
                        : "Kategori '{$categoryName}' sudah mencapai {$percentValue}% dari anggaran. Pertimbangkan untuk mengurangi pengeluaran di kategori ini.",
                    'action_label' => $lang === 'en' ? 'View Budgets' : 'Lihat Budget',
                    'action_url'   => '/budgets',
                ];
            }
        }

        // Priority 3 — Low savings rate
        if ($income > 0) {
            $savingsRate = ($savings / $income) * 100;
            if ($savingsRate < 10 && $savings >= 0) {
                $targetSavingFmt = $this->formatRupiah($income * 0.20);
                $roundedRate     = round($savingsRate);
                $tips[] = [
                    'type'         => 'saving',
                    'title'        => $lang === 'en' ? 'Boost Your Savings Rate' : 'Tingkatkan Rasio Tabungan',
                    'body'         => $lang === 'en'
                        ? "Your savings rate is currently {$roundedRate}%. Ideal target is at least 20% of income ({$targetSavingFmt}/month)."
                        : "Rasio tabungan Anda saat ini {$roundedRate}%. Target ideal minimal 20% dari pemasukan ({$targetSavingFmt}/bulan).",
                    'action_label' => $lang === 'en' ? 'Create Goals' : 'Buat Goals',
                    'action_url'   => '/goals',
                ];
            }
        }

        // Priority 4 — No goals
        if (empty($goals)) {
            $tips[] = [
                'type'         => 'goal',
                'title'        => $lang === 'en' ? 'Set Your First Financial Goal' : 'Buat Target Keuangan Pertama',
                'body'         => $lang === 'en'
                    ? "Users with active savings goals tend to save 3× more consistently. Start setting your first target today!"
                    : "Pengguna yang memiliki goals tabungan cenderung menabung 3× lebih konsisten. Mulai buat target pertama Anda sekarang!",
                'action_label' => $lang === 'en' ? 'Create Goals' : 'Buat Goals',
                'action_url'   => '/goals',
            ];
        }

        // Priority 5 — Inconsistent recording
        if ($activeDays < 10) {
            $tips[] = [
                'type'         => 'expense',
                'title'        => $lang === 'en' ? 'Record Transactions Regularly' : 'Catat Transaksi Lebih Rutin',
                'body'         => $lang === 'en'
                    ? "You have recorded transactions on {$activeDays} days in the last 30 days. Daily tracking improves analysis accuracy."
                    : "Anda baru mencatat transaksi {$activeDays} hari dalam 30 hari terakhir. Pencatatan rutin meningkatkan akurasi analisis keuangan Anda.",
                'action_label' => $lang === 'en' ? 'Record Transaction' : 'Catat Transaksi',
                'action_url'   => '/transactions',
            ];
        }

        // Priority 6 — Top spending category tip
        if (!empty($topCategories) && count($tips) < 3) {
            $top            = $topCategories[0];
            $categoryName   = $top['category'];
            $topAmountFmt   = $this->formatRupiah($top['amount']);
            $tips[] = [
                'type'         => 'expense',
                'title'        => $lang === 'en' ? "Largest Expense: {$categoryName}" : "Pengeluaran Terbesar: {$categoryName}",
                'body'         => $lang === 'en'
                    ? "Category '{$categoryName}' is your largest expense in the last 30 days at {$topAmountFmt}. Keep an eye on this."
                    : "Kategori '{$categoryName}' adalah pengeluaran terbesar Anda 30 hari terakhir sebesar {$topAmountFmt}. Pantau tren ini.",
                'action_label' => $lang === 'en' ? 'View Reports' : 'Lihat Laporan',
                'action_url'   => '/reports',
            ];
        }

        // Priority 7 — No budget set
        if (empty($budgets) && count($tips) < 3) {
            $tips[] = [
                'type'         => 'budget',
                'title'        => $lang === 'en' ? 'Set Monthly Budgets' : 'Atur Anggaran Bulanan',
                'body'         => $lang === 'en'
                    ? "No active category budgets this month. Setting budget limits helps you curb unnecessary expenses."
                    : "Belum ada anggaran aktif bulan ini. Membuat budget kategori membantu mengontrol pengeluaran secara efektif.",
                'action_label' => $lang === 'en' ? 'Create Budget' : 'Buat Budget',
                'action_url'   => '/budgets',
            ];
        }

        // Priority 8 — Audit Recurring Bills
        if (count($tips) < 3) {
            $tips[] = [
                'type'         => 'expense',
                'title'        => $lang === 'en' ? 'Audit Recurring Bills' : 'Evaluasi Pengeluaran Rutin',
                'body'         => $lang === 'en'
                    ? "Review active subscriptions & scheduled bills to cut unused services and optimize recurring expenses."
                    : "Tinjau langganan dan tagihan rutin Anda untuk memotong pengeluaran pasif yang tidak terpakai.",
                'action_label' => $lang === 'en' ? 'Manage Recurring' : 'Atur Rutin',
                'action_url'   => '/recurring',
            ];
        }

        // Priority 9 — Emergency Cushion / Goals
        if (count($tips) < 3) {
            $tips[] = [
                'type'         => 'goal',
                'title'        => $lang === 'en' ? 'Boost Emergency Cushion' : 'Optimalkan Dana Darurat',
                'body'         => $lang === 'en'
                    ? "With positive cash flow, dedicate excess surplus toward an emergency fund or long-term growth targets."
                    : "Dengan arus kas yang positif, sisihkan kelebihan dana ke target dana darurat atau tabungan jangka panjang.",
                'action_label' => $lang === 'en' ? 'View Goals' : 'Cek Goals',
                'action_url'   => '/goals',
            ];
        }

        // Priority 10 — Spending Trends / Reports
        if (count($tips) < 3) {
            $tips[] = [
                'type'         => 'saving',
                'title'        => $lang === 'en' ? 'Analyze Spending Trends' : 'Analisis Tren Finansial',
                'body'         => $lang === 'en'
                    ? "Inspect your monthly cashflow velocity and category distribution in detailed financial reports."
                    : "Periksa grafik perputaran uang dan sebaran kategori pengeluaran Anda di menu Laporan.",
                'action_label' => $lang === 'en' ? 'View Reports' : 'Lihat Laporan',
                'action_url'   => '/reports',
            ];
        }

        return array_slice($tips, 0, 3);
    }

    public function formatRupiah(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}
