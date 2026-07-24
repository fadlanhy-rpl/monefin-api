<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultCategories = [
            // Income
            ['user_id' => null, 'name' => 'Gaji', 'type' => 'income', 'icon' => 'banknote'],
            ['user_id' => null, 'name' => 'Uang Saku', 'type' => 'income', 'icon' => 'wallet'],
            ['user_id' => null, 'name' => 'Bonus / Hadiah', 'type' => 'income', 'icon' => 'gift'],
            ['user_id' => null, 'name' => 'Investasi', 'type' => 'income', 'icon' => 'trending-up'],
            ['user_id' => null, 'name' => 'Pendapatan Lain-lain', 'type' => 'income', 'icon' => 'coins'],

            // Expense
            ['user_id' => null, 'name' => 'Makanan & Minuman', 'type' => 'expense', 'icon' => 'utensils'],
            ['user_id' => null, 'name' => 'Transportasi', 'type' => 'expense', 'icon' => 'car'],
            ['user_id' => null, 'name' => 'Belanja & Kebutuhan', 'type' => 'expense', 'icon' => 'shopping-bag'],
            ['user_id' => null, 'name' => 'Tagihan & Utilitas', 'type' => 'expense', 'icon' => 'file-text'],
            ['user_id' => null, 'name' => 'Hiburan & Rekreasi', 'type' => 'expense', 'icon' => 'gamepad-2'],
            ['user_id' => null, 'name' => 'Kesehatan', 'type' => 'expense', 'icon' => 'heart-pulse'],
            ['user_id' => null, 'name' => 'Pendidikan', 'type' => 'expense', 'icon' => 'graduation-cap'],
            ['user_id' => null, 'name' => 'Pengeluaran Lain-lain', 'type' => 'expense', 'icon' => 'more-horizontal'],
        ];

        foreach ($defaultCategories as $category) {
            Category::firstOrCreate(
                ['user_id' => null, 'name' => $category['name'], 'type' => $category['type']],
                $category
            );
        }
    }
}
