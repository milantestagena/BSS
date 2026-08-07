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
     */
    public function fitFor(TaxonomyNode $country, float $totalBudget, int $adults, int $children, int $days, float $accommodationTotal = 0.0, bool $mealsIncluded = false): ?string
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

        if ($disposableBudget >= $estimate['eating_out_total_eur']) {
            return 'eating_out';
        }

        if ($disposableBudget >= $estimate['self_catering_total_eur']) {
            return 'self_catering';
        }

        return 'insufficient';
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
     * @param  Collection<int, TaxonomyNode>  $countries
     * @return Collection<int, array{country: TaxonomyNode, estimate: array, accommodation_total_eur: float, fit: string, caveat: bool}>
     */
    public function narrowCandidates(Collection $countries, float $totalBudget, int $adults, int $children, int $days, ?callable $accommodationTotalFor = null): Collection
    {
        $evaluated = $countries
            ->map(function (TaxonomyNode $country) use ($totalBudget, $adults, $children, $days, $accommodationTotalFor) {
                $estimate = $this->estimate($country, $adults, $children, $days);
                if ($estimate === null) {
                    return null;
                }

                $accommodationTotal = $accommodationTotalFor ? $accommodationTotalFor($country) : 0.0;

                return [
                    'country' => $country,
                    'estimate' => $estimate,
                    'accommodation_total_eur' => $accommodationTotal,
                    'fit' => $this->fitFor($country, $totalBudget, $adults, $children, $days, $accommodationTotal),
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
