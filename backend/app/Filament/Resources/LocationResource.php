<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Booking.com's raw location catalog (or fake-ID test stand-ins until real affiliate/API access
 * exists) — deliberately separate from TaxonomyNode, see Location model + migration comment.
 */
class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Locations (Booking)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('booking_dest_id')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Real Booking dest_id once known, or a fake test value for now (e.g. "test_prag_city").'),
                    Forms\Components\TextInput::make('dest_type')
                        ->required()
                        ->datalist(['city', 'region', 'district', 'airport', 'landmark', 'country'])
                        ->maxLength(255),
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('country_code')
                        ->maxLength(2)
                        ->label('Country code (ISO)'),
                    Forms\Components\TextInput::make('nr_hotels')
                        ->numeric()
                        ->label('Number of hotels')
                        ->helperText('Filter signal — skip locations Booking has no real supply for.'),
                    Forms\Components\TextInput::make('source')
                        ->default('manual_test')
                        ->helperText('e.g. "manual_test", "booking_api", "booking_sandbox".'),
                    Forms\Components\DateTimePicker::make('imported_at'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_dest_id')->searchable()->label('Dest ID'),
                Tables\Columns\TextColumn::make('dest_type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('country_code')->label('Country'),
                Tables\Columns\TextColumn::make('nr_hotels')->numeric()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('source')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('taxonomy_nodes_count')->counts('taxonomyNodes')->label('Linked nodes'),
            ])
            ->defaultGroup('dest_type')
            ->filters([
                Tables\Filters\SelectFilter::make('dest_type')
                    ->options(fn () => Location::query()->distinct()->orderBy('dest_type')->pluck('dest_type', 'dest_type')->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
