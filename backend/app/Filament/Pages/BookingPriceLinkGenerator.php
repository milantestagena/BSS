<?php

namespace App\Filament\Pages;

use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Owner's ask, 2026-08-26: a quick way to open a real, plain Booking.com search link (no CJ
 * tracking — this is research, not a real click-through) for a chosen city/week/group size, to
 * manually read off the 3rd-4th cheapest listing's price — same "researched, not automated"
 * workflow as everything else in this project's price data (CLAUDE.md section 8 item 2). Started
 * as a one-off HTML checklist artifact for 5 sample cities, but the owner wanted real dropdowns
 * over the full live city list instead, same pattern as MealPlanCoefficientCalculator.
 *
 * Occupancy only goes 1-3, not higher — owner's call: a group of 4+ books as two separate
 * apartments/rooms, not a single larger-occupancy search, so there's no real comparison price to
 * read for that case.
 */
class BookingPriceLinkGenerator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Booking Price Link';

    protected static ?string $title = 'Booking Price Link Generator';

    protected static string $view = 'filament.pages.booking-price-link-generator';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $generatedUrl = null;

    public function mount(): void
    {
        $this->form->fill(['adults' => 1]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('taxonomy_node_id')
                    ->label('City')
                    ->options(fn () => TaxonomyNode::where('type', 'city')->orderBy('label')->pluck('label', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->generatedUrl = null),
                Forms\Components\Select::make('week_start_date')
                    ->label('Week')
                    // Same "kasno-letovanje" campaign lookup as WizardCampaignDestinationWeeklyPriceResource's
                    // own week filter — one active campaign, no reason to make this pickable yet.
                    ->options(function () {
                        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();

                        return $campaign?->seasonWeeks()
                            ->mapWithKeys(fn ($week) => [
                                $week->toDateString() => $week->format('D, M j').' – '.$week->copy()->addDays(6)->format('M j'),
                            ])
                            ->all() ?? [];
                    })
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->generatedUrl = null),
                Forms\Components\Select::make('adults')
                    ->label('Group size')
                    ->options([1 => '1 (solo)', 2 => '2', 3 => '3'])
                    ->helperText('4+ books as two separate apartments/rooms, not a single bigger search — no real comparison price to read there.')
                    ->default(1)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->generatedUrl = null),
            ])
            ->statePath('data');
    }

    public function generate(): void
    {
        $state = $this->form->getState();
        $node = TaxonomyNode::with('parent')->find($state['taxonomy_node_id'] ?? null);
        if (! $node) {
            Notification::make()->title('Pick a city first')->danger()->send();

            return;
        }

        if (empty($state['week_start_date'])) {
            Notification::make()->title('Pick a week first')->danger()->send();

            return;
        }

        $checkin = Carbon::parse($state['week_start_date']);
        $checkout = $checkin->copy()->addDays(7);
        $searchTerm = $node->parent ? "{$node->label}, {$node->parent->label}" : $node->label;

        $params = [
            'ss' => $searchTerm,
            'checkin' => $checkin->toDateString(),
            'checkout' => $checkout->toDateString(),
            'group_adults' => $state['adults'],
            'no_rooms' => 1,
            'selected_currency' => 'EUR',
            'order' => 'price',
        ];

        $this->generatedUrl = 'https://www.booking.com/searchresults.html?'.http_build_query($params);
    }
}
