<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Goal;
use App\Models\IncomeSetting;
use App\Models\SpendingThreshold;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Buat User Demo
        $user = User::firstOrCreate(
            ['email' => 'demo@monefin.com'],
            [
                'name' => 'Fadlan Demo',
                'password' => Hash::make('password123'),
            ]
        );

        // 2. Buat Income Setting (Uang saku Rp 3.000.000/bulan)
        IncomeSetting::updateOrCreate(
            ['user_id' => $user->id, 'is_active' => true],
            [
                'amount' => 3000000,
                'period_type' => 'monthly',
                'effective_date' => Carbon::now()->startOfMonth(),
            ]
        );

        // 3. Buat Spending Threshold Default (60% / 85%)
        SpendingThreshold::updateOrCreate(
            ['user_id' => $user->id],
            [
                'hemat_max_percent' => 60.00,
                'boros_min_percent' => 85.00,
            ]
        );

        // 4. Buat Akun Keuangan
        $bca = Account::firstOrCreate([
            'user_id' => $user->id,
            'name' => 'Bank BCA',
        ], [
            'type' => 'bank',
            'balance' => 2500000,
        ]);

        $cash = Account::firstOrCreate([
            'user_id' => $user->id,
            'name' => 'Dompet / Tunai',
        ], [
            'type' => 'cash',
            'balance' => 350000,
        ]);

        $gopay = Account::firstOrCreate([
            'user_id' => $user->id,
            'name' => 'GoPay',
        ], [
            'type' => 'ewallet',
            'balance' => 150000,
        ]);

        // 5. Ambil Kategori Default
        $catUangSaku = Category::where('name', 'Uang Saku')->first();
        $catMakan = Category::where('name', 'Makanan & Minuman')->first();
        $catTransport = Category::where('name', 'Transportasi')->first();
        $catBelanja = Category::where('name', 'Belanja & Kebutuhan')->first();
        $catHiburan = Category::where('name', 'Hiburan & Rekreasi')->first();

        $now = Carbon::now();

        // 6. Buat Transaksi Contoh Bulan Ini
        if ($catUangSaku) {
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $bca->id,
                'category_id' => $catUangSaku->id,
                'type' => 'income',
                'amount' => 3000000,
                'description' => 'Transfer Uang Saku Bulanan',
                'transaction_date' => $now->copy()->startOfMonth()->toDateString(),
            ]);
        }

        if ($catMakan) {
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $cash->id,
                'category_id' => $catMakan->id,
                'type' => 'expense',
                'amount' => 45000,
                'description' => 'Makan Siang Ayam Geprek',
                'transaction_date' => $now->copy()->subDays(2)->toDateString(),
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $gopay->id,
                'category_id' => $catMakan->id,
                'type' => 'expense',
                'amount' => 25000,
                'description' => 'Kopi Kopi Janji Jiwa',
                'transaction_date' => $now->copy()->subDays(1)->toDateString(),
            ]);
        }

        if ($catTransport) {
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $gopay->id,
                'category_id' => $catTransport->id,
                'type' => 'expense',
                'amount' => 20000,
                'description' => 'Gojek ke Kampus/Sekolah',
                'transaction_date' => $now->copy()->subDays(3)->toDateString(),
            ]);
        }

        if ($catBelanja) {
            Transaction::create([
                'user_id' => $user->id,
                'account_id' => $bca->id,
                'category_id' => $catBelanja->id,
                'type' => 'expense',
                'amount' => 150000,
                'description' => 'Beli Buku Catatan & Alat Tulis',
                'transaction_date' => $now->copy()->subDays(5)->toDateString(),
            ]);
        }

        // 7. Buat Budget Contoh
        if ($catMakan) {
            Budget::updateOrCreate([
                'user_id' => $user->id,
                'category_id' => $catMakan->id,
                'month' => $now->month,
                'year' => $now->year,
            ], [
                'limit_amount' => 1000000,
            ]);
        }

        if ($catTransport) {
            Budget::updateOrCreate([
                'user_id' => $user->id,
                'category_id' => $catTransport->id,
                'month' => $now->month,
                'year' => $now->year,
            ], [
                'limit_amount' => 400000,
            ]);
        }

        // 8. Buat Target Tabungan (Goals) Contoh
        Goal::create([
            'user_id' => $user->id,
            'name' => 'Beli Laptop Coding',
            'target_amount' => 8000000,
            'current_amount' => 2500000,
            'deadline' => $now->copy()->addMonths(6)->toDateString(),
        ]);

        Goal::create([
            'user_id' => $user->id,
            'name' => 'Dana Darurat',
            'target_amount' => 3000000,
            'current_amount' => 1000000,
            'deadline' => $now->copy()->addMonths(3)->toDateString(),
        ]);
    }
}
