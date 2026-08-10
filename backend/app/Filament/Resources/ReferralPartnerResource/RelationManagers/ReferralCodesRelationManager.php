<?php

namespace App\Filament\Resources\ReferralPartnerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Codes a partner can hand out (?ref=CODE on the wizard URL) — see CLAUDE.md section 6. A
 * partner can have more than one, e.g. for different campaigns/channels.
 */
class ReferralCodesRelationManager extends RelationManager
{
    protected static string $relationship = 'referralCodes';

    protected static ?string $recordTitleAttribute = 'code';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('label')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code'),
                Tables\Columns\TextColumn::make('label')->placeholder('—'),
                Tables\Columns\TextColumn::make('attributions_count')->counts('attributions')->label('Referred users'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
