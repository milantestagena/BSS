<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Real, manually-observed nightly prices for this location — see
 * LateSummerAccommodationPrice / AccommodationPriceEstimator, 2026-08-03. Same plain
 * hasMany RelationManager pattern as ClimateRelationManager. Owner's own workflow: browse
 * Booking.com's map by hand per destination/date-range, one row per season tier.
 */
class LateSummerPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'lateSummerPrices';

    protected static ?string $title = 'Late summer prices';

    private const TIERS = [
        'van_sezone' => 'Van sezone',
        'pred_post_sezona' => 'Pred/post sezona',
        'sezona' => 'Sezona',
        'praznici' => 'Praznici',
    ];

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('season_tier')
                ->label('Tier')
                ->options(self::TIERS)
                ->required(),
            Forms\Components\TextInput::make('price_per_night_eur')
                ->numeric()->step('any')->prefix('€')->label('Price / night')
                ->required(),
            Forms\Components\TextInput::make('notes')
                ->maxLength(255)
                ->helperText('e.g. "1BR apartman, Sliema" — what exactly this price is for.'),
            Forms\Components\DatePicker::make('observed_at')
                ->required()
                ->default(now())
                ->helperText('When you looked this up — prices drift, this is a snapshot.'),
            Forms\Components\TextInput::make('source')
                ->default('manual_website')
                ->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('season_tier')
            ->columns([
                Tables\Columns\TextColumn::make('season_tier')
                    ->formatStateUsing(fn (string $state) => self::TIERS[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_per_night_eur')->label('€/night')->money('EUR'),
                Tables\Columns\TextColumn::make('notes')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('observed_at')->date()->sortable(),
                Tables\Columns\TextColumn::make('source')->toggleable(),
            ])
            ->defaultSort('season_tier')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
