<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PageVisitResource\Pages;
use App\Models\PageVisit;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Raw daily-visit log, read-only — see PageVisit model. No create/edit/delete: this is an
 * automatic log written by PageVisitResolver, not owner-managed data, same "list only" shape
 * WizardEvent would get if it had a resource yet.
 */
class PageVisitResource extends Resource
{
    protected static ?string $model = PageVisit::class;

    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationLabel = 'Page Visits';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('country')
                    ->badge()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('country')
                    ->options(fn () => PageVisit::query()->whereNotNull('country')->distinct()->orderBy('country')->pluck('country', 'country')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPageVisits::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
