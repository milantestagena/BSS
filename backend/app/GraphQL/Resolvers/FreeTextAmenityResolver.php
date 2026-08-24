<?php

namespace App\GraphQL\Resolvers;

use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Services\FreeTextAmenityMatcher;
use App\Services\SearchSessionQueryCompiler;

/**
 * Owner's ask, 2026-08-24, after finding Booking.com's own "Smart filters" AI search box throws
 * away the raw text it's given (only its structured output survives into the URL — a live
 * before/after capture showed the typed text nowhere in the resulting search URL, just new
 * nflt chips + a `sr_sfu=1` flag). Runs the SAME kind of translation ourselves, straight into
 * filters this codebase already owns end-to-end — see SearchSessionQueryCompiler's
 * applyAmenityYesFilters/AMENITY_TYPES.
 *
 * Deliberately NOT @aiCredits-gated — CLAUDE.md section 3: free-text entries within a session
 * never cost extra credits, and this mutation only ever fires FROM a free-text answer the user
 * already typed for free.
 */
class FreeTextAmenityResolver
{
    /** @return string[] the session's full, updated amenities_yes list after merging */
    public function extract($_, array $args): array
    {
        $session = SearchSession::findOrFail($args['sessionId']);
        $freeText = (string) ($session->free_text_answers['smestaj_preference'] ?? '');

        $current = $session->free_text_answers ?? [];
        $existing = collect($current['amenities_yes'] ?? []);

        if (trim($freeText) === '') {
            return $existing->values()->all();
        }

        $catalog = TaxonomyNode::whereIn('type', SearchSessionQueryCompiler::AMENITY_TYPES)->get(['slug', 'label']);
        $matched = app(FreeTextAmenityMatcher::class)->match($freeText, $catalog);

        if (empty($matched)) {
            return $existing->values()->all();
        }

        $merged = $existing->merge($matched)->unique()->values();
        $current['amenities_yes'] = $merged->all();
        $session->free_text_answers = $current;
        $session->save();

        return $merged->all();
    }
}
