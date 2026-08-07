<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * English `label` is the canonical source (see wizard_architecture / i18n decision); this tab
 * manages translations into other locales. Manual add/edit only for now — the "Prevedi" AI
 * button (GPT-4o-mini call, status='machine', hash-based staleness) is shared infrastructure
 * the Honest Report feature also needs and is intentionally out of scope here; this tab is
 * still fully usable for translations authored directly (status='human'), like the Serbian
 * ones already seeded.
 */
class TranslationsRelationManager extends RelationManager
{
    protected static string $relationship = 'translations';

    protected static ?string $title = 'Translations';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('field')->default('label')->required(),
            Forms\Components\TextInput::make('locale')->required()->maxLength(10)
                ->helperText('e.g. sr, de, fr'),
            Forms\Components\Textarea::make('value')->required(),
            Forms\Components\Select::make('status')
                ->options(['human' => 'Human', 'machine' => 'Machine', 'stale' => 'Stale'])
                ->default('human')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('value')
            ->columns([
                Tables\Columns\TextColumn::make('field'),
                Tables\Columns\TextColumn::make('locale')->badge(),
                Tables\Columns\TextColumn::make('value')->limit(50),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'stale' => 'danger',
                        'machine' => 'warning',
                        default => 'success',
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data, RelationManager $livewire) {
                        $data['source_hash'] = hash('crc32', (string) $livewire->getOwnerRecord()->{$data['field']});

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data, RelationManager $livewire) {
                        // Hand-editing a translation counts as a deliberate human correction —
                        // re-stamp the hash so it isn't immediately flagged 'stale' again.
                        $data['source_hash'] = hash('crc32', (string) $livewire->getOwnerRecord()->{$data['field']});
                        $data['status'] = $data['status'] === 'machine' ? 'human' : $data['status'];

                        return $data;
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
