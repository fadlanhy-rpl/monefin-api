<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModularAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_forgot_and_reset_password_flow(): void
    {
        $user = User::factory()->create([
            'email'    => 'user@example.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        // 1. Forgot Password
        $resForgot = $this->postJson('/api/auth/forgot-password', [
            'email' => 'user@example.com',
        ]);
        $resForgot->assertStatus(200);

        // Find created OTP
        $otp = OtpCode::where('email', 'user@example.com')->where('type', 'reset')->first();
        $this->assertNotNull($otp);

        // 2. Reset Password using direct verify helper simulation
        // Since OtpCode::verify checks hash, let's generate a known OTP
        $knownOtp = OtpCode::generateFor('user@example.com', 'reset', 10);

        $resReset = $this->postJson('/api/auth/reset-password', [
            'email'                 => 'user@example.com',
            'otp'                   => $knownOtp,
            'password'              => 'NewSecurePass123!',
            'password_confirmation' => 'NewSecurePass123!',
        ]);
        $resReset->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecurePass123!', $user->password));
    }

    public function test_profile_update_and_password_update(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPassword123!'),
        ]);

        Sanctum::actingAs($user);

        // Update Profile
        $resProfile = $this->postJson('/api/auth/profile', [
            'name'  => 'Updated Name',
            'phone' => '081234567890',
        ]);
        $resProfile->assertStatus(200);
        $this->assertSame('Updated Name', $user->fresh()->name);

        // Update Password
        $resPass = $this->postJson('/api/auth/password', [
            'current_password'      => 'CurrentPassword123!',
            'new_password'          => 'UpdatedPassword123!',
            'new_password_confirmation' => 'UpdatedPassword123!',
        ]);
        $resPass->assertStatus(200);
        $this->assertTrue(Hash::check('UpdatedPassword123!', $user->fresh()->password));
    }

    public function test_session_management_endpoints(): void
    {
        $user = User::factory()->create();

        // Create 2 tokens
        $token1 = $user->createToken('Desktop Chrome');
        $token2 = $user->createToken('Mobile Safari');

        // Authenticate with token 1
        $this->withToken($token1->plainTextToken);

        $resSessions = $this->getJson('/api/auth/sessions');
        $resSessions->assertStatus(200)
            ->assertJsonStructure(['data' => ['sessions']]);

        // Revoke token 2
        $resRevoke = $this->deleteJson('/api/auth/sessions/' . $token2->accessToken->id);
        $resRevoke->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token2->accessToken->id,
        ]);
    }

    public function test_toggle_two_factor_auth(): void
    {
        $user = User::factory()->create([
            'two_factor_enabled' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/2fa/toggle', [
            'enabled' => true,
        ]);

        $response->assertStatus(200);
        $this->assertTrue((bool) $user->fresh()->two_factor_enabled);
    }
}
