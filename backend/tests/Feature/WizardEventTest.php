<?php

namespace Tests\Feature;

use App\GraphQL\Resolvers\GeographyResolver;
use App\GraphQL\Resolvers\WizardEventResolver;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Models\WizardEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WizardEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_wizard_event_writes_a_row(): void
    {
        $session = SearchSession::create(['status' => 'in_progress']);

        (new WizardEventResolver)->record(null, [
            'sessionId' => $session->id, 'eventType' => 'step_viewed', 'payload' => ['stepKey' => 'persona'],
        ]);

        $this->assertSame(1, WizardEvent::count());
        $event = WizardEvent::first();
        $this->assertSame((string) $session->id, (string) $event->search_session_id);
        $this->assertSame('step_viewed', $event->event_type);
        $this->assertSame('persona', $event->payload['stepKey']);
    }

    public function test_zero_match_fallback_logs_an_event(): void
    {
        // Same setup as GeographyResolverTest's fallback test — nothing tagged, so the fallback
        // fires (see suggested()'s else branch) and should log it.
        TaxonomyNode::create(['type' => 'country', 'slug' => 'italija', 'label' => 'test', 'sort_order' => 0]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['preference_tags' => ['dobra_hrana']],
        ]);

        (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertSame(1, WizardEvent::where('event_type', 'zero_match_fallback')->count());
    }
}
