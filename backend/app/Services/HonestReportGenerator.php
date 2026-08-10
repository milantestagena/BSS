<?php

namespace App\Services;

use App\Models\SearchSession;
use Illuminate\Support\Str;

/**
 * First real AI feature in this project, 2026-08-10 (everything before this was
 * deterministic — see computeHotelHighlight() in the frontend, explicitly NOT AI per the
 * owner's own call). Reads the session's already-compiled honestReportSignals (see
 * SearchSessionQueryCompiler) plus one listing's description/reviews, and asks GPT-4o-mini
 * for a short, specific pros/cons breakdown — grounded in the actual listing text, not
 * generic filler.
 *
 * Mock-data phase (owner's call, 2026-08-10, same "demo first" precedent as the whole wizard
 * — see wizard_architecture, 2026-07-13 "Novi redosled"): the listing name/description/
 * reviews passed in today come from MOCK_HOTELS on the frontend, not a real Booking.com
 * listing — this class doesn't know or care, it just takes whatever text it's given. Once
 * real Booking data exists, the caller swaps the source; nothing here changes.
 */
class HonestReportGenerator
{
    public function __construct(private OpenAiClient $client)
    {
    }

    /**
     * @param  array{name: string, description: string, reviews: string[]}  $listing
     * @return array{pros: string[], cons: string[], summary: string}
     */
    public function generate(SearchSession $session, array $listing): array
    {
        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $raw = $this->client->chat([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($signals, $listing)],
        ]);

        return $this->parse($raw);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are an honest, discerning travel assistant writing a short "Honest Report" for one
        specific property, for one specific traveler. You are given the traveler's stated
        preferences and a property's description plus real guest reviews.

        Rules:
        - Be concrete and specific. Reference actual details from the description/reviews, not
          generic travel-blog filler ("a wonderful stay for everyone").
        - Connect specifics to what THIS traveler said they care about — don't just summarize
          the listing, explain why each point matters (or doesn't) for them.
        - If the reviews contradict or complicate the marketing description, say so honestly —
          that's the whole point of an "honest" report, not a sales pitch.
        - Never invent a detail that isn't in the description or reviews given to you.
        - 2-4 pros, 1-3 cons (a genuinely great match can have fewer cons, but don't invent a
          weak one just to pad the list — omit it instead).
        - Respond with ONLY valid JSON, no other text, in exactly this shape:
          {"pros": ["...", "..."], "cons": ["...", "..."], "summary": "one sentence overall verdict"}
        PROMPT;
    }

    private function userPrompt(array $signals, array $listing): string
    {
        $lines = ["Traveler's stated preferences and context (JSON):", json_encode($signals, JSON_PRETTY_PRINT)];

        $lines[] = "\nProperty: {$listing['name']}";
        $lines[] = "Description: {$listing['description']}";

        if (! empty($listing['reviews'])) {
            $lines[] = "Guest reviews:";
            foreach ($listing['reviews'] as $review) {
                $lines[] = "- {$review}";
            }
        }

        return implode("\n", $lines);
    }

    /** Falls back to an empty-but-valid shape if the model doesn't return parseable JSON —
     *  same "missing data, not a crash" convention as the rest of this codebase. Strips a
     *  ```json fenced block if the model wraps its answer in one despite the system prompt. */
    private function parse(string $raw): array
    {
        $cleaned = trim($raw);
        if (Str::startsWith($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $cleaned);
        }

        $decoded = json_decode($cleaned, true);

        return [
            'pros' => $decoded['pros'] ?? [],
            'cons' => $decoded['cons'] ?? [],
            'summary' => $decoded['summary'] ?? '',
        ];
    }
}
