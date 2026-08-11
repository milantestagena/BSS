<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WizardCampaignDestinationWeeklyPriceResource\Pages;
use App\Models\WizardCampaign;
use App\Models\WizardCampaignDestinationWeeklyPrice;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Week-first price grid — owner's explicit ask, 2026-08-11: "mnogo je lakse da imamo
 * grupisano po nedelji, pa da upisujem za gradove" (opposite of the destination-first
 * WizardCampaignDestinationPriceResource). Rows are pre-created empty via
 * `campaign:seed-weekly-price-rows` (run after `campaign:seed-destination-price-rows`), same
 * fill-in-the-blanks philosophy. `price_per_person_eur` is inline-editable directly in the
 * table (TextInputColumn), grouped by week so a whole week's destinations are scanned/typed
 * together. No create/edit pages — rows only ever come from the seed command, never created
 * one at a time here.
 */
class WizardCampaignDestinationWeeklyPriceResource extends Resource
{
    protected static ?string $model = WizardCampaignDestinationWeeklyPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Weekly Prices';

    protected static ?string $navigationGroup = 'Pricing';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('week_start_date')
                    ->label('Week of')
                    ->date('D, M j')
                    ->sortable(),
                Tables\Columns\TextColumn::make('destinationPrice.destination.parent.label')
                    ->label('Country')
                    ->sortable(),
                Tables\Columns\TextColumn::make('destinationPrice.destination.label')
                    ->label('Destination')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('price_per_person_eur')
                    ->label('€ / person / night')
                    ->type('number')
                    ->step('any')
                    ->rules(['nullable', 'numeric', 'min:0']),
            ])
            ->defaultGroup('week_start_date')
            ->defaultSort('week_start_date')
            ->filters([
                Tables\Filters\SelectFilter::make('week_start_date')
                    ->label('Week')
                    ->options(function () {
                        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();

                        return $campaign?->seasonWeeks()
                            ->mapWithKeys(fn ($week) => [$week->toDateString() => $week->format('D, M j')])
                            ->all() ?? [];
                    }),
                Tables\Filters\Filter::make('missing_price')
                    ->label('Missing price only')
                    ->query(fn ($query) => $query->whereNull('price_per_person_eur')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWizardCampaignDestinationWeeklyPrices::route('/'),
        ];
    }
}
