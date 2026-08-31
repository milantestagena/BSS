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

    public ?float $suggestedPrice = null;

    public ?int $nights = null;

    /** The editable value that actually gets written to
     *  WizardCampaignDestinationWeeklyPrice.price_per_person_eur — pre-filled from
     *  $suggestedPrice/nights/group size after extraction, but left editable since that field is
     *  explicitly a human sanity-check, same as the reference price itself. */
    public ?float $priceToSaveEur = null;

    /** "N properties found" read off the results page header, typed in by the owner — used to
     *  pick the reference listing at the ~10th percentile of the WHOLE market instead of a fixed
     *  rank, since only the cheapest 25 (one page) are ever visible in the pasted source. Owner's
     *  call, 2026-08-31: fixed rank 3 was really "3rd of 25 visible", which is a very different
     *  percentile depending on whether the city has 30 or 1500 hotels total. Once 10% of the
     *  total exceeds the 25 visible, the last (25th) visible one is the best available stand-in
     *  — still the cheapest ~10%, just can't see further into the list than one page. */
    public ?int $totalHotelsFound = null;

    /** 1-based rank actually used for $suggestedPrice, surfaced in the UI so the owner can see
     *  which percentile they're looking at (e.g. "#25 of 24 clean — 264 total found"). */
    public ?int $referenceRank = null;

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
        $this->suggestedPrice = null;
        $this->priceToSaveEur = null;
        $this->referenceRank = null;

        if (trim((string) $this->pastedHtml) === '') {
            Notification::make()->title('Paste the page source first')->danger()->send();

            return;
        }

        $state = $this->form->getState();
        // Nights may already be set from clicking "Generate link" first — recompute from the
        // form's own week either way, so extraction works standalone too.
        if (! empty($state['week_start_date'])) {
            $checkin = Carbon::parse($state['week_start_date']);
            $this->nights = $checkin->diffInDays($checkin->copy()->addDays(7));
        }
        $adults = (int) ($state['adults'] ?? 1);

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

        // Cards arrive in the page's own order=price order (cheapest first), but only one page
        // (25) is ever visible in pasted source. Target the ~10th-percentile rank across the
        // WHOLE market (owner's ask, 2026-08-31) rather than a fixed "3rd" — "3rd of 25 visible"
        // means something very different for a 30-hotel town than a 1500-hotel city. Capped at
        // 25 since that's the most we can ever see; past ~250 total hotels the cap just means
        // "last visible", still genuinely the cheapest ~10%, just page-limited.
        $clean = collect($this->extractedListings)
            ->filter(fn (array $l) => ! $l['isAnomaly'] && $l['price'] !== null)
            ->values();

        $targetRank = $this->totalHotelsFound && $this->totalHotelsFound > 0
            ? max(1, min(25, (int) round($this->totalHotelsFound * 0.10)))
            : 3; // no total given — fall back to the original "3rd clean listing" default

        $reference = $clean->get($targetRank - 1) ?? $clean->last();

        if ($reference) {
            $this->referenceRank = min($targetRank, $clean->count());
            // Small safety margin above the reference, same convention as the manual capture
            // workflow (CLAUDE.md section 8 item 2, after the Rhodes incident) — a suggestion to
            // sanity-check by eye, not an authoritative figure.
            $this->suggestedPrice = round($reference['price'] * 1.1, 2);

            if ($this->nights && $adults) {
                $this->priceToSaveEur = round($this->suggestedPrice / $this->nights / $adults, 2);
            }
        }
    }

    /**
     * Writes the owner-confirmed €/person/night value into
     * WizardCampaignDestinationWeeklyPrice, for the same city+week picked above — the actual
     * field WizardCampaignDestinationPrice::estimateAccommodationTotal() reads (per-person,
     * per-night; see that model). $priceToSaveEur is pre-filled from the extraction above but
     * stays editable — same "sanity-check by eye before storing" convention as the reference
     * price itself.
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
