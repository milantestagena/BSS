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
     * (all-inclusive) covers the WHOLE 2.5 — nothing left to eat out. Self-catering isn't in
     * this map at all, 2026-08-14 — it's its own top-level meal_style ('sam_se_snalazim'), not
     * a meal_plan_preference pick anymore (see fitFor's `$mealStyle` param).
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
        $adultDaily = $this->perAdultDailyEatingOutEur($country);
        $coffeePrice = $country->hospitalityMeta()['avg_cafe_coffee_eur'] ?? null;

        if ($adultDaily === null || $coffeePrice === null) {
            return null;
        }

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
     * Per-adult, per-day eating-out rate — extracted 2026-08-19 so DestinationGuide can show a
     * plain "roughly €X/day if you eat out" line without duplicating this math. Null if the
     * country has no `meta.hospitality` seeded.
     */
    public function perAdultDailyEatingOutEur(TaxonomyNode $country): ?float
    {
        $hospitality = $country->hospitalityMeta();
        $mealPrice = $hospitality['avg_restaurant_meal_eur'] ?? null;
        $coffeePrice = $hospitality['avg_cafe_coffee_eur'] ?? null;

        if ($mealPrice === null || $coffeePrice === null) {
            return null;
        }

        return self::MEALS_PER_DAY_PER_ADULT * $mealPrice + self::COFFEES_PER_DAY_PER_ADULT * $coffeePrice;
    }

    /** Same per-adult/day rate, self-catering style — see EATING_OUT_TO_SELF_CATERING_RATIO. */
    public function perAdultDailySelfCateringEur(TaxonomyNode $country): ?float
    {
        $eatingOut = $this->perAdultDailyEatingOutEur($country);

        return $eatingOut === null ? null : $eatingOut / self::EATING_OUT_TO_SELF_CATERING_RATIO;
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
     * `$mealStyle` (2026-08-14, second redesign): the session's meal_style answer —
     * 'jede_napolju' (Local restaurants), 'u_smestaju' (At the accommodation), or
     * 'sam_se_snalazim' (I'll organize myself / cook). Checked FIRST and takes priority over
     * everything below: 'jede_napolju' ONLY ever tries eating_out (no silent self_catering
     * fallback), 'sam_se_snalazim' ONLY ever tries self_catering directly. Real bug this fixes,
     * caught live: a session that explicitly said "I eat at restaurants" was still shown
     * countries captioned "Fits if you cook for yourself" — the OLD no-mealPlanSlugs fallback
     * tried self_catering as a second attempt regardless of what the user actually said, which
     * made sense before meal_style existed as an explicit signal but is actively misleading now.
     * 'u_smestaju' falls through to the `$mealPlanSlugs` branch below (nothing to check yet
     * without a specific tier).
     *
     * `$mealPlanSlugs` (2026-08-13): the session's requested meal_plan_preference picks — hotel
     * tiers only now (self-catering isn't one of these anymore, see MEAL_PLAN_COVERAGE_RATIOS'
     * docblock), only ever populated when `$mealStyle === 'u_smestaju'` (see
     * WizardService.isQuestionVisible). ONLY consulted when `$mealsIncluded` is false — i.e. we
     * don't have a real bundled price for this destination, so estimate one instead of silently
     * ignoring what was asked for (owner's catch: a 500€/8-day/all-inclusive session was passing
     * Greece at a price that could never actually buy all-inclusive there).
     *
     * Multi-select here is NOT a contradiction to resolve down to one slug — owner's own
     * framing, 2026-08-14: "teo bih da se uklopim u all inclusive negde, al ako nema, pa mogu i
     * da przim pomfrit iz kese na terasi" (picking both all-inclusive AND a lighter tier means
     * "all-inclusive if it fits, breakfast if that's what it takes" — a priority list, not a
     * mistake). So every pick is checked, from most-inclusive/preferred down to least, and the
     * BEST one that actually fits wins — returned as ITS OWN slug (e.g. 'sve_ukljuceno',
     * 'dorucak'), not a generic 'meal_plan' bucket, so a caller can say "Egypt: all-inclusive
     * fits, Turkey: only breakfast does." Only 'insufficient' when NONE of the picks fit.
     */
    public function fitFor(TaxonomyNode $country, float $totalBudget, int $adults, int $children, int $days, float $accommodationTotal = 0.0, bool $mealsIncluded = false, array $mealPlanSlugs = [], ?string $mealStyle = null): ?string
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

        if ($mealStyle === 'jede_napolju') {
            return $disposableBudget >= $estimate['eating_out_total_eur'] ? 'eating_out' : 'insufficient';
        }

        if ($mealStyle === 'sam_se_snalazim') {
            return $disposableBudget >= $estimate['self_catering_total_eur'] ? 'self_catering' : 'insufficient';
        }

        if (! empty($mealPlanSlugs)) {
            $ranked = collect($mealPlanSlugs)
                ->filter(fn (string $slug) => isset(self::MEAL_PLAN_COVERAGE_RATIOS[$slug]))
                ->sortByDesc(fn (string $slug) => self::MEAL_PLAN_COVERAGE_RATIOS[$slug]);

            foreach ($ranked as $slug) {
                if ($disposableBudget >= $this->mealPlanTotalFor($country, $slug, $estimate['eating_out_total_eur'])) {
                    return $slug;
                }
            }

            return 'insufficient';
        }

        // Defensive only — meal_style is mandatory, so this should be unreachable once a
        // session has actually finished the broj_putnika step.
        if ($disposableBudget >= $estimate['eating_out_total_eur']) {
            return 'eating_out';
        }

        if ($disposableBudget >= $estimate['self_catering_total_eur']) {
            return 'self_catering';
        }

        return 'insufficient';
    }

    /**
     * Out-of-pocket food total for a specific hotel meal_plan_preference tier — the portion NOT
     * covered by that tier's included meals, still paid at restaurants elsewhere. Owner's ask,
     * 2026-08-23: used to work out how much of a stated total_budget can realistically go
     * toward the ROOM RATE itself (Booking's own price filter, which already reflects a
     * selected meal plan's cost once `mealplan=` is applied) instead of sitting unspent as an
     * unaccounted food buffer — see SearchSessionQueryCompiler::accommodationNightlyPriceCeiling.
     * Distinct from mealPlanTotalFor() below (that one estimates the FULL combined food spend,
     * hotel-embedded + out-of-pocket, for fitFor()'s budget-fit comparison) — this returns only
     * the leftover slice, using the same MEAL_PLAN_COVERAGE_RATIOS/MEALS_PER_DAY_PER_ADULT this
     * class already has. sve_ukljuceno (all-inclusive) covers the full 2.5, so this is 0 — the
     * whole budget can target the room rate, matching the owner's own "3 obroka, sve ide po
     * nocenju" framing. An unrecognized slug is treated as covering nothing (full eating-out
     * cost stays out-of-pocket) — same conservative-default convention as the rest of this class.
     */
    public function outOfPocketMealTotal(TaxonomyNode $country, string $mealPlanSlug, float $eatingOutTotal): float
    {
        $coveredRatio = self::MEAL_PLAN_COVERAGE_RATIOS[$mealPlanSlug] ?? 0.0;
        $uncoveredFraction = max(0.0, self::MEALS_PER_DAY_PER_ADULT - $coveredRatio) / self::MEALS_PER_DAY_PER_ADULT;

        return $eatingOutTotal * $uncoveredFraction;
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
     * Owner's ask, 2026-08-14 (second refinement): even when the user's stated meal_style is
     * 'jede_napolju' or 'sam_se_snalazim' (so fitFor() correctly never suggests a hotel meal
     * plan as the PRIMARY fit — see that method's docblock), it's still worth telling them
     * separately whether all-inclusive would fit for the same money — a genuinely useful "did
     * you know" upsell, not a suggestion to change their plan. Owner's own gradation:
     * "biram restoran... možemo mu kažemo negde imaš all inclusive za te pare, ne nudimo
     * kuvanje" (eating-out picker: fine to mention all-inclusive, never self-catering) and
     * "bira kuvanje... proverimo mi all inclusive, tom nije do istraživanja restorana, al neće
     * da se žali da mu je neko sve već spremio" (self-catering picker: same all-inclusive
     * check — they're not interested in restaurants specifically, but wouldn't mind everything
     * done for them at the same price). Deliberately ALWAYS checks all-inclusive specifically
     * (not "any meal plan"), matching the owner's own examples — that's the one upgrade
     * dramatic enough to be worth a callout regardless of which non-hotel style was picked.
     * Purely informational — never changes fitFor()'s own inclusion/exclusion/sort decision.
     */
    public function allInclusiveFits(TaxonomyNode $country, float $totalBudget, int $adults, int $children, int $days, float $accommodationTotal = 0.0, bool $mealsIncluded = false): bool
    {
        if ($mealsIncluded) {
            return $totalBudget >= $accommodationTotal;
        }

        $estimate = $this->estimate($country, $adults, $children, $days);
        if ($estimate === null) {
            return false;
        }

        $disposableBudget = $totalBudget - $accommodationTotal;
        if ($disposableBudget < 0) {
            return false;
        }

        return $disposableBudget >= $this->mealPlanTotalFor($country, 'sve_ukljuceno', $estimate['eating_out_total_eur']);
    }

    /**
     * The numeric food total actually implied by a `fitFor()` result — 2026-09-01, backs
     * GeographyResolver's budget_fit_percent (accommodation + food, as a % of total_budget).
     * Deliberately derived from the SAME `$fit` string `narrowCandidates()` already computed,
     * not a second independent calculation, so this number can never disagree with what
     * SearchSessionQueryCompiler's `budgetFit`/budgetNoteFor() already displays for the same
     * session. `null`/'insufficient' both fall back to self_catering_total_eur — the cheapest
     * real number available, consistent with fitFor()'s own conservative-default convention.
     */
    private function foodTotalForFit(?string $fit, array $estimate, TaxonomyNode $country): float
    {
        return match ($fit) {
            'eating_out' => $estimate['eating_out_total_eur'],
            'self_catering', null, 'insufficient' => $estimate['self_catering_total_eur'],
            default => $this->mealPlanTotalFor($country, $fit, $estimate['eating_out_total_eur']),
        };
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
     * `$mealPlanSlugs` / `$mealStyle` (2026-08-13 / 2026-08-14): threaded straight into fitFor()
     * for every candidate — see that method's docblock.
     *
     * @param  Collection<int, TaxonomyNode>  $countries
     * @param  string[]  $mealPlanSlugs
     * @return Collection<int, array{country: TaxonomyNode, estimate: array, accommodation_total_eur: float, food_total_eur: float, fit: string, caveat: bool}>
     */
    public function narrowCandidates(Collection $countries, float $totalBudget, int $adults, int $children, int $days, ?callable $accommodationTotalFor = null, array $mealPlanSlugs = [], ?string $mealStyle = null): Collection
    {
        $evaluated = $countries
            ->map(function (TaxonomyNode $country) use ($totalBudget, $adults, $children, $days, $accommodationTotalFor, $mealPlanSlugs, $mealStyle) {
                $estimate = $this->estimate($country, $adults, $children, $days);
                if ($estimate === null) {
                    return null;
                }

                $accommodationTotal = $accommodationTotalFor ? $accommodationTotalFor($country) : 0.0;
                $fit = $this->fitFor($country, $totalBudget, $adults, $children, $days, $accommodationTotal, false, $mealPlanSlugs, $mealStyle);

                return [
                    'country' => $country,
                    'estimate' => $estimate,
                    'accommodation_total_eur' => $accommodationTotal,
                    'food_total_eur' => $this->foodTotalForFit($fit, $estimate, $country),
                    'fit' => $fit,
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
