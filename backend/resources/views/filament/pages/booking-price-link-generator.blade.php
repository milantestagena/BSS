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
            @if ($suggestedPrice)
                <div class="mt-6 rounded-lg bg-success-50 p-3 text-sm text-success-700 ring-1 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400">
                    Suggested reference price (3rd clean listing + 10% margin): <strong>€{{ number_format($suggestedPrice, 2) }}</strong> — sanity-check by eye before storing.
                </div>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">#</th>
                            <th class="py-2 pr-4">Property</th>
                            <th class="py-2 pr-4">Room type</th>
                            <th class="py-2 pr-4">Price</th>
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
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
