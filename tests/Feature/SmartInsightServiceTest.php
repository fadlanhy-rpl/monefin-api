<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SmartInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmartInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'preferences' => [
                'ai_enabled' => false,
                'language' => 'id',
            ],
        ]);
    }

    public function test_can_get_dashboard_insight(): void
    {
        Sanctum::actingAs($this->user);

        $account = Account::create([
            'user_id' => $this->user->id,
            'name' => 'Main Wallet',
            'type' => 'bank',
            'balance' => 5000000,
        ]);

        $category = Category::create([
            'user_id' => $this->user->id,
            'name' => 'Makan',
            'type' => 'expense',
        ]);

        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 50000,
            'transaction_date' => now()->toDateString(),
        ]);

        $response = $this->getJson('/api/smart-insights/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'title',
                    'body',
                    'action_label',
                    'action_url',
                    'type',
                    'source',
                    'source_label',
                ]
            ]);

        $this->assertEquals('engine', $response->json('data.source'));
    }

    public function test_can_get_categories_budgets_accounts_and_goals_insights(): void
    {
        Sanctum::actingAs($this->user);

        foreach (['categories', 'budgets', 'accounts', 'goals'] as $page) {
            $response = $this->getJson("/api/smart-insights/{$page}");
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'title',
                        'body',
                        'action_label',
                        'action_url',
                        'type',
                        'source',
                        'source_label',
                    ]
                ]);
        }
    }

    public function test_invalid_page_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/smart-insights/invalid-page');
        $response->assertStatus(422);
    }

    public function test_can_invalidate_user_cache(): void
    {
        SmartInsightService::invalidateUserCache($this->user);
        $this->assertTrue(true);
    }
}
