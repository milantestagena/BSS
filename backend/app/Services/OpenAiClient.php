<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over OpenAI's Chat Completions API (GPT-4o-mini) — see CLAUDE.md, "AI:
 * GPT-4o-mini (troškovi validirani kao zanemarljivi)". First real AI integration in this
 * project, 2026-08-10 — everything before this was deterministic (see
 * wizard_architecture memory, computeHotelHighlight()).
 */
class OpenAiClient
{
    private const MODEL = 'gpt-4o-mini';

    /**
     * Sends a chat completion request and returns the assistant's reply text. Throws on any
     * non-2xx response or network failure — callers decide how to degrade (see
     * HonestReportGenerator, which lets a failure surface rather than silently faking content).
     */
    public function chat(array $messages, float $temperature = 0.7): string
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => self::MODEL,
                'messages' => $messages,
                'temperature' => $temperature,
            ])
            ->throw()
            ->json();

        return $response['choices'][0]['message']['content'] ?? '';
    }
}
