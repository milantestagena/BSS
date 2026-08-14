<?php

namespace App\Services;

use App\Models\TaxonomyNode;
use Illuminate\Support\Collection;

/**
 * Estimates whether a total trip budget realistically fits a candidate destination, and
 * under which spending style (eating out vs self-catering) — see wizard_architecture memory,
 * 2026-07-30 "BudgetEstimationEngine". Owner's own framing: "za 7 dana Egipat da jede po
 * restoranima ili Kipar al da jede u apartmanu, iz prodavnice" — this is a caveat/narrowing
 * signal, not a real Booking parameter (Booking has no such filter), so its output feeds
 * GeographyResolver scoring and Honest Report signals, not toBookingParams().
 *
 * All coefficients are deliberately plain constants, not config/DB-driven — owner's explicit
 * call ("sve sto moze da bude konstanta, neka bude"), matching [[feedback_engineering_standards]]
 * rule 5 (no premature abstraction) until a real need to tune them per-campaign shows up.
 */
class BudgetEstimationEngine
{
    /** Owner's refinement, 2026-08-05: not a flat 3 restaurant-priced meals/day — realistically
     *  dinner + breakfast (cheaper) plus treats/coffee covers most days, lunch is often
     *  skipped/light while traveling. "Neither 2 nor 3, somewhere between." */
    private const MEALS_PER_DAY_PER_ADULT = 2.5;

    private const COFFEES_PER_DAY_PER_ADULT = 1;

    /** A child's meal costs roughly half an adult's (kids' menu / smaller portion). */
    private const CHILD_MEAL_COST_RATIO = 0.5;

    /** One treat (e.g. ice cream) per child per day, priced as a multiple of a coffee. */
    private const CHILD_TREATS_PER_DAY = 1;

    private const CHILD_TREAT_COST_AS_MULTIPLE_OF_COFFEE = 2;

    /**
     * Self-catering cost is estimated as a fraction of the eating-out estimate rather than
     * built up from individual grocery item prices — local_stores meta is even sparser than
     * hospitality meta today, and owner's own rule of thumb (1:3 to 1:4) is a reasonable
     * stand-in until per-item grocery data is worth the seeding effort.
     */
    private const EATING_OUT_TO_SELF_CATERING_RATIO = 3.5;

    /**
     * Owner's ask, 2026-08-13: when we DON'T have a real `includes_meals` price for a
     * destination but the user asked for a specific meal_plan_preference, estimate what the
     * hotel would charge for it as a fraction of MEALS_PER_DAY_PER_ADULT (2.5) — a full
     * eating-out day, per the same per-adult mealPrice this whole class already uses. Breakfast
     * is lighter than a full meal (~0.3), dinner a bit less than lunch (~0.65) — same "plain
     * reasoned constant, not measured" convention as everything else here. `sve_ukljuceno`
     * (all-inclusive) covers the WHOLE 2.5 — nothing left to eat out.
     * `samostalno_kuvanje` (self-catering) isn't in this map — it reuses the EXISTING
     * self-catering path (the user is cooking, not the hotel feeding them).
     */
    private const MEAL_PLAN_COVERAGE_RATIOS = [
        'dorucak' => 0.3,
        'dorucak_rucak' => 1.3,
        'dorucak_vecera' => 0.95,
        'pun_pansion' => 1.95,
        'sve_ukljuceno' => self::MEALS_PER_DAY_PER_ADULT,
    ];

    /**
     * Estimated cost (EUR) for the whole trip, both spending styles, for the given country.
     * Returns null if the country has no `meta.hospitality.avg_restaurant_meal_eur` seeded —
     * absence, not a zero estimate, so callers don't mistake "no data" for "free."
     */
    public function estimate(TaxonomyNode $country, int $adults, int $children, int $days): ?array
    {
        $mealPrice = $country->meta['hospitality']['avg_restaurant_meal_eur'] ?? null;
        $coffeePrice = $country->meta['hospitality']['avg_cafe_coffee_eur'] ?? null;

        if ($mealPrice === null || $coffeePrice === null) {
            return null;
        }

        $adultDaily = self::MEALS_PER_DAY_PER_ADULT * $mealPrice + self::COFFEES_PER_DAY_PER_ADULT * $coffeePrice;
        $childDaily = $adultDaily * self::CHILD_MEAL_COST_RATIO
            + self::CHILD_TREATS_PER_DAY * ($coffeePrice * self::CHILD_TREAT_COST_AS_MULTIPLE_OF_COFFEE);

        $eatingOutTotal = ($adults * $adultDaily + $children * $childDaily) * $days;
        $selfCateringTotal = $eatingOutTotal / self::EATING_OUT_TO_SELF_CATERING_RATIO;

        return [
            'eating_out_total_eur' => round($eatingOutTotal, 2),
            'self_catering_total_eur' => round($selfCateringTotal, 2),
        ];
    }

