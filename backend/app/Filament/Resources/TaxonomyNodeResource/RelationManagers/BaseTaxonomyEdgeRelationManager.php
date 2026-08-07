<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

use App\Filament\Resources\TaxonomyNodeResource;
use App\Models\TaxonomyNode;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Shared base for the Implies/Suggests/Excludes relation managers. They differ only in which
 * pivot `relation_type` they attach/filter — kept as three separate subclasses (not one
 * manager with a type selector) so each tab is self-explanatory on its own, which matters
 * given these relations are directional and not auto-symmetric (see taxonomy_node_relations
 * migration comment) — a mislabeled row here is exactly the class of mistake the whole
 * admin-editable design is meant to prevent.
 */
abstract class BaseTaxonomyEdgeRelationManager extends RelationManager
{
    protected static string $relationType;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('label')
                    ->url(fn (TaxonomyNode $record) => TaxonomyNodeResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('slug'),
            ])
            ->headerActions([
                // Deliberately NOT overriding ->options()/->recordSelect() with a custom
                // grouped array here: Filament's AttachAction already wires up a searchable
                // select correctly out of the box (real related-key values, live search
                // against `recordTitleAttribute`). A first attempt at grouping options by
                // `type` for easier browsing replaced that wiring with a static array whose
                // group keys Livewire ended up submitting as the record id itself (breaks
                // as soon as you pick anything — e.g. attaching under the "budget_tier"
                // group tried to attach node id "budget_tier"). recordSelectSearchColumns()
                // gets most of the "easier to find in ~40 nodes" benefit without touching
                // the wiring: admin can search by slug or type text, not just label.
                Tables\Actions\AttachAction::make()
                    ->recordSelectSearchColumns(['label', 'slug', 'type'])
                    // Filament's default attach doesn't know about the pivot's `relation_type`
                    // column (NOT NULL) — without this override it throws a DB error.
                    ->using(function (RelationManager $livewire, array $data) {
                        $livewire->getOwnerRecord()->{static::getRelationshipName()}()
                            ->attach($data['recordId'], ['relation_type' => static::$relationType]);
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
