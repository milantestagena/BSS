<?php

namespace Tests\Unit;

use App\Models\HolidayPricingWindow;
use App\Models\LateSummerAccommodationPrice;
use App\Models\TaxonomyNode;
use App\Models\TaxonomyNodeAccommodationSeason;
use App\Services\AccommodationPriceEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers AccommodationPriceEstimator — see wizard_architecture memory, 2026-08-03.
 */
class AccommodationPriceEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private function makeCountry(string $slug = 'testland', array $meta = []): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => 'country', 'slug' => $slug, 'label' => 'test', 'sort_order' => 0, 'meta' => $meta,
        ]);
    }

    public function test_falls_back_to_season_tier_when_no_holiday_matches(): void
    {
        $country = $this->makeCountry();
        TaxonomyNodeAccommodationSeason::create(['taxonomy_node_id' => $country->id, 'month' => 7, 'season_tier' => 'sezona']);

        $result = (new AccommodationPriceEstimator)->estimateFor($country, new \DateTimeImmutable('2026-07-15'));

        $this->assertSame('sezona', $result['tier']);
        $this->assertFalse($result['is_holiday']);
        $this->assertSame(2.0, $result['multiplier']);
    }

    public function test_season_tier_falls_back_to_parent_country(): void
    {
        $country = $this->makeCountry();
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'parent_id' => $country->id, 'sort_order' => 0]);
        TaxonomyNodeAccommodationSeason::create(['taxonomy_node_id' => $country->id, 'month' => 1, 'season_tier' => 'van_sezone']);

        $result = (new AccommodationPriceEstimator)->estimateFor($city, new \DateTimeImmutable('2026-01-15'));

        $this->assertSame('van_sezone', $result['tier']);
    }

    public function test_returns_null_when_no_season_data_and_no_holiday(): void
    {
        $country = $this->makeCountry();

        $result = (new AccommodationPriceEstimator)->estimateFor($country, new \DateTimeImmutable('2026-06-01'));

        $this->assertNull($result);
    }

    public function test_may_day_holiday_overrides_the_base_season(): void
    {
        $country = $this->makeCountry();
        TaxonomyNodeAccommodationSeason::create(['taxonomy_node_id' => $country->id, 'month' => 5, 'season_tier' => 'van_sezone']);
        HolidayPricingWindow::create([
            'key' => 'may_day', 'label' => 'Prvi maj', 'month' => 5, 'day' => 1,
            'is_easter_based' => false, 'window_before_days' => 3, 'window_after_days' => 3,
            'price_multiplier' => 1.8,
        ]);

        $result = (new AccommodationPriceEstimator)->estimateFor($country, new \DateTimeImmutable('2026-05-02'));

        $this->assertSame('praznici', $result['tier']);
        $this->assertTrue($result['is_holiday']);
        $this->assertSame('may_day', $result['holiday_key']);
        $this->assertSame(1.8, $result['multiplier']);
    }

    public function test_christmas_window_spilling_into_january_matches_from_previous_years_window(): void
    {
        // window is Dec 24 -> +9 days = Jan 2 of the FOLLOWING year — a query date of Jan 1st
        // must match against DECEMBER of the previous year, not its own year's Dec 24.
        $country = $this->makeCountry();
        TaxonomyNodeAccommodationSeason::create(['taxonomy_node_id' => $country->id, 'month' => 1, 'season_tier' => 'van_sezone']);
        HolidayPricingWindow::create([
            'key' => 'christmas_newyear', 'label' => 'Božić / Nova godina', 'month' => 12, 'day' => 24,
            'is_easter_based' => false, 'window_before_days' => 0, 'window_after_days' => 9,
            'price_multiplier' => 3.5,
        ]);

        $result = (new AccommodationPriceEstimator)->estimateFor($country, new \DateTimeImmutable('2027-01-01'));

        $this->assertSame('praznici', $result['tier']);
        $this->assertSame('christmas_newyear', $result['holiday_key']);
    }

    public function test_orthodox_and_western_easter_can_land_on_different_dates(): void
    {
        // 2026: western Easter is 2026-04-05, orthodox Easter is 2026-04-12 — a week apart.
        $western = $this->makeCountry('western-land');
        $orthodox = $this->makeCountry('orthodox-land', ['easter_calendar' => 'orthodox']);
        foreach ([$western, $orthodox] as $country) {
            TaxonomyNodeAccommodationSeason::create(['taxonomy_node_id' => $country->id, 'month' => 4, 'season_tier' => 'pred_post_sezona']);
        }
        HolidayPricingWindow::create([
            'key' => 'easter', 'label' => 'Uskrs', 'is_easter_based' => true,
            'window_before_days' => 0, 'window_after_days' => 0,
            'price_multiplier' => 2.2,
        ]);

        $estimator = new AccommodationPriceEstimator;
        $onWesternEaster = new \DateTimeImmutable('2026-04-05');

        $this->assertTrue($estimator->estimateFor($western, $onWesternEaster)['is_holiday']);
        $this->assertFalse($estimator->estimateFor($orthodox, $onWesternEaster)['is_holiday']);
    }

    public function test_real_observed_price_wins_over_the_multiplier_estimate(): void
    {
        $country = $this->makeCountry();
        TaxonomyNodeAccommodationSeason::create(['taxonomy_node_id' => $country->id, 'month' => 7, 'season_tier' => 'sezona']);
        LateSummerAccommodationPrice::create([
            'taxonomy_node_id' => $country->id, 'season_tier' => 'sezona',
            'price_per_night_eur' => 140, 'observed_at' => '2026-08-01',
        ]);

        $result = (new AccommodationPriceEstimator)->estimateFor($country, new \DateTimeImmutable('2026-07-15'));

        $this->assertSame('sezona', $result['tier']);
        $this->assertSame(140.0, $result['price_per_night_eur']);
        $this->assertNull($result['multiplier']);
        $this->assertSame('manual_website', $result['source']);
    }

    public function test_real_observed_price_falls_back_to_parent_country(): void
    {
        $country = $this->makeCountry();
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'parent_id' => $country->id, 'sort_order' => 0]);
        TaxonomyNodeAccommodationSeason::create(['taxonomy_node_id' => $country->id, 'month' => 7, 'season_tier' => 'sezona']);
        LateSummerAccommodationPrice::create([
            'taxonomy_node_id' => $country->id, 'season_tier' => 'sezona',
            'price_per_night_eur' => 90, 'observed_at' => '2026-08-01',
        ]);

        $result = (new AccommodationPriceEstimator)->estimateFor($city, new \DateTimeImmutable('2026-07-15'));

        $this->assertSame(90.0, $result['price_per_night_eur']);
    }
}