    /**
     * Which spending style (if any) fits the given total budget for this country — see class
     * docblock for the owner's Egypt-vs-Cyprus example. Returns null if there's no cost data
     * for the country at all (not the same as "doesn't fit").
     *
     * `$accommodationTotal` (EUR, whole trip) is subtracted from the budget FIRST — see
     * wizard_architecture, 2026-08-05: bug caught live by the owner (total_budget=2200,
     * accommodation alone=1500 for 5 people/10 nights, engine still called it "eating_out" fit
     * because it only ever compared total_budget against the FOOD estimate, never accounting
     * for the biggest line item. Defaults to 0.0 (old behavior) when the caller has no real
     * accommodation price yet — see SearchSessionQueryCompiler::resolveBudgetContext().
     *
     * `$mealsIncluded` (2026-08-05, the Egypt all-inclusive catch): when true, skips computing
     * a food estimate entirely and just checks the accommodation cost against the budget —
     * food's already paid for as part of that price. Without this, `fitFor()` used to
     * re-derive its OWN food estimate from the country's hospitality meta regardless of what
     * the caller already knew, silently re-adding a cost that was deliberately zeroed
     * elsewhere (SearchSessionQueryCompiler's `estimate` signal) — the fit result never
     * actually reflected the zeroing.
     *
     * `$mealPlanSlugs` (2026-08-13, redesigned 2026-08-14): the session's requested
     * meal_plan_preference picks, ONLY consulted when `$mealsIncluded` is false — i.e. we don't
     * have a real bundled price for this destination, so estimate one instead of silently
     * ignoring what was asked for (owner's catch: a 500€/8-day/all-inclusive session was
     * passing Greece at a price that could never actually buy all-inclusive there).
     *
     * Multi-select here is NOT a contradiction to resolve down to one slug — owner's own
     * framing, 2026-08-14: "teo bih da se uklopim u all inclusive negde, al ako nema, pa mogu i
     * da przim pomfrit iz kese na terasi" (picking both all-inclusive AND self-catering means
     * "all-inclusive if it fits, self-catering if that's what it takes" — a priority list, not
     * a mistake). So every pick is checked, from most-inclusive/preferred down to least, and the
     * BEST one that actually fits wins — returned as ITS OWN slug (e.g. 'sve_ukljuceno',
     * 'dorucak', 'samostalno_kuvanje'), not a generic 'meal_plan'/'self_catering' bucket, so a
     * caller can say "Egypt: all-inclusive fits, Greece: only self-catering does." Only
     * 'insufficient' when NONE of the picks fit. `samostalno_kuvanje` routes straight to the
     * existing self_catering total; it's only ever a real pick here because meal_plan_preference
     * is gated behind meal_style saying "at the accommodation" (2026-08-14 redesign — meal_style
     * itself carries no budget logic of its own, it's a pure flow gate, see
     * WizardService.isQuestionVisible). An empty array falls through to the original plain
     * eating_out/self_catering behavior (no meal_plan_preference asked/answered at all).
     */
    public function fitFor(TaxonomyNode $country, float $totalBudget, int $adults, int $children, int $days, float $accommodationTotal = 0.0, bool $mealsIncluded = false, array $mealPlanSlugs = []): ?string
    {
        if ($mealsIncluded) {
            return $totalBudget >= $accommodationTotal ? 'eating_out' : 'insufficient';
        }

        $estimate = $this->estimate($country, $adults, $children, $days);

        if ($estimate === null) {
            return null;
        }

        $disposableBudget = $totalBudget - $accommodationTotal;
        if ($disposableBudget < 0) {
            return 'insufficient';
        }

        if (! empty($mealPlanSlugs)) {
            $ranked = collect($mealPlanSlugs)
                ->filter(fn (string $slug) => $slug === 'samostalno_kuvanje' || isset(self::MEAL_PLAN_COVERAGE_RATIOS[$slug]))
                ->sortByDesc(fn (string $slug) => self::MEAL_PLAN_COVERAGE_RATIOS[$slug] ?? -1);

            foreach ($ranked as $slug) {
                if ($slug === 'samostalno_kuvanje') {
                    if ($disposableBudget >= $estimate['self_catering_total_eur']) {
                        return 'samostalno_kuvanje';
                    }

                    continue;
                }

                if ($disposableBudget >= $this->mealPlanTotalFor($country, $slug, $estimate['eating_out_total_eur'])) {
                    return $slug;
                }
            }

            return 'insufficient';
        }

        if ($disposableBudget >= $estimate['eating_out_total_eur']) {
            return 'eating_out';
        }

        if ($disposableBudget >= $estimate['self_catering_total_eur']) {
            return 'self_catering';
        }

        return 'insufficient';
    }

