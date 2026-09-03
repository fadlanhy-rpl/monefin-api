<?php

namespace App\Services\Ai;

interface AiProvider
{
    /**
     * Send a chat completion request.
     *
     * @param  array<array{role: string, content: string}>  $messages
     */
    public function chat(array $messages, float $temperature = 0.7): string;

    /**
     * Stream a chat completion request chunk by chunk.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @param  callable(string $token): void  $onChunk
     */
    public function streamChat(array $messages, callable $onChunk, float $temperature = 0.7): void;

    public function getProviderName(): string;

    public function getModelName(): string;
}
