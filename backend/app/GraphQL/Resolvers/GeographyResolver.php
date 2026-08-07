<?php

namespace App\GraphQL\Resolvers;

use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Services\BudgetEstimationEngine;
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

        if (! empty($args['parentId'])) {
            $query->where('parent_id', $args['parentId']);
        }

        $nodes = $query->get();
        $budgetCaveatIds = collect();

        if ($args['type'] === 'country') {
            $nodes = $this->filterByCulturalAvailability($nodes, $session);
            [$nodes, $budgetCaveatIds] = $this->filterByBudget($nodes, $session);
        }

        $preferenceTags = collect($session->free_text_answers['preference_tags'] ?? [])
            ->merge($session->free_text_answers['implied_preference_tags'] ?? []);

        return $nodes
            ->map(function (TaxonomyNode $node) use ($preferenceTags, $budgetCaveatIds, $impliedIds) {
                $meta = $node->meta ?? [];

                $nodeTags = collect($meta['drinks'] ?? [])
                    ->merge($meta['atmosphere'] ?? [])
                    ->merge($meta['food'] ?? [])
                    ->merge($meta['budget'] ?? []);

                $node->setAttribute('implied', $impliedIds->contains($node->id));
                $node->setAttribute('match_score', $preferenceTags->intersect($nodeTags)->count() * 5);
                $node->setAttribute('budget_caveat', $budgetCaveatIds->contains($node->id));

                return $node;
            })
            ->sortByDesc('match_score')
            ->values();
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
        $days = $this->tripDurationDays($session);

        if (! $session->total_budget || ! $session->adults_count || ! $days || $countries->isEmpty()) {
            return [$countries, collect()];
        }

        $totalTravelers = $session->adults_count + count($session->children_ages ?? []);

        $result = (new BudgetEstimationEngine)->narrowCandidates(
            $countries,
            (float) $session->total_budget,
            $session->adults_count,
            count($session->children_ages ?? []),
            $days,
            fn (TaxonomyNode $country) => $this->cheapestAccommodationTotal($country, $session, $totalTravelers, $days)
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
     */
    private function cheapestAccommodationTotal(TaxonomyNode $country, SearchSession $session, int $totalTravelers, int $days): float
    {
        if (! $session->wizard_campaign_id) {
            return 0.0;
        }

        $cheapestPerPerson = $country->children()
            ->with(['campaignDestinationPrices' => fn ($q) => $q->where('wizard_campaign_id', $session->wizard_campaign_id)])
            ->get()
            ->pluck('campaignDestinationPrices')
            ->flatten()
            ->pluck('price_per_person_eur')
            ->filter(fn ($v) => $v !== null)
            ->min();

        return $cheapestPerPerson !== null ? $cheapestPerPerson * $totalTravelers * $days : 0.0;
    }

    /**
     * Trip length in days: explicit date_from/date_to if both set, otherwise the session's
     * termin_category default_duration_days, otherwise null (not enough to estimate from).
     */
    private function tripDurationDays(SearchSession $session): ?int
    {
        if ($session->date_from && $session->date_to) {
            return $session->date_from->diffInDays($session->date_to) + 1;
        }

        if (! $session->termin_category) {
            return null;
        }

        $termin = TaxonomyNode::where('type', 'termin_category')
            ->where('slug', $session->termin_category)
            ->first();

        return $termin?->meta['default_duration_days'] ?? null;
    }
}
