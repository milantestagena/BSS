<?php

namespace App\Filament\Resources\WizardCampaignResource\RelationManagers;

use App\Filament\Resources\TaxonomyNodeResource;
use App\Models\TaxonomyNode;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The positive membership list GeographyResolver::filterByCampaignOwnership reads — see that
 * method's docblock for the ownership/reachability rules a row participates in. Plain
 * belongsToMany, no `relation_type`-style pivot payload (unlike TaxonomyNodeResource's
 * Base*EdgeRelationManager, which needs a custom ->using() to write that column) — a row's
 * meaning here is fully determined by the attached node's own `type`, so vanilla
 * AttachAction/DetachAction wiring is enough.
 */
class DestinationsRelationManager extends RelationManager
{
    protected static string $relationship = 'destinations';

    protected static ?string $title = 'Destinations';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('label')
                    ->url(fn (TaxonomyNode $record) => TaxonomyNodeResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('parent.label')->label('Parent')->placeholder('—'),
            ])
            ->defaultGroup('type')
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->recordSelectSearchColumns(['label', 'slug', 'type'])
                    // Only geography nodes make sense here — filterByCampaignOwnership only
                    // ever reads region_theme/country/city rows off this pivot.
                    ->recordSelectOptionsQuery(fn ($query) => $query->whereIn('type', ['region_theme', 'country', 'city'])),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }
}
