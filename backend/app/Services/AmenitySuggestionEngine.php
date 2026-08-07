<?php

namespace App\Services;

use App\Models\SearchSession;

/**
 * Derives suggested (pre-filled, editable — NOT silently forced) property type / meal plan /
 * amenities from signals the session already has: budget "comfort ratio" and group size —
 * see wizard_architecture memory, 2026-08-03. Owner's own examples, verified against this
 * implementation: "ako nema budzet, nemoj markiras private pool... mnogo para, ponudi mu fuul
 * dozivljaj" and "kratak period, 6 clana porodice... veliki budzet, ne nudi apartmane."
 *
 * Deliberately a SEPARATE engine from BudgetEstimationEngine (which only answers "does this
 * destination fit the budget") — this one answers "given that it fits, what should we suggest
 * checking." All thresholds are plain constants, tunable after real testing — v1 judgment
 * calls, not measured values, same as every other heuristic built tonight.
 */
class AmenitySuggestionEngine
{
    private const LUXURY_RATIO = 2.0;

    private const COMFORTABLE_RATIO = 1.0;

    private const LARGE_GROUP_SIZE = 4;

    /**
     * @param  array{eating_out_total_eur: float, self_catering_total_eur: float}  $budgetEstimate  From BudgetEstimationEngine::estimate() — same session, same destination.
     * @param  float  $accommodationTotal  Whole-trip EUR, subtracted from total_budget BEFORE
     *         the ratio is computed — see wizard_architecture, 2026-08-05: bug caught live by
     *         the owner, a tight-budget family (2200 total, 1500 of it accommodation alone) was
     *         still getting `privatni_bazen` suggested, because the ratio used to compare the
     *         FULL total_budget against food cost only — a big total budget always read as
     *         "luxury" regardless of how much of it lodging consumed. Defaults to 0.0 for
     *         callers with no real accommodation price yet (old behavior, unchanged).
     * @return array{tip_smestaja: string[], meal_plan: string[], accommodation_facility: string[], room_facility: string[]}
     */
    public function suggest(SearchSession $session, array $budgetEstimate, float $accommodationTotal = 0.0): array
    {
        $disposableBudget = max(0.0, (float) $session->total_budget - $accommodationTotal);

        // eating_out_total_eur is 0 specifically when the accommodation price already includes
        // meals (see SearchSessionQueryCompiler::resolveBudgetContext's includes_meals handling,
        // 2026-08-05) — there's no food baseline left to divide by. Any leftover budget in that
        // case is treated as COMFORTABLE, not LUXURY — we have no real signal to justify more
        // than that without a food cost to compare against.
        if ($budgetEstimate['eating_out_total_eur'] <= 0) {
            $ratio = $disposableBudget > 0 ? self::COMFORTABLE_RATIO : 0.0;
        } else {
            $ratio = $disposableBudget > 0
                ? $disposableBudget / $budgetEstimate['eating_out_total_eur']
                : 0.0;
        }

        $totalTravelers = $session->adults_count + count($session->children_ages ?? []);
        $isLargeGroup = $totalTravelers >= self::LARGE_GROUP_SIZE;

        return [
            'tip_smestaja' => $this->suggestPropertyType($ratio, $isLargeGroup),
            'meal_plan' => $this->suggestMealPlan($ratio, $this->prefersExploringLocalFood($session)),
            'accommodation_facility' => $this->suggestAccommodationFacilities($ratio),
            'room_facility' => $this->suggestRoomFacilities($ratio),
        ];
    }

    /**
     * Large groups need space+kitchen regardless of budget (holiday_home/apartman/guest_house);
     * a luxury budget upgrades that to villa and drops apartman — owner's explicit example
     * ("veliki budzet, ne nudi apartmane"). Small groups follow budget alone: self-catering
     * budget -> apartman/guest_house, comfortable -> hotel/apartman, luxury -> hotel/vila.
     */
    private function suggestPropertyType(float $ratio, bool $isLargeGroup): array
    {
        if ($isLargeGroup) {
            return $ratio >= self::LUXURY_RATIO
                ? ['vila', 'holiday_home']
                : ['holiday_home', 'apartman', 'guest_house'];
        }

        if ($ratio >= self::LUXURY_RATIO) {
            return ['hotel', 'vila'];
        }

        if ($ratio >= self::COMFORTABLE_RATIO) {
            return ['hotel', 'apartman'];
        }

        return ['apartman', 'guest_house'];
    }

    /**
     * Self-catering-budget sessions get no meal plan suggestion at all — they're already being
     * pointed at apartments with kitchens, suggesting a meal plan on top would contradict that.
     *
     * `$prefersLocalFood` overrides budget entirely — owner's own example, 2026-08-03: "ako
     * imamo kintu, mozda volimo da probamo lokalne pekare... fali podpitanje". A Gurman/Foodie
     * traveler wants to explore local food regardless of budget, so a hotel meal plan would be
     * the WRONG default even at the luxury tier — this is a preference axis independent of
     * money, not something the budget ratio alone can capture.
     */
    private function suggestMealPlan(float $ratio, bool $prefersLocalFood): array
    {
        if ($prefersLocalFood) {
            return [];
        }

        if ($ratio >= self::LUXURY_RATIO) {
            return ['dorucak_vecera'];
        }

        if ($ratio >= self::COMFORTABLE_RATIO) {
            return ['dorucak'];
        }

        return [];
    }

    /**
     * True when "gurman" (Foodie) is among the session's selected personas — either the solo
     * `persona` FK or the group `persona_tags` array (see wizard_architecture's group-size
     * persona/persona_group split, 2026-07-30).
     */
    private function prefersExploringLocalFood(SearchSession $session): bool
    {
        if ($session->persona?->slug === 'gurman') {
            return true;
        }

        $personaTags = $session->free_text_answers['persona_tags'] ?? [];

        return in_array('gurman', $personaTags, true);
    }

    /**
     * Owner's explicit example: "ako nema budzet, nemoj markiras private pool" — pool/spa/beach
     * are price-correlated enough that suggesting them on a tight budget would just read as a
     * bait upsell rather than a helpful default.
     */
    private function suggestAccommodationFacilities(float $ratio): array
    {
        if ($ratio >= self::LUXURY_RATIO) {
            return ['bazen', 'plaza', 'spa'];
        }

        if ($ratio >= self::COMFORTABLE_RATIO) {
            return ['wifi', 'parking'];
        }

        return ['wifi'];
    }

    private function suggestRoomFacilities(float $ratio): array
    {
        if ($ratio >= self::LUXURY_RATIO) {
            return ['privatni_bazen', 'pogled_na_more', 'balkon', 'klima'];
        }

        if ($ratio >= self::COMFORTABLE_RATIO) {
            return ['klima', 'balkon'];
        }

        return ['klima'];
    }
}
