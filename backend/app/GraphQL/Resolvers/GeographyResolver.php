<?php

namespace App\GraphQL\Resolvers;

use App\Models\DestinationGuide;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Models\WizardCampaignDestinationPrice;
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

        // Per-campaign tag on/off, 2026-08-19 (owner's ask) — same spirit as
        // wizard_campaign_questions letting a campaign pick its own subset of QUESTIONS, one
        // level deeper: which preference_tag OPTIONS it actually offers (e.g. a future
        // Christmas-market tag only makes sense for a city-break campaign, `lepe_plaze` only
        // for a swim one). `meta.campaign_keys` absent (the default for every existing tag today)
        // means "available everywhere" — nothing needs retagging just because this filter now
        // exists. A session with no campaign at all (generic/non-campaign flow) also sees
        // everything unrestricted, same "never over-narrow without a real signal" convention as
        // the rest of this resolver.
        if ($args['type'] === 'preference_tag' && $session->campaign) {
            $campaignKey = $session->campaign->key;
            $query->where(function ($q) use ($campaignKey) {
                $q->whereNull('meta->campaign_keys')
                    ->orWhereJsonContains('meta->campaign_keys', $campaignKey);
            });
        }

        // Eager-loaded so excludes_slugs (below) is one query for the whole result set, not
        // N+1 — 2026-08-23, jeftino/kvalitet mutual exclusion.
        $nodes = $query->with('excludes')->get();
        $budgetCaveatIds = collect();
        $budgetFitById = collect();
        $allInclusiveById = collect();

        if ($args['type'] === 'country') {
            $nodes = $this->filterByCulturalAvailability($nodes, $session);
            [$nodes, $budgetCaveatIds, $budgetFitById, $allInclusiveById] = $this->filterByBudget($nodes, $session);
        }

        if ($args['type'] === 'country' || $args['type'] === 'city') {
            $nodes = $this->filterByClimate($nodes, $session, $args['type']);
        }

        // .unique() added 2026-08-13: a tag can now legitimately appear in BOTH arrays (explicit
        // AND implied — see WizardService.syncAnswersFromSession backfilling a `suggests` tag
        // into the user's own explicit answer so it shows pre-checked-but-editable) — without
        // dedup, intersect()'s count would double it, inflating match_score for that one tag.
        $preferenceTags = collect($session->free_text_answers['preference_tags'] ?? [])
            ->merge($session->free_text_answers['implied_preference_tags'] ?? [])
            ->unique();

        // "Superstar" denominator (see matchesEveryVibeTag below) — only VIBE tags count toward
        // a perfect match, not cultural-availability picks (alcohol/halal/vegan/lgbt), which
        // never appear in nodeTags at all (they're a hard filter, not a vibe attribute — see
        // filterByCulturalAvailability) and would make a perfect match nearly impossible to ever
        // reach once one is selected.
        $vibeTagCount = TaxonomyNode::where('type', 'preference_tag')
            ->whereIn('slug', $preferenceTags)
            ->get()
            ->filter(fn (TaxonomyNode $tag) => empty($tag->meta['cultural_category']))
            ->count();

        $isGeoType = in_array($args['type'], ['country', 'city'], true);

        $priceTotals = [];
        if ($isGeoType) {
            foreach ($nodes as $node) {
                $priceTotals[$node->id] = $this->accommodationTotalFor($node, $args['type'], $session);
            }
        }

        // hasGuide, 2026-08-19 — one query for the whole result set (not N+1), same shape as
        // every other bulk-computed attribute here. Campaign-scoped since DestinationGuide rows
        // are (campaign, destination) pairs, not just per-destination.
        $guidedIds = ($isGeoType && $session->wizard_campaign_id)
            ? DestinationGuide::where('wizard_campaign_id', $session->wizard_campaign_id)
                ->whereIn('taxonomy_node_id', $nodes->pluck('id'))
                ->pluck('taxonomy_node_id')
            : collect();

        // Air/sea temperature for the "See more" popover, 2026-08-24 (owner's ask) — real
        // Open-Meteo data already imported per city/month (see [[wizard_architecture]]'s
        // climate:import), just not surfaced to the frontend anywhere yet. Months spanned once
        // for the whole result set (same trip dates for every card); per-node lookup happens in
        // the map below since it genuinely varies by destination.
        [$climateCheckin, $climateCheckout] = $isGeoType ? $this->tripCheckinCheckout($session) : [null, null];
        $climateMonths = $climateCheckin ? $this->monthsSpanned($climateCheckin, $climateCheckout) : [];

        $mapped = $nodes->map(function (TaxonomyNode $node) use ($args, $preferenceTags, $vibeTagCount, $budgetCaveatIds, $budgetFitById, $allInclusiveById, $impliedIds, $guidedIds, $climateMonths) {
            $nodeTags = $this->resolveNodeTags($node);
            $matchedTags = $preferenceTags->intersect($nodeTags)->values();

            $node->setAttribute('implied', $impliedIds->contains($node->id));
            $node->setAttribute('matched_tags', $matchedTags->all());
            $node->setAttribute('match_score', $matchedTags->count() * 5);
            $node->setAttribute('budget_caveat', $budgetCaveatIds->contains($node->id));
            $node->setAttribute('budget_fit', $budgetFitById->get($node->id));
            $node->setAttribute('all_inclusive_fits', $allInclusiveById->get($node->id, false));
            $node->setAttribute('perfect_match', $this->isPerfectMatch($node, $args['type'], $matchedTags, $vibeTagCount, $preferenceTags));
            $node->setAttribute('has_guide', $guidedIds->contains($node->id));
            // jeftino/kvalitet mutual exclusion, 2026-08-23 — see QuestionInputComponent.
            // onMultiChoiceToggle. Cheap for every type (empty array when a node has no excludes
            // rows at all), not just preference_tag, so this stays generic like implied/matched_tags.
            $node->setAttribute('excludes_slugs', $node->excludes->pluck('slug')->all());

            if (! empty($climateMonths)) {
                $climate = $this->climateSummaryFor($node, $climateMonths);
                $node->setAttribute('climate_air_temp_c', $climate['air']);
                $node->setAttribute('climate_sea_temp_c', $climate['sea']);
            }

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
                    // Owner's ask, 2026-08-21: the Superstar card must always lead, even when a
                    // non-perfect-match node ties or beats it on raw match_score (possible since
                    // match_score also counts cultural-availability matches, which don't count
                    // toward perfect_match's own vibe-only denominator — see isPerfectMatch).
                    if ($a->perfect_match !== $b->perfect_match) {
                        return $b->perfect_match <=> $a->perfect_match;
                    }
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
                ->sortBy([
                    ['perfect_match', 'desc'],
                    // Laravel's array-comparator form calls this with ($a, $b) and uses the
                    // returned <=> result as-is — 'desc'/'asc' has no effect on a callable
                    // comparator (only on plain-attribute comparisons), so ascending is baked
                    // into the comparison itself here, not the direction flag.
                    fn (TaxonomyNode $a, TaxonomyNode $b) => ($priceTotals[$a->id] ?? PHP_FLOAT_MAX) <=> ($priceTotals[$b->id] ?? PHP_FLOAT_MAX),
                ])
                ->values();
        }

        // perfect_match leads, 2026-08-21 (owner's ask — "zvezdica mora na prvo mesto") — see
        // the identical reasoning in the $costPreference branch above.
        return $mapped
            ->sortBy([
                ['perfect_match', 'desc'],
                ['match_score', 'desc'],
            ])
            ->values();
    }

    /** The same vibe-tag pool (drinks/atmosphere/food/budget meta) every matched_tags/match_score
     *  computation in this file reads from — extracted so isPerfectMatch can run it again for a
     *  COUNTRY's child cities, not just the node currently being mapped. */
    private function resolveNodeTags(TaxonomyNode $node): Collection
    {
        $meta = $node->meta ?? [];

        return collect($meta['drinks'] ?? [])
            ->merge($meta['atmosphere'] ?? [])
            ->merge($meta['food'] ?? [])
            ->merge($meta['budget'] ?? []);
    }

    /**
     * "Superstar" — owner's ask, 2026-08-17: a soft alternative to a hard AND/OR filter on
     * preference_tags (a real AND would too often collide with this campaign's sparse per-city
     * tag coverage and return nothing). A destination that matched EVERY selected vibe tag, not
     * just some.
     *
     * For type=city: straightforward — this city's own matchedTags covers every vibe tag asked
     * for.
     *
     * For type=country: deliberately NOT "the country's own aggregate meta matches everything" —
     * owner caught this live, 2026-08-17: Turkey (Lively nightlife + Great food + Great beaches)
     * showed a country-level star, but not one of its actual bookable cities could deliver all
     * three at once (Marmaris has nightlife, Ölüdeniz has beaches, no single city had all three) —
     * a country-level star the traveler can never actually reach at the city step reads as a
     * broken promise. So a country only earns the star if AT LEAST ONE of its child cities
     * independently earns it too ("ako ti neki grad ima zvezdicu - onda tek zemlja moze da je
     * dobije").
     */
    private function isPerfectMatch(TaxonomyNode $node, string $type, Collection $matchedTags, int $vibeTagCount, Collection $preferenceTags): bool
    {
        if ($vibeTagCount === 0) {
            return false;
        }

        if ($type === 'city') {
            return $matchedTags->count() === $vibeTagCount;
        }

        if ($type === 'country') {
            return $node->children()->get()->contains(
                fn (TaxonomyNode $city) => $preferenceTags->intersect($this->resolveNodeTags($city))->count() === $vibeTagCount
            );
        }

        return false;
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

            $sameUnit = WizardCampaignDestinationPrice::wantsSameUnit($totalTravelers, $session->number_of_rooms);

            return $priceRow?->estimateAccommodationTotal($checkin, $checkout, $totalTravelers, $sameUnit);
        }

        // Nights, not calendar days present — see WizardCampaignDestinationPrice's night-count
        // fix, 2026-08-12. No +1: checkout day itself is never a paid night.
        $nights = $checkin->diffInDays($checkout);
        // averageAccommodationTotal, not cheapestAccommodationTotal — this feeds price_rank
        // (relative color), a different question than the budget-fit INCLUDE/EXCLUDE check
        // elsewhere in this file. See averageAccommodationTotal's docblock, 2026-08-14.
        $total = $this->averageAccommodationTotal($node, $session, $totalTravelers, $nights);

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

        if ($values->count() < 2) {
            foreach ($nodes as $node) {
                $node->setAttribute('price_rank', null);
            }

            return;
        }

        // Owner's call, 2026-08-14: a genuine tie (every priced candidate landed on the exact
        // same total) is still real information — "these cost the same" — not "we don't know."
        // Gets the same color for everyone instead of no color at all, which used to read as a
        // missing-data gap rather than an actual (if rare) price match. Rank 2 (lime, a medium
        // green in priceRankClass) rather than the middle amber (3) — owner's follow-up call:
        // "sve je bolje nego nema nista" (green reads as "still fine," not a warning).
        if ($values->max() === $values->min()) {
            foreach ($nodes as $node) {
                $total = $totals[$node->id] ?? null;
                $node->setAttribute('price_rank', ($total !== null && $total > 0) ? 2 : null);
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
     * @return array{0: Collection<int, TaxonomyNode>, 1: Collection<int, int>, 2: Collection<int, string>, 3: Collection<int, bool>}
     */
    private function filterByBudget(Collection $countries, SearchSession $session): array
    {
        $nights = $this->tripDurationNights($session);

        if (! $session->total_budget || ! $session->adults_count || ! $nights || $countries->isEmpty()) {
            return [$countries, collect(), collect(), collect()];
        }

        $totalTravelers = $session->adults_count + count($session->children_ages ?? []);
        // BudgetEstimationEngine::estimate() prices FOOD, which is bought on both the checkin
        // and checkout day (you still eat before flying home) — one more than nights slept.
        $foodDays = $nights + 1;

        // Owner's catch, 2026-08-13: a 500€/8-day/all-inclusive session was passing Greece at a
        // price that could never actually buy all-inclusive there — meal_plan_preference wasn't
        // reaching the budget check at all. See BudgetEstimationEngine::mealPlanTotalFor.
        // meal_plan_preference (and therefore this) is only ever answered at all once meal_style
        // says "at the accommodation" — see WizardService.isQuestionVisible, 2026-08-14 redesign.
        // Owner's call, 2026-08-14: the raw multi-select array is passed straight through now,
        // NOT collapsed to one "strongest" slug first — picking both all-inclusive AND a lighter
        // tier means "all-inclusive if it fits, breakfast if that's what it takes," so every
        // candidate gets checked against ALL picks (see fitFor's docblock).
        $mealPlanSlugs = $session->free_text_answers['meal_plan_preference'] ?? [];
        // Owner's second catch, 2026-08-14: meal_style itself (not just meal_plan_preference)
        // must reach fitFor() too — an eating_out-only session was silently falling back to a
        // self_catering fit when eating_out alone didn't cover a country, showing "Fits if you
        // cook for yourself" to someone who explicitly said they'd eat at restaurants.
        $mealStyle = $session->free_text_answers['meal_style'] ?? null;

        $result = (new BudgetEstimationEngine)->narrowCandidates(
            $countries,
            (float) $session->total_budget,
            $session->adults_count,
            count($session->children_ages ?? []),
            $foodDays,
            fn (TaxonomyNode $country) => $this->cheapestAccommodationTotal($country, $session, $totalTravelers, $nights),
            $mealPlanSlugs,
            $mealStyle
        );

        $narrowed = $result->pluck('country')->values();
        $caveatIds = $result->filter(fn (array $row) => $row['caveat'])->pluck('country.id')->values();
        // Owner's ask, 2026-08-14: "dodaj one komentare... ovde mozes i sa manjim budzetom" —
        // the frontend needs WHICH spending style each country actually fit under (not just the
        // pass/fail caveat flag) to show a real reason instead of a bare price-rank color.
        $fitById = $result->pluck('fit', 'country.id');

        // Owner's ask, 2026-08-14 (second refinement) — a purely informational cross-check,
        // independent of the strict fit above: does all-inclusive fit here for this budget?
        // Worth surfacing even (especially) when the session's meal_style means fitFor() would
        // never suggest it as the primary reason — see BudgetEstimationEngine::allInclusiveFits.
        $budgetEngine = new BudgetEstimationEngine;
        $allInclusiveById = $result->mapWithKeys(fn (array $row) => [
            $row['country']->id => $budgetEngine->allInclusiveFits(
                $row['country'], (float) $session->total_budget, $session->adults_count,
                count($session->children_ages ?? []), $foodDays, $row['accommodation_total_eur']
            ),
        ]);

        return [$narrowed, $caveatIds, $fitById, $allInclusiveById];
    }

    /**
     * Owner's ask, CLAUDE.md §8 item 3 (2026-08-14): "sezona ide od ~150 gradova ka ~15 do
     * oktobra" — narrows candidates to ones actually warm enough for the session's travel
     * month, instead of showing every seeded swim city year-round regardless of season.
     * Reuses the SAME `honest_report_thresholds.sea_temp_c` config already on the
     * termin_category (good/caveat, e.g. 22/18 — see SearchSessionQueryCompiler::climateSignal,
     * which already reads it as a Honest Report caveat, never a hard exclude). Here the
     * 'caveat' bound becomes the hard exclude line — the exact same criterion used when these
     * cities were originally chosen for the campaign ("nijedna nije pala ispod cold praga").
     *
     * No-op if the termin_category has no sea_temp_c threshold configured, or the session has
     * no resolvable travel date yet — same "skip until the inputs exist" convention as
     * filterByBudget/filterByCulturalAvailability. Uses the trip's FIRST month only (a late-
     * summer window rarely spans more than one or two calendar months, and the earlier month is
     * the warmer, more forgiving one to check against — "never over-exclude").
     *
     * type=city: excludes any city colder than the threshold. type=country: keeps a country
     * only if at least one child city still passes — offering a country just to have its city
     * list come back empty next is worse than not offering it at all. Reverts to the unfiltered
     * list if this would leave nothing, same "never show zero results" philosophy as every
     * other narrowing pass in this class.
     */
    private function filterByClimate(Collection $nodes, SearchSession $session, string $type): Collection
    {
        if ($nodes->isEmpty()) {
            return $nodes;
        }

        $termin = $session->termin_category
            ? TaxonomyNode::where('type', 'termin_category')->where('slug', $session->termin_category)->first()
            : null;
        $caveatThreshold = $termin?->meta['honest_report_thresholds']['sea_temp_c']['caveat'] ?? null;
        if ($caveatThreshold === null) {
            return $nodes;
        }

        [$checkin] = $this->tripCheckinCheckout($session);
        if (! $checkin) {
            return $nodes;
        }
        $month = $checkin->month;

        $passesClimate = function (TaxonomyNode $city) use ($month, $caveatThreshold): bool {
            $seaTemp = $city->climateFor($month)?->sea_temp_c;

            return $seaTemp === null || (float) $seaTemp >= $caveatThreshold;
        };

        if ($type === 'city') {
            $narrowed = $nodes->filter($passesClimate)->values();
        } else {
            $narrowed = $nodes->filter(function (TaxonomyNode $country) use ($passesClimate) {
                $children = $country->children()->where('type', 'city')->get();

                return $children->isEmpty() || $children->contains($passesClimate);
            })->values();
        }

        return $narrowed->isEmpty() ? $nodes : $narrowed;
    }

    /** [checkin.month, ..., checkout.month] with no duplicates — a trip can span two calendar
     *  months (e.g. 29 Aug -> 5 Sept), and the "See more" temperature line shows a range across
     *  all of them rather than picking just one arbitrarily (see climateSummaryFor). */
    private function monthsSpanned(Carbon $checkin, Carbon $checkout): array
    {
        $months = [];
        $cursor = $checkin->copy();
        while ($cursor->lte($checkout)) {
            $months[$cursor->month] = true;
            $cursor->addMonth()->startOfMonth();
        }

        return array_keys($months);
    }

    /**
     * Real air/sea temperature for the "See more" popover, 2026-08-24 (owner's ask) — Open-Meteo
     * data already imported per city/month (see climate:import), not surfaced anywhere on the
     * frontend until now. Returns null for a metric with no data at all across the spanned
     * months (absent, not guessed, same convention as everything else here) — never a min/max of
     * zero data points. A single-month trip returns min === max (still a real value, not a
     * range); the frontend shows a plain number then instead of "X - X".
     *
     * City nodes read their own climateFor() (which already falls back to the parent if the city
     * itself somehow has no row — see TaxonomyNode::climateFor). Country nodes have no climate
     * rows of their own (temperature genuinely varies by city within a country) — averaged across
     * whichever child cities DO have data for that month, same "aggregate across children"
     * pattern cheapestAccommodationTotal already uses for country-level price cards.
     *
     * @param  int[]  $months
     * @return array{air: ?array{min: float, max: float}, sea: ?array{min: float, max: float}}
     */
    private function climateSummaryFor(TaxonomyNode $node, array $months): array
    {
        $airValues = [];
        $seaValues = [];

        foreach ($months as $month) {
            if ($node->type === 'city') {
                $climate = $node->climateFor($month);
                if ($climate?->avg_temp_c !== null) $airValues[] = (float) $climate->avg_temp_c;
                if ($climate?->sea_temp_c !== null) $seaValues[] = (float) $climate->sea_temp_c;
                continue;
            }

            $childClimates = $node->children()->where('type', 'city')->get()
                ->map(fn (TaxonomyNode $city) => $city->climateFor($month))
                ->filter();

            $monthAir = $childClimates->pluck('avg_temp_c')->filter(fn ($v) => $v !== null);
            $monthSea = $childClimates->pluck('sea_temp_c')->filter(fn ($v) => $v !== null);
            if ($monthAir->isNotEmpty()) $airValues[] = (float) $monthAir->avg();
            if ($monthSea->isNotEmpty()) $seaValues[] = (float) $monthSea->avg();
        }

        return [
            'air' => empty($airValues) ? null : ['min' => min($airValues), 'max' => max($airValues)],
            'sea' => empty($seaValues) ? null : ['min' => min($seaValues), 'max' => max($seaValues)],
        ];
    }

    /**
     * At this stage a specific CITY hasn't been picked yet — only a candidate country — so
     * there's no single campaign price to look up (prices are seeded per-city, see
     * WizardCampaignDestinationPrice). Uses the cheapest priced child city as the country's
     * representative accommodation cost, matching this engine's existing "never over-exclude,
     * lean toward what could still work" philosophy (same spirit as the 2-closest-fallback in
     * narrowCandidates itself) — this is a hard budget-fit INCLUDE/EXCLUDE signal (see
     * filterByBudget), so it deliberately asks "is there at least one affordable option here,"
     * not "is the typical option here affordable." 0.0 (no accommodation cost applied) if the
     * session has no campaign, or none of the country's cities have a price filled in yet.
     *
     * Weekly-price-aware, 2026-08-11 — each candidate city's CHEAPEST per-night rate across the
     * actual stay dates (see WizardCampaignDestinationPrice::cheapestNightlyRateFor) is used
     * instead of the flat price_per_person_eur scalar, so this stays correct once destinations
     * move to per-week pricing instead of one flat number.
     *
     * Deliberately NOT used for price_rank/color (see averageAccommodationTotal below) — MIN is
     * right for "can they afford anything here," but wrong for "how does this country's overall
     * price level compare to the others," which is what the color is showing.
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

        $sameUnit = WizardCampaignDestinationPrice::wantsSameUnit($totalTravelers, $session->number_of_rooms);
        $roomMultiplierSum = WizardCampaignDestinationPrice::roomMultiplierSumFor($totalTravelers, $sameUnit);

        return $cheapestPerNight !== null ? $cheapestPerNight * $roomMultiplierSum * $nights : 0.0;
    }

    /**
     * Country-level accommodation signal for price_rank/color specifically (see
     * assignPriceRanks) — a SEPARATE method from cheapestAccommodationTotal above on purpose,
     * since that one deliberately answers a different question (budget-fit INCLUDE/EXCLUDE:
     * "is there at least one affordable option here").
     *
     * Bug fixed 2026-08-14: price_rank used to reuse cheapestAccommodationTotal (MIN across
     * cities), so a country's single cheapest outlier town stood in for its whole price signal —
     * two countries that each happened to have one similarly-priced budget town (Turkey's Alanya
     * and Egypt's Marsa Alam, both ~13€) came out with an IDENTICAL total and lost their color
     * entirely (see assignPriceRanks' tie guard). Owner's call: average spreads candidates out
     * by their overall price level instead of colliding on one shared bargain town.
     */
    private function averageAccommodationTotal(TaxonomyNode $country, SearchSession $session, int $totalTravelers, int $nights): float
    {
        if (! $session->wizard_campaign_id) {
            return 0.0;
        }

        [$checkin, $checkout] = $this->tripCheckinCheckout($session);
        if (! $checkin) {
            return 0.0;
        }

        $avgPerNight = $country->children()
            ->with(['campaignDestinationPrices' => fn ($q) => $q->where('wizard_campaign_id', $session->wizard_campaign_id)->with(['campaign', 'weeklyPrices'])])
            ->get()
            ->pluck('campaignDestinationPrices')
            ->flatten()
            ->map(fn ($priceRow) => $priceRow->cheapestNightlyRateFor($checkin, $checkout))
            ->filter(fn ($v) => $v !== null)
            ->avg();

        $sameUnit = WizardCampaignDestinationPrice::wantsSameUnit($totalTravelers, $session->number_of_rooms);
        $roomMultiplierSum = WizardCampaignDestinationPrice::roomMultiplierSumFor($totalTravelers, $sameUnit);

        return $avgPerNight !== null ? $avgPerNight * $roomMultiplierSum * $nights : 0.0;
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
