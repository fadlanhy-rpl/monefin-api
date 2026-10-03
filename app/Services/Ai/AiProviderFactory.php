<?php

namespace App\Services\Ai;

/**
 * Factory that creates the correct AI provider instance
 * based on the user's configured provider name.
 */
class AiProviderFactory
{
    /**
     * Gemini models that are actually live on Google AI (verified Sep 2026).
     * gemini-2.0-flash retired 1 Jun 2026, gemini-1.5-* retired 2025 —
     * never map to those. Single source of truth for model validation.
     */
    public const GEMINI_LIVE_MODELS = [
        'gemini-3.8-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-2.5-flash',
    ];

    /** Retired Gemini model IDs that must be upgraded, never called directly. */
    public const GEMINI_RETIRED_MODELS = [
        'gemini-2.0-flash',
        'gemini-2.0-flash-001',
        'gemini-2.0-flash-lite',
        'gemini-2.0-flash-lite-001',
        'gemini-1.5-flash',
        'gemini-1.5-flash-001',
        'gemini-1.5-flash-002',
        'gemini-1.5-pro',
        'gemini-1.5-pro-001',
        'gemini-1.5-pro-002',
    ];

    /** Safe single-level fallback when a live Gemini model 503s. */
    public const GEMINI_FALLBACK_MODEL = 'gemini-3.5-flash-lite';

    /**
     * Retired model IDs on other providers → live replacement.
     * Groq deprecated mixtral-8x7b-32768 (Mar 2025, official replacement:
     * llama-3.3-70b-versatile). xAI grok-2-* era IDs are superseded by grok-latest.
     */
    public const RETIRED_MODEL_MAP = [
        'groq' => [
            'mixtral-8x7b-32768' => 'llama-3.3-70b-versatile',
            'llama-3.2-11b-vision-preview' => 'meta-llama/llama-4-scout-17b-16e-instruct',
            'moonshotai/kimi-k2-instruct-0905' => 'openai/gpt-oss-120b',
        ],
        'grok' => [
            'grok-2-latest'       => 'grok-latest',
            'grok-2-vision-1212'  => 'grok-latest',
            'grok-beta'           => 'grok-latest',
        ],
    ];

    /**
     * Available providers and their recommended default models.
     * Note: Users are completely free to specify ANY model identifier supported by the provider.
     */
    public const PROVIDERS = [
        'openai' => [
            'label'  => 'OpenAI',
            'models' => ['gpt-5-mini', 'gpt-4o-mini', 'gpt-4o'],
        ],
        'gemini' => [
            'label'  => 'Google Gemini',
            'models' => ['gemini-3.6-flash', 'gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-2.5-flash'],
        ],
        'deepseek' => [
            'label'  => 'DeepSeek',
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'kimi' => [
            'label'  => 'Kimi (Moonshot)',
            'models' => ['kimi-k2.6', 'kimi-k2.7-code', 'kimi-k3'],
        ],
        'claude' => [
            'label'  => 'Anthropic Claude',
            'models' => ['claude-sonnet-5', 'claude-opus-5', 'claude-haiku-4-5'],
        ],
        'grok' => [
            'label'  => 'xAI (Grok)',
            'models' => ['grok-latest', 'grok-4', 'grok-3'],
        ],
        'groq' => [
            'label'  => 'Groq (LPU Cloud)',
            'models' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'openai/gpt-oss-120b'],
        ],
        'custom' => [
            'label'     => 'Custom (OpenAI-Compatible)',
            'models'    => ['inclusionai/ling-3.0-flash-vl:free', 'qwen', 'deepseek-r1', 'llama-3.3-70b-instruct'],
            'is_custom' => true,
        ],
    ];

    /**
     * Create a provider instance from user configuration.
     */
    public static function make(string $provider, string $apiKey, string $model, ?string $baseUrl = null): AiProvider
    {
        if ($provider === 'claude') {
            return new ClaudeProvider($apiKey, $model);
        }

        return new OpenAiCompatibleProvider($provider, $apiKey, $model, $baseUrl);
    }

    /**
     * Check whether a given provider slug is supported.
     */
    public static function isSupported(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    /**
     * Get the default model for a provider.
     */
    public static function defaultModel(string $provider): string
    {
        return self::PROVIDERS[$provider]['models'][0] ?? 'default';
    }

    /**
     * Resolve a user-configured Gemini model to a callable one.
     * - Empty → provider default (first live model, never a retired one).
     * - Retired (2.0 / 1.5-*) → upgraded to GEMINI_FALLBACK_MODEL.
     * - Anything else (incl. user custom / future 3.x) → passed through untouched.
     */
    public static function resolveGeminiModel(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return self::defaultModel('gemini');
        }
        if (in_array($model, self::GEMINI_RETIRED_MODELS, true)) {
            return self::GEMINI_FALLBACK_MODEL;
        }
        return $model;
    }

    /**
     * Resolve retired IDs on non-Gemini providers (Groq/Grok).
     * Unknown & live models pass through untouched (user freedom).
     */
    public static function resolveOtherProviderModel(string $provider, string $model): string
    {
        $map = self::RETIRED_MODEL_MAP[$provider] ?? [];
        return $map[trim($model)] ?? $model;
    }
}