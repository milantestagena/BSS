<?php

namespace App\Services;

use App\Models\TaxonomyNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Translates a session's free-text smestaj_preference into real Booking filter slugs the
 * wizard already knows how to route (see SearchSessionQueryCompiler::applyAmenityYesFilters).
 * Owner's ask, 2026-08-24: Booking's own "Smart filters" AI search box does this same kind of
 * translation, but a live capture showed it throws the raw text away — only its OWN structured
 * filter output survives into the URL (confirmed via a real before/after diff, see FreeTextAmenityResolver
 * docblock), nothing we can hook into from outside. Doing the same translation ourselves, into
 * filters we already own end-to-end, sidesteps that entirely — no dependency on Booking's box.
 */
class FreeTextAmenityMatcher
{
    public function __construct(private OpenAiClient $client)
    {
    }

    /**
     * @param  Collection<int, TaxonomyNode>  $catalog
     * @return string[] slugs, a strict subset of $catalog's slugs — never invented
     */
    public function match(string $freeText, Collection $catalog): array
    {
        if (trim($freeText) === '' || $catalog->isEmpty()) {
            return [];
        }

        $catalogForPrompt = $catalog->map(fn (TaxonomyNode $n) => ['slug' => $n->slug, 'label' => $n->label])->values()->all();

        $raw = $this->client->chat([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => json_encode(['text' => $freeText, 'catalog' => $catalogForPrompt])],
        ], 0.0);

        return $this->parse($raw, $catalog);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You match a traveler's free-text accommodation wishes against a fixed catalog of real,
        known amenity/facility tags. You are given "text" (what the traveler wrote) and
        "catalog" (an array of {slug, label} pairs — the ONLY valid tags).

        Rules:
        - Return ONLY slugs that are explicitly present in the given catalog. Never invent a
          slug that isn't in the catalog, even if the text implies something not covered.
        - Only include a slug if the text genuinely implies it — don't pad the list with
          plausible-sounding guesses.
        - If nothing in the text matches anything in the catalog, return an empty array.
        - Respond with ONLY valid JSON, no other text, in exactly this shape: {"slugs": ["...", "..."]}
        PROMPT;
    }

    /** Falls back to an empty list on unparseable output, and defensively re-filters against
     *  the real catalog regardless — never trust the model to have actually obeyed "only from
     *  the catalog", same "verify, don't just prompt" convention as HonestReportGenerator. */
    private function parse(string $raw, Collection $catalog): array
    {
        $cleaned = trim($raw);
        if (Str::startsWith($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $cleaned);
        }

        $decoded = json_decode($cleaned, true);
        $slugs = collect($decoded['slugs'] ?? []);
        $validSlugs = $catalog->pluck('slug');

        return $slugs->filter(fn ($slug) => $validSlugs->contains($slug))->values()->all();
    }
}
