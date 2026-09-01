<x-filament-panels::page>
    {{ $this->form }}

    @if (! empty($data['taxonomy_node_id'] ?? null))
        <div class="mt-4 flex flex-wrap items-center gap-3">
            @if ($url = $this->startWeekUrl())
                <x-filament::button tag="a" :href="$url" target="_blank" color="success" icon="heroicon-o-arrow-top-right-on-square">
                    Open Sep 5
                </x-filament::button>
            @endif
            @if ($url = $this->endWeekUrl())
                <x-filament::button tag="a" :href="$url" target="_blank" color="success" icon="heroicon-o-arrow-top-right-on-square">
                    Open Oct 24
                </x-filament::button>
            @endif
        </div>

        <div class="mt-4 flex flex-wrap gap-3">
            @foreach ($this->currentWeeklyPricesFor() as $row)
                <div class="rounded-lg px-3 py-1.5 text-xs {{ $row['price'] !== null ? 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' : 'bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500' }}">
                    {{ $row['label'] }}: <strong>{{ $row['price'] !== null ? '€'.number_format($row['price'], 0) : '—' }}</strong>
                </div>
            @endforeach
        </div>
    @endif

    <x-filament::section class="mt-8">
        <x-slot name="heading">Extract prices from pasted page source</x-slot>
        <x-slot name="description">
            Open both links above, sort by price, then Ctrl+U (View Source) and paste each full page source below — avoids the chat's message-length cutoff on real search pages.
        </x-slot>

        <form wire:submit.prevent="extractPrices">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Sep 5 page source</label>
                    <textarea
                        wire:model="pastedHtmlStart"
                        rows="6"
                        placeholder="Paste Sep 5 Booking.com page source here…"
                        class="fi-input mt-1 block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
                    ></textarea>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Oct 24 page source</label>
                    <textarea
                        wire:model="pastedHtmlEnd"
                        rows="6"
                        placeholder="Paste Oct 24 Booking.com page source here…"
                        class="fi-input mt-1 block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
                    ></textarea>
                </div>
            </div>

            <x-filament::button type="submit" class="mt-4">
                Extract prices
            </x-filament::button>
        </form>

        @if (! empty($extractedListingsStart) || ! empty($extractedListingsEnd))
            <div class="mt-6 grid gap-6 md:grid-cols-2">
                @foreach (['Sep 5' => $extractedListingsStart, 'Oct 24' => $extractedListingsEnd] as $heading => $listings)
                    <div>
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $heading }}</h4>
                        @if (empty($listings))
                            <p class="mt-2 text-xs text-gray-400">Nothing pasted yet.</p>
                        @else
                            <div class="mt-2 overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                            <th class="py-2 pr-3">#</th>
                                            <th class="py-2 pr-3">Property</th>
                                            <th class="py-2 pr-3">Room type</th>
                                            <th class="py-2 pr-3">€/night</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($listings as $i => $listing)
                                            <tr class="border-b border-gray-100 dark:border-white/5 @if($listing['isAnomaly']) opacity-50 @endif">
                                                <td class="py-1.5 pr-3 text-gray-400">{{ $i + 1 }}</td>
                                                <td class="py-1.5 pr-3">{{ $listing['name'] }}</td>
                                                <td class="py-1.5 pr-3 text-gray-500">
                                                    {{ $listing['roomType'] ?? '—' }}
                                                    @if($listing['isAnomaly'])
                                                        <span class="ml-1 rounded bg-danger-50 px-1.5 py-0.5 text-xs text-danger-600 dark:bg-danger-400/10 dark:text-danger-400">flagged</span>
                                                    @endif
                                                </td>
                                                <td class="py-1.5 pr-3 font-medium">
                                                    {{ $listing['pricePerNight'] !== null ? '€'.number_format($listing['pricePerNight'], 2) : '—' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section class="mt-8">
        <x-slot name="heading">Two-anchor interpolation</x-slot>
        <x-slot name="description">
            Sep 5 / Oct 24 are pre-selected — Start/End price are pre-filled from the extraction above (still editable). Every week between gets linearly interpolated, rounded to the nearest €5.
        </x-slot>

        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Start week</label>
                <select wire:model.live="startWeek" class="fi-input mt-1 block w-48 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500">
                    <option value="">—</option>
                    @foreach ($this->seasonWeekOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Start €/person/night</label>
                <input type="number" step="0.01" wire:model.live="startPrice" class="fi-input mt-1 block w-32 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500" />
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">End week</label>
                <select wire:model.live="endWeek" class="fi-input mt-1 block w-48 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500">
                    <option value="">—</option>
                    @foreach ($this->seasonWeekOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">End €/person/night</label>
                <input type="number" step="0.01" wire:model.live="endPrice" class="fi-input mt-1 block w-32 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500" />
            </div>
        </div>

        @if (! empty($interpolatedWeeks))
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">Week</th>
                            <th class="py-2 pr-4">€/person/night</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($interpolatedWeeks as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4">{{ $row['label'] }}</td>
                                <td class="py-2 pr-4 font-medium">€{{ number_format($row['price'], 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-filament::button wire:click="saveInterpolatedWeeks" color="success" class="mt-4">
                Save all {{ count($interpolatedWeeks) }} weeks for this city
            </x-filament::button>
        @endif

        @if (! empty($data['taxonomy_node_id'] ?? null))
            <div class="mt-6 border-t border-gray-100 pt-4 dark:border-white/5">
                <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Currently saved for this city</p>
                <div class="flex flex-wrap gap-3">
                    @foreach ($this->currentWeeklyPricesFor() as $row)
                        <div class="rounded-lg px-3 py-1.5 text-xs {{ $row['price'] !== null ? 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' : 'bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500' }}">
                            {{ $row['label'] }}: <strong>{{ $row['price'] !== null ? '€'.number_format($row['price'], 0) : '—' }}</strong>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
