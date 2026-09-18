<?php

namespace App\Services\Ai;

/**
 * Factory that creates the correct AI provider instance
 * based on the user's configured provider name.
 */
class AiProviderFactory
{
    /**
     * Available providers and their recommended default models.
     * Note: Users are completely free to specify ANY model identifier supported by the provider.
     */
    public const PROVIDERS = [
        'openai' => [
            'label'  => 'OpenAI',
            'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo'],
        ],
        'gemini' => [
            'label'  => 'Google Gemini',
            'models' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash'],
        ],
        'deepseek' => [
            'label'  => 'DeepSeek',
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'kimi' => [
            'label'  => 'Kimi (Moonshot)',
            'models' => ['kimi-k2.6', 'kimi-k2.7-code'],
        ],
        'claude' => [
            'label'  => 'Anthropic Claude',
            'models' => ['claude-sonnet-4-5', 'claude-opus-4-5', 'claude-haiku-4-5'],
        ],
        'grok' => [
            'label'  => 'xAI (Grok)',
            'models' => ['grok-2-latest', 'grok-2-vision-1212', 'grok-beta'],
        ],
        'groq' => [
            'label'  => 'Groq (LPU Cloud)',
            'models' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768'],
        ],
        'custom' => [
            'label'     => 'Custom (OpenAI-Compatible)',
            'models'    => ['qwen', 'qwen-2.5-72b-instruct', 'qwen-2.5-32b-instruct', 'deepseek-r1', 'llama-3.3-70b-instruct'],
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
}