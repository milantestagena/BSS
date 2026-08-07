<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

use App\Filament\Resources\TaxonomyNodeResource;
use App\Models\TaxonomyNodeRelation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only: shows every node that points AT this one (via implies/suggests/excludes),
 * since those relations are directional and never auto-symmetric — opening e.g. "City break"
 * shows what it excludes (via the Excludes tab), opening "Letovanje" shows THIS tab to
 * confirm it's excluded by City break, without needing to re-litigate "does it go backward".
 */
class ReferencedByRelationManager extends RelationManager
{
    protected static string $relationship = 'referencedBy';

    protected static ?string $title = 'Referenced by';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('relation_type')->badge(),
                Tables\Columns\TextColumn::make('from.type')->label('From type')->badge(),
                Tables\Columns\TextColumn::make('from.label')->label('From')
                    ->url(fn (TaxonomyNodeRelation $record) => TaxonomyNodeResource::getUrl('edit', ['record' => $record->from_taxonomy_node_id])),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
