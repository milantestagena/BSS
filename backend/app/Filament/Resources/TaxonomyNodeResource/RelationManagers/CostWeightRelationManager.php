<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

use App\Filament\Resources\TaxonomyNodeResource;
use App\Models\TaxonomyNode;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * How much this node (persona, preference_tag, ...) cares about a cost_category (hospitality /
 * local_stores / transport) — see TaxonomyNode::weightedToward() and wizard_architecture,
 * 2026-07-13. Payload-carrying edge like SeasonalWindowRelationManager, so not a
 * Base*EdgeRelationManager subclass either.
 */
class CostWeightRelationManager extends RelationManager
{
    protected static string $relationship = 'weightedToward';

    protected static ?string $title = 'Cost weight';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('label')
                    ->url(fn (TaxonomyNode $record) => TaxonomyNodeResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('pivot.meta')
                    ->label('Weight')
                    ->formatStateUsing(fn ($state) => $state['weight'] ?? '—'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->recordSelectSearchColumns(['label', 'slug', 'type'])
                    ->form(fn (Tables\Actions\AttachAction $action) => [
                        $action->getRecordSelect(),
                        Forms\Components\Select::make('weight')
                            ->options([1 => '1 — manje bitno', 2 => '2 — podrazumevano', 3 => '3 — baš bitno'])
                            ->default(2)
                            ->required()
                            ->helperText('Ista skala kao svuda drugde u projektu (importance_weight).'),
                    ])
                    ->using(function (RelationManager $livewire, array $data) {
                        $livewire->getOwnerRecord()->weightedToward()->attach($data['recordId'], [
                            'relation_type' => 'weighted_toward',
                            'meta' => json_encode(['weight' => (int) $data['weight']]),
                        ]);
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }
}
