<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'preferences' => [
                'language' => 'id',
            ],
        ]);
    }

    public function test_can_get_financial_health_insights(): void
    {
        Sanctum::actingAs($this->user);

        $account = Account::create([
            'user_id' => $this->user->id,
            'name' => 'BCA',
            'type' => 'bank',
            'balance' => 10000000,
        ]);

        $catIncome = Category::create([
            'user_id' => $this->user->id,
            'name' => 'Gaji',
            'type' => 'income',
        ]);

        $catExpense = Category::create([
            'user_id' => $this->user->id,
            'name' => 'Makan',
            'type' => 'expense',
        ]);

        // Monthly income: 5,000,000
        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $catIncome->id,
            'type' => 'income',
            'amount' => 5000000,
            'transaction_date' => now()->toDateString(),
        ]);

        // Monthly expense: 2,000,000
        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $catExpense->id,
            'type' => 'expense',
            'amount' => 2000000,
            'transaction_date' => now()->toDateString(),
        ]);

        // Budget: limit 3,000,000, spent 2,000,000 -> 66%
        Budget::create([
            'user_id' => $this->user->id,
            'category_id' => $catExpense->id,
            'limit_amount' => 3000000,
            'month' => now()->month,
            'year' => now()->year,
        ]);

        // Active goal: target 10,000,000, current 4,000,000
        Goal::create([
            'user_id' => $this->user->id,
            'name' => 'Dana Darurat',
            'target_amount' => 10000000,
            'current_amount' => 4000000,
        ]);

        $response = $this->getJson('/api/ai/insights');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'health_score',
                'score_label',
                'weekly_summary',
                'tips',
                'positive_note',
                'source',
            ],
        ]);

        $this->assertEquals('engine', $response->json('data.source'));
        $this->assertIsInt($response->json('data.health_score'));
        $this->assertGreaterThanOrEqual(0, $response->json('data.health_score'));
        $this->assertLessThanOrEqual(100, $response->json('data.health_score'));
        $this->assertIsArray($response->json('data.tips'));
        $this->assertNotEmpty($response->json('data.tips'));
    }

    public function test_can_generate_english_insights(): void
    {
        $this->user->update([
            'preferences' => [
                'language' => 'en',
            ],
        ]);

        Sanctum::actingAs($this->user);

        $service = app(FinancialHealthService::class);
        $insights = $service->insights($this->user, 'en');

        $this->assertArrayHasKey('health_score', $insights);
        $this->assertArrayHasKey('score_label', $insights);
        $this->assertArrayHasKey('weekly_summary', $insights);
        $this->assertStringContainsString('financial', strtolower($insights['weekly_summary']));
    }
}
