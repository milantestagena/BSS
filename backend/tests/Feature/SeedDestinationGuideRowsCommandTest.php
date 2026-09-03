<?php

namespace Tests\Feature;

use App\Models\DestinationGuide;
use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use App\Models\WizardCampaignDestinationPrice;
use App\Models\WizardCampaignDestinationWeeklyPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers campaign:seed-destination-guide-rows — the "only already-priced destinations
 *  qualify" selection rule, and idempotency. */
class SeedDestinationGuideRowsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $type, string $slug, ?int $parentId = null, ?array $meta = null): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => $type, 'slug' => $slug, 'label' => $slug, 'sort_order' => 0,
            'parent_id' => $parentId, 'meta' => $meta,
        ]);
    }

    public function test_excludes_a_city_with_vibe_profile_but_no_real_price(): void
    {
        $campaign = WizardCampaign::create(['key' => 'kasno-letovanje', 'label' => 'Test']);
        $city = $this->node('city', 'unpriced', null, ['vibe_profile' => ['description' => 'test']]);

        $this->artisan('campaign:seed-destination-guide-rows', ['campaignKey' => 'kasno-letovanje']);

        $this->assertDatabaseMissing('destination_guides', ['taxonomy_node_id' => $city->id]);
    }

    public function test_includes_a_city_with_vibe_profile_and_a_real_price(): void
    {
        $campaign = WizardCampaign::create(['key' => 'kasno-letovanje', 'label' => 'Test']);
        $city = $this->node('city', 'priced', null, ['vibe_profile' => ['description' => 'test']]);
        WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => 15,
        ]);

        $this->artisan('campaign:seed-destination-guide-rows', ['campaignKey' => 'kasno-letovanje']);

        $this->assertDatabaseHas('destination_guides', ['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id]);
    }

    public function test_includes_a_country_whose_only_child_city_is_priced(): void
    {
        $campaign = WizardCampaign::create(['key' => 'kasno-letovanje', 'label' => 'Test']);
        $country = $this->node('country', 'landia', null, ['vibe_profile' => ['description' => 'test']]);
        $city = $this->node('city', 'priced2', $country->id, ['vibe_profile' => ['description' => 'test']]);
        WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => 15,
        ]);

        $this->artisan('campaign:seed-destination-guide-rows', ['campaignKey' => 'kasno-letovanje']);

        $this->assertDatabaseHas('destination_guides', ['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $country->id]);
    }

    /** Bug fixed 2026-09-03 (owner caught it live: Cape Verde had no guide rows at all) — a
     *  weekly-only price (no flat price_per_person_eur, real data lives entirely in
     *  WizardCampaignDestinationWeeklyPrice) must qualify exactly like a flat one. Cape Verde's
     *  two cities were the only destinations in this shape when the gap was found. */
    public function test_includes_a_city_priced_only_via_weekly_rows(): void
    {
        $campaign = WizardCampaign::create(['key' => 'kasno-letovanje', 'label' => 'Test']);
        $city = $this->node('city', 'weeklyonly', null, ['vibe_profile' => ['description' => 'test']]);
        $price = WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => null,
        ]);
        WizardCampaignDestinationWeeklyPrice::create([
            'wizard_campaign_destination_price_id' => $price->id, 'week_start_date' => '2026-09-05', 'price_per_person_eur' => 65,
        ]);

        $this->artisan('campaign:seed-destination-guide-rows', ['campaignKey' => 'kasno-letovanje']);

        $this->assertDatabaseHas('destination_guides', ['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id]);
    }

    public function test_running_twice_does_not_create_duplicates(): void
    {
        $campaign = WizardCampaign::create(['key' => 'kasno-letovanje', 'label' => 'Test']);
        $city = $this->node('city', 'priced3', null, ['vibe_profile' => ['description' => 'test']]);
        WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => 15,
        ]);

        $this->artisan('campaign:seed-destination-guide-rows', ['campaignKey' => 'kasno-letovanje']);
        $this->artisan('campaign:seed-destination-guide-rows', ['campaignKey' => 'kasno-letovanje']);

        $this->assertSame(1, DestinationGuide::where('taxonomy_node_id', $city->id)->count());
    }
}
