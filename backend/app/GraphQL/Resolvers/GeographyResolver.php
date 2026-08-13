<?php

namespace App\GraphQL\Resolvers;

use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Services\BudgetEstimationEngine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GeographyResolver
{
    /**
     * Suggest taxonomy nodes of any type (region_theme / country / city / termin_category /
     * preference_tag / ...) for the given session. Driven entirely by the taxonomy_node_relations
     * table (implies/suggests/excludes), not by `meta` tags or per-type hardcoding — see
     * wizard_architecture for why (admin-editable, no hidden rules in code).
     *
     * - excludes: a node excluded by ANY currently-selected node is removed outright.
     * - implies: a node implied by ANY currently-selected node stays in the list (2026-08-04,
     *   owner's call — previously hidden entirely, but that meant it silently vanished with no
     *   explanation) — marked `implied: true` instead, so the frontend renders it
     *   selected-and-locked (ChoiceComponent's `disabled` state) rather than a free toggle.
     * - suggests: does not filter the list here; it only affects which value a *different*,
     *   dependent question pre-fills (handled when that question is written, not when this
     *   one is read).
     *
     * For `type=country` specifically, two more narrowing passes run (2026-07-30, see
     * wizard_architecture "GeographyResolver" section): cultural-availability requirements
     * (hard exclude, no fallback — see filterByCulturalAvailability) and budget fit (hard
     * exclude with a 2-closest fallback — see BudgetEstimationEngine::narrowCandidates). Both
     * are no-ops when the session doesn't have the inputs they need yet (no total_budget
     * answered, no cultural preference_tags selected) — this must never break the plain
     * excludes/implies/tag-overlap behavior other question types still rely on.
     */
    public function suggested($_, array $args): Collection
    {
        $session = SearchSession::findOrFail($args['sessionId']);
        $selectedIds = $session->selectedTaxonomyNodeIds();

        $selectedNodes = TaxonomyNode::whereIn('id', $selectedIds)
            ->with(['excludes', 'implies'])
            ->get();

        $excludedIds = $selectedNodes->pluck('excludes')->flatten()->pluck('id');
        $impliedIds = $selectedNodes->pluck('implies')->flatten()->pluck('id')->unique();

        $query = TaxonomyNode::query()
            ->where('type', $args['type'])
            ->orderBy('sort_order')
            ->whereNotIn('id', $excludedIds);

        // Owner's ask, 2026-08-12: Country/region became multi-select, so the City step needs to
        // gather candidates from ANY of the selected countries, not just one — parentIds (plural)
        // for that, parentId (singular) kept for other single-parent callers. Neither set means
        // "every country selected" (skipping the Country step entirely still shows every city) —
        // same "never over-narrow" philosophy as the budget/cultural-availability fallbacks.
        if (! empty($args['parentIds'])) {
            $query->whereIn('parent_id', $args['parentIds']);
        } elseif (! empty($args['parentId'])) {
            $query->where('parent_id', $args['parentId']);
        }

        $nodes = $query->get();
        $budgetCaveatIds = collect();

        if ($args['type'] === 'country') {
            $nodes = $this->filterByCulturalAvailability($nodes, $session);
            [$nodes, $budgetCaveatIds] = $this->filterByBudget($nodes, $session);
        }

        // .unique() added 2026-08-13: a tag can now legitimately appear in BOTH arrays (explicit
        // AND implied — see WizardService.syncAnswersFromSession backfilling a `suggests` tag
        // into the user's own explicit answer so it shows pre-checked-but-editable) — without
        // dedup, intersect()'s count would double it, inflating match_score for that one tag.
        $preferenceTags = collect($session->free_text_answers['preference_tags'] ?? [])
            ->merge($session->free_text_answers['implied_preference_tags'] ?? [])
            ->unique();

        $isGeoType = in_array($args['type'], ['country', 'city'], true);

        $priceTotals = [];
        if ($isGeoType) {
            foreach ($nodes as $node) {
                $priceTotals[$node->id] = $this->accommodationTotalFor($node, $args['type'], $session);
            }
        }

        $mapped = $nodes->map(function (TaxonomyNode $node) use ($preferenceTags, $budgetCaveatIds, $impliedIds) {
            $meta = $node->meta ?? [];

            $nodeTags = collect($meta['drinks'] ?? [])
                ->merge($meta['atmosphere'] ?? [])
                ->merge($meta['food'] ?? [])
                ->merge($meta['budget'] ?? []);

            $matchedTags = $preferenceTags->intersect($nodeTags)->values();

            $node->setAttribute('implied', $impliedIds->contains($node->id));
            $node->setAttribute('matched_tags', $matchedTags->all());
            $node->setAttribute('match_score', $matchedTags->count() * 5);
            $node->setAttribute('budget_caveat', $budgetCaveatIds->contains($node->id));

            return $node;
        });

        // Owner's call, 2026-08-11: a country/city with zero matched preference tags shouldn't
        // be shown at all once the traveler has stated what matters to them — the goal is
        // "we found YOUR perfect spot," not an exhaustive/transparent list of near-misses.
        // Guarded so this never produces a blank screen: skipped entirely when no preference
        // tags are selected yet, and reverted to the unfiltered list if literally nothing
        // matched (e.g. this region's atmosphere/drinks/food tags aren't seeded yet) — same
        // "never show zero results" philosophy as BudgetEstimationEngine::narrowCandidates.
        // `implied` nodes are always kept regardless of match_score — they're forced on by an
        // already-selected answer, not a preference-tag match, so hiding one would silently
        // contradict the 2026-08-04 "implied stays visible, shown locked" decision.
        if ($isGeoType && $preferenceTags->isNotEmpty()) {
            $narrowed = $mapped->filter(fn (TaxonomyNode $node) => $node->implied || $node->match_score > 0);
            if ($narrowed->isNotEmpty()) {
                $mapped = $narrowed->values();
            } else {
                // Owner's ask, 2026-08-13 (funnel log) — a real signal for what to tag next:
                // logged server-side (not left for the frontend to notice) since this is the
                // one place that actually knows the fallback fired, not just that a list came back.
                \App\Models\WizardEvent::create([
                    'search_session_id' => $session->id,
                    'event_type' => 'zero_match_fallback',
                    'payload' => ['type' => $args['type'], 'preference_tags' => $preferenceTags->values()->all()],
                ]);
            }
        }

        if ($isGeoType) {
            $this->assignPriceRanks($mapped, $priceTotals);
        }

        // `jeftino`/`kvalitet` reframed 2026-08-13 (owner's call) — these never belonged as
        // atmosphere/meta tags in the first place (a city isn't durably "cheap," what it costs
        // THIS session depends on dates/headcount, which we already compute for real via
        // accommodationTotalFor/price_rank). Reframed as a SORT preference over that real price
        // instead: real atmosphere/persona matches still decide the primary order, this only
        // breaks ties (or drives order entirely once nothing else matched) — "bira jeftino, a
        // stavio je budzet od 3000... mozda planira nesto da vrati" is exactly why this doesn't
        // override a real preference_tag match, only tie-breaks among equals. If both are
        // somehow selected at once (contradictory), `kvalitet` wins — deterministic, not worth
        // a UI-level mutual-exclusion relation for an edge case nobody's hit yet.
        $costPreference = match (true) {
            $preferenceTags->contains('kvalitet') => 'kvalitet',
            $preferenceTags->contains('jeftino') => 'jeftino',
            default => null,
        };

        if ($isGeoType && $costPreference) {
            return $mapped
                ->sort(function (TaxonomyNode $a, TaxonomyNode $b) use ($priceTotals, $costPreference) {
                    if ($a->match_score !== $b->match_score) {
                        return $b->match_score <=> $a->match_score;
                    }

                    $priceA = $priceTotals[$a->id] ?? null;
                    $priceB = $priceTotals[$b->id] ?? null;
                    if ($priceA === null || $priceB === null) {
                        return 0;
                    }

                    // jeftino: cheapest first. kvalitet: priciest-within-budget first. For
                    // type=country, filterByBudget already dropped anything that doesn't fit (or
                    // fell back to the 2 nearest with a caveat) before we ever get here, so
                    // "priciest of what's left" IS "best quality tier still in reach" — no
                    // separate page-2 fallback needed, that already happened upstream. type=city
                    // has no budget-fit narrowing at all yet (only country does) — this still
                    // orders correctly (priciest first), just without that pre-filter's safety
                    // net for the city list specifically.
                    return $costPreference === 'jeftino' ? $priceA <=> $priceB : $priceB <=> $priceA;
                })
                ->values();
        }

        // Owner's call, 2026-08-11: "cena je uvek parametar, jer svako oce da ustedi" — when
        // nothing carries a real match_score (no preference stated yet, or the fallback above
        // just kept everything because nothing matched at all), sorting by match_score is a
        // no-op tie, so order by price ascending instead — a useful default is better than an
        // arbitrary one. Untied (falls through to sort_order) when there's no price data either.
        if ($isGeoType && $mapped->max('match_score') === 0) {
            return $mapped
                ->sortBy(fn (TaxonomyNode $node) => $priceTotals[$node->id] ?? PHP_FLOAT_MAX)
                ->values();
        }

        return $mapped->sortByDesc('match_score')->values();
    }

    /**
     * Real accommodation cost signal for price_rank (see assignPriceRanks) — reuses the same
     * per-week pricing machinery as the budget-fit narrowing above, just for country AND city
     * types instead of country only. Null (no price signal, price_rank stays null) whenever
     * the session doesn't have enough answered yet (no campaign, no resolvable trip dates/
     * traveler count) or the destination has no price data at all.
     */
    private function accommodationTotalFor(TaxonomyNode $node, string $type, SearchSession $session): ?float
    {
        if (! $session->wizard_campaign_id) {
            return null;
        }

        [$checkin, $checkout] = $this->tripCheckinCheckout($session);
        if (! $checkin) {
            return null;
        }

        $totalTravelers = ($session->adults_count ?? 0) + count($session->children_ages ?? []);
        if ($totalTravelers === 0) {
            return null;
        }

        if ($type === 'city') {
            $priceRow = $node->campaignDestinationPrices()
                ->where('wizard_campaign_id', $session->wizard_campaign_id)
                ->with(['campaign', 'weeklyPrices'])
                ->first();

            return $priceRow?->estimateAccommodationTotal($checkin, $checkout, $totalTravelers);
        }

        // Nights, not calendar days present — see WizardCampaignDestinationPrice's night-count
        // fix, 2026-08-12. No +1: checkout day itself is never a paid night.
        $nights = $checkin->diffInDays($checkout);
        $total = $this->cheapestAccommodationTotal($node, $session, $totalTravelers, $nights);

        return $total > 0.0 ? $total : null;
    }

    /**
     * Relative price_rank (1 = cheapest of the CURRENT candidate set, 5 = priciest) — deliberately
     * relative rather than an absolute price bracket, since "expensive" only means something in
     * comparison to the other options actually being shown. Left null (no price coloring shown)
     * when fewer than 2 candidates have real price data — not enough spread to rank meaningfully.
     */
    private function assignPriceRanks(Collection $nodes, array $totals): void
    {
        $values = collect($totals)->filter(fn (?float $v) => $v !== null && $v > 0);

        if ($values->count() < 2 || $values->max() === $values->min()) {
            foreach ($nodes as $node) {
                $node->setAttribute('price_rank', null);
            }

            return;
        }

        $min = $values->min();
        $max = $values->max();

        foreach ($nodes as $node) {
            $total = $totals[$node->id] ?? null;

            if ($total === null || $total <= 0) {
                $node->setAttribute('price_rank', null);
                continue;
            }

            $normalized = ($total - $min) / ($max - $min);
            $node->setAttribute('price_rank', (int) round(1 + $normalized * 4));
        }
    }

    /**
     * Hard-excludes countries that fail a cultural-availability requirement the session
     * explicitly asked for (selected preference_tag with `meta.cultural_category`+`max_tier`,
     * e.g. "zeli_alkohol_slobodno" -> alcohol tier must be <= 2). No fallback — unlike budget,
     * a stated cultural requirement is a dealbreaker, not a resource constraint to relax.
     * A country with NO row for that category at all is kept, not excluded — missing data
     * isn't evidence of a bad fit, see [[wizard_architecture]] cultural_availability seeding
     * note (country-level only, not exhaustive yet).
     */
    private function filterByCulturalAvailability(Collection $countries, SearchSession $session): Collection
    {
        $tagSlugs = collect($session->free_text_answers['preference_tags'] ?? [])
            ->merge($session->free_text_answers['implied_preference_tags'] ?? []);

        if ($tagSlugs->isEmpty()) {
            return $countries;
        }

        $requirements = TaxonomyNode::where('type', 'preference_tag')
            ->whereIn('slug', $tagSlugs)
            ->get()
            ->filter(fn (TaxonomyNode $tag) => isset($tag->meta['cultural_category'], $tag->meta['max_tier']));

        if ($requirements->isEmpty()) {
            return $countries;
        }

        return $countries->filter(function (TaxonomyNode $country) use ($requirements) {
            foreach ($requirements as $requirement) {
                $tier = $country->culturalTierFor($requirement->meta['cultural_category']);

                if ($tier !== null && $tier->tier > $requirement->meta['max_tier']) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Narrows countries by BudgetEstimationEngine, when the session has enough answered to
     * run it (total_budget + adults_count + a resolvable trip length). Returns the (possibly
     * narrowed, possibly fallback-only) country list plus the set of node IDs that are in the
     * list only as an over-budget-but-closest fallback (surfaced to the frontend as
     * `budget_caveat` so it can show "more expensive than asked, but nearest fit").
     *
     * @return array{0: Collection<int, TaxonomyNode>, 1: Collection<int, int>}
     */
    private function filterByBudget(Collection $countries, SearchSession $session): array
    {
        $nights = $this->tripDurationNights($session);

        if (! $session->total_budget || ! $session->adults_count || ! $nights || $countries->isEmpty()) {
            return [$countries, collect()];
        }

        $totalTravelers = $session->adults_count + count($session->children_ages ?? []);
        // BudgetEstimationEngine::estimate() prices FOOD, which is bought on both the checkin
        // and checkout day (you still eat before flying home) — one more than nights slept.
        $foodDays = $nights + 1;

        // Owner's catch, 2026-08-13: a 500€/8-day/all-inclusive session was passing Greece at a
        // price that could never actually buy all-inclusive there — meal_plan_preference wasn't
        // reaching the budget check at all. See BudgetEstimationEngine::mealPlanTotalFor.
        $mealPlanSlugs = $session->free_text_answers['meal_plan_preference'] ?? [];
        $mealPlanSlug = BudgetEstimationEngine::strongestMealPlanSlug($mealPlanSlugs);

        $result = (new BudgetEstimationEngine)->narrowCandidates(
            $countries,
            (float) $session->total_budget,
            $session->adults_count,
            count($session->children_ages ?? []),
            $foodDays,
            fn (TaxonomyNode $country) => $this->cheapestAccommodationTotal($country, $session, $totalTravelers, $nights),
            $mealPlanSlug
        );

        $narrowed = $result->pluck('country')->values();
        $caveatIds = $result->filter(fn (array $row) => $row['caveat'])->pluck('country.id')->values();

        return [$narrowed, $caveatIds];
    }

    /**
     * At this stage a specific CITY hasn't been picked yet — only a candidate country — so
     * there's no single campaign price to look up (prices are seeded per-city, see
     * WizardCampaignDestinationPrice). Uses the cheapest priced child city as the country's
     * representative accommodation cost, matching this engine's existing "never over-exclude,
     * lean toward what could still work" philosophy (same spirit as the 2-closest-fallback in
     * narrowCandidates itself). 0.0 (no accommodation cost applied) if the session has no
     * campaign, or none of the country's cities have a price filled in yet.
     *
     * Weekly-price-aware, 2026-08-11 — each candidate city's CHEAPEST per-night rate across the
     * actual stay dates (see WizardCampaignDestinationPrice::cheapestNightlyRateFor) is used
     * instead of the flat price_per_person_eur scalar, so this stays correct once destinations
     * move to per-week pricing instead of one flat number.
     */
    private function cheapestAccommodationTotal(TaxonomyNode $country, SearchSession $session, int $totalTravelers, int $nights): float
    {
        if (! $session->wizard_campaign_id) {
            return 0.0;
        }

        [$checkin, $checkout] = $this->tripCheckinCheckout($session);
        if (! $checkin) {
            return 0.0;
        }

        $cheapestPerNight = $country->children()
            ->with(['campaignDestinationPrices' => fn ($q) => $q->where('wizard_campaign_id', $session->wizard_campaign_id)->with(['campaign', 'weeklyPrices'])])
            ->get()
            ->pluck('campaignDestinationPrices')
            ->flatten()
            ->map(fn ($priceRow) => $priceRow->cheapestNightlyRateFor($checkin, $checkout))
            ->filter(fn ($v) => $v !== null)
            ->min();

        return $cheapestPerNight !== null ? $cheapestPerNight * $totalTravelers * $nights : 0.0;
    }

    /**
     * Trip length in NIGHTS (not calendar days present) — explicit date_from/date_to if both
     * set, otherwise the session's termin_category default_duration_days (already nights, see
     * that field's "8 nights, not an arbitrary round number" seeding note), otherwise null (not
     * enough to estimate from). Owner's catch, 2026-08-12: the explicit-dates branch used to add
     * +1 (calendar days present, correct for FOOD but not nights slept) — callers that need food
     * days instead add that +1 themselves at the call site (see filterByBudget).
     */
    private function tripDurationNights(SearchSession $session): ?int
    {
        if ($session->date_from && $session->date_to) {
            return $session->date_from->diffInDays($session->date_to);
        }

        if (! $session->termin_category) {
            return null;
        }

        $termin = TaxonomyNode::where('type', 'termin_category')
            ->where('slug', $session->termin_category)
            ->first();

        return $termin?->meta['default_duration_days'] ?? null;
    }

    /**
     * [checkin, checkout] Carbon pair — same source/fallback logic as
     * SearchSessionQueryCompiler::resolveDates(), duplicated rather than shared (see that
     * method's docblock; this class and that one already independently derive trip length the
     * same two-source way, this just adds the actual calendar dates cheapestAccommodationTotal
     * needs for per-week pricing). [null, null] if neither exact dates nor a termin_category
     * window exist yet.
     */
    private function tripCheckinCheckout(SearchSession $session): array
    {
        if ($session->date_from && $session->date_to) {
            return [Carbon::instance($session->date_from), Carbon::instance($session->date_to)];
        }

        if (! $session->termin_category) {
            return [null, null];
        }

        $termin = TaxonomyNode::where('type', 'termin_category')
            ->where('slug', $session->termin_category)
            ->first();

        $windowStart = $termin?->meta['window_start'] ?? null;
        $durationDays = $termin?->meta['default_duration_days'] ?? null;
        if (! $windowStart || ! $durationDays) {
            return [null, null];
        }

        $today = Carbon::today();
        $checkin = Carbon::createFromFormat('Y-m-d', $today->year.'-'.$windowStart)->startOfDay();
        if ($checkin->lt($today)) {
            $checkin->addYear();
        }

        return [$checkin, $checkin->copy()->addDays($durationDays)];
    }
}
