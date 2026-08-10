<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralPartnerResource\Pages;
use App\Filament\Resources\ReferralPartnerResource\RelationManagers\ReferralCodesRelationManager;
use App\Models\ReferralPartner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Reseller profiles — see CLAUDE.md section 6. No create page here: a reseller is an EXISTING
 * customer User promoted by the admin (UserResource's "Make reseller" action), which is what
 * creates this row. Editable here afterward: share_percentage/status/notes, plus their
 * referral codes (relation manager).
 */
class ReferralPartnerResource extends Resource
{
    protected static ?string $model = ReferralPartner::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Referrals';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('share_percentage')
                ->numeric()
                ->required()
                ->suffix('%')
                ->helperText('Applied to the FIRST booking only — see CLAUDE.md section 6 decay tiers (2nd = 15%, 3rd = 5%, fixed).'),
            Forms\Components\Select::make('status')
                ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                ->required(),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Name')->weight(FontWeight::Medium)->searchable(),
                Tables\Columns\TextColumn::make('user.email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('share_percentage')->label('First-booking share')->suffix('%'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('referral_codes_count')->counts('referralCodes')->label('Codes'),
                Tables\Columns\TextColumn::make('created_at')->label('Promoted')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ReferralCodesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralPartners::route('/'),
            'edit' => Pages\EditReferralPartner::route('/{record}/edit'),
        ];
    }
}
