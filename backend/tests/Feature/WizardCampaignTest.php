<?php

namespace Tests\Feature;

use App\GraphQL\Resolvers\SearchSessionResolver;
use App\Models\WizardCampaign;
use App\Models\WizardQuestion;
use App\Models\WizardStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the wizard_campaigns mechanism — see wizard_architecture memory, 2026-07-29/30.
 * A campaign is a subset+order over the SAME global wizard_questions, plus preset_answers
 * applied at session start.
 */
class WizardCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_questions_respect_campaign_specific_order(): void
    {
        $step = WizardStep::create(['key' => 'test_step', 'label' => 'test', 'sort_order' => 0]);
        $q1 = $step->questions()->create(['key' => 'q1', 'label' => 'Q1', 'input_type' => 'text', 'sort_order' => 0]);
        $q2 = $step->questions()->create(['key' => 'q2', 'label' => 'Q2', 'input_type' => 'text', 'sort_order' => 1]);

        $campaign = WizardCampaign::create(['key' => 'test-campaign', 'label' => 'test', 'is_active' => true]);
        // Deliberately reversed vs each question's own global sort_order.
        $campaign->questions()->attach($q2->id, ['sort_order' => 0]);
        $campaign->questions()->attach($q1->id, ['sort_order' => 1]);

        $ordered = $campaign->questions()->get();

        $this->assertSame(['q2', 'q1'], $ordered->pluck('key')->all());
    }

    public function test_same_question_can_belong_to_multiple_campaigns(): void
    {
        $step = WizardStep::create(['key' => 'test_step', 'label' => 'test', 'sort_order' => 0]);
        $shared = $step->questions()->create(['key' => 'shared_q', 'label' => 'Q', 'input_type' => 'text', 'sort_order' => 0]);

        $a = WizardCampaign::create(['key' => 'campaign-a', 'label' => 'A', 'is_active' => true]);
        $b = WizardCampaign::create(['key' => 'campaign-b', 'label' => 'B', 'is_active' => true]);
        $a->questions()->attach($shared->id, ['sort_order' => 0]);
        $b->questions()->attach($shared->id, ['sort_order' => 0]);

        $this->assertCount(2, $shared->fresh()->campaigns);
    }

    public function test_start_campaign_session_applies_preset_answers(): void
    {
        WizardCampaign::create([
            'key' => 'kasno-letovanje', 'label' => 'test', 'is_active' => true,
            'preset_answers' => ['termin_category' => 'kasno_kupanje'],
        ]);

        $session = (new SearchSessionResolver)->startCampaign(null, ['campaignKey' => 'kasno-letovanje']);

        $this->assertSame('kasno_kupanje', $session->termin_category);
        $this->assertSame('in_progress', $session->status);
    }

    public function test_start_campaign_session_works_with_no_preset_answers(): void
    {
        WizardCampaign::create(['key' => 'empty-campaign', 'label' => 'test', 'is_active' => true]);

        $session = (new SearchSessionResolver)->startCampaign(null, ['campaignKey' => 'empty-campaign']);

        $this->assertNull($session->termin_category);
    }
}
