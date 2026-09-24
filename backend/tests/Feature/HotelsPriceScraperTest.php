<?php

namespace Tests\Feature;

use App\Filament\Pages\HotelsPriceScraper;
use App\Models\User;
use App\Models\WizardCampaign;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HotelsPriceScraperTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(): WizardCampaign
    {
        // Saturday-aligned weeks: Aug 29, Sep 5, 12, 19, 26, Oct 3, 10, 17, 24, 31.
        return WizardCampaign::create([
            'key' => 'testcamp', 'label' => 'Test', 'is_active' => true, 'sort_order' => 0,
            'season_start_date' => '2026-08-29', 'season_end_date' => '2026-11-07',
        ]);
    }

    private function page(WizardCampaign $campaign)
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        return Livewire::test(HotelsPriceScraper::class)->set('data.wizard_campaign_id', $campaign->id);
    }

    /** Re-sets endWeek twice (via a different value first) so updatedEndWeek() always fires and
     *  recomputeInterpolation() runs against the prices just assigned. */
    private function recompute($component, string $startWeek, string $endWeek)
    {
        return $component
            ->set('startWeek', $startWeek)
            ->set('endWeek', '2026-10-17')
            ->set('endWeek', $endWeek);
    }

    /** Same row shape as Hotels.com's real sidebar (checked against two real captures): a flex row
     *  div containing the input, plus a sibling `uitk-text` div holding the "From" price. */
    private function sidebarRow(string $inputType, string $name, string $value, ?int $price): string
    {
        $priceDiv = $price !== null ? '<div class="uitk-text uitk-type-300">$'.$price.'</div>' : '';

        return '<div class="uitk-layout-flex uitk-layout-flex-align-items-center uitk-layout-flex-gap-two">'
            .'<div><input type="'.$inputType.'" name="'.$name.'" value="'.$value.'"/></div>'.$priceDiv.'</div>';
    }

    private function sidebar(array $lodging, array $guestRating, ?int $floor): string
    {
        $html = '';
        if ($floor !== null) {
            $html .= '<input type="range" name="price" min="'.$floor.'" max="9999" value="'.$floor.'"/>';
        }
        foreach ($guestRating as $value => $price) {
            $html .= $this->sidebarRow('radio', 'guestRating', (string) $value, $price);
        }
        foreach ($lodging as $value => $price) {
            $html .= $this->sidebarRow('checkbox', 'lodging', (string) $value, $price);
        }

        return '<div>'.$html.'</div>';
    }

    private function extract(string $html, int $nights = 7): array
    {
        $page = new HotelsPriceScraper();
        $page->nights = $nights;
        $method = new \ReflectionMethod($page, 'extractNightlyPrices');

        return $method->invoke($page, $html);
    }

    /** Real case, Cefalù 2026-09-24 (owner's "pogresne cene"): 17 properties, the overall floor
     *  ($517, also guestRating Any and "Very good 8+") was a lone Bed & breakfast, while the
     *  cheapest actual hotel was $789. Old rule gave €90; the real price is ~€115-130. */
    public function test_jeftino_ignores_a_lone_bed_and_breakfast_outlier(): void
    {
        $html = $this->sidebar(
            ['HOTEL' => 789, 'APARTMENT' => 844, 'BED_AND_BREAKFAST' => 517, 'VILLA' => 2881, 'COUNTRY_HOUSE' => 1202],
            ['ANY' => 517, 45 => 594, 40 => 517, 35 => 517],
            510,
        );

        $result = $this->extract($html);

        $this->assertSame(789.0, $result['mainstreamFloor']);
        // 789 / 7 * 1.10 = 123.98 -> rounded up to the nearest 10.
        $this->assertSame(130.0, $result['cheap']);
        // "Very good 8+" ($517) sits below the cheapest ordinary stay -> raised to it.
        $this->assertSame(130.0, $result['quality']);
    }

    /** Real Hurgada capture: the cheapest overall IS a hotel/aparthotel ($141), so ignoring the
     *  hostel/guesthouse/motel rows must not change the answer. */
    public function test_jeftino_unchanged_when_the_cheapest_stay_is_already_an_ordinary_one(): void
    {
        $html = $this->sidebar(
            ['HOTEL' => 141, 'APART_HOTEL' => 141, 'HOSTEL' => 151, 'GUEST_HOUSE' => 257, 'MOTEL' => 461, 'VILLA' => 1382],
            ['ANY' => 141, 45 => 190, 40 => 175, 35 => 175],
            140,
        );

        $result = $this->extract($html);

        $this->assertSame(30.0, $result['cheap']);
        $this->assertSame(30.0, $result['quality']);
    }

    public function test_jeftino_falls_back_to_the_overall_floor_when_the_paste_has_no_lodging_rows(): void
    {
        $html = $this->sidebar([], ['ANY' => 141, 40 => 175], 140);

        $result = $this->extract($html);

        $this->assertNull($result['mainstreamFloor']);
        $this->assertSame(30.0, $result['cheap']);
    }

    public function test_extrapolation_skips_a_week_that_is_already_in_the_past(): void
    {
        Carbon::setTestNow('2026-09-24');

        $component = $this->page($this->campaign())
            ->set('startCheapPrice', 90)->set('endCheapPrice', 50)
            ->set('startQualityPrice', 90)->set('endQualityPrice', 70);
        $component = $this->recompute($component, '2026-09-26', '2026-10-31');

        $weeks = collect($component->get('interpolatedWeeks'))->pluck('week');

        // Sep 19 is the season week right before Start, but it's already gone — never written.
        $this->assertFalse($weeks->contains('2026-09-19'));
        $this->assertSame('2026-09-26', $weeks->first());
        $this->assertSame('2026-10-31', $weeks->last());

        Carbon::setTestNow();
    }

    public function test_quality_price_is_never_below_the_cheap_price_even_when_extrapolated(): void
    {
        Carbon::setTestNow('2026-08-31');

        // jeftino 90 -> 50 (slope -8/wk), kvalitet 90 -> 70 (slope -4/wk). Extrapolating one week
        // BEFORE Start (Sep 5, still bookable) pushes jeftino to 100 but kvalitet only to 90.
        $component = $this->page($this->campaign())
            ->set('startCheapPrice', 90)->set('endCheapPrice', 50)
            ->set('startQualityPrice', 90)->set('endQualityPrice', 70);
        $component = $this->recompute($component, '2026-09-12', '2026-10-17');

        $rows = collect($component->get('interpolatedWeeks'));

        $sep5 = $rows->firstWhere('week', '2026-09-05');
        $this->assertSame(100.0, (float) $sep5['cheap']);
        $this->assertSame(100.0, (float) $sep5['quality']);

        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual($row['cheap'], $row['quality'], "kvalitet below jeftino on {$row['week']}");
        }

        Carbon::setTestNow();
    }

    public function test_extrapolated_price_never_drops_below_ten_euro(): void
    {
        Carbon::setTestNow('2026-08-31');

        // slope -20/wk from 40 -> 10 would extrapolate the week after End to -10.
        $component = $this->page($this->campaign())
            ->set('startCheapPrice', 40)->set('endCheapPrice', 10)
            ->set('startQualityPrice', 40)->set('endQualityPrice', 10);
        $component = $this->recompute($component, '2026-09-12', '2026-10-10');

        foreach ($component->get('interpolatedWeeks') as $row) {
            $this->assertGreaterThanOrEqual(10.0, (float) $row['cheap']);
            $this->assertGreaterThanOrEqual(10.0, (float) $row['quality']);
        }

        Carbon::setTestNow();
    }
}
