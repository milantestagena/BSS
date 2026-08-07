<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WizardQuestionResource\Pages;
use App\Models\WizardQuestion;
use App\Models\WizardStep;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WizardQuestionResource extends Resource
{
    protected static ?string $model = WizardQuestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Wizard Questions';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('wizard_step_id')
                ->label('Step')
                ->relationship('step', 'label')
                ->required()
                ->searchable()
                ->preload(),
            Forms\Components\TextInput::make('key')->required()->maxLength(255),
            Forms\Components\TextInput::make('label')
                ->required()
                ->maxLength(255)
                ->helperText('English canonical source — translations added separately.'),
            Forms\Components\Select::make('input_type')
                ->required()
                ->options([
                    'taxonomy_choice' => 'Taxonomy choice',
                    'taxonomy_multi_choice' => 'Taxonomy multi-choice',
                    'number' => 'Number',
                    'number_array' => 'Number array',
                    'date_range' => 'Date range',
                    'boolean' => 'Boolean',
                    'text' => 'Text',
                ]),
            Forms\Components\TextInput::make('taxonomy_type')
                ->maxLength(255)
                ->helperText('Which TaxonomyNode.type this question offers as choices — only for taxonomy_choice/taxonomy_multi_choice.'),
            Forms\Components\TextInput::make('session_field')
                ->maxLength(255)
                ->helperText('SearchSession column, or "free_text_answers.key" for jsonb-bag fields.'),
            Forms\Components\Toggle::make('allow_free_text')->default(false),
            Forms\Components\Select::make('depends_on_taxonomy_node_id')
                ->label('Only show if this was selected')
                ->relationship('dependsOn', 'label')
                ->searchable(['label', 'slug', 'type'])
                ->preload()
                ->helperText('Leave empty to always show this question. Drives sub-questions (e.g. "jeftino" -> budget question) without a code deploy.'),
            Forms\Components\TextInput::make('sort_order')->required()->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('step.label')->label('Step')->sortable(),
                Tables\Columns\TextColumn::make('key')->searchable(),
                Tables\Columns\TextColumn::make('label')->searchable(),
                Tables\Columns\TextColumn::make('input_type')->badge(),
                Tables\Columns\TextColumn::make('taxonomy_type')->toggleable(),
                Tables\Columns\TextColumn::make('dependsOn.label')->label('Depends on')->toggleable()
                    ->url(fn (WizardQuestion $record) => $record->depends_on_taxonomy_node_id
                        ? TaxonomyNodeResource::getUrl('edit', ['record' => $record->depends_on_taxonomy_node_id])
                        : null),
                Tables\Columns\IconColumn::make('allow_free_text')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sort_order')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->defaultGroup('step.label')
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('wizard_step_id')
                    ->label('Step')
                    ->options(fn () => WizardStep::query()->orderBy('sort_order')->pluck('label', 'id')->all()),
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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWizardQuestions::route('/'),
            'create' => Pages\CreateWizardQuestion::route('/create'),
            'edit' => Pages\EditWizardQuestion::route('/{record}/edit'),
        ];
    }
}
