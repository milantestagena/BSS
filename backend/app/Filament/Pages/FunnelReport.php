<?php

namespace App\Filament\Pages;

use App\Models\WizardEvent;
use App\Models\WizardStep;
use Filament\Pages\Page;

/**
 * Owner's ask, 2026-09-05 ("da imamo log za svakog dokle je stigo pa odustao") — a real
 * drop-off funnel, not just a raw page-visit or clickthrough count. Reads off wizard_events'
 * existing step_viewed log (fired on every step render since 2026-08-13, see
 * WizardService.recordEvent call sites) plus the new booking_redirect event (added same day as
 * this page, fired at the actual Booking.com redirect — see Wizard.selectResultsCity). Counts
 * are DISTINCT sessions per step, not raw events — a session revisiting a step (Back button)
 * must not inflate its count.
 *
 * Deliberately NOT a strict monotonic funnel: campaigns preset/skip some steps (e.g.
 * trip_type/termin_category are often preset, never rendered at all), so a later step can
 * legitimately show a HIGHER count than an earlier one if the earlier step is commonly skipped
 * — this is real branching behavior, not a bug, and forcing an artificial decreasing order would
 * misrepresent it. Ordered by wizard_steps.sort_order (the one canonical step order — no
 * per-campaign step reordering exists today, only questions get that).
 */
class FunnelReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationLabel = 'Funnel Report';

    protected static string $view = 'filament.pages.funnel-report';

    public array $rows = [];

    public function mount(): void
    {
        $steps = WizardStep::where('is_active', true)->orderBy('sort_order')->get(['key', 'label']);

        $rows = [];
        foreach ($steps as $step) {
            $rows[] = [
                'label' => $step->label,
                'count' => WizardEvent::where('event_type', 'step_viewed')
                    ->where('payload->stepKey', $step->key)
                    ->distinct('search_session_id')
                    ->count('search_session_id'),
            ];
        }

        $rows[] = [
            'label' => 'Reached Booking.com',
            'count' => WizardEvent::where('event_type', 'booking_redirect')
                ->distinct('search_session_id')
                ->count('search_session_id'),
        ];

        $max = max(array_column($rows, 'count')) ?: 1;
        foreach ($rows as &$row) {
            $row['percent'] = round($row['count'] / $max * 100);
        }

        $this->rows = $rows;
    }
}
