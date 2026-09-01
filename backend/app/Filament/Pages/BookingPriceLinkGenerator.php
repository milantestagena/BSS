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
use Illuminate\Support\Collection;

/**
 * Owner's ask, 2026-08-26: a quick way to open a real, plain Booking.com search link (no CJ
 * tracking — this is research, not a real click-through) for a chosen city, to manually read off
 * the 3rd-4th cheapest listing's price — same "researched, not automated" workflow as everything
 * else in this project's price data (CLAUDE.md section 8 item 2). Started as a one-off HTML
 * checklist artifact for 5 sample cities, then real dropdowns over the full live city list, then
 * (2026-09-01, this redesign) a fixed two-anchor workflow — every prior single-week/adjustable-
 * group-size version got replaced once the two-anchor interpolation approach (see
 * $interpolatedWeeks' docblock) became the standard way every city gets priced: exactly two real
 * searches per city (Sep 5, Oct 24 — the pair validated against Alanya/Bodrum/Albufeira/Tenerife),
 * always at group size 2, everything else derived.
 */
class BookingPriceLinkGenerator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Booking Price Link';

    protected static ?string $title = 'Booking Price Link Generator';

    protected static string $view = 'filament.pages.booking-price-link-generator';

    /** The two fixed research weeks, owner's decision 2026-09-01 — every city gets researched at
     *  exactly these two, never a picked/varying week anymore. Sep 5 is the season's real start;
     *  Oct 24 (not the literal last week) skips the Nov-crossing week both Alanya and Bodrum
     *  showed behaving as an outlier — see $interpolatedWeeks' docblock. */
    private const ANCHOR_START_WEEK = '2026-09-05';

    private const ANCHOR_END_WEEK = '2026-10-24';

    /** 7-day package = 6 nights, not 7 — arrival afternoon, departure morning, no night slept on
     *  the checkout day (owner's correction, 2026-08-31). Always the same for every search now
     *  that every research week is a full campaign week. */
    private const NIGHTS = 6;

    /** Always 2 — owner's decision 2026-09-01: the group-size picker is gone, every research
     *  search is done as a couple. Occupancy scaling for 1/3/4/5 travelers is handled entirely
     *  by WizardCampaignDestinationPrice::roomMultiplierSumFor() at query time, not by
     *  re-researching at different group sizes. */
    private const ADULTS = 2;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Raw page source pasted for the Sep 5 / Oct 24 searches — two separate boxes now (was one,
     *  tied to whichever single week was selected) so both anchors get extracted in one submit.
     *  Owner's ask, 2026-09-01, after the flat "+10%" rule (one search, one formula) broke down
     *  hard on real data — see $interpolatedWeeks' docblock for the full story. */
    public ?string $pastedHtmlStart = null;

    public ?string $pastedHtmlEnd = null;

    /** @var array<int, array{name: string, price: ?float, pricePerNight: ?float, roomType: ?string, isAnomaly: bool}> */
    public array $extractedListingsStart = [];

    /** @var array<int, array{name: string, price: ?float, pricePerNight: ?float, roomType: ?string, isAnomaly: bool}> */
    public array $extractedListingsEnd = [];

    /**
     * Two-anchor linear interpolation, 2026-08-31 — replaced an earlier flat "+10%" rule, which
     * broke down hard on real data (Tenerife and Albufeira both came out far off when checked
     * against real Sep 5 prices). Grounded in two REAL per-city data points instead of one point
     * extrapolated by an assumed universal percentage: research Sep 5 and Oct 24 (skipping the
     * Nov-crossing last week, which both Alanya and Bodrum showed behaving as an outlier — Alanya
     * dropped further, Bodrum rose), and every week between gets linearly interpolated and
     * rounded to the nearest €5 independently — naturally reproduces the real plateau-then-step
     * pattern already seen in Alanya/Bodrum/Albufeira's actual researched data (repeated values
     * wherever the raw step is under €2.50). The one real week past End (Oct 31 for the current
     * anchors) gets extrapolated one more step at the same slope, 2026-09-01 (owner's catch,
     * Antalya: "nisi iskopirao predzadnju u zadnju") — a flat last step naturally extrapolates
     * flat, a sloped one continues the trend, no special-casing needed. Doesn't touch Aug 29 —
     * already past by the time this campaign matters, and covered by
     * WizardCampaignDestinationPrice's own nearest-priced-neighbor fallback regardless. As of
     * 2026-09-01 this is THE workflow (was one of several) — $startWeek/$endWeek default to the
     * two anchors above and stay that way in practice, kept as real properties (not hardcoded
     * into the save) only so a future season's different anchor pair doesn't need a code change.
     */
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
        $slope = ($this->endPrice - $this->startPrice) / $steps;

        $this->interpolatedWeeks = $weeks->map(function ($week, $i) use ($slope) {
            $raw = $this->startPrice + $slope * $i;

            return [
                'week' => $week->toDateString(),
                'label' => $week->format('D, M j'),
                'price' => round($raw / 5) * 5,
            ];
        })->all();

        // Extrapolate exactly one more week past End, same per-week slope as the interpolation
        // itself — owner's ask, 2026-09-01: the season's real last week (e.g. Oct 31) is
        // deliberately never one of the two research anchors (known outlier for Alanya/Bodrum),
        // so it needs covering too rather than left for the runtime's nearest-neighbor fallback
        // alone. A flat last step naturally extrapolates flat (slope 0); a sloped one continues
        // the trend one more step — same rule either way, no special-casing.
        $afterEnd = $campaign->seasonWeeks()->first(fn ($w) => $w->gt($end));
        if ($afterEnd) {
            $raw = $this->endPrice + $slope;
            $this->interpolatedWeeks[] = [
                'week' => $afterEnd->toDateString(),
                'label' => $afterEnd->format('D, M j'),
                'price' => round($raw / 5) * 5,
            ];
        }
    }

    /**
     * Writes every interpolated week's price for the city picked in the form above — real
     * research still only happened at the two anchor weeks, everything between is a rounded
     * linear estimate (see $interpolatedWeeks' docblock).
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

    /** Same season-week list as the interpolation calculator's two anchor <select> elements
     *  (plain Livewire properties, not part of the Filament Form/statePath above, so a real
     *  Filament Select component doesn't bind here). */
    public function seasonWeekOptions(): array
    {
        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();

        return $campaign?->seasonWeeks()
            ->mapWithKeys(fn ($week) => [
                $week->toDateString() => $week->format('D, M j').' – '.$week->copy()->addDays(6)->format('M j'),
            ])
            ->all() ?? [];
    }

    /** Season weeks that haven't already started — owner's catch, 2026-09-01: a past week (e.g.
     *  Aug 29 once it's September) can't be booked anymore, so it shouldn't count toward
     *  progress or show up as a gap to fill. Shared by the dropdown's N/total count and the
     *  current-prices strip so both agree on the same denominator. */
    private function futureSeasonWeeks(WizardCampaign $campaign): Collection
    {
        $today = Carbon::today();

        return $campaign->seasonWeeks()->filter(fn ($week) => $week->gte($today))->values();
    }

    /** Non-null weekly-price count per city, for this campaign's future weeks only — one query
     *  for the whole dropdown, not N+1 per city. */
    private function filledWeekCountsByCity(?WizardCampaign $campaign): Collection
    {
        if (! $campaign) {
            return collect();
        }

        $futureWeekDates = $this->futureSeasonWeeks($campaign)->map->toDateString()->all();

        return WizardCampaignDestinationWeeklyPrice::query()
            ->join(
                'wizard_campaign_destination_prices',
                'wizard_campaign_destination_weekly_prices.wizard_campaign_destination_price_id',
                '=',
                'wizard_campaign_destination_prices.id',
            )
            ->where('wizard_campaign_destination_prices.wizard_campaign_id', $campaign->id)
            ->whereNotNull('wizard_campaign_destination_weekly_prices.price_per_person_eur')
            ->whereIn('wizard_campaign_destination_weekly_prices.week_start_date', $futureWeekDates)
            ->selectRaw('wizard_campaign_destination_prices.taxonomy_node_id, count(*) as filled')
            ->groupBy('wizard_campaign_destination_prices.taxonomy_node_id')
            ->pluck('filled', 'taxonomy_node_id');
    }

    /** What's already saved for the city currently picked in the form above, future weeks only —
     *  every remaining campaign week with its current value (or null for a gap), so the owner
     *  can see progress and spot gaps without clicking through each week individually. Also
     *  re-rendered directly below the Save button (owner's ask, 2026-09-01) so a save's result is
     *  visible immediately without scrolling back to the top. */
    public function currentWeeklyPricesFor(): array
    {
        // $this->data directly, NOT $this->form->getState() — getState() runs full form
        // validation, which throws a 500 the instant a city is picked but the form isn't fully
        // valid yet (real bug caught live, 2026-09-01). This is a read-only display helper, it
        // only needs the raw value.
        $taxonomyNodeId = $this->data['taxonomy_node_id'] ?? null;
        if (empty($taxonomyNodeId)) {
            return [];
        }

        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();
        if (! $campaign) {
            return [];
        }

        $destinationPrice = WizardCampaignDestinationPrice::where('wizard_campaign_id', $campaign->id)
            ->where('taxonomy_node_id', $taxonomyNodeId)
            ->first();

        // Keyed by the cast Carbon date's own toDateString(), not a raw pluck() — pluck() bypasses
        // the model's date cast and returns whatever the DB driver gives back for the column,
        // which isn't guaranteed to match Carbon's format exactly.
        $existing = $destinationPrice
            ? $destinationPrice->weeklyPrices->keyBy(fn ($w) => $w->week_start_date->toDateString())
            : collect();

        return $this->futureSeasonWeeks($campaign)->map(fn ($week) => [
            'week' => $week->toDateString(),
            'label' => $week->format('D, M j'),
            'price' => $existing->get($week->toDateString())?->price_per_person_eur,
        ])->all();
    }

    public function mount(): void
    {
        $this->form->fill();
        $this->startWeek = self::ANCHOR_START_WEEK;
        $this->endWeek = self::ANCHOR_END_WEEK;
    }

    /** Country ids the live wizard never actually shows for this campaign — same
     *  taxonomy_node_relations `excludes` edge GeographyResolver reads (termin_category
     *  'kasno_kupanje' excludes 'hrvatska': "najhladnija, nikad nije dobila prave cene", see
     *  CLAUDE.md §8). Owner's catch, 2026-09-01: Croatia's 3 cities still have real
     *  WizardCampaignDestinationPrice rows left over from before that exclusion, so the "has a
     *  price row" filter alone let them leak into the dropdown even though no real visitor will
     *  ever see them — researching their prices further would be wasted time. */
    private function excludedCountryIds(): \Illuminate\Support\Collection
    {
        return TaxonomyNode::where('type', 'termin_category')
            ->where('slug', 'kasno_kupanje')
            ->first()
            ?->excludes()->pluck('taxonomy_nodes.id') ?? collect();
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
                    // "✓ 9/9" / "3/9" progress suffix, 2026-09-01 (owner's ask — track progress
                    // across 59 cities without a separate screen) — see filledWeekCountsByCity().
                    // Also excludes cities whose country the live wizard itself excludes (Croatia,
                    // caught live 2026-09-01) — see excludedCountryIds().
                    ->options(function () {
                        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();
                        $totalWeeks = $campaign ? $this->futureSeasonWeeks($campaign)->count() : 0;
                        $filled = $this->filledWeekCountsByCity($campaign);
                        $excludedCountryIds = $this->excludedCountryIds();

                        return TaxonomyNode::where('type', 'city')
                            ->when($campaign, fn ($q) => $q->whereHas(
                                'campaignDestinationPrices',
                                fn ($q) => $q->where('wizard_campaign_id', $campaign->id),
                            ))
                            ->whereNotIn('parent_id', $excludedCountryIds)
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
                    ->live(),
            ])
            ->statePath('data');
    }

    /**
     * Shared URL builder — always Sep 5 / Oct 24, always group size 2 (see ADULTS/ANCHOR_*
     * constants). Extracted 2026-08-31 back when week/group were still pickable; kept as its own
     * method since the two quick-links (startWeekUrl/endWeekUrl) both need it.
     */
    private function bookingUrlFor(int $taxonomyNodeId, string $weekStartDate): ?string
    {
        $node = TaxonomyNode::with('parent')->find($taxonomyNodeId);
        if (! $node) {
            return null;
        }

        $checkin = Carbon::parse($weekStartDate);
        $checkout = $checkin->copy()->addDays(self::NIGHTS);
        $searchTerm = $node->parent ? "{$node->label}, {$node->parent->label}" : $node->label;

        $params = [
            'ss' => $searchTerm,
            'checkin' => $checkin->toDateString(),
            'checkout' => $checkout->toDateString(),
            'group_adults' => self::ADULTS,
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

    /** Quick-link for the currently picked city + Sep 5. Null (renders no link) until a city is
     *  chosen. */
    public function startWeekUrl(): ?string
    {
        $taxonomyNodeId = $this->data['taxonomy_node_id'] ?? null;

        return $taxonomyNodeId ? $this->bookingUrlFor($taxonomyNodeId, self::ANCHOR_START_WEEK) : null;
    }

    /** Same as startWeekUrl(), for Oct 24. */
    public function endWeekUrl(): ?string
    {
        $taxonomyNodeId = $this->data['taxonomy_node_id'] ?? null;

        return $taxonomyNodeId ? $this->bookingUrlFor($taxonomyNodeId, self::ANCHOR_END_WEEK) : null;
    }

    /**
     * Parses one pasted Booking.com results-page source into an ordered listing table, so the
     * owner can read off the 3rd/4th real price (CLAUDE.md section 8 item 2's convention)
     * without scrolling raw HTML or hitting the chat's 50k-character message truncation. Targets
     * stable `data-testid` attributes (`property-card`, `title`, `price-and-discounted-price`,
     * `recommended-units`'s `<h4>`), not the obfuscated CSS classes Booking ships.
     *
     * Doesn't yet separate out city/tourist tax shown alongside the headline price on some
     * listings — owner's note, 2026-09-01: improve this parser once that's common enough to
     * actually distort a reference price, not before.
     *
     * @return array<int, array{name: string, price: ?float, pricePerNight: ?float, roomType: ?string, isAnomaly: bool}>
     */
    private function parseListings(?string $html): array
    {
        if (trim((string) $html) === '') {
            return [];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $cards = $xpath->query('//*[@data-testid="property-card"]');

        $listings = [];
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

            $listings[] = [
                'name' => $name !== '' ? $name : '(unknown)',
                'price' => $price,
                'pricePerNight' => $price !== null ? round($price / self::NIGHTS, 2) : null,
                'roomType' => $roomType,
                'isAnomaly' => $isAnomaly,
            ];
        }

        // Booking's own order=price sort is occasionally out of order on the actual page (owner's
        // observation, 2026-08-31) — re-sort here rather than trust DOM order, so the #rank shown
        // and the 3rd-clean-listing suggestion below are always correct regardless.
        return collect($listings)
            ->sortBy(fn (array $l) => $l['price'] ?? PHP_FLOAT_MAX)
            ->values()
            ->all();
    }

    /** 3rd clean (non-anomaly, priced) listing's €/person/night, rounded DOWN to the nearest €5 —
     *  a starting point to eyeball/adjust, not a computed final answer (owner's ask, 2026-08-31:
     *  "odokativno", no auto margin). Null when there aren't enough clean listings to reference. */
    private function suggestedPriceFrom(array $listings): ?float
    {
        $clean = collect($listings)
            ->filter(fn (array $l) => ! $l['isAnomaly'] && $l['pricePerNight'] !== null)
            ->values();
        $reference = $clean->get(2) ?? $clean->get(1) ?? $clean->first();
        if (! $reference) {
            return null;
        }

        return floor(($reference['pricePerNight'] / self::ADULTS) / 5) * 5;
    }

    /**
     * Parses both pasted page sources at once — Sep 5 and Oct 24 — and pre-fills the
     * interpolation calculator's Start/End price from each table's own 3rd-clean-listing
     * suggestion. Owner's redesign, 2026-09-01: was one paste box tied to one picked week: now
     * both anchors get researched and extracted together, directly feeding the interpolation
     * step below instead of a separate single-week save.
     */
    public function extractPrices(): void
    {
        $this->extractedListingsStart = $this->parseListings($this->pastedHtmlStart);
        $this->extractedListingsEnd = $this->parseListings($this->pastedHtmlEnd);

        if (empty($this->extractedListingsStart) && empty($this->extractedListingsEnd)) {
            Notification::make()
                ->title('No property cards found')
                ->body('Paste the full page source (Ctrl+U / View Source) of both Booking results pages, not a screenshot or partial copy.')
                ->danger()
                ->send();

            return;
        }

        if ($suggested = $this->suggestedPriceFrom($this->extractedListingsStart)) {
            $this->startPrice = $suggested;
        }
        if ($suggested = $this->suggestedPriceFrom($this->extractedListingsEnd)) {
            $this->endPrice = $suggested;
        }

        $this->recomputeInterpolation();
    }
}
