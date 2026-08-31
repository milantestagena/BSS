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
        <x-slot name="heading">September / October calculator</x-slot>
        <x-slot name="description">
            Research whichever week has the most listed hotels (reliably 250+, where the fixed rank-25 rule applies without recomputing a percentile) and enter it as October — September is that + 10%, rounded to the nearest €5. Pure calculator, doesn't save anywhere — pick which real weeks get which value via the city/week/save flow above.
        </x-slot>

        <div class="flex flex-wrap items-end gap-6">
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">October (researched) €/person/night</label>
                <input
                    type="number"
                    step="0.01"
                    wire:model.live="octoberPrice"
                    class="fi-input mt-1 block w-40 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
                />
            </div>

            @if ($septemberPrice !== null)
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    September: <strong class="text-base">€{{ number_format($septemberPrice, 0) }}</strong>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
