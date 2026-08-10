<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\CreditTransaction;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * MVP-level admin view of users + AI-credit balance — see CLAUDE.md section 5 ("widget za
 * pregled balansa korisnika u admin panelu (MVP nivo, ne treba komplikovan UI)"). Users are
 * created via Google OAuth (see GoogleAuthController), not manually here, so this resource is
 * read-focused with one manual action (adjust credits) rather than a full create/edit form.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('wallet.balance')->label('Credits')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('referral_source')->label('Referral')->placeholder('—')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('created_at')->label('Joined')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('adjustCredits')
                    ->label('Adjust credits')
                    ->icon('heroicon-o-plus-circle')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->helperText('Positive to grant, negative to deduct.'),
                        Forms\Components\TextInput::make('description')
                            ->maxLength(255),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->wallet()->increment('balance', (int) $data['amount']);
                        CreditTransaction::create([
                            'user_id' => $record->id,
                            'amount' => (int) $data['amount'],
                            'type' => 'manual_bonus',
                            'description' => $data['description'] ?? null,
                        ]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
        ];
    }
}
