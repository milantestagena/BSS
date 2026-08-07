<?php

namespace Tests\Unit;

use App\Models\SearchSession;
use App\Services\AmenitySuggestionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers AmenitySuggestionEngine — see wizard_architecture memory, 2026-08-03. Verified
 * directly against the owner's own worked examples.
 */
class AmenitySuggestionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(int $adults, array $childrenAges, float $totalBudget): SearchSession
    {
        return SearchSession::create([
            'status' => 'in_progress', 'adults_count' => $adults,
            'children_ages' => $childrenAges, 'total_budget' => $totalBudget,
        ]);
    }

    public function test_tight_budget_never_suggests_private_pool_or_spa(): void
    {
        // ratio < 1.0 (budget below even the eating-out estimate)
        $session = $this->makeSession(2, [], 100);
        $estimate = ['eating_out_total_eur' => 500, 'self_catering_total_eur' => 143];

        $result = (new AmenitySuggestionEngine)->suggest($session, $estimate);

        $this->assertNotContains('privatni_bazen', $result['room_facility']);
        $this->assertNotContains('spa', $result['accommodation_facility']);
        $this->assertNotContains('bazen', $result['accommodation_facility']);
        $this->assertEmpty($result['meal_plan']);
    }

    public function test_generous_budget_suggests_full_experience(): void
    {
        // ratio >= 2.0
        $session = $this->makeSession(2, [], 1200);
        $estimate = ['eating_out_total_eur' => 500, 'self_catering_total_eur' => 143];

        $result = (new AmenitySuggestionEngine)->suggest($session, $estimate);

        $this->assertContains('privatni_bazen', $result['room_facility']);
        $this->assertContains('bazen', $result['accommodation_facility']);
        $this->assertSame(['dorucak_vecera'], $result['meal_plan']);
    }

    public function test_large_family_with_big_budget_gets_villa_not_apartment(): void
    {
        // owner's exact example: "kratak period, 6 clana porodice... veliki budzet, ne nudi apartmane"
        $session = $this->makeSession(2, [8, 10, 12, 14], 3000);
        $estimate = ['eating_out_total_eur' => 1000, 'self_catering_total_eur' => 286];

        $result = (new AmenitySuggestionEngine)->suggest($session, $estimate);

        $this->assertContains('vila', $result['tip_smestaja']);
        $this->assertNotContains('apartman', $result['tip_smestaja']);
    }

    public function test_large_family_with_tight_budget_still_gets_apartment_for_space(): void
    {
        $session = $this->makeSession(2, [8, 10, 12, 14], 500);
        $estimate = ['eating_out_total_eur' => 1000, 'self_catering_total_eur' => 286];

        $result = (new AmenitySuggestionEngine)->suggest($session, $estimate);

        $this->assertContains('apartman', $result['tip_smestaja']);
        $this->assertContains('holiday_home', $result['tip_smestaja']);
        $this->assertNotContains('vila', $result['tip_smestaja']);
    }

    public function test_small_group_never_gets_space_oriented_suggestions(): void
    {
        $session = $this->makeSession(1, [], 1000);
        $estimate = ['eating_out_total_eur' => 250, 'self_catering_total_eur' => 71];

        $result = (new AmenitySuggestionEngine)->suggest($session, $estimate);

        $this->assertNotContains('holiday_home', $result['tip_smestaja']);
        $this->assertNotContains('guest_house', $result['tip_smestaja']);
    }

    public function test_gurman_persona_gets_no_meal_plan_suggestion_even_at_luxury_budget(): void
    {
        // owner's example: "ako imamo kintu, mozda volimo da probamo lokalne pekare" — a
        // Foodie should never get a hotel meal plan pre-suggested, budget notwithstanding.
        $gurman = \App\Models\TaxonomyNode::create(['type' => 'persona', 'slug' => 'gurman', 'label' => 'test', 'sort_order' => 0]);
        $session = $this->makeSession(2, [], 5000);
        $session->update(['persona_id' => $gurman->id]);
        $estimate = ['eating_out_total_eur' => 500, 'self_catering_total_eur' => 143];

        $result = (new AmenitySuggestionEngine)->suggest($session->fresh(), $estimate);

        $this->assertEmpty($result['meal_plan']);
    }

    public function test_accommodation_total_is_subtracted_before_the_luxury_ratio(): void
    {
        // Owner's own live example, 2026-08-05: without subtracting accommodation, ratio =
        // 2200/838.5 ≈ 2.6, above LUXURY_RATIO — private pool got suggested for a
        // budget-conscious family. With accommodation subtracted, disposable budget barely
        // covers eating out, ratio drops well under COMFORTABLE_RATIO.
        $session = $this->makeSession(3, [8], 2200);
        $estimate = ['eating_out_total_eur' => 838.5, 'self_catering_total_eur' => 239.57];

        $withoutAccommodation = (new AmenitySuggestionEngine)->suggest($session, $estimate, 0.0);
        $this->assertContains('privatni_bazen', $withoutAccommodation['room_facility']);

        $withAccommodation = (new AmenitySuggestionEngine)->suggest($session, $estimate, 1500.0);
        $this->assertNotContains('privatni_bazen', $withAccommodation['room_facility']);
        $this->assertNotContains('spa', $withAccommodation['accommodation_facility']);
    }

    public function test_accommodation_total_exceeding_budget_never_goes_negative_ratio(): void
    {
        $session = $this->makeSession(2, [], 500);
        $estimate = ['eating_out_total_eur' => 250, 'self_catering_total_eur' => 71];

        $result = (new AmenitySuggestionEngine)->suggest($session, $estimate, 900.0);

        $this->assertNotContains('privatni_bazen', $result['room_facility']);
        $this->assertEmpty($result['meal_plan']);
    }

    public function test_gurman_in_persona_group_tags_also_suppresses_meal_plan(): void
    {
        $session = $this->makeSession(2, [], 5000);
        $session->update(['free_text_answers' => ['persona_tags' => ['gurman']]]);
        $estimate = ['eating_out_total_eur' => 500, 'self_catering_total_eur' => 143];

        $result = (new AmenitySuggestionEngine)->suggest($session->fresh(), $estimate);

        $this->assertEmpty($result['meal_plan']);
    }
}
