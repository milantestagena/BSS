<?php

namespace App\Filament\Pages;

use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use App\Models\WizardCampaignDestinationPrice;
use App\Models\WizardCampaignDestinationWeeklyPrice;
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

    /** Raw page source pasted from the real Booking results page (Ctrl+U / View Source, or the
     *  results container's outerHTML from dev tools) — owner's ask, 2026-08-26, after a
     *  full-page paste into chat kept getting cut off at the 50k-character message limit before
     *  reaching the 3rd/4th listing (the ones the price convention actually needs). Parsed
     *  server-side instead, no size limit that matters here. Plain Livewire property, not part
     *  of the dropdown form above — unrelated concern, its own "Extract prices" action. */
    public ?string $pastedHtml = null;

    /** @var array<int, array{name: string, price: ?float, pricePerNight: ?float, roomType: ?string, isAnomaly: bool}> */
    public array $extractedListings = [];

    public ?int $nights = null;

    /** Pre-filled from the 3rd clean listing's €/person/night, rounded DOWN to the nearest €5 —
     *  a starting point to eyeball/adjust, not a computed final answer (tried a "~10th percentile
     *  of total market" auto-formula, 2026-08-31, dropped as confusing — this is the simpler
     *  fallback the owner actually wanted). Fully editable before saving. Written to
     *  WizardCampaignDestinationWeeklyPrice.price_per_person_eur — the actual field
     *  WizardCampaignDestinationPrice::estimateAccommodationTotal() reads. */
    public ?float $priceToSaveEur = null;

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
        // 7-day package = 6 nights, not 7 — arrival afternoon, departure morning, no night slept
        // on the checkout day. Owner's correction, 2026-08-31.
        $checkout = $checkin->copy()->addDays(6);
        $this->nights = $checkin->diffInDays($checkout);
        $searchTerm = $node->parent ? "{$node->label}, {$node->parent->label}" : $node->label;

        $params = [
            'ss' => $searchTerm,
            'checkin' => $checkin->toDateString(),
            'checkout' => $checkout->toDateString(),
            'group_adults' => $state['adults'],
            'no_rooms' => 1,
            'selected_currency' => 'EUR',
            'order' => 'price',
            // Booking's "Property type" filter is include-only (checking nothing behaves as
            // "everything"), no dedicated exclude — owner's real capture, 2026-08-31, from a
            // Tenerife results page: 25 of the first 25 cheapest were hostels, drowning out the
            // real comparison prices. So instead every real ht_id EXCEPT Hostels (203) is
            // selected explicitly. Capsule hotels (225) dropped too — same pod/shared-sleep
            // category as hostels, not a real family/couple room.
            'nflt' => implode(';', array_map(
                fn (int $id) => "ht_id={$id}",
                [204, 206, 201, 213, 220, 228, 216, 210, 221, 223, 208, 212, 214, 224, 222, 215],
            )),
        ];

        $this->generatedUrl = 'https://www.booking.com/searchresults.html?'.http_build_query($params);
    }

    /**
     * Parses pasted Booking.com results-page source into an ordered listing table, so the owner
     * can read off the 3rd/4th real price (CLAUDE.md section 8 item 2's convention) without
     * scrolling raw HTML or hitting the chat's 50k-character message truncation. Targets stable
     * `data-testid` attributes (`property-card`, `title`, `price-and-discounted-price`,
     * `recommended-units`'s `<h4>`), not the obfuscated CSS classes Booking ships.
     */
    public function extractPrices(): void
    {
        $this->extractedListings = [];
        $this->priceToSaveEur = null;

        if (trim((string) $this->pastedHtml) === '') {
            Notification::make()->title('Paste the page source first')->danger()->send();

            return;
        }

        $state = $this->form->getState();
        // Nights may already be set from clicking "Generate link" first — recompute from the
        // form's own week either way, so extraction works standalone too.
        if (! empty($state['week_start_date'])) {
            $checkin = Carbon::parse($state['week_start_date']);
            $this->nights = $checkin->diffInDays($checkin->copy()->addDays(6));
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$this->pastedHtml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $cards = $xpath->query('//*[@data-testid="property-card"]');

        foreach ($cards as $card) {
            $nameNode = $xpath->query('.//div[@data-testid="title"]', $card)->item(0);
            $name = $nameNode
                ? trim($nameNode->textContent)
                : trim(str_replace(', Property', '', $card->getAttribute('aria-label')));

            $priceNode = $xpath->query('.//span[@data-testid="price-and-discounted-price"]', $card)->item(0);
            $price = null;
            if ($priceNode) {
                $digits = preg_replace('/[^\d.,]/u', '', $priceNode->textContent) ?? '';
                $digits = str_replace(',', '', $digits);
                $price = $digits !== '' ? (float) $digits : null;
            }

            $roomTypeNode = $xpath->query('.//h4', $card)->item(0);
            $roomType = $roomTypeNode ? trim($roomTypeNode->textContent) : null;
            $isAnomaly = $roomType !== null && preg_match('/dorm|shared|hostel bed/i', $roomType) === 1;

            if ($name === '' && $price === null) {
                continue;
            }

            $this->extractedListings[] = [
                'name' => $name !== '' ? $name : '(unknown)',
                'price' => $price,
                'pricePerNight' => ($price !== null && $this->nights) ? round($price / $this->nights, 2) : null,
                'roomType' => $roomType,
                'isAnomaly' => $isAnomaly,
            ];
        }

        if (empty($this->extractedListings)) {
            Notification::make()
                ->title('No property cards found')
                ->body('Paste the full page source (Ctrl+U / View Source) of the Booking results page, not a screenshot or partial copy.')
                ->danger()
                ->send();

            return;
        }

        // Booking's own order=price sort is occasionally out of order on the actual page (owner's
        // observation, 2026-08-31) — re-sort here rather than trust DOM order, so the #rank shown
        // and the 3rd-clean-listing default below are always correct regardless.
        $this->extractedListings = collect($this->extractedListings)
            ->sortBy(fn (array $l) => $l['price'] ?? PHP_FLOAT_MAX)
            ->values()
            ->all();

        // Prefill from the 3rd clean (non-anomaly, priced) listing, rounded DOWN to the nearest
        // €5 — a starting point to eyeball/adjust, not a computed final answer (owner's ask,
        // 2026-08-31: "odokativno", no auto margin this time, just a round number to start from).
        $clean = collect($this->extractedListings)
            ->filter(fn (array $l) => ! $l['isAnomaly'] && $l['pricePerNight'] !== null)
            ->values();
        $reference = $clean->get(2) ?? $clean->get(1) ?? $clean->first();
        if ($reference) {
            $adults = max(1, (int) ($state['adults'] ?? 1));
            $perPersonPerNight = $reference['pricePerNight'] / $adults;
            $this->priceToSaveEur = floor($perPersonPerNight / 5) * 5;
        }
    }

    /**
     * Writes the owner-typed €/person/night value into WizardCampaignDestinationWeeklyPrice,
     * for the same city+week picked above — the actual field
     * WizardCampaignDestinationPrice::estimateAccommodationTotal() reads (per-person, per-night;
     * see that model).
     */
    public function savePrice(): void
    {
        $state = $this->form->getState();
        $node = TaxonomyNode::find($state['taxonomy_node_id'] ?? null);
        if (! $node) {
            Notification::make()->title('Pick a city first')->danger()->send();

            return;
        }

        if (empty($state['week_start_date'])) {
            Notification::make()->title('Pick a week first')->danger()->send();

            return;
        }

        if ($this->priceToSaveEur === null || $this->priceToSaveEur <= 0) {
            Notification::make()->title('Enter a price to save first')->danger()->send();

            return;
        }

        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();
        if (! $campaign) {
            Notification::make()->title('No active campaign found')->danger()->send();

            return;
        }

        $destinationPrice = WizardCampaignDestinationPrice::firstOrCreate(
            ['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $node->id],
            ['source' => 'manual_research'],
        );

        WizardCampaignDestinationWeeklyPrice::updateOrCreate(
            ['wizard_campaign_destination_price_id' => $destinationPrice->id, 'week_start_date' => $state['week_start_date']],
            ['price_per_person_eur' => $this->priceToSaveEur],
        );

        Notification::make()
            ->title("Saved €{$this->priceToSaveEur}/person/night for {$node->label}")
            ->success()
            ->send();
    }
}
