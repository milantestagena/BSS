<?php

namespace Tests\Feature;

use App\Filament\Pages\FunnelReport;
use App\Models\User;
use App\Models\WizardEvent;
use App\Models\WizardStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FunnelReportTest extends TestCase
{
    use RefreshDatabase;

    private function event(int $sessionId, string $type, array $payload = []): void
    {
        WizardEvent::create(['search_session_id' => $sessionId, 'event_type' => $type, 'payload' => $payload ?: null]);
    }

    /** 2026-09-25: the ad campaign delivered visits but zero answered even the first question, and
     *  step_viewed alone couldn't say whether people bounced or got stuck. */
    public function test_report_separates_touching_the_form_from_finishing_the_first_step(): void
    {
        WizardStep::create(['key' => 'travelers', 'label' => 'Who is traveling', 'sort_order' => 1, 'is_active' => true]);
        WizardStep::create(['key' => 'timing', 'label' => 'When', 'sort_order' => 2, 'is_active' => true]);

        foreach ([1, 2, 3, 4] as $session) {
            $this->event($session, 'step_viewed', ['stepKey' => 'travelers']);
        }
        // Sessions 1 and 2 touched something; only session 1 finished the step; session 1 also reached the redirect.
        $this->event(1, 'first_interaction', ['stepKey' => 'travelers', 'source' => 'travelers']);
        $this->event(2, 'first_interaction', ['stepKey' => 'travelers', 'source' => 'travelers']);
        $this->event(1, 'step_completed', ['stepKey' => 'travelers']);
        $this->event(1, 'step_viewed', ['stepKey' => 'timing']);
        $this->event(1, 'booking_redirect', ['destination' => 'Skiathos']);
        // A repeat event for the same session must not inflate a distinct-session count.
        $this->event(1, 'step_completed', ['stepKey' => 'travelers']);
        // Completing a LATER step must not count as finishing the first one.
        $this->event(3, 'step_completed', ['stepKey' => 'timing']);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rows = collect(Livewire::test(FunnelReport::class)->get('rows'))->pluck('count', 'label');

        $this->assertSame(4, $rows['Who is traveling']);
        $this->assertSame(2, $rows['↳ Touched something (first interaction)']);
        $this->assertSame(1, $rows['↳ Finished the first step']);
        $this->assertSame(1, $rows['When']);
        $this->assertSame(1, $rows['Reached Hotels.com']);
    }

    public function test_the_two_new_rows_sit_directly_under_the_first_step(): void
    {
        WizardStep::create(['key' => 'travelers', 'label' => 'Who is traveling', 'sort_order' => 1, 'is_active' => true]);
        WizardStep::create(['key' => 'timing', 'label' => 'When', 'sort_order' => 2, 'is_active' => true]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $labels = collect(Livewire::test(FunnelReport::class)->get('rows'))->pluck('label')->all();

        $this->assertSame(
            ['Who is traveling', '↳ Touched something (first interaction)', '↳ Finished the first step', 'When', 'Reached Hotels.com'],
            $labels,
        );
    }
}
