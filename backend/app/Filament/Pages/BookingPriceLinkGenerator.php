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

    /** Two-anchor linear interpolation, 2026-08-31 — replaces the earlier flat "+10%" rule, which
     *  broke down hard on real data (Tenerife and Albufeira both came out far off when checked
     *  against real Sep 5 prices). This is grounded in two REAL per-city data points instead of
     *  one point extrapolated by an assumed universal percentage: research just Sep 5 and Oct 24
     *  (skipping the Nov-crossing last week, which both Alanya and Bodrum showed behaving as an
     *  outlier), and every week between gets linearly interpolated and rounded to the nearest €5
     *  independently — naturally reproduces the real plateau-then-step pattern already seen in
     *  Alanya/Bodrum/Albufeira's actual researched data (repeated values wherever the raw step is
     *  under €2.50). Doesn't touch Aug 29 or Oct 31 — both known edge weeks, left to manual
     *  judgment. Still just a calculator until "Save all" is clicked — nothing written until
     *  then, and only for the city already picked in the form above. */
    public ?string $startWeek = null;

    public ?string $endWeek = null;

    public ?float $startPrice = null;

    public ?float $endPrice = null;

    /** @var array<int, array{week: string, label: string, price: float}> */
    public array $interpolatedWeeks = [];

    public function updatedStartWeek(): void
    {
        $this->recomputeInterpolation();
    }

    public function updatedEndWeek(): void
    {
        $this->recomputeInterpolation();
    }

    public function updatedStartPrice(): void
    {
        $this->recomputeInterpolation();
    }

    public function updatedEndPrice(): void
    {
        $this->recomputeInterpolation();
    }

    private function recomputeInterpolation(): void
    {
        $this->interpolatedWeeks = [];

        if (! $this->startWeek || ! $this->endWeek || $this->startPrice === null || $this->endPrice === null) {
            return;
        }

        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();
        if (! $campaign) {
            return;
        }

        $start = Carbon::parse($this->startWeek);
        $end = Carbon::parse($this->endWeek);
        if ($start->gte($end)) {
            return;
        }

        $weeks = $campaign->seasonWeeks()->filter(fn ($w) => $w->gte($start) && $w->lte($end))->values();
        if ($weeks->count() < 2) {
            return;
        }

        $steps = $weeks->count() - 1;
        $this->interpolatedWeeks = $weeks->map(function ($week, $i) use ($steps) {
            $raw = $this->startPrice + ($this->endPrice - $this->startPrice) * ($i / $steps);

            return [
                'week' => $week->toDateString(),
                'label' => $week->format('D, M j'),
                'price' => round($raw / 5) * 5,
            ];
        })->all();
    }

    /**
     * Writes every interpolated week's price for the city picked in the form above — real
     * research still only happened at the two anchor weeks, everything between is a rounded
     * linear estimate (see $interpolatedWeeks' docblock). Same underlying write as savePrice(),
     * just looped.
     */
    public function saveInterpolatedWeeks(): void
    {
        $state = $this->form->getState();
        $node = TaxonomyNode::find($state['taxonomy_node_id'] ?? null);
        if (! $node) {
            Notification::make()->title('Pick a city first')->danger()->send();

            return;
        }

        if (empty($this->interpolatedWeeks)) {
            Notification::make()->title('Nothing to save — set both anchor weeks and prices first')->danger()->send();

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

        foreach ($this->interpolatedWeeks as $row) {
            WizardCampaignDestinationWeeklyPrice::updateOrCreate(
                ['wizard_campaign_destination_price_id' => $destinationPrice->id, 'week_start_date' => $row['week']],
                ['price_per_person_eur' => $row['price']],
            );
        }

        Notification::make()
            ->title(count($this->interpolatedWeeks)." weeks saved for {$node->label}")
            ->success()
            ->send();
    }

    /** Same season-week list as the form's own Week dropdown, plain array for the interpolation
     *  calculator's two anchor <select> elements (plain Livewire properties, not part of the
     *  Filament Form/statePath above, so a real Filament Select component doesn't bind here). */
    public function seasonWeekOptions(): array
    {
        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();

        return $campaign?->seasonWeeks()
            ->mapWithKeys(fn ($week) => [
                $week->toDateString() => $week->format('D, M j').' – '.$week->copy()->addDays(6)->format('M j'),
            ])
            ->all() ?? [];
    }

    /** Non-null weekly-price count per city, for this campaign — one query for the whole
     *  dropdown, not N+1 per city. */
    private function filledWeekCountsByCity(?WizardCampaign $campaign): \Illuminate\Support\Collection
    {
        if (! $campaign) {
            return collect();
        }

        return WizardCampaignDestinationWeeklyPrice::query()
            ->join(
                'wizard_campaign_destination_prices',
                'wizard_campaign_destination_weekly_prices.wizard_campaign_destination_price_id',
                '=',
                'wizard_campaign_destination_prices.id',
            )
            ->where('wizard_campaign_destination_prices.wizard_campaign_id', $campaign->id)
            ->whereNotNull('wizard_campaign_destination_weekly_prices.price_per_person_eur')
            ->selectRaw('wizard_campaign_destination_prices.taxonomy_node_id, count(*) as filled')
            ->groupBy('wizard_campaign_destination_prices.taxonomy_node_id')
            ->pluck('filled', 'taxonomy_node_id');
    }

    /** What's already saved for the city currently picked in the form above — every campaign
     *  week with its current value (or null for a gap), so the owner can see progress and spot
     *  gaps without clicking through each week individually. Owner's ask, 2026-09-01. */
    public function currentWeeklyPricesFor(): array
    {
        $state = $this->form->getState();
        if (empty($state['taxonomy_node_id'])) {
            return [];
        }

        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();
        if (! $campaign) {
            return [];
        }

        $destinationPrice = WizardCampaignDestinationPrice::where('wizard_campaign_id', $campaign->id)
            ->where('taxonomy_node_id', $state['taxonomy_node_id'])
            ->first();

        // Keyed by the cast Carbon date's own toDateString(), not a raw pluck() — pluck() bypasses
        // the model's date cast and returns whatever the DB driver gives back for the column,
        // which isn't guaranteed to match Carbon's format exactly.
        $existing = $destinationPrice
            ? $destinationPrice->weeklyPrices->keyBy(fn ($w) => $w->week_start_date->toDateString())
            : collect();

        return $campaign->seasonWeeks()->map(fn ($week) => [
            'week' => $week->toDateString(),
            'label' => $week->format('D, M j'),
            'price' => $existing->get($week->toDateString())?->price_per_person_eur,
        ])->all();
    }

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
                    // Owner's ask, 2026-08-31: only cities that actually belong to this campaign
                    // (have a WizardCampaignDestinationPrice row for it) — was showing all 78
                    // city nodes in the DB, only 59 of which are actually part of
                    // kasno-letovanje. campaign:seed-destination-price-rows is what creates
                    // these rows, so "has a row" IS "belongs to the campaign" here. Labels get a
                    // "✓ 10/10" / "3/10" progress suffix, 2026-09-01 (owner's ask — track
                    // progress across 59 cities without a separate screen) — see
                    // filledWeekCountsByCity().
                    ->options(function () {
                        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();
                        $totalWeeks = $campaign?->seasonWeeks()->count() ?? 0;
                        $filled = $this->filledWeekCountsByCity($campaign);

                        return TaxonomyNode::where('type', 'city')
                            ->when($campaign, fn ($q) => $q->whereHas(
                                'campaignDestinationPrices',
                                fn ($q) => $q->where('wizard_campaign_id', $campaign->id),
                            ))
                            ->orderBy('label')
                            ->get()
                            ->mapWithKeys(function (TaxonomyNode $city) use ($filled, $totalWeeks) {
                                $count = $filled->get($city->id, 0);
                                $mark = $count >= $totalWeeks && $totalWeeks > 0 ? '✓ ' : '';

                                return [$city->id => "{$mark}{$city->label} ({$count}/{$totalWeeks})"];
                            });
                    })
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
        if (empty($state['taxonomy_node_id'])) {
            Notification::make()->title('Pick a city first')->danger()->send();

            return;
        }

        if (empty($state['week_start_date'])) {
            Notification::make()->title('Pick a week first')->danger()->send();

            return;
        }

        $this->generatedUrl = $this->bookingUrlFor($state['taxonomy_node_id'], $state['week_start_date'], $state['adults'] ?? 1);
        $checkin = Carbon::parse($state['week_start_date']);
        $this->nights = $checkin->diffInDays($checkin->copy()->addDays(6));
    }

    /**
     * Shared URL builder — `generate()` above and the two-anchor calculator's quick-links both
     * need "this city, this week, this group size" turned into the same real Booking search URL.
     * Extracted 2026-08-31 rather than duplicated a second time.
     */
    private function bookingUrlFor(int $taxonomyNodeId, string $weekStartDate, int $adults): ?string
    {
        $node = TaxonomyNode::with('parent')->find($taxonomyNodeId);
        if (! $node) {
            return null;
        }

        $checkin = Carbon::parse($weekStartDate);
        // 7-day package = 6 nights, not 7 — arrival afternoon, departure morning, no night slept
        // on the checkout day. Owner's correction, 2026-08-31.
        $checkout = $checkin->copy()->addDays(6);
        $searchTerm = $node->parent ? "{$node->label}, {$node->parent->label}" : $node->label;

        $params = [
            'ss' => $searchTerm,
            'checkin' => $checkin->toDateString(),
            'checkout' => $checkout->toDateString(),
            'group_adults' => $adults,
            'no_rooms' => 1,
            'selected_currency' => 'EUR',
            'order' => 'price',
            // Booking's "Property type" filter is include-only (checking nothing behaves as
            // "everything"), no dedicated exclude — owner's real capture, 2026-08-31, from a
            // Tenerife results page: 25 of the first 25 cheapest were hostels, drowning out the
            // real comparison prices. First attempt selected every ht_id except Hostels/Capsule
            // hotels, but that made the URL too long and Booking silently dropped/broke on it
            // (owner caught it live — only the first 5 checkboxes actually landed). Narrowed to
            // just the types that matter for family/couple leisure stays: Hotels, Apartments,
            // Villas, Holiday homes, plus the separate "Entire homes & apartments" privacy_type
            // chip — same 5 the broken URL happened to leave checked.
            'nflt' => implode(';', ['ht_id=204', 'ht_id=201', 'ht_id=213', 'ht_id=220', 'privacy_type=3']),
        ];

        return 'https://www.booking.com/searchresults.html?'.http_build_query($params);
    }

    /** Quick-link for the two-anchor calculator's Start/End week selects — same city+group size
     *  already picked in the form above, just this section's own week. Null (renders no link)
     *  until a city and that specific week are both chosen. */
    public function anchorUrlFor(?string $weekStartDate): ?string
    {
        if (! $weekStartDate) {
            return null;
        }

        $state = $this->form->getState();
        if (empty($state['taxonomy_node_id'])) {
            return null;
        }

        return $this->bookingUrlFor($state['taxonomy_node_id'], $weekStartDate, $state['adults'] ?? 1);
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
