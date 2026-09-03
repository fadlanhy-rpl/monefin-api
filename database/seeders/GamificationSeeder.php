<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Achievement;
use App\Models\FinancialQuest;

class GamificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $achievements = [
            [
                'slug'           => 'first_tx',
                'title'          => 'Pencatat Pemula',
                'description'    => 'Catat transaksi pertama Anda di MoneFin.',
                'category'       => 'transaction',
                'tier'           => 'bronze',
                'xp_reward'      => 50,
                'icon'           => 'Zap',
                'required_count' => 1,
            ],
            [
                'slug'           => 'tx_10',
                'title'          => 'Pencatat Aktif',
                'description'    => 'Catat minimal 10 transaksi pengeluaran/pemasukan.',
                'category'       => 'transaction',
                'tier'           => 'bronze',
                'xp_reward'      => 100,
                'icon'           => 'Zap',
                'required_count' => 10,
            ],
            [
                'slug'           => 'tx_50',
                'title'          => 'Pakar Arus Kas',
                'description'    => 'Catat minimal 50 transaksi di dalam aplikasi.',
                'category'       => 'transaction',
                'tier'           => 'silver',
                'xp_reward'      => 250,
                'icon'           => 'Zap',
                'required_count' => 50,
            ],
            [
                'slug'           => 'tx_100',
                'title'          => 'Legenda Finansial',
                'description'    => 'Catat minimal 100 transaksi secara konsisten.',
                'category'       => 'transaction',
                'tier'           => 'gold',
                'xp_reward'      => 500,
                'icon'           => 'Zap',
                'required_count' => 100,
            ],
            [
                'slug'           => 'streak_3',
                'title'          => 'Percikan Disiplin',
                'description'    => 'Pertahankan streak pencatatan selama 3 hari berturut-turut.',
                'category'       => 'streak',
                'tier'           => 'bronze',
                'xp_reward'      => 75,
                'icon'           => 'Flame',
                'required_count' => 3,
            ],
            [
                'slug'           => 'streak_7',
                'title'          => 'Pejuang Mingguan',
                'description'    => 'Pertahankan streak pencatatan selama 7 hari berturut-turut.',
                'category'       => 'streak',
                'tier'           => 'silver',
                'xp_reward'      => 150,
                'icon'           => 'Flame',
                'required_count' => 7,
            ],
            [
                'slug'           => 'streak_30',
                'title'          => 'Konsistensi Bulanan',
                'description'    => 'Pertahankan streak pencatatan selama 30 hari tanpa terputus.',
                'category'       => 'streak',
                'tier'           => 'gold',
                'xp_reward'      => 400,
                'icon'           => 'Flame',
                'required_count' => 30,
            ],
            [
                'slug'           => 'streak_100',
                'title'          => 'Grandmaster Habit',
                'description'    => 'Capai 100 hari streak pencatatan legendaris.',
                'category'       => 'streak',
                'tier'           => 'platinum',
                'xp_reward'      => 1000,
                'icon'           => 'Flame',
                'required_count' => 100,
            ],
            [
                'slug'           => 'first_goal',
                'title'          => 'Perancang Impian',
                'description'    => 'Buat target tabungan pertama Anda.',
                'category'       => 'saving',
                'tier'           => 'bronze',
                'xp_reward'      => 50,
                'icon'           => 'Target',
                'required_count' => 1,
            ],
            [
                'slug'           => 'first_deposit',
                'title'          => 'Langkah Awal Menabung',
                'description'    => 'Lakukan deposit pertama ke target tabungan Anda.',
                'category'       => 'saving',
                'tier'           => 'bronze',
                'xp_reward'      => 50,
                'icon'           => 'Target',
                'required_count' => 1,
            ],
            [
                'slug'           => 'goal_completed_1',
                'title'          => 'Penakluk Target',
                'description'    => 'Capai 100% dari salah satu target tabungan Anda.',
                'category'       => 'saving',
                'tier'           => 'silver',
                'xp_reward'      => 200,
                'icon'           => 'Target',
                'required_count' => 1,
            ],
            [
                'slug'           => 'goal_completed_3',
                'title'          => 'Wirausahawan Impian',
                'description'    => 'Berhasil menyelesaikan 3 target tabungan impian.',
                'category'       => 'saving',
                'tier'           => 'gold',
                'xp_reward'      => 500,
                'icon'           => 'Target',
                'required_count' => 3,
            ],
            [
                'slug'           => 'security_2fa',
                'title'          => 'Benteng Keamanan',
                'description'    => 'Aktifkan Two-Factor Authentication (2FA) untuk perlindungan akun.',
                'category'       => 'security',
                'tier'           => 'silver',
                'xp_reward'      => 100,
                'icon'           => 'ShieldCheck',
                'required_count' => 1,
            ],
            [
                'slug'           => 'budget_created',
                'title'          => 'Pengendali Anggaran',
                'description'    => 'Buat minimal 3 batas anggaran kategori.',
                'category'       => 'budget',
                'tier'           => 'bronze',
                'xp_reward'      => 100,
                'icon'           => 'Award',
                'required_count' => 3,
            ],
            [
                'slug'           => 'recurring_setup',
                'title'          => 'Master Otomatisasi',
                'description'    => 'Buat setidaknya 1 jadwal transaksi rutin otomatis.',
                'category'       => 'general',
                'tier'           => 'silver',
                'xp_reward'      => 100,
                'icon'           => 'Award',
                'required_count' => 1,
            ],
        ];

        foreach ($achievements as $ach) {
            Achievement::updateOrCreate(['slug' => $ach['slug']], $ach);
        }

        $quests = [
            [
                'slug'         => 'record_daily_tx',
                'title'        => 'Catat Transaksi Hari Ini',
                'description'  => 'Catat minimal 1 pengeluaran atau pemasukan hari ini.',
                'type'         => 'daily',
                'target_type'  => 'record_transactions',
                'target_count' => 1,
                'xp_reward'    => 25,
                'is_active'    => true,
            ],
            [
                'slug'         => 'weekly_saver',
                'title'        => 'Tantangan Menabung Mingguan',
                'description'  => 'Lakukan minimal 1x deposit dana ke Target Tabungan minggu ini.',
                'type'         => 'weekly',
                'target_type'  => 'deposit_goal',
                'target_count' => 1,
                'xp_reward'    => 60,
                'is_active'    => true,
            ],
            [
                'slug'         => 'weekly_tracking_3',
                'title'        => 'Pencatat Konsisten',
                'description'  => 'Catat transaksi minimal 3 kali dalam minggu ini.',
                'type'         => 'weekly',
                'target_type'  => 'record_transactions',
                'target_count' => 3,
                'xp_reward'    => 80,
                'is_active'    => true,
            ],
            [
                'slug'         => 'weekly_budget_check',
                'title'        => 'Evaluasi Finansial Mingguan',
                'description'  => 'Kunjungi menu Laporan Finansial untuk memantau performa arus kas.',
                'type'         => 'weekly',
                'target_type'  => 'check_analytics',
                'target_count' => 1,
                'xp_reward'    => 50,
                'is_active'    => true,
            ],
        ];

        foreach ($quests as $quest) {
            FinancialQuest::updateOrCreate(['slug' => $quest['slug']], $quest);
        }
    }
}
