<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WizardCampaignDestinationPriceResource\Pages;
use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use App\Models\WizardCampaignDestinationPrice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Fill-in-the-blanks price grid — one row per (campaign, destination), owner's own
 * derivation off-app (3-bed apartment / 2.5, ~20% over the cheapest observed option — see
 * wizard_architecture, 2026-08-05). Rows are pre-created empty via
 * `campaign:seed-destination-price-rows` so this is scan-and-type, not one-at-a-time.
 * `price_per_person_eur` is inline-editable directly in the table (TextInputColumn) — the
 * whole point, per owner's ask, is fast bulk entry across ~37 destinations.
 */
class WizardCampaignDestinationPriceResource extends Resource
{
    protected static ?string $model = WizardCampaignDestinationPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-euro';

    protected static ?string $navigationLabel = 'Campaign Prices';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('wizard_campaign_id')
                ->label('Campaign')
                ->relationship('campaign', 'label')
                ->required()
                ->searchable()
                ->preload(),
            Forms\Components\Select::make('taxonomy_node_id')
                ->label('Destination')
                ->relationship('destination', 'label', fn ($query) => $query->where('type', 'city'))
                ->required()
                ->searchable()
                ->preload(),
            Forms\Components\TextInput::make('price_per_person_eur')
                ->numeric()->step('any')->prefix('€')->label('Price / person / night'),
            Forms\Components\Toggle::make('includes_meals')
                ->label('Includes meals (all-inclusive / full-board)')
                ->helperText('Check this if the price already bundles food — e.g. Egyptian Red Sea resorts. The separate food estimate is skipped for these so it is not double-counted.'),
            Forms\Components\TextInput::make('notes')
                ->maxLength(255)
                ->helperText('e.g. "3-bed apt / 2.5, +20%"'),
            Forms\Components\TextInput::make('source')
                ->default('manual_website')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('campaign.label')->badge()->sortable(),
                Tables\Columns\TextColumn::make('destination.parent.label')->label('Country')->sortable(),
                Tables\Columns\TextColumn::make('destination.label')->label('Destination')->searchable()->sortable(),
                Tables\Columns\TextInputColumn::make('price_per_person_eur')
                    ->label('€ / person / night')
                    ->type('number')
                    ->step('any')
                    ->rules(['nullable', 'numeric', 'min:0']),
                Tables\Columns\ToggleColumn::make('includes_meals')
                    ->label('All-incl.')
                    ->tooltip('Price already includes meals — food estimate is skipped for this destination'),
                Tables\Columns\TextColumn::make('notes')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->defaultGroup('campaign.label')
            ->defaultSort('destination.label')
            ->filters([
                Tables\Filters\SelectFilter::make('wizard_campaign_id')
                    ->label('Campaign')
                    ->relationship('campaign', 'label'),
                Tables\Filters\SelectFilter::make('country')
                    ->searchable()
                    ->options(fn () => TaxonomyNode::where('type', 'country')->orderBy('label')->pluck('label', 'id'))
                    ->query(function ($query, array $data) {
                        if (! empty($data['value'])) {
                            $query->whereHas('destination', fn ($q) => $q->where('parent_id', $data['value']));
                        }
                    }),
                Tables\Filters\Filter::make('missing_price')
                    ->label('Missing price only')
                    ->query(fn ($query) => $query->whereNull('price_per_person_eur')),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWizardCampaignDestinationPrices::route('/'),
            'create' => Pages\CreateWizardCampaignDestinationPrice::route('/create'),
            'edit' => Pages\EditWizardCampaignDestinationPrice::route('/{record}/edit'),
        ];
    }
}
