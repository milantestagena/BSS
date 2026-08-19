<?php

namespace Tests\Feature;

use App\GraphQL\Resolvers\DestinationGuideResolver;
use App\Models\DestinationGuide;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use App\Models\WizardCampaignDestinationPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers DestinationGuideResolver::show + DestinationGuide's live price computations. */
class DestinationGuideResolverTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $type, string $slug, ?int $parentId = null): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => $type, 'slug' => $slug, 'label' => $slug, 'sort_order' => 0, 'parent_id' => $parentId,
        ]);
    }

    public function test_returns_null_when_session_has_no_campaign(): void
    {
        $city = $this->node('city', 'testgrad');
        $session = SearchSession::create(['status' => 'in_progress']);

        $result = (new DestinationGuideResolver)->show(null, ['sessionId' => $session->id, 'taxonomyNodeId' => $city->id]);

        $this->assertNull($result);
    }

    public function test_returns_null_when_no_guide_row_exists(): void
    {
        $campaign = WizardCampaign::create(['key' => 'test-campaign', 'label' => 'Test']);
        $city = $this->node('city', 'testgrad');
        $session = SearchSession::create(['status' => 'in_progress', 'wizard_campaign_id' => $campaign->id]);

        $result = (new DestinationGuideResolver)->show(null, ['sessionId' => $session->id, 'taxonomyNodeId' => $city->id]);

        $this->assertNull($result);
    }

    public function test_returns_the_matching_guide_row(): void
    {
        $campaign = WizardCampaign::create(['key' => 'test-campaign', 'label' => 'Test']);
        $city = $this->node('city', 'testgrad');
        $guide = DestinationGuide::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id,
            'extra_tips' => ['Bring sunscreen.'],
        ]);
        $session = SearchSession::create(['status' => 'in_progress', 'wizard_campaign_id' => $campaign->id]);

        $result = (new DestinationGuideResolver)->show(null, ['sessionId' => $session->id, 'taxonomyNodeId' => $city->id]);

        $this->assertSame($guide->id, $result->id);
        $this->assertSame(['Bring sunscreen.'], $result->extra_tips);
    }

    public function test_city_level_accommodation_price_reads_the_real_campaign_price(): void
    {
        $campaign = WizardCampaign::create(['key' => 'test-campaign', 'label' => 'Test']);
        $city = $this->node('city', 'testgrad');
        WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => 22.5,
        ]);
        $guide = DestinationGuide::create(['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id]);

        $this->assertSame(22.5, $guide->accommodationPriceEur());
        $this->assertNull($guide->accommodationPriceRangeEur());
    }

    public function test_country_level_accommodation_price_range_aggregates_priced_children(): void
    {
        $campaign = WizardCampaign::create(['key' => 'test-campaign', 'label' => 'Test']);
        $country = $this->node('country', 'testland');
        $cheapCity = $this->node('city', 'cheap', $country->id);
        $priceyCity = $this->node('city', 'pricey', $country->id);
        $unpricedCity = $this->node('city', 'unpriced', $country->id);

        WizardCampaignDestinationPrice::create(['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $cheapCity->id, 'price_per_person_eur' => 10]);
        WizardCampaignDestinationPrice::create(['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $priceyCity->id, 'price_per_person_eur' => 30]);
        WizardCampaignDestinationPrice::create(['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $unpricedCity->id, 'price_per_person_eur' => null]);

        $guide = DestinationGuide::create(['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $country->id]);

        $this->assertSame(['min' => 10.0, 'max' => 30.0], $guide->accommodationPriceRangeEur());
        $this->assertNull($guide->accommodationPriceEur());
    }
}
