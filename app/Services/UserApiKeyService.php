<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Service to securely manage Bring-Your-Own-Key (BYOK) encryption and decryption
 * with a high-throughput memory cache layer to eliminate redundant DB reads and AES crypto overhead.
 */
class UserApiKeyService
{
    public const CACHE_TTL_SECONDS = 900; // 15 Minutes

    /**
     * Store and encrypt a user API key.
     */
    public function storeKey(User $user, string $provider, string $rawKey, array $extraConfig = []): void
    {
        $encrypted = Crypt::encryptString($rawKey);

        $prefs = $user->preferences ?? [];
        $aiConfig = $prefs['ai_config'] ?? [];

        $aiConfig['provider'] = $provider;
        $aiConfig['api_key'] = $encrypted;
        foreach ($extraConfig as $k => $v) {
            $aiConfig[$k] = $v;
        }

        $prefs['ai_config'] = $aiConfig;
        $prefs['ai_enabled'] = true;
        $user->preferences = $prefs;
        $user->save();

        // Bust cache
        Cache::forget("user:{$user->id}:ai_key:{$provider}");
        Cache::forget("user:{$user->id}:ai_key:active");
    }

    /**
     * Retrieve decrypted API key with Cache layer (15 min TTL).
     */
    public function getDecryptedKey(User $user, ?string $provider = null): ?string
    {
        $prefs = $user->preferences ?? [];
        if (!($prefs['ai_enabled'] ?? false)) {
            return null;
        }

        $aiConfig = $prefs['ai_config'] ?? [];
        $targetProvider = $provider ?: ($aiConfig['provider'] ?? 'openai');
        $encryptedKey = $aiConfig['api_key'] ?? null;

        if (!$encryptedKey) {
            return null;
        }

        $cacheKey = "user:{$user->id}:ai_key:{$targetProvider}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($encryptedKey) {
            try {
                return Crypt::decryptString($encryptedKey);
            } catch (\Throwable $e) {
                Log::warning("Failed to decrypt user AI API key: {$e->getMessage()}");
                return null;
            }
        });
    }

    /**
     * Clear cached API key for a user.
     */
    public function clearKeyCache(User $user, ?string $provider = null): void
    {
        if ($provider) {
            Cache::forget("user:{$user->id}:ai_key:{$provider}");
        }
        Cache::forget("user:{$user->id}:ai_key:active");
        Cache::forget("user:{$user->id}:ai_key:openai");
        Cache::forget("user:{$user->id}:ai_key:claude");
        Cache::forget("user:{$user->id}:ai_key:openrouter");
        Cache::forget("user:{$user->id}:ai_key:gemini");
    }
}
