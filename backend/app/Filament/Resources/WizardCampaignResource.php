<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WizardCampaignResource\Pages;
use App\Filament\Resources\WizardCampaignResource\RelationManagers\DestinationsRelationManager;
use App\Models\WizardCampaign;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * First Filament UI for wizard_campaigns at all (previously zero admin UI, not even for the
 * pre-existing questions() pivot) — built 2026-09-18 specifically to host
 * DestinationsRelationManager, the owner's explicit ask to self-serve campaign geography
 * assignment (see GeographyResolver::filterByCampaignOwnership). Form is deliberately minimal
 * (identity fields only) — preset_answers/meta stay seeder-managed for now, same "not
 * everything needs a UI yet" boundary questions() itself has lived with since 2026-07-30.
 */
class WizardCampaignResource extends Resource
{
    protected static ?string $model = WizardCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Wizard Campaigns';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('key')->required()->maxLength(255)->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('label')->required()->maxLength(255),
            Forms\Components\TextInput::make('landing_headline')->maxLength(255),
            Forms\Components\Toggle::make('is_active')->default(true),
            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            Forms\Components\DatePicker::make('season_start_date'),
            Forms\Components\DatePicker::make('season_end_date'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->searchable(),
                Tables\Columns\TextColumn::make('label')->searchable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
                Tables\Columns\TextColumn::make('destinations_count')->counts('destinations')->label('Destinations'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DestinationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWizardCampaigns::route('/'),
            'create' => Pages\CreateWizardCampaign::route('/create'),
            'edit' => Pages\EditWizardCampaign::route('/{record}/edit'),
        ];
    }
}
