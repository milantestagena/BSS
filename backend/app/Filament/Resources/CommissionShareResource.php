<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommissionShareResource\Pages;
use App\Models\CommissionShare;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Obligations owed to referral partners, one row per confirmed booking — see CLAUDE.md
 * section 6. Read-mostly: rows are created by GenerateCommissionShare (BookingConfirmed
 * listener), not manually here. `estimated_amount_eur` starts null and gets filled in by hand
 * during the monthly Partner Centre CSV reconciliation — there is no Details API access below
 * the 20k-bookings/year threshold, so this is an estimate updated manually, not automated.
 */
class CommissionShareResource extends Resource
{
    protected static ?string $model = CommissionShare::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Referrals';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('attribution.partner.user.name')->label('Partner')->searchable(),
                Tables\Columns\TextColumn::make('attribution.user.name')->label('Referred user'),
                Tables\Columns\TextColumn::make('booking_reference')->placeholder('—'),
                Tables\Columns\TextColumn::make('booking_sequence_number')->label('Booking #'),
                Tables\Columns\TextColumn::make('share_percentage_applied')->suffix('%'),
                Tables\Columns\TextColumn::make('estimated_amount_eur')->money('EUR')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success',
                        'reconciled' => 'info',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Booked')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'reconciled' => 'Reconciled', 'paid' => 'Paid']),
            ])
            ->actions([
                Tables\Actions\Action::make('reconcile')
                    ->visible(fn (CommissionShare $record) => $record->status === 'pending')
                    ->form([
                        Forms\Components\TextInput::make('estimated_amount_eur')
                            ->numeric()
                            ->required()
                            ->prefix('€')
                            ->helperText('From the Partner Centre CSV export for this booking.'),
                    ])
                    ->action(function (CommissionShare $record, array $data): void {
                        $record->update([
                            'estimated_amount_eur' => $data['estimated_amount_eur'],
                            'status' => 'reconciled',
                            'reconciled_at' => now(),
                        ]);
                    }),
                Tables\Actions\Action::make('markPaid')
                    ->label('Mark paid')
                    ->visible(fn (CommissionShare $record) => $record->status === 'reconciled')
                    ->action(fn (CommissionShare $record) => $record->update(['status' => 'paid', 'paid_at' => now()])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionShares::route('/'),
        ];
    }
}