    /**
     * Owner's ask, 2026-08-13: real total cost of a specific meal_plan_preference, used only as
     * a FALLBACK when we don't have a real `includes_meals` price for the destination. Reuses
     * the already-computed `eatingOutTotal` (which already accounts for adults/children/coffee/
     * days) rather than re-deriving per-meal math from scratch — the covered-meals fraction of
     * that total gets multiplied by the country's `meal_plan_coefficient` (see
     * MealPlanCoefficientCalculator Filament page for deriving a real per-country value), the
     * uncovered fraction stays at plain restaurant price. When the coefficient is exactly 1.0
     * this always equals `eatingOutTotal` itself, whatever the split — "ono bi uvek bilo *2.5,
     * kad bi koeficijent bio 1" (owner's own framing).
     *
     * Default 0.8, not 1.0 (owner's call, 2026-08-13, after cross-checking our
     * MEAL_PLAN_COVERAGE_RATIOS against an independent estimate: a flat 0.7 lined up with real-
     * world board-supplement pricing across breakfast/half-board/full-board/all-inclusive
     * simultaneously — hotels really do discount combo meal plans vs pure street-price
     * extrapolation, "najjeftiniji retko kad nude all inclusive." 0.8 picked deliberately more
     * conservative than that 0.7 match — safer to slightly overestimate cost than let a
     * genuinely-too-tight budget through). Still an ESTIMATE, not measured — supersede per
     * country via the calculator page once real Booking prices are checked.
     */
    private function mealPlanTotalFor(TaxonomyNode $country, string $mealPlanSlug, float $eatingOutTotal): float
    {
        $coveredRatio = self::MEAL_PLAN_COVERAGE_RATIOS[$mealPlanSlug];
        $coefficient = (float) ($country->meta['meal_plan_coefficient'] ?? 0.8);
        $coveredFraction = $coveredRatio / self::MEALS_PER_DAY_PER_ADULT;

        return $eatingOutTotal * (1 + $coveredFraction * ($coefficient - 1));
    }

    /**
     * Narrows a list of candidate countries to the ones the budget realistically covers
     * ('eating_out' or 'self_catering'). If NONE fit, falls back to the 2 closest by smallest
     * overage rather than returning nothing — owner's explicit call: never show zero results,
     * show the nearest matches with an honest "more expensive, but closest to what you asked
     * for" caveat instead. Countries with no cost data at all are silently dropped either way
     * (not a fit, not a fallback candidate — we have nothing to compare).
     *
     * `$accommodationTotalFor` (optional): `fn(TaxonomyNode $country): float`, called once per
     * candidate — each country can have its own real accommodation price (see
     * TaxonomyNode::campaignPricePerPersonFor()), so this can't be a single scalar like
     * fitFor()'s parameter. Kept decoupled from campaign/session specifics on purpose — this
     * engine only ever deals in plain numbers, the caller supplies how to get them.
     *
     * `$mealPlanSlugs` (2026-08-13): threaded straight into fitFor() for every candidate — see
     * that method's docblock.
     *
     * @param  Collection<int, TaxonomyNode>  $countries
     * @param  string[]  $mealPlanSlugs
     * @return Collection<int, array{country: TaxonomyNode, estimate: array, accommodation_total_eur: float, fit: string, caveat: bool}>
     */
    public function narrowCandidates(Collection $countries, float $totalBudget, int $adults, int $children, int $days, ?callable $accommodationTotalFor = null, array $mealPlanSlugs = []): Collection
    {
        $evaluated = $countries
            ->map(function (TaxonomyNode $country) use ($totalBudget, $adults, $children, $days, $accommodationTotalFor, $mealPlanSlugs) {
                $estimate = $this->estimate($country, $adults, $children, $days);
                if ($estimate === null) {
                    return null;
                }

                $accommodationTotal = $accommodationTotalFor ? $accommodationTotalFor($country) : 0.0;

                return [
                    'country' => $country,
                    'estimate' => $estimate,
                    'accommodation_total_eur' => $accommodationTotal,
                    'fit' => $this->fitFor($country, $totalBudget, $adults, $children, $days, $accommodationTotal, false, $mealPlanSlugs),
                    'caveat' => false,
                ];
            })
            ->filter();

        $fitting = $evaluated->filter(fn (array $row) => $row['fit'] !== 'insufficient');

        if ($fitting->isNotEmpty()) {
            return $fitting->values();
        }

        return $evaluated
            ->sortBy(fn (array $row) => $row['estimate']['self_catering_total_eur'] + $row['accommodation_total_eur'] - $totalBudget)
            ->take(2)
            ->map(fn (array $row) => [...$row, 'caveat' => true])
            ->values();
    }
}
