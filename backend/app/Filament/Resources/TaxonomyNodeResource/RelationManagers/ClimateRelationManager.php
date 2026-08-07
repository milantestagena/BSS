<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * 12 monthly rows (temp/rain/sun/snow) for this location — see TaxonomyNode::climateMonths().
 * A plain hasMany RelationManager (create/edit/delete), not a pivot-based Attach/Detach one
 * like Implies/Suggests/SeasonalWindow/CostWeight — taxonomy_node_climates isn't an edge
 * between two taxonomy nodes, it's data owned directly by this one.
 */
class ClimateRelationManager extends RelationManager
{
    protected static string $relationship = 'climateMonths';

    protected static ?string $title = 'Climate';

    private const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('month')
                ->options(self::MONTHS)
                ->required(),
            Forms\Components\TextInput::make('avg_temp_c')->numeric()->step('any')->suffix('°C')->label('Avg. air temperature'),
            Forms\Components\TextInput::make('sea_temp_c')->numeric()->step('any')->suffix('°C')->label('Sea temperature')
                ->helperText('Only for coastal locations (meta.on_sea) — the actual "can I swim here" signal.'),
            Forms\Components\TextInput::make('precip_mm')->numeric()->step('any')->suffix('mm')->label('Precipitation'),
            Forms\Components\TextInput::make('sun_hours')->numeric()->step('any')->suffix('h')->label('Sunshine hours'),
            Forms\Components\TextInput::make('snow_cm')->numeric()->step('any')->suffix('cm')->label('Snow depth')
                ->helperText('Leave empty where not relevant (e.g. beach cities).'),
            Forms\Components\TextInput::make('source')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('month')
            ->columns([
                Tables\Columns\TextColumn::make('month')
                    ->formatStateUsing(fn (int $state) => self::MONTHS[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('avg_temp_c')->label('Air')->suffix(' °C'),
                Tables\Columns\TextColumn::make('sea_temp_c')->label('Sea')->suffix(' °C')->placeholder('—'),
                Tables\Columns\TextColumn::make('precip_mm')->label('Rain')->suffix(' mm'),
                Tables\Columns\TextColumn::make('sun_hours')->label('Sun')->suffix(' h'),
                Tables\Columns\TextColumn::make('snow_cm')->label('Snow')->suffix(' cm')->placeholder('—'),
                Tables\Columns\TextColumn::make('source')->toggleable(),
            ])
            ->defaultSort('month')
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
