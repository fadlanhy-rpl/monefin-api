<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_new_user_registration_creates_account_and_dispatches_otp(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'     => 'Charlie Brown',
            'email'    => 'charlie@example.com',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email'             => 'charlie@example.com',
            'name'              => 'Charlie Brown',
            'email_verified_at' => null,
        ]);

        $user = User::where('email', 'charlie@example.com')->first();
        $this->assertTrue(Hash::check('SecurePass123!', $user->password));
    }

    public function test_reregistration_of_verified_user_is_rejected_with_422(): void
    {
        User::factory()->create([
            'name'              => 'Verified User',
            'email'             => 'verified@example.com',
            'password'          => Hash::make('OriginalPass123!'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name'     => 'Attacker Name',
            'email'    => 'verified@example.com',
            'password' => 'AttackerPass123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test vuln-0033: Unauthenticated account takeover via re-registration of unverified email.
     * Ensure attacker cannot overwrite victim's password and name.
     */
    public function test_reregistration_of_unverified_email_does_not_overwrite_password_or_name(): void
    {
        // 1. Victim registers with their own credentials and leaves account unverified
        $victimEmail = 'victim@example.com';
        $victimPassword = 'VictimOriginalPass123!';
        $victimName = 'Victim Real Name';

        $regResponse1 = $this->postJson('/api/auth/register', [
            'name'     => $victimName,
            'email'    => $victimEmail,
            'password' => $victimPassword,
        ]);
        $regResponse1->assertStatus(201);

        $victim = User::where('email', $victimEmail)->first();
        $this->assertNotNull($victim);
        $originalHash = $victim->password;

        // 2. Attacker attempts to hijack the unverified account by re-registering
        $attackerPassword = 'AttackerHijackPass123!';
        $attackerName = 'Attacker Impersonator';

        $regResponse2 = $this->postJson('/api/auth/register', [
            'name'     => $attackerName,
            'email'    => $victimEmail,
            'password' => $attackerPassword,
        ]);
        $regResponse2->assertStatus(201);

        // 3. Verify that database was NOT altered
        $victim->refresh();
        $this->assertSame($victimName, $victim->name, 'Victim name must not be overwritten');
        $this->assertSame($originalHash, $victim->password, 'Victim password hash must not be overwritten');
        $this->assertTrue(Hash::check($victimPassword, $victim->password), 'Original victim password must still match');
        $this->assertFalse(Hash::check($attackerPassword, $victim->password), 'Attacker password must not match');

        // 4. Victim completes verification out-of-band
        $victim->email_verified_at = now();
        $victim->save();

        // 5. Attacker tries to login with attacker password -> MUST FAIL (401)
        $loginAttacker = $this->postJson('/api/auth/login', [
            'email'    => $victimEmail,
            'password' => $attackerPassword,
        ]);
        $loginAttacker->assertStatus(401);

        // 6. Victim logs in with original password -> MUST SUCCEED (200)
        $loginVictim = $this->postJson('/api/auth/login', [
            'email'    => $victimEmail,
            'password' => $victimPassword,
        ]);
        $loginVictim->assertStatus(200)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }
}
