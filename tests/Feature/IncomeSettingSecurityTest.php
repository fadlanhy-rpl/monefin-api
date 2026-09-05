<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\IncomeSetting;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncomeSettingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private Account $aliceAccount;
    private Account $bobAccount;
    private Category $aliceCategory;
    private Category $bobCategory;
    private Category $sharedCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->aliceAccount = Account::create([
            'user_id' => $this->alice->id,
            'name' => 'Alice Checking',
            'type' => 'bank',
            'balance' => 5000000.00,
            'account_number' => '11112222',
        ]);

        $this->bobAccount = Account::create([
            'user_id' => $this->bob->id,
            'name' => 'Bob Checking',
            'type' => 'bank',
            'balance' => 7700000.00,
            'account_number' => '33334444',
        ]);

        $this->aliceCategory = Category::create([
            'user_id' => $this->alice->id,
            'name' => 'SECRET-ALICE-PRIVATE',
            'type' => 'expense',
        ]);

        $this->bobCategory = Category::create([
            'user_id' => $this->bob->id,
            'name' => 'Bob Groceries',
            'type' => 'expense',
        ]);

        $this->sharedCategory = Category::create([
            'user_id' => null,
            'name' => 'General',
            'type' => 'expense',
        ]);
    }

    /**
     * Test 1: Cross-user account reference returns HTTP 422
     */
    public function test_cross_user_account_reference_returns_422(): void
    {
        Sanctum::actingAs($this->bob);

        $response = $this->postJson('/api/income-settings', [
            'type' => 'expense',
            'title' => 'Cross-user probe',
            'amount' => 1000,
            'period_type' => 'monthly',
            'account_id' => $this->aliceAccount->id,
            'category_id' => $this->bobCategory->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    /**
     * Test 2: Cross-user category reference returns HTTP 422
     */
    public function test_cross_user_category_reference_returns_422(): void
    {
        Sanctum::actingAs($this->bob);

        $response = $this->postJson('/api/income-settings', [
            'type' => 'expense',
            'title' => 'Cross-user probe category',
            'amount' => 1000,
            'period_type' => 'monthly',
            'account_id' => $this->bobAccount->id,
            'category_id' => $this->aliceCategory->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /**
     * Test 3: Own account and own category reference succeeds with HTTP 201
     */
    public function test_own_resources_returns_201(): void
    {
        Sanctum::actingAs($this->bob);

        $response = $this->postJson('/api/income-settings', [
            'type' => 'expense',
            'title' => 'Bob Daily Expense',
            'amount' => 50000,
            'period_type' => 'daily',
            'account_id' => $this->bobAccount->id,
            'category_id' => $this->bobCategory->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Bob Daily Expense')
            ->assertJsonPath('data.account_id', $this->bobAccount->id)
            ->assertJsonPath('data.category_id', $this->bobCategory->id);
    }

    /**
     * Test 4: Shared category (user_id = null) succeeds with HTTP 201
     */
    public function test_shared_category_returns_201(): void
    {
        Sanctum::actingAs($this->bob);

        $response = $this->postJson('/api/income-settings', [
            'type' => 'expense',
            'title' => 'Bob Shared Category Expense',
            'amount' => 25000,
            'period_type' => 'monthly',
            'account_id' => $this->bobAccount->id,
            'category_id' => $this->sharedCategory->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.category_id', $this->sharedCategory->id);
    }

    /**
     * Test 5: Account enumeration via PUT on attacker's own row returns HTTP 422
     */
    public function test_account_enumeration_via_put_returns_422(): void
    {
        Sanctum::actingAs($this->bob);

        $setting = IncomeSetting::create([
            'user_id' => $this->bob->id,
            'type' => 'expense',
            'title' => 'Bob Routine',
            'amount' => 10000,
            'period_type' => 'monthly',
            'account_id' => $this->bobAccount->id,
            'category_id' => $this->bobCategory->id,
            'is_active' => true,
            'effective_date' => Carbon::today(),
        ]);

        // Attacker attempts to swap account_id to Alice's account
        $response = $this->putJson("/api/income-settings/{$setting->id}", [
            'account_id' => $this->aliceAccount->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    /**
     * Test 6: Modifying another user's income setting returns HTTP 403
     */
    public function test_modifying_another_users_income_setting_returns_403(): void
    {
        $aliceSetting = IncomeSetting::create([
            'user_id' => $this->alice->id,
            'type' => 'expense',
            'title' => 'Alice Routine',
            'amount' => 10000,
            'period_type' => 'monthly',
            'account_id' => $this->aliceAccount->id,
            'category_id' => $this->aliceCategory->id,
            'is_active' => true,
            'effective_date' => Carbon::today(),
        ]);

        Sanctum::actingAs($this->bob);

        $response = $this->putJson("/api/income-settings/{$aliceSetting->id}", [
            'amount' => 20000,
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test 7: Deleting another user's income setting returns HTTP 403
     */
    public function test_deleting_another_users_income_setting_returns_403(): void
    {
        $aliceSetting = IncomeSetting::create([
            'user_id' => $this->alice->id,
            'type' => 'expense',
            'title' => 'Alice Routine',
            'amount' => 10000,
            'period_type' => 'monthly',
            'account_id' => $this->aliceAccount->id,
            'category_id' => $this->aliceCategory->id,
            'is_active' => true,
            'effective_date' => Carbon::today(),
        ]);

        Sanctum::actingAs($this->bob);

        $response = $this->deleteJson("/api/income-settings/{$aliceSetting->id}");

        $response->assertStatus(403);
    }

    /**
     * Test 8: Response serialization does not leak sensitive account fields
     */
    public function test_sensitive_account_fields_are_not_leaked(): void
    {
        Sanctum::actingAs($this->bob);

        $response = $this->postJson('/api/income-settings', [
            'type' => 'expense',
            'title' => 'Bob Routine Check',
            'amount' => 50000,
            'period_type' => 'daily',
            'account_id' => $this->bobAccount->id,
            'category_id' => $this->bobCategory->id,
        ]);

        $response->assertStatus(201);
        $accountData = $response->json('data.account');

        $this->assertNotNull($accountData);
        $this->assertArrayHasKey('id', $accountData);
        $this->assertArrayHasKey('name', $accountData);
        $this->assertArrayHasKey('type', $accountData);

        // Sensitive fields must NOT be present
        $this->assertArrayNotHasKey('balance', $accountData);
        $this->assertArrayNotHasKey('account_number', $accountData);
        $this->assertArrayNotHasKey('account_holder', $accountData);
    }

    /**
     * Test 9: Recurring worker executes legitimate settings correctly
     */
    public function test_recurring_worker_processes_legitimate_setting(): void
    {
        IncomeSetting::create([
            'user_id' => $this->bob->id,
            'type' => 'expense',
            'title' => 'Bob Daily Lunch',
            'amount' => 50000,
            'period_type' => 'daily',
            'account_id' => $this->bobAccount->id,
            'category_id' => $this->bobCategory->id,
            'is_active' => true,
            'effective_date' => Carbon::today(),
        ]);

        $bobBalanceBefore = (float) $this->bobAccount->fresh()->balance;

        $this->artisan('transactions:process-recurring')
            ->assertExitCode(0);

        $bobBalanceAfter = (float) $this->bobAccount->fresh()->balance;
        $this->assertEquals($bobBalanceBefore - 50000, $bobBalanceAfter);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->bob->id,
            'account_id' => $this->bobAccount->id,
            'amount' => 50000,
        ]);
    }

    /**
     * Test 10: Recurring worker defense-in-depth rejects foreign account
     */
    public function test_recurring_worker_rejects_foreign_account_and_protects_balance(): void
    {
        // Malicious or corrupted record directly planted in DB
        $corruptedSetting = IncomeSetting::create([
            'user_id' => $this->bob->id,
            'type' => 'expense',
            'title' => 'Exploit Recurring',
            'amount' => 500000,
            'period_type' => 'daily',
            'account_id' => $this->aliceAccount->id, // ALICE'S ACCOUNT!
            'category_id' => $this->bobCategory->id,
            'is_active' => true,
            'effective_date' => Carbon::today(),
        ]);

        $aliceBalanceBefore = (float) $this->aliceAccount->fresh()->balance;
        $bobBalanceBefore = (float) $this->bobAccount->fresh()->balance;

        $this->artisan('transactions:process-recurring')
            ->assertExitCode(0);

        // Alice's balance MUST NOT change
        $aliceBalanceAfter = (float) $this->aliceAccount->fresh()->balance;
        $this->assertEquals($aliceBalanceBefore, $aliceBalanceAfter);

        // Bob's balance MUST NOT change
        $bobBalanceAfter = (float) $this->bobAccount->fresh()->balance;
        $this->assertEquals($bobBalanceBefore, $bobBalanceAfter);

        // No transaction must be created for this corrupted setting
        $this->assertDatabaseMissing('transactions', [
            'description' => 'Exploit Recurring',
        ]);
    }

    /**
     * Test 11: Recurring worker defense-in-depth rejects foreign category
     */
    public function test_recurring_worker_rejects_foreign_category_and_does_not_create_transaction(): void
    {
        IncomeSetting::create([
            'user_id' => $this->bob->id,
            'type' => 'expense',
            'title' => 'Exploit Foreign Category',
            'amount' => 50000,
            'period_type' => 'daily',
            'account_id' => $this->bobAccount->id,
            'category_id' => $this->aliceCategory->id, // ALICE'S PRIVATE CATEGORY!
            'is_active' => true,
            'effective_date' => Carbon::today(),
        ]);

        $this->artisan('transactions:process-recurring')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('transactions', [
            'description' => 'Exploit Foreign Category',
        ]);
    }
}
