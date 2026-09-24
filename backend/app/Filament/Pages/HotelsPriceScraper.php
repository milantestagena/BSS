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
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Owner's ask, 2026-09-09: a real parser, not a manual "read 3rd-4th listing" workflow like
 * BookingPriceLinkGenerator (Hotels.com's own filter sidebar already shows a "From $X" price next
 * to EVERY guestRating option — no ordering/filtering/mental math needed, the number is already
 * sitting there). One paste per (city, week) — unlike Booking's two-anchor interpolation, real
 * Hotels.com prices get entered for every week directly (owner's call: "ceo opseg... za jjeftine i
 * za kvalitetne", 2026-09-09), so there's no interpolation math here at all.
 *
 * Reads the "Guest rating" fieldset (name="guestRating") as the primary source — owner's explicit
 * pick, 2026-09-09 ("ako pise 122 za rating 8"), even though the live wizard's own kvalitet filter
 * (toHotelsUrl()) sends star=40 AND guestRating=40 together. The "ANY" row feeds jeftino, "40"
 * ("Very good 8+") feeds kvalitet.
 *
 * Extended 2026-09-23, after a live kasno-letovanje spot-check (Hurgada/Rodos) surfaced two real
 * gaps: (1) this page was hardcoded to zimsko-sunce only, even though kasno-letovanje has been on
 * `provider: 'hotels_com'` since 2026-09-18 and had no scraper tool of its own — a
 * "read the sidebar's Property type/Stay options toggle" manual comparison against Hurgada gave a
 * misleadingly high per-night estimate because those totals bundle taxes/fees and vary by category,
 * not a clean per-night rate; (2) trusting the single "ANY" guestRating row alone has no
 * cross-check against a parsing/DOM-shift error. Now generalized to any `provider() === 'hotels_com'`
 * campaign (a Campaign select drives the City options) and cheapTotal is `min(price-slider floor,
 * guestRating ANY)` instead of guestRating ANY alone — the price-range slider's own `min` HTML
 * attribute ("$140, Minimum, Total price") is Hotels.com's own authoritative floor across every
 * property type for the exact dates/occupancy searched, immune to the single-row/DOM-shift risk of
 * reading one radio option's text. Property class ("star") rows are also extracted and shown
 * (not saved — no dedicated column for a per-star price) purely as an eyeball sanity check: e.g. a
 * 1-star property class priced above 3-star for the same city/week flags a likely fluke listing,
 * not a real signal to act on.
 *
 * Two-anchor interpolation added same day, once kasno-letovanje's real 70-destination/6-10-week-
 * each remaining backlog made "one paste per (city, week)" impractical for a single evening's push
 * before going live — the exact per-week-entry decision the class docblock above originally called
 * out as deliberately DIFFERENT from BookingPriceLinkGenerator's two-anchor approach. Reusing that
 * same proven pattern here (see this file's $interpolatedWeeks): paste just the Start and End
 * week's sidebars, extract each anchor's jeftino/kvalitet via the exact same extractNightlyPrices()
 * used by the single-week flow below, then linearly interpolate every week between (plus one
 * extrapolated week each side), rounded to the nearest €10 (this class's own rounding granularity,
 * not Booking's €5). The single-week paste-and-save flow stays as-is beneath it, for any city/week
 * that needs a real, individually-researched override instead of a straight-line estimate.
 */
class HotelsPriceScraper extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static ?string $navigationLabel = 'Hotels.com Price Scraper';

    protected static ?string $title = 'Hotels.com Price Scraper';

    protected static string $view = 'filament.pages.hotels-price-scraper';

    /** Markup applied on top of Hotels.com's own "From" total-stay price before rounding — owner's
     *  formula, 2026-09-09: real hotel-shown "from" prices are a floor, not a guaranteed bookable
     *  rate by the time a traveler actually clicks through (same "never undersell reality"
     *  principle as the Booking margin convention, just a flat rule here instead of "eyeball the
     *  3rd-4th listing" since there's no listing table to eyeball anymore). */
    private const MARKUP = 1.10;

    /** Lodging types (name="lodging" `value`s, see HotelsComFilters::LODGING_TYPES) that count
     *  toward "jeftino" — the ordinary leisure-stay types, same spirit as
     *  BookingPriceLinkGenerator's own narrowing to Hotels/Resorts/Apartments/Villas/Holiday homes
     *  (owner's 2026-08-31 catch: hostels drowned out the real comparison prices). Deliberately
     *  EXCLUDES bed & breakfast, guesthouse, hostel, farm, country house, motel, etc.: in a small
     *  result set a single one of those becomes "the cheapest thing in town" and drags every
     *  price down (real case, Cefalù 2026-09-24: 17 properties, one B&B at $517 while the
     *  cheapest actual hotel was $789 — jeftino came out €90 against a real ~€115-130). */
    private const MAINSTREAM_LODGING = [
        'HOTEL', 'HOTEL_RESORT', 'APARTMENT', 'APART_HOTEL', 'VILLA',
        'VACATION_HOME', 'CONDO', 'CONDO_RESORT', 'RESIDENCE', 'TOWNHOUSE',
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $week = null;

    /** How many nights the owner's own pasted search actually covered — Hotels.com's sidebar
     *  shows a TOTAL stay price, not per-night, and this campaign has no fixed research-nights
     *  convention yet the way kasno-letovanje's NIGHTS=6 does (see BookingPriceLinkGenerator).
     *  Left as a real input rather than a hardcoded guess so a wrong assumption here can't
     *  silently bias every single saved price by the same wrong factor. */
    public int $nights = 7;

    public ?string $pastedHtml = null;

    public ?float $extractedCheapTotal = null;

    public ?float $extractedQualityTotal = null;

    /** Hotels.com's own price-range slider floor ("$X, Minimum, Total price") — the cross-check
     *  against guestRating ANY, see this class's docblock. Null if the pasted HTML has no
     *  `input[name=price]` (a stripped/partial paste). */
    public ?float $extractedPriceFloor = null;

    /** Raw guestRating ANY total, kept separate from extractedCheapTotal (which may end up being
     *  the price-floor instead) purely for display, so the admin can see both inputs that fed the
     *  min(). */
    public ?float $extractedGuestRatingAny = null;

    /** Cheapest MAINSTREAM lodging type's total (what jeftino is actually built from when the
     *  paste has lodging rows) — display only, see extractNightlyPrices(). */
    public ?float $extractedMainstreamFloor = null;

    /** value => total-stay-price, e.g. ['50' => 293.0, '40' => 141.0, ...] — display-only, see
     *  docblock. Not persisted anywhere. */
    public array $extractedStarPrices = [];

    /** Anchor weeks for the interpolation flow — default to the campaign's full remaining range
     *  (first/last future week) when a campaign is picked, see applyDefaultAnchorWeeks(), but stay
     *  put across a city switch within the same campaign since the whole point is picking these
     *  once and then cycling through many cities. */
    public ?string $startWeek = null;

    public ?string $endWeek = null;

    public ?string $pastedHtmlStart = null;

    public ?string $pastedHtmlEnd = null;

    /** Already markup-and-rounded €/night (not raw $ totals) — see extractAnchorPrices(). */
    public ?float $startCheapPrice = null;

    public ?float $endCheapPrice = null;

    public ?float $startQualityPrice = null;

    public ?float $endQualityPrice = null;

    /** @var array<int, array{week: string, label: string, cheap: ?float, quality: ?float}> */
    public array $interpolatedWeeks = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    /** Same param shape as SearchSessionQueryCompiler::toHotelsUrl() (destination = city label,
     *  startDate/endDate, adults=2/rooms=1 — matching this page's own "2 adults, 1 room" research
     *  convention) so the admin can jump straight to the real search for the picked city+week
     *  instead of building the URL by hand before every "View Source". Takes an explicit week
     *  (rather than always reading $this->week) so the same helper serves the single-week section's
     *  own Week select AND the two-anchor section's Start/End selects. Null until a city is picked
     *  and $week is non-null. No affiliate tracking wrap here — this is a research visit, not a
     *  real booking link. */
    public function hotelsSearchUrlFor(?string $week): ?string
    {
        $node = TaxonomyNode::find($this->data['taxonomy_node_id'] ?? null);
        if (! $node || ! $week) {
            return null;
        }

        $checkin = Carbon::parse($week);
        $checkout = $checkin->copy()->addDays(max(1, $this->nights));

        $params = [
            'destination' => $node->label,
            'startDate' => $checkin->toDateString(),
            'endDate' => $checkout->toDateString(),
            'adults' => 2,
            'rooms' => 1,
            'flexibility' => '0_DAY',
        ];

        $query = collect($params)->map(fn ($value, $key) => $key.'='.rawurlencode((string) $value))->implode('&');

        return 'https://www.hotels.com/Hotel-Search?'.$query;
    }

    private function campaign(): ?WizardCampaign
    {
        $id = $this->data['wizard_campaign_id'] ?? null;

        return $id ? WizardCampaign::find($id) : null;
    }

    private function futureSeasonWeeks(WizardCampaign $campaign): \Illuminate\Support\Collection
    {
        $today = Carbon::today();

        return $campaign->seasonWeeks()->filter(fn ($week) => $week->gte($today))->values();
    }

    /** Defaults Start/End to the campaign's full remaining range (first/last future week) — the
     *  owner's own described workflow: "pocev od subote [prve preostale] i zadnju nedelju". Still
     *  freely overridable per-city via the two dropdowns for a city that needs a narrower/different
     *  range. */
    private function applyDefaultAnchorWeeks(): void
    {
        $campaign = $this->campaign();
        $weeks = $campaign ? $this->futureSeasonWeeks($campaign) : collect();

        $this->startWeek = $weeks->first()?->toDateString();
        $this->endWeek = $weeks->last()?->toDateString();
        $this->interpolatedWeeks = [];
    }

    public function seasonWeekOptions(): array
    {
        $campaign = $this->campaign();

        return $campaign
            ? $this->futureSeasonWeeks($campaign)
                ->mapWithKeys(fn ($week) => [
                    $week->toDateString() => $week->format('D, M j').' – '.$week->copy()->addDays(6)->format('M j'),
                ])
                ->all()
            : [];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('wizard_campaign_id')
                    ->label('Campaign')
                    ->options(fn () => WizardCampaign::all()
                        ->filter(fn (WizardCampaign $c) => $c->provider() === 'hotels_com')
                        ->pluck('label', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('taxonomy_node_id', null);
                        $this->week = null;
                        $this->resetPerCityState();
                        $this->applyDefaultAnchorWeeks();
                    }),
                Forms\Components\Select::make('taxonomy_node_id')
                    ->label('City')
                    ->options(function (Get $get) {
                        $campaignId = $get('wizard_campaign_id');
                        if (! $campaignId) {
                            return [];
                        }

                        return TaxonomyNode::where('type', 'city')
                            ->whereHas('campaignDestinationPrices', fn ($q) => $q->where('wizard_campaign_id', $campaignId))
                            ->orderBy('label')
                            ->pluck('label', 'id');
                    })
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->resetPerCityState()),
            ])
            ->statePath('data');
    }

    private function resetPerCityState(): void
    {
        $this->pastedHtml = null;
        $this->extractedCheapTotal = null;
        $this->extractedQualityTotal = null;
        $this->extractedPriceFloor = null;
        $this->extractedGuestRatingAny = null;
        $this->extractedMainstreamFloor = null;
        $this->extractedStarPrices = [];

        // Deliberately does NOT touch startWeek/endWeek — those are picked once per campaign and
        // stay put while cycling through many cities, see applyDefaultAnchorWeeks()'s docblock.
        $this->pastedHtmlStart = null;
        $this->pastedHtmlEnd = null;
        $this->startCheapPrice = null;
        $this->endCheapPrice = null;
        $this->startQualityPrice = null;
        $this->endQualityPrice = null;
        $this->interpolatedWeeks = [];
    }

    /**
     * Generic Hotels.com filter-sidebar row reader — works for any radio/checkbox group that
     * follows the real captured shape (input[name=$inputName] -> ancestor row div also containing
     * a sibling "uitk-text" price div), confirmed 2026-09-09 against a real pasted
     * sidebar.hotels.html for BOTH "guestRating" (radio) and "star" (checkbox) groups. Returns
     * value => total-stay-price map (e.g. ['ANY' => 78.0, '45' => 122.0, '40' => 122.0, '35' => 122.0]).
     *
     * @return array<string, float>
     */
    private function extractFilterRowPrices(string $html, string $inputName): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $inputs = $xpath->query("//input[@name=\"{$inputName}\"]");

        $prices = [];
        foreach ($inputs as $input) {
            $value = $input->getAttribute('value');
            if ($value === '') {
                continue;
            }

            $row = $xpath->query('ancestor::div[contains(concat(" ", normalize-space(@class), " "), " uitk-layout-flex-align-items-center ")][1]', $input)->item(0);
            if (! $row) {
                continue;
            }

            $priceNode = null;
            foreach ($xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " uitk-text ")]', $row) as $candidate) {
                if (preg_match('/\d/', $candidate->textContent) === 1) {
                    $priceNode = $candidate;
                    break;
                }
            }
            if (! $priceNode) {
                continue;
            }

            $digits = preg_replace('/[^\d.]/u', '', trim($priceNode->textContent)) ?? '';
            if ($digits !== '') {
                $prices[$value] = (float) $digits;
            }
        }

        return $prices;
    }

    /** Public — the results panel (blade) calls this directly to render jeftino/kvalitet
     *  €/night from the raw $ totals stored in extractedCheapTotal/extractedQualityTotal. */
    public function markupAndRoundUp(float $totalPrice): float
    {
        $perNight = $totalPrice / max(1, $this->nights);

        return ceil(($perNight * self::MARKUP) / 10) * 10;
    }

    /** Reads the price-range slider's `min` HTML attribute directly off
     *  `input[name="price"]` — Hotels.com's own computed floor across every listed property for
     *  the exact dates/occupancy searched ("$140, Minimum, Total price"), same both on the primary
     *  and secondary handle so either input works. Returns null if the pasted HTML has no price
     *  slider (a stripped/partial paste). */
    private function extractPriceFloor(string $html): ?float
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $input = $xpath->query('//input[@name="price"]')->item(0);

        if (! $input || $input->getAttribute('min') === '') {
            return null;
        }

        return (float) $input->getAttribute('min');
    }

    /**
     * Shared extraction core — pulls guestRating/price-floor/star rows out of one pasted sidebar
     * and returns the already markup-and-rounded €/night jeftino/kvalitet figures, plus the raw
     * inputs for display. Used by both extractAndSave() (single week) and extractAnchorPrices()
     * (two-anchor interpolation) so the two flows can never quietly drift on how a price is derived.
     *
     * jeftino = the cheapest MAINSTREAM lodging type's "From" price (MAINSTREAM_LODGING — hotel,
     * apartment, villa, resort...), NOT the overall floor: the overall floor (price slider /
     * guestRating ANY) is whatever single listing is cheapest across every type, which in a small
     * result set is often a lone B&B/guesthouse/hostel (see MAINSTREAM_LODGING). Only when the
     * paste has no usable lodging rows does it fall back to min(price-slider floor, guestRating
     * ANY), the previous rule. kvalitet is guestRating "40" (Very good 8+), raised to at least
     * jeftino: the rating rows can't be split by lodging type, and an 8+ "from" price sitting
     * BELOW the cheapest ordinary stay just means the same outlier is 8+ too.
     *
     * @return array{cheap: ?float, quality: ?float, priceFloor: ?float, guestRatingAny: ?float, mainstreamFloor: ?float, starPrices: array<string, float>}
     */
    private function extractNightlyPrices(string $html): array
    {
        $guestRatingPrices = $this->extractFilterRowPrices($html, 'guestRating');
        $lodgingPrices = $this->extractFilterRowPrices($html, 'lodging');
        $priceFloor = $this->extractPriceFloor($html);
        $starPrices = $this->extractFilterRowPrices($html, 'star');

        $anyTotal = $guestRatingPrices['ANY'] ?? null;

        $mainstream = collect(self::MAINSTREAM_LODGING)
            ->map(fn (string $type) => $lodgingPrices[$type] ?? null)
            ->filter(fn (?float $v) => $v !== null);
        $mainstreamFloor = $mainstream->isNotEmpty() ? $mainstream->min() : null;

        $fallbackCandidates = collect([$priceFloor, $anyTotal])->filter(fn (?float $v) => $v !== null);
        $cheapTotal = $mainstreamFloor ?? ($fallbackCandidates->isNotEmpty() ? $fallbackCandidates->min() : null);

        $qualityTotal = $guestRatingPrices['40'] ?? null;
        if ($qualityTotal !== null && $cheapTotal !== null) {
            $qualityTotal = max($qualityTotal, $cheapTotal);
        }

        return [
            'cheap' => $cheapTotal !== null ? $this->markupAndRoundUp($cheapTotal) : null,
            'quality' => $qualityTotal !== null ? $this->markupAndRoundUp($qualityTotal) : null,
            'priceFloor' => $priceFloor,
            'guestRatingAny' => $anyTotal,
            'mainstreamFloor' => $mainstreamFloor,
            'starPrices' => $starPrices,
        ];
    }

    /**
     * Parses the pasted sidebar and saves straight into that (city, week)'s
     * price_per_person_eur / quality_tier_price_per_person_eur — no separate "extract" then "save"
     * step, since there's no table to review first (unlike Booking's page, where the listing table
     * itself is the useful review surface). For a single real, individually-researched week — most
     * cities go through extractAnchorPrices()/saveInterpolatedWeeks() below instead.
     */
    public function extractAndSave(): void
    {
        $node = TaxonomyNode::find($this->data['taxonomy_node_id'] ?? null);
        if (! $node) {
            Notification::make()->title('Pick a city first')->danger()->send();

            return;
        }
        if (! $this->week) {
            Notification::make()->title('Pick a week first')->danger()->send();

            return;
        }
        if (trim((string) $this->pastedHtml) === '') {
            Notification::make()->title('Paste the sidebar HTML first')->danger()->send();

            return;
        }

        $campaign = $this->campaign();
        if (! $campaign) {
            Notification::make()->title('Pick a campaign first')->danger()->send();

            return;
        }

        $extracted = $this->extractNightlyPrices($this->pastedHtml);
        if ($extracted['guestRatingAny'] === null && $extracted['priceFloor'] === null) {
            Notification::make()
                ->title('No "Guest rating" or price-slider rows found')
                ->body('Paste the full filter sidebar source (the panel with Guest rating / Property class / Price / etc.), not a screenshot or a partial copy.')
                ->danger()
                ->send();

            return;
        }

        $this->extractedCheapTotal = $extracted['cheap'];
        $this->extractedQualityTotal = $extracted['quality'];
        $this->extractedPriceFloor = $extracted['priceFloor'];
        $this->extractedGuestRatingAny = $extracted['guestRatingAny'];
        $this->extractedMainstreamFloor = $extracted['mainstreamFloor'];
        $this->extractedStarPrices = $extracted['starPrices'];

        $destinationPrice = WizardCampaignDestinationPrice::firstOrCreate(
            ['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $node->id],
            ['source' => 'manual_research'],
        );

        WizardCampaignDestinationWeeklyPrice::updateOrCreate(
            ['wizard_campaign_destination_price_id' => $destinationPrice->id, 'week_start_date' => $this->week],
            [
                'price_per_person_eur' => $extracted['cheap'],
                'quality_tier_price_per_person_eur' => $extracted['quality'],
            ],
        );

        Notification::make()
            ->title("Saved {$node->label}, week of {$this->week}")
            ->body(
                'Jeftino: '.($extracted['cheap'] !== null ? '€'.number_format($extracted['cheap'], 0).'/night' : 'not found').
                ' · Kvalitet: '.($extracted['quality'] !== null ? '€'.number_format($extracted['quality'], 0).'/night' : 'not found')
            )
            ->success()
            ->send();
    }

    public function updatedStartWeek(): void
    {
        $this->recomputeInterpolation();
    }

    public function updatedEndWeek(): void
    {
        $this->recomputeInterpolation();
    }

    /**
     * Parses BOTH anchor pastes at once via extractNightlyPrices() (same core as the single-week
     * flow above), pre-fills Start/End jeftino+kvalitet, and immediately (re)computes the
     * interpolated table. Either paste can be left empty if that anchor already has a good number
     * from elsewhere — recomputeInterpolation() just treats a still-null price as "can't
     * interpolate this tier," not an error.
     */
    public function extractAnchorPrices(): void
    {
        if (trim((string) $this->pastedHtmlStart) === '' && trim((string) $this->pastedHtmlEnd) === '') {
            Notification::make()->title('Paste at least one anchor week\'s sidebar first')->danger()->send();

            return;
        }

        $start = trim((string) $this->pastedHtmlStart) !== '' ? $this->extractNightlyPrices($this->pastedHtmlStart) : null;
        $end = trim((string) $this->pastedHtmlEnd) !== '' ? $this->extractNightlyPrices($this->pastedHtmlEnd) : null;

        if (($start === null || ($start['cheap'] === null && $start['quality'] === null))
            && ($end === null || ($end['cheap'] === null && $end['quality'] === null))) {
            Notification::make()
                ->title('No "Guest rating" or price-slider rows found in either paste')
                ->body('Paste the full filter sidebar source, not a screenshot or a partial copy.')
                ->danger()
                ->send();

            return;
        }

        $this->startCheapPrice = $start['cheap'] ?? null;
        $this->startQualityPrice = $start['quality'] ?? null;
        $this->endCheapPrice = $end['cheap'] ?? null;
        $this->endQualityPrice = $end['quality'] ?? null;

        $this->recomputeInterpolation();
    }

    /**
     * Two-anchor linear interpolation — same pattern as BookingPriceLinkGenerator's
     * $interpolatedWeeks (see this class's docblock), adapted to jeftino AND kvalitet at once and
     * rounded to the nearest €10 (this class's own granularity) instead of Booking's €5. Each tier
     * interpolates independently and is left null throughout if either its Start or End anchor is
     * missing, rather than flat-lining from a single known point. One extra week is extrapolated on
     * each side (same slope) when the campaign has a season week immediately before Start or after
     * End, mirroring Booking's "cover every real week, some by research some by trend" rule.
     */
    private function recomputeInterpolation(): void
    {
        $this->interpolatedWeeks = [];

        $campaign = $this->campaign();
        if (! $campaign || ! $this->startWeek || ! $this->endWeek) {
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
        $cheapAvailable = $this->startCheapPrice !== null && $this->endCheapPrice !== null;
        $qualityAvailable = $this->startQualityPrice !== null && $this->endQualityPrice !== null;
        $cheapSlope = $cheapAvailable ? ($this->endCheapPrice - $this->startCheapPrice) / $steps : 0.0;
        $qualitySlope = $qualityAvailable ? ($this->endQualityPrice - $this->startQualityPrice) / $steps : 0.0;
        // Floored at €10: a steep slope extrapolated one step past an anchor can otherwise land on
        // 0 or a negative "price" (a real Save would then write a free/negative night).
        $roundTen = fn (float $v): float => max(10.0, round($v / 10) * 10);

        $this->interpolatedWeeks = $weeks->map(function ($week, $i) use ($cheapAvailable, $qualityAvailable, $cheapSlope, $qualitySlope, $roundTen) {
            return [
                'week' => $week->toDateString(),
                'label' => $week->format('D, M j'),
                'cheap' => $cheapAvailable ? $roundTen($this->startCheapPrice + $cheapSlope * $i) : null,
                'quality' => $qualityAvailable ? $roundTen($this->startQualityPrice + $qualitySlope * $i) : null,
            ];
        })->all();

        // Only extrapolate backward into a week that can still be booked — a week already in the
        // past is never shown or queried (futureSeasonWeeks() drops it everywhere else), so
        // writing a made-up price for it is just noise in the table and in the DB.
        $beforeStart = $campaign->seasonWeeks()->filter(fn ($w) => $w->lt($start))->last();
        if ($beforeStart && $beforeStart->gte(Carbon::today())) {
            array_unshift($this->interpolatedWeeks, [
                'week' => $beforeStart->toDateString(),
                'label' => $beforeStart->format('D, M j'),
                'cheap' => $cheapAvailable ? $roundTen($this->startCheapPrice - $cheapSlope) : null,
                'quality' => $qualityAvailable ? $roundTen($this->startQualityPrice - $qualitySlope) : null,
            ]);
        }

        $afterEnd = $campaign->seasonWeeks()->first(fn ($w) => $w->gt($end));
        if ($afterEnd) {
            $this->interpolatedWeeks[] = [
                'week' => $afterEnd->toDateString(),
                'label' => $afterEnd->format('D, M j'),
                'cheap' => $cheapAvailable ? $roundTen($this->endCheapPrice + $cheapSlope) : null,
                'quality' => $qualityAvailable ? $roundTen($this->endQualityPrice + $qualitySlope) : null,
            ];
        }

        // Quality tier (8+ rating) is a SUBSET of every property, so its "from" price can never
        // truly sit below the overall cheapest one. Interpolating between two valid anchors keeps
        // that true, but each tier extrapolates on its OWN slope, so a steeper jeftino slope
        // pushed backward/forward past an anchor can leapfrog kvalitet (real case: Sep 19 came out
        // jeftino €100 / kvalitet €90). Clamp kvalitet up to jeftino wherever that happens.
        $this->interpolatedWeeks = array_map(function (array $row) {
            if ($row['cheap'] !== null && $row['quality'] !== null && $row['quality'] < $row['cheap']) {
                $row['quality'] = $row['cheap'];
            }

            return $row;
        }, $this->interpolatedWeeks);
    }

    /** Writes every interpolatedWeeks row for the currently picked city in one go. */
    public function saveInterpolatedWeeks(): void
    {
        $node = TaxonomyNode::find($this->data['taxonomy_node_id'] ?? null);
        if (! $node) {
            Notification::make()->title('Pick a city first')->danger()->send();

            return;
        }

        $campaign = $this->campaign();
        if (! $campaign) {
            Notification::make()->title('Pick a campaign first')->danger()->send();

            return;
        }

        if (empty($this->interpolatedWeeks)) {
            Notification::make()->title('Nothing to save — set both anchor weeks and extract prices first')->danger()->send();

            return;
        }

        $destinationPrice = WizardCampaignDestinationPrice::firstOrCreate(
            ['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $node->id],
            ['source' => 'manual_research'],
        );

        foreach ($this->interpolatedWeeks as $row) {
            WizardCampaignDestinationWeeklyPrice::updateOrCreate(
                ['wizard_campaign_destination_price_id' => $destinationPrice->id, 'week_start_date' => $row['week']],
                [
                    'price_per_person_eur' => $row['cheap'],
                    'quality_tier_price_per_person_eur' => $row['quality'],
                ],
            );
        }

        Notification::make()
            ->title(count($this->interpolatedWeeks)." weeks saved for {$node->label}")
            ->success()
            ->send();
    }

    /** Every future week for the picked city, both price columns, so a gap or a typo'd number is
     *  visible immediately without opening the database. */
    public function currentWeeklyPricesFor(): array
    {
        $taxonomyNodeId = $this->data['taxonomy_node_id'] ?? null;
        if (empty($taxonomyNodeId)) {
            return [];
        }

        $campaign = $this->campaign();
        if (! $campaign) {
            return [];
        }

        $destinationPrice = WizardCampaignDestinationPrice::where('wizard_campaign_id', $campaign->id)
            ->where('taxonomy_node_id', $taxonomyNodeId)
            ->first();

        $existing = $destinationPrice
            ? $destinationPrice->weeklyPrices->keyBy(fn ($w) => $w->week_start_date->toDateString())
            : collect();

        return $this->futureSeasonWeeks($campaign)->map(fn ($week) => [
            'week' => $week->toDateString(),
            'label' => $week->format('D, M j'),
            'cheap' => $existing->get($week->toDateString())?->price_per_person_eur,
            'quality' => $existing->get($week->toDateString())?->quality_tier_price_per_person_eur,
        ])->all();
    }
}
