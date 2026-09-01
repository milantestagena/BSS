<x-filament-panels::page>
    <form wire:submit.prevent="generate">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap items-center gap-4">
            <x-filament::button type="submit">
                Generate link
            </x-filament::button>

            @if ($generatedUrl)
                <x-filament::button tag="a" :href="$generatedUrl" target="_blank" color="success" icon="heroicon-o-arrow-top-right-on-square">
                    Open on Booking.com
                </x-filament::button>
            @endif
        </div>

        @if ($generatedUrl)
            <p class="mt-4 break-all text-sm text-gray-500">{{ $generatedUrl }}</p>
        @endif
    </form>

    @if (! empty($data['taxonomy_node_id'] ?? null))
        <div class="mt-6 flex flex-wrap gap-3">
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
            Open the link above, sort by price, then Ctrl+U (View Source) and paste the full HTML here — avoids the chat's message-length cutoff on real search pages.
        </x-slot>

        <form wire:submit.prevent="extractPrices">
            <textarea
                wire:model="pastedHtml"
                rows="6"
                placeholder="Paste Booking.com page source here…"
                class="fi-input block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
            ></textarea>

            <x-filament::button type="submit" class="mt-4">
                Extract prices
            </x-filament::button>
        </form>

        @if (! empty($extractedListings))
            @if ($nights)
                <p class="mt-6 text-sm text-gray-500">{{ $nights }} {{ Str::plural('night', $nights) }} — pick a row below by eye and enter its €/night as the value to save.</p>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">#</th>
                            <th class="py-2 pr-4">Property</th>
                            <th class="py-2 pr-4">Room type</th>
                            <th class="py-2 pr-4">Price</th>
                            <th class="py-2 pr-4">€/night</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($extractedListings as $i => $listing)
                            <tr class="border-b border-gray-100 dark:border-white/5 @if($listing['isAnomaly']) opacity-50 @endif">
                                <td class="py-2 pr-4 text-gray-400">{{ $i + 1 }}</td>
                                <td class="py-2 pr-4">{{ $listing['name'] }}</td>
                                <td class="py-2 pr-4 text-gray-500">
                                    {{ $listing['roomType'] ?? '—' }}
                                    @if($listing['isAnomaly'])
                                        <span class="ml-1 rounded bg-danger-50 px-1.5 py-0.5 text-xs text-danger-600 dark:bg-danger-400/10 dark:text-danger-400">flagged</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 font-medium">
                                    {{ $listing['price'] !== null ? '€'.number_format($listing['price'], 2) : '—' }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ $listing['pricePerNight'] !== null ? '€'.number_format($listing['pricePerNight'], 2) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form wire:submit.prevent="savePrice" class="mt-6 flex flex-wrap items-end gap-4 border-t border-gray-100 pt-4 dark:border-white/5">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">€/person/night to save</label>
                    <input
                        type="number"
                        step="0.01"
                        wire:model="priceToSaveEur"
                        class="fi-input mt-1 block w-40 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
                    />
                </div>
                <x-filament::button type="submit" color="success">
                    Save price for this city + week
                </x-filament::button>
            </form>
        @endif
    </x-filament::section>

    <x-filament::section class="mt-8">
        <x-slot name="heading">Two-anchor interpolation</x-slot>
        <x-slot name="description">
            Research just two real weeks — e.g. Sep 5 and Oct 24, skipping the Nov-crossing last week (known outlier for both Alanya and Bodrum) — and every week between gets linearly interpolated, rounded to the nearest €5. Uses the city already picked above.
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
                @if ($url = $this->anchorUrlFor($startWeek))
                    <a href="{{ $url }}" target="_blank" class="mt-1 block text-xs text-primary-600 underline">Open on Booking.com</a>
                @endif
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
                @if ($url = $this->anchorUrlFor($endWeek))
                    <a href="{{ $url }}" target="_blank" class="mt-1 block text-xs text-primary-600 underline">Open on Booking.com</a>
                @endif
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">End €/person/night</label>
                <input type="number" step="0.01" wire:model.live="endPrice" class="fi-input mt-1 block w-32 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500" />
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-500">Pick a city in the form at the top of the page first — the quick links above use it.</p>

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
    </x-filament::section>
</x-filament-panels::page>
