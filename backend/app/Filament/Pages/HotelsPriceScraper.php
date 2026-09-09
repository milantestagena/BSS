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
 * Owner's ask, 2026-09-09: a real parser, not a manual "read 3rd-4th listing" workflow like
 * BookingPriceLinkGenerator (Hotels.com's own filter sidebar already shows a "From $X" price next
 * to EVERY guestRating option — no ordering/filtering/mental math needed, the number is already
 * sitting there). One paste per (city, week) — unlike Booking's two-anchor interpolation, real
 * Hotels.com prices get entered for every week directly (owner's call: "ceo opseg... za jjeftine i
 * za kvalitetne", 2026-09-09), so there's no interpolation math here at all.
 *
 * Reads the "Guest rating" fieldset (name="guestRating") specifically, not "Property class"
 * (name="star") — owner's explicit pick, 2026-09-09 ("ako pise 122 za rating 8"), even though the
 * live wizard's own kvalitet filter (toHotelsUrl()) sends star=40 AND guestRating=40 together. The
 * "ANY" row is jeftino's source, "40" ("Very good 8+") is kvalitet's — same single fieldset, two
 * of its rows.
 *
 * Hardcoded to zimsko-sunce, same simplicity as BookingPriceLinkGenerator hardcoding
 * kasno-letovanje — the only hotels_com-provider campaign that exists today, see
 * WizardCampaign::provider(). Revisit if/when Jesenjovanje needs the same tool.
 */
class HotelsPriceScraper extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static ?string $navigationLabel = 'Hotels.com Price Scraper';

    protected static ?string $title = 'Hotels.com Price Scraper';

    protected static string $view = 'filament.pages.hotels-price-scraper';

    private const CAMPAIGN_KEY = 'zimsko-sunce';

    /** Markup applied on top of Hotels.com's own "From" total-stay price before rounding — owner's
     *  formula, 2026-09-09: real hotel-shown "from" prices are a floor, not a guaranteed bookable
     *  rate by the time a traveler actually clicks through (same "never undersell reality"
     *  principle as the Booking margin convention, just a flat rule here instead of "eyeball the
     *  3rd-4th listing" since there's no listing table to eyeball anymore). */
    private const MARKUP = 1.10;

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

    public function mount(): void
    {
        $this->form->fill();
    }

    private function campaign(): ?WizardCampaign
    {
        return WizardCampaign::where('key', self::CAMPAIGN_KEY)->first();
    }

    private function futureSeasonWeeks(WizardCampaign $campaign): \Illuminate\Support\Collection
    {
        $today = Carbon::today();

        return $campaign->seasonWeeks()->filter(fn ($week) => $week->gte($today))->values();
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
                Forms\Components\Select::make('taxonomy_node_id')
                    ->label('City')
                    ->options(function () {
                        $campaign = $this->campaign();
                        if (! $campaign) {
                            return [];
                        }

                        return TaxonomyNode::where('type', 'city')
                            ->whereHas('campaignDestinationPrices', fn ($q) => $q->where('wizard_campaign_id', $campaign->id))
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

    private function markupAndRoundUp(float $totalPrice): float
    {
        $perNight = $totalPrice / max(1, $this->nights);

        return ceil(($perNight * self::MARKUP) / 10) * 10;
    }

    /**
     * Parses the pasted sidebar, pulls "ANY" (jeftino) and "40"/Very good 8+ (kvalitet) from the
     * Guest rating group, applies markupAndRoundUp() to both, and saves straight into that
     * (city, week)'s price_per_person_eur / quality_tier_price_per_person_eur — no separate
     * "extract" then "save" step, since there's no table to review first (unlike Booking's page,
     * where the listing table itself is the useful review surface).
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
            Notification::make()->title('No '.self::CAMPAIGN_KEY.' campaign found')->danger()->send();

            return;
        }

        $guestRatingPrices = $this->extractFilterRowPrices($this->pastedHtml, 'guestRating');
        if (empty($guestRatingPrices)) {
            Notification::make()
                ->title('No "Guest rating" filter rows found')
                ->body('Paste the full filter sidebar source (the panel with Guest rating / Property class / etc.), not a screenshot or a partial copy.')
                ->danger()
                ->send();

            return;
        }

        $cheapTotal = $guestRatingPrices['ANY'] ?? null;
        $qualityTotal = $guestRatingPrices['40'] ?? null;

        $this->extractedCheapTotal = $cheapTotal;
        $this->extractedQualityTotal = $qualityTotal;

        $destinationPrice = WizardCampaignDestinationPrice::firstOrCreate(
            ['wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $node->id],
            ['source' => 'manual_research'],
        );

        WizardCampaignDestinationWeeklyPrice::updateOrCreate(
            ['wizard_campaign_destination_price_id' => $destinationPrice->id, 'week_start_date' => $this->week],
            [
                'price_per_person_eur' => $cheapTotal !== null ? $this->markupAndRoundUp($cheapTotal) : null,
                'quality_tier_price_per_person_eur' => $qualityTotal !== null ? $this->markupAndRoundUp($qualityTotal) : null,
            ],
        );

        Notification::make()
            ->title("Saved {$node->label}, week of {$this->week}")
            ->body(
                'Jeftino: '.($cheapTotal !== null ? '€'.number_format($this->markupAndRoundUp($cheapTotal), 0).'/night (from $'.$cheapTotal.' total)' : 'not found').
                ' · Kvalitet: '.($qualityTotal !== null ? '€'.number_format($this->markupAndRoundUp($qualityTotal), 0).'/night (from $'.$qualityTotal.' total)' : 'not found')
            )
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
