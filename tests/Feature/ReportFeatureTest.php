<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
    private Category $categoryIncome;
    private Category $categoryExpense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        
        $this->account = Account::create([
            'user_id'        => $this->user->id,
            'name'           => 'Bank Utama',
            'type'           => 'bank',
            'balance'        => 10000000,
            'account_number' => '12345678',
        ]);

        $this->categoryIncome = Category::create([
            'user_id' => $this->user->id,
            'type'    => 'income',
            'name'    => 'Gaji',
        ]);

        $this->categoryExpense = Category::create([
            'user_id' => $this->user->id,
            'type'    => 'expense',
            'name'    => 'Makanan',
        ]);

        // Seed some transactions
        Transaction::create([
            'user_id'          => $this->user->id,
            'account_id'       => $this->account->id,
            'category_id'      => $this->categoryIncome->id,
            'type'             => 'income',
            'amount'           => 10000000,
            'transaction_date' => now()->startOfMonth()->toDateString(),
            'description'      => 'Gaji Bulanan',
        ]);

        Transaction::create([
            'user_id'          => $this->user->id,
            'account_id'       => $this->account->id,
            'category_id'      => $this->categoryExpense->id,
            'type'             => 'expense',
            'amount'           => 3000000,
            'transaction_date' => now()->toDateString(),
            'description'      => 'Makan Siang',
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_compare_endpoint_returns_monthly_data_and_summary(): void
    {
        $response = $this->getJson('/api/reports/compare?months=3');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['month', 'income', 'expense', 'savings', 'cashflow']
                ],
                'summary' => [
                    'total_income',
                    'total_expense',
                    'net_savings',
                    'saving_rate',
                    'period_months',
                ]
            ]);

        $summary = $response->json('summary');
        $this->assertEquals(10000000, $summary['total_income']);
        $this->assertEquals(3000000, $summary['total_expense']);
        $this->assertEquals(7000000, $summary['net_savings']);
        $this->assertEquals(70.0, $summary['saving_rate']);
    }

    public function test_category_breakdown_endpoint(): void
    {
        $response = $this->getJson('/api/reports/category-breakdown?type=expense');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['category_id', 'category_name', 'total', 'percentage']
                ],
                'grand_total',
                'type',
            ]);

        $this->assertEquals(3000000, $response->json('grand_total'));
        $this->assertEquals('expense', $response->json('type'));
    }

    public function test_export_endpoint_returns_valid_excel_spreadsheet(): void
    {
        $response = $this->get('/api/reports/export');

        $response->assertStatus(200);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );

        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment;', $contentDisposition);
        $this->assertStringContainsString('MoneFin_LaporanKeuangan_', $contentDisposition);
        $this->assertStringContainsString('.xlsx', $contentDisposition);

        // Verify response content is a non-empty binary zip/xlsx file (starts with PK\x03\x04)
        $content = $response->getContent();
        $this->assertNotEmpty($content);
        $this->assertStringStartsWith("PK", $content);
    }
}
