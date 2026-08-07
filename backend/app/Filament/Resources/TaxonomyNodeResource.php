<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxonomyNodeResource\Pages;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\ClimateRelationManager;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\CostWeightRelationManager;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\ExcludesRelationManager;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\ImpliesRelationManager;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\LateSummerPricesRelationManager;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\ReferencedByRelationManager;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\SeasonalWindowRelationManager;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\SuggestsRelationManager;
use App\Filament\Resources\TaxonomyNodeResource\RelationManagers\TranslationsRelationManager;
use App\Models\TaxonomyNode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TaxonomyNodeResource extends Resource
{
    protected static ?string $model = TaxonomyNode::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Taxonomy';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('type')
                        ->required()
                        ->maxLength(255)
                        ->datalist(fn () => TaxonomyNode::query()->distinct()->orderBy('type')->pluck('type')->all())
                        ->live()
                        ->helperText('Autocomplete against existing types, but you can type a brand-new one — no code deploy needed.'),
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('label')
                        ->required()
                        ->maxLength(255)
                        ->helperText('English canonical source. Other languages are translations, added in the Translations tab after saving.'),
                    Forms\Components\Select::make('parent_id')
                        ->label('Parent')
                        ->relationship('parent', 'label')
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('sort_order')
                        ->required()
                        ->numeric()
                        ->default(0),
                    Forms\Components\Select::make('booking_location_id')
                        ->label('Booking location')
                        ->relationship('bookingLocation', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Real Booking dest_id match once known, or a fake test Location for now — see Locations (Booking) in the nav.'),
                ]),

            // meta — type-conditional groups over a generic JSON fallback. See plan:
            // KeyValue only does string->string, but country/city meta has nested arrays,
            // so dedicated TagsInput fields per known type, JSON textarea for anything else.
            //
            // ->dehydrated() on every group (matching its own ->visible() condition) is
            // required, not optional: ->visible() only hides a field from the DOM, it doesn't
            // stop Filament from writing that field's (often empty-array) default into the
            // shared `meta` state. Without it, every group's leftover keys (best_seasons: [],
            // date_tag: null, min: null, ...) merge into the SAME top-level `meta` array
            // regardless of the selected type, and the plain Textarea fallback below ends up
            // bound to that merged array instead of a clean JSON string — rendered client-side
            // as the literal text "[object Object]" instead of real JSON.
            Forms\Components\Section::make('Meta')
                ->schema([
                    Forms\Components\Group::make([
                        Forms\Components\TagsInput::make('meta.best_seasons'),
                        Forms\Components\TagsInput::make('meta.atmosphere'),
                        Forms\Components\TagsInput::make('meta.drinks'),
                        Forms\Components\TagsInput::make('meta.food'),
                        Forms\Components\TagsInput::make('meta.budget'),
                    ])
                        ->columns(2)
                        ->visible(fn (Get $get) => in_array($get('type'), ['country', 'city']))
                        ->dehydrated(fn (Get $get) => in_array($get('type'), ['country', 'city'])),

                    // Powers TaxonomyNode::distanceKmTo (haversine) — see
                    // wizard_architecture's distance-from-home mechanism.
                    Forms\Components\Group::make([
                        Forms\Components\TextInput::make('meta.lat')->numeric()->step('any')->label('Latitude'),
                        Forms\Components\TextInput::make('meta.lng')->numeric()->step('any')->label('Longitude'),
                        Forms\Components\Toggle::make('meta.on_sea')
                            ->label('On the sea (coastal)')
                            ->helperText('Drives whether the Climate tab\'s sea_temp_c column is meaningful for this city.'),
                        Forms\Components\Toggle::make('meta.has_beach')
                            ->label('Has a real beach')
                            ->helperText('Coastal ≠ beach (e.g. most of Malta is "on the sea" but only a handful of spots have an actual beach). Coarse for now, owner refines per-city later.'),
                    ])
                        ->columns(2)
                        ->visible(fn (Get $get) => in_array($get('type'), ['country', 'city']))
                        ->dehydrated(fn (Get $get) => in_array($get('type'), ['country', 'city'])),

                    // Destination-side cost-of-living data — matched against a session's
                    // weighted_toward edges (see CostWeightRelationManager + wizard_architecture,
                    // 2026-07-13). `priced_at`/`source` per category so a future refresh job can
                    // tell stale entries from fresh ones without a separate audit table.
                    Forms\Components\Group::make([
                        Forms\Components\Fieldset::make('Ugostiteljstvo')
                            ->schema([
                                Forms\Components\TextInput::make('meta.hospitality.avg_restaurant_meal_eur')->numeric()->prefix('€')->label('Obrok u restoranu'),
                                Forms\Components\TextInput::make('meta.hospitality.avg_cafe_coffee_eur')->numeric()->prefix('€')->label('Kafa'),
                                Forms\Components\TextInput::make('meta.hospitality.avg_bar_beer_eur')->numeric()->prefix('€')->label('Pivo (kafić)'),
                                Forms\Components\DatePicker::make('meta.hospitality.priced_at')->label('Cena važi od'),
                                Forms\Components\TextInput::make('meta.hospitality.source')->label('Izvor'),
                            ])
                            ->columns(3),
                        Forms\Components\Fieldset::make('Lokalne prodavnice')
                            ->schema([
                                Forms\Components\TextInput::make('meta.local_stores.avg_store_beer_eur')->numeric()->prefix('€')->label('Pivo (prodavnica)'),
                                Forms\Components\TextInput::make('meta.local_stores.avg_meat_price_eur_kg')->numeric()->prefix('€')->label('Meso (kg)'),
                                // Owner's catch, 2026-07-14: cigarette price varies hugely by
                                // excise duty, not just cost-of-living generally — real enough
                                // that people cross into lower-tax transit countries just to
                                // buy their duty-free personal allowance. Worth a preference_tag
                                // ("jeftine cigarete"?) once there's a real reason to build that
                                // branch — not done now, this is just the raw data point.
                                Forms\Components\TextInput::make('meta.local_stores.avg_cigarettes_pack_eur')->numeric()->prefix('€')->label('Cigarete (pakla)'),
                                Forms\Components\DatePicker::make('meta.local_stores.priced_at')->label('Cena važi od'),
                                Forms\Components\TextInput::make('meta.local_stores.source')->label('Izvor'),
                            ])
                            ->columns(3),
                        Forms\Components\Fieldset::make('Prevoz')
                            ->schema([
                                Forms\Components\TextInput::make('meta.transport.avg_public_transport_ticket_eur')->numeric()->prefix('€')->label('Karta gradskog prevoza'),
                                Forms\Components\DatePicker::make('meta.transport.priced_at')->label('Cena važi od'),
                                Forms\Components\TextInput::make('meta.transport.source')->label('Izvor'),
                            ])
                            ->columns(3),
                    ])
                        ->visible(fn (Get $get) => in_array($get('type'), ['country', 'city']))
                        ->dehydrated(fn (Get $get) => in_array($get('type'), ['country', 'city'])),

                    Forms\Components\Group::make([
                        Forms\Components\TextInput::make('meta.date_tag')
                            ->datalist(['summer', 'winter', 'holiday', 'any', 'exact']),
                        Forms\Components\TextInput::make('meta.default_duration_days')->numeric(),
                    ])
                        ->columns(2)
                        ->visible(fn (Get $get) => $get('type') === 'termin_category')
                        ->dehydrated(fn (Get $get) => $get('type') === 'termin_category'),

                    Forms\Components\Group::make([
                        Forms\Components\TextInput::make('meta.min')->numeric(),
                        Forms\Components\TextInput::make('meta.max')->numeric(),
                        Forms\Components\Select::make('meta.currency')->options(['EUR' => 'EUR'])->default('EUR'),
                    ])
                        ->columns(3)
                        ->visible(fn (Get $get) => $get('type') === 'budget_tier')
                        ->dehydrated(fn (Get $get) => $get('type') === 'budget_tier'),

                    Forms\Components\Group::make([
                        Forms\Components\TagsInput::make('meta.booking_accommodation_type_ids')
                            ->helperText('Real Booking.com accommodation_type IDs once known — see wizard_architecture.'),
                        Forms\Components\Toggle::make('meta.is_hostel'),
                        Forms\Components\Toggle::make('meta.is_shared_room'),
                    ])
                        ->columns(3)
                        ->visible(fn (Get $get) => $get('type') === 'tip_smestaja')
                        ->dehydrated(fn (Get $get) => $get('type') === 'tip_smestaja'),

                    // Read-only on purpose: an editable Textarea sharing the `meta` key
                    // collides client-side with the dot-notation `meta.xxx` fields above
                    // (Livewire renders it as "[object Object]" instead of JSON, regardless of
                    // ->visible()/->dehydrated() — a binding issue, not a formatting one).
                    // None of the types without a dedicated group above currently carry any
                    // meta content, so a plain dump is enough for now; revisit with a proper
                    // editable field (different state key, reconciled in a save hook) if that
                    // changes.
                    Forms\Components\Placeholder::make('meta_display')
                        ->label('Meta')
                        ->visible(fn (Get $get) => ! in_array($get('type'), ['country', 'city', 'termin_category', 'budget_tier', 'tip_smestaja']))
                        ->content(function (?TaxonomyNode $record) {
                            if (! $record?->meta) {
                                return 'No meta content for this type.';
                            }

                            return new \Illuminate\Support\HtmlString(
                                '<pre style="white-space: pre-wrap;">' . e(print_r($record->meta, true)) . '</pre>'
                            );
                        })
                        ->helperText('No dedicated fields for this type yet — read-only dump.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('label')->searchable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('parent.label')->label('Parent')->toggleable()
                    ->url(fn (TaxonomyNode $record) => $record->parent_id ? static::getUrl('edit', ['record' => $record->parent_id]) : null),
                Tables\Columns\TextColumn::make('sort_order')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultGroup('type')
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(fn () => TaxonomyNode::query()->distinct()->orderBy('type')->pluck('type', 'type')->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('fetchClimate')
                    ->label('Fetch climate')
                    ->icon('heroicon-o-cloud')
                    ->visible(fn (TaxonomyNode $record) => in_array($record->type, ['country', 'city']) && $record->meta['lat'] ?? null)
                    ->requiresConfirmation()
                    ->action(function (TaxonomyNode $record) {
                        $result = static::fetchClimateFor($record, app(\App\Services\OpenMeteoClient::class));
                        \Filament\Notifications\Notification::make()
                            ->title($result ? "Climate updated for {$record->label}" : 'No coordinates on this node')
                            ->status($result ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('fetchClimateBulk')
                        ->label('Fetch climate (Open-Meteo)')
                        ->icon('heroicon-o-cloud')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $client = app(\App\Services\OpenMeteoClient::class);
                            $updated = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (static::fetchClimateFor($record, $client)) {
                                    $updated++;
                                } else {
                                    $skipped++;
                                }
                            }

                            \Filament\Notifications\Notification::make()
                                ->title("Climate updated for {$updated} node(s)" . ($skipped ? ", skipped {$skipped} (no type match or no lat/lng)" : ''))
                                ->status('success')
                                ->send();
                        }),
                ]),
            ]);
    }

    /**
     * Fetches real historical monthly climate (Open-Meteo) for one country/city node and
     * upserts its taxonomy_node_climates rows (source='open_meteo', overwriting any earlier
     * manual_estimate). Returns false (no-op) for non-geography types or nodes without
     * meta.lat/meta.lng — callers report that as "skipped", not an error.
     */
    private static function fetchClimateFor(TaxonomyNode $record, \App\Services\OpenMeteoClient $client): bool
    {
        if (! in_array($record->type, ['country', 'city']) || empty($record->meta['lat']) || empty($record->meta['lng'])) {
            return false;
        }

        $year = now()->subYear()->year;
        $lat = $record->meta['lat'];
        $lng = $record->meta['lng'];

        $climate = $client->monthlyClimate($lat, $lng, $year);
        $seaTemp = $record->meta['on_sea'] ?? false ? $client->monthlySeaTemp($lat, $lng, $year) : [];

        foreach ($climate as $month => $values) {
            \App\Models\TaxonomyNodeClimate::updateOrCreate(
                ['taxonomy_node_id' => $record->id, 'month' => $month],
                [...$values, 'sea_temp_c' => $seaTemp[$month]['sea_temp_c'] ?? null, 'source' => 'open_meteo']
            );
        }

        return true;
    }

    public static function getRelations(): array
    {
        return [
            ImpliesRelationManager::class,
            SuggestsRelationManager::class,
            ExcludesRelationManager::class,
            SeasonalWindowRelationManager::class,
            CostWeightRelationManager::class,
            ClimateRelationManager::class,
            LateSummerPricesRelationManager::class,
            ReferencedByRelationManager::class,
            TranslationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyNodes::route('/'),
            'create' => Pages\CreateTaxonomyNode::route('/create'),
            'edit' => Pages\EditTaxonomyNode::route('/{record}/edit'),
        ];
    }
}
