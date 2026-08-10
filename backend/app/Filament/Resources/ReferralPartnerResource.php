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
use Illuminate\Support\Facades\Hash;

/**
 * Influencer/affiliate partners — see CLAUDE.md section 6. Manually onboarded here (unlike
 * User, which only comes from Google OAuth): `share_percentage` is per-partner negotiated, and
 * this is also where a login password gets set for the partner-facing dashboard
 * (PartnerAuthController, 'partner' guard).
 */
class ReferralPartnerResource extends Resource
{
    protected static ?string $model = ReferralPartner::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Referrals';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('password')
                ->password()
                ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                ->dehydrated(fn (?string $state) => filled($state))
                ->required(fn (string $context) => $context === 'create')
                ->helperText('Leave blank to keep the current password when editing.'),
            Forms\Components\TextInput::make('share_percentage')
                ->numeric()
                ->required()
                ->default(50)
                ->suffix('%')
                ->helperText('Applied to the FIRST booking only — see CLAUDE.md section 6 decay tiers (2nd = 15%, 3rd = 5%, fixed).'),
            Forms\Components\Select::make('status')
                ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                ->default('active')
                ->required(),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight(FontWeight::Medium)->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('share_percentage')->label('First-booking share')->suffix('%'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('referral_codes_count')->counts('referralCodes')->label('Codes'),
                Tables\Columns\TextColumn::make('created_at')->label('Added')->dateTime()->sortable(),
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
            'create' => Pages\CreateReferralPartner::route('/create'),
            'edit' => Pages\EditReferralPartner::route('/{record}/edit'),
        ];
    }
}
