<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

use App\Filament\Resources\TaxonomyNodeResource;
use App\Models\TaxonomyNode;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Which termin_categories this location (country/city) is good for, and which months —
 * e.g. Greece -> letovanje, months 6-9. Not a Base*EdgeRelationManager subclass because this
 * edge carries a payload (meta.months) that implies/suggests/excludes don't — see
 * TaxonomyNode::seasonalWindows().
 */
class SeasonalWindowRelationManager extends RelationManager
{
    protected static string $relationship = 'seasonalWindows';

    protected static ?string $title = 'Seasonal windows';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('label')
                    ->url(fn (TaxonomyNode $record) => TaxonomyNodeResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('pivot.meta')
                    ->label('Months')
                    ->formatStateUsing(fn ($state) => empty($state['months']) ? '—' : implode(', ', $state['months'])),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->recordSelectSearchColumns(['label', 'slug', 'type'])
                    ->form(fn (Tables\Actions\AttachAction $action) => [
                        $action->getRecordSelect(),
                        Forms\Components\TagsInput::make('months')
                            ->helperText('Month numbers, 1-12 (e.g. 6, 7, 8, 9 for a summer window).')
                            ->placeholder('6'),
                    ])
                    ->using(function (RelationManager $livewire, array $data) {
                        $months = collect($data['months'] ?? [])->map(fn ($m) => (int) $m)->values()->all();

                        $livewire->getOwnerRecord()->seasonalWindows()->attach($data['recordId'], [
                            'relation_type' => 'seasonal_window',
                            'meta' => json_encode(['months' => $months]),
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
