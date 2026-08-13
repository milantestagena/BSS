<?php

namespace App\Filament\Pages;

use App\Models\TaxonomyNode;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Owner's ask, 2026-08-13: a way to derive `TaxonomyNode.meta.meal_plan_coefficient` (see
 * BudgetEstimationEngine::mealPlanTotalFor) from a real Booking price comparison instead of
 * guessing — without hand-editing raw JSON. Enter one hotel's room-only vs all-inclusive price
 * for the same room/dates, plus the country's known avg restaurant meal price, and this derives
 * the coefficient: how much more (or less) the hotel charges per day-of-meals than eating out
 * would cost, relative to MEALS_PER_DAY_PER_ADULT (2.5). 1.0 = charges exactly restaurant price,
 * <1 = discount (bulk economics), >1 = markup ("naplata lenjosti").
 *
 * Deliberately a standalone calculator page, not a TaxonomyNode form field — the three raw
 * prices themselves aren't data worth storing (they're just this one hotel's snapshot used to
 * derive a number), only the resulting coefficient is.
 */
class MealPlanCoefficientCalculator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Meal Plan Coefficient';

    protected static ?string $title = 'Meal Plan Coefficient Calculator';

    protected static string $view = 'filament.pages.meal-plan-coefficient-calculator';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?float $computedCoefficient = null;

    public ?float $currentCoefficient = null;

    public function mount(): void
    {
        $this->form->fill(['adults' => 2]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('taxonomy_node_id')
                    ->label('Country')
                    ->options(fn () => TaxonomyNode::where('type', 'country')->orderBy('label')->pluck('label', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        $country = $state ? TaxonomyNode::find($state) : null;
                        $set('avg_restaurant_meal_eur', $country?->meta['hospitality']['avg_restaurant_meal_eur'] ?? null);
                        $this->currentCoefficient = $country?->meta['meal_plan_coefficient'] ?? null;
                        $this->computedCoefficient = null;
                    }),
                Forms\Components\TextInput::make('adults')
                    ->label('Adults the two prices below are for')
                    ->numeric()
                    ->minValue(1)
                    ->default(2)
                    ->required()
                    ->helperText('Booking often prices per room, not per person — tell it how many adults that room price covers.'),
                Forms\Components\TextInput::make('room_only_price_per_night')
                    ->label('Room-only price / night')
                    ->numeric()
                    ->step('any')
                    ->prefix('€')
                    ->required(),
                Forms\Components\TextInput::make('all_inclusive_price_per_night')
                    ->label('All-inclusive price / night (same room, same dates)')
                    ->numeric()
                    ->step('any')
                    ->prefix('€')
                    ->required(),
                Forms\Components\TextInput::make('avg_restaurant_meal_eur')
                    ->label('Avg. restaurant meal price')
                    ->numeric()
                    ->step('any')
                    ->prefix('€')
                    ->required()
                    ->helperText('Auto-filled from this country\'s real hospitality data when available — override if you have a better number.'),
            ])
            ->statePath('data');
    }

    public function compute(): void
    {
        $state = $this->form->getState();

        $mealPrice = (float) $state['avg_restaurant_meal_eur'];
        if ($mealPrice <= 0) {
            Notification::make()->title('Need a real restaurant meal price above 0')->danger()->send();

            return;
        }

        $dailySurchargePerAdult = ((float) $state['all_inclusive_price_per_night'] - (float) $state['room_only_price_per_night'])
            / max(1, (int) $state['adults']);

        // MEALS_PER_DAY_PER_ADULT — matches BudgetEstimationEngine::mealPlanTotalFor exactly,
        // sve_ukljuceno's coverage ratio IS this constant, so this is the right baseline to
        // compare a full all-inclusive daily surcharge against.
        $baseline = $mealPrice * 2.5;

        $this->computedCoefficient = round($dailySurchargePerAdult / $baseline, 2);
    }

    public function save(): void
    {
        if ($this->computedCoefficient === null) {
            $this->compute();
        }

        $state = $this->form->getState();
        $country = TaxonomyNode::find($state['taxonomy_node_id'] ?? null);
        if (! $country) {
            Notification::make()->title('Pick a country first')->danger()->send();

            return;
        }

        $meta = $country->meta ?? [];
        $meta['meal_plan_coefficient'] = $this->computedCoefficient;
        $country->update(['meta' => $meta]);

        $this->currentCoefficient = $this->computedCoefficient;

        Notification::make()
            ->title("Saved coefficient {$this->computedCoefficient} for {$country->label}")
            ->success()
            ->send();
    }
}
