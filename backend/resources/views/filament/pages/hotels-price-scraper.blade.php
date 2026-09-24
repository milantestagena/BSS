<x-filament-panels::page>
    {{ $this->form }}

    @if (! empty($data['wizard_campaign_id'] ?? null) && ! empty($data['taxonomy_node_id'] ?? null))
        <x-filament::section class="mt-6">
            <x-slot name="heading">Anchor weeks (fast path — most cities)</x-slot>
            <x-slot name="description">
                Paste just the Start and End week's filter sidebar (2 adults, 1 room each). Each anchor's jeftino comes from the cheapest ordinary stay (hotel / apartment / villa / resort — a lone B&B, guesthouse or hostel is ignored), kvalitet from "Very good 8+" (never below jeftino). Every week between gets linearly interpolated from the two, rounded to the nearest €10 — plus one extrapolated week each side (only if that week is still in the future). Defaults to this campaign's full remaining range; narrow it per-city if a straight line doesn't fit.
            </x-slot>

            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Start week</label>
                    <select wire:model.live="startWeek" class="fi-input mt-1 block w-56 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500">
                        <option value="" style="color: #111827; background-color: #fff;">—</option>
                        @foreach ($this->seasonWeekOptions() as $value => $label)
                            <option value="{{ $value }}" style="color: #111827; background-color: #fff;">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">End week</label>
                    <select wire:model.live="endWeek" class="fi-input mt-1 block w-56 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500">
                        <option value="" style="color: #111827; background-color: #fff;">—</option>
                        @foreach ($this->seasonWeekOptions() as $value => $label)
                            <option value="{{ $value }}" style="color: #111827; background-color: #fff;">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($this->hotelsSearchUrlFor($startWeek))
                    <x-filament::button tag="a" :href="$this->hotelsSearchUrlFor($startWeek)" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" color="gray">
                        Open Start week
                    </x-filament::button>
                @endif
                @if ($this->hotelsSearchUrlFor($endWeek))
                    <x-filament::button tag="a" :href="$this->hotelsSearchUrlFor($endWeek)" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" color="gray">
                        Open End week
                    </x-filament::button>
                @endif
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Start week sidebar</label>
                    <textarea
                        wire:model="pastedHtmlStart"
                        rows="6"
                        placeholder="Paste the Start week's filter sidebar source here…"
                        class="fi-input mt-1 block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
                    ></textarea>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">End week sidebar</label>
                    <textarea
                        wire:model="pastedHtmlEnd"
                        rows="6"
                        placeholder="Paste the End week's filter sidebar source here…"
                        class="fi-input mt-1 block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
                    ></textarea>
                </div>
            </div>

            <x-filament::button wire:click="extractAnchorPrices" class="mt-4">
                Extract anchors
            </x-filament::button>

            @if ($startCheapPrice !== null || $endCheapPrice !== null)
                <div class="mt-4 flex flex-wrap gap-3 text-sm">
                    <div class="rounded-lg bg-gray-50 px-3 py-1.5 text-gray-700 dark:bg-white/5 dark:text-gray-300">
                        Start — Jeftino: <strong>{{ $startCheapPrice !== null ? '€'.number_format($startCheapPrice, 0) : '—' }}</strong> · Kvalitet: <strong>{{ $startQualityPrice !== null ? '€'.number_format($startQualityPrice, 0) : '—' }}</strong>
                    </div>
                    <div class="rounded-lg bg-gray-50 px-3 py-1.5 text-gray-700 dark:bg-white/5 dark:text-gray-300">
                        End — Jeftino: <strong>{{ $endCheapPrice !== null ? '€'.number_format($endCheapPrice, 0) : '—' }}</strong> · Kvalitet: <strong>{{ $endQualityPrice !== null ? '€'.number_format($endQualityPrice, 0) : '—' }}</strong>
                    </div>
                </div>
            @endif

            @if (! empty($interpolatedWeeks))
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-4">Week</th>
                                <th class="py-2 pr-4">Jeftino</th>
                                <th class="py-2 pr-4">Kvalitet</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($interpolatedWeeks as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pr-4">{{ $row['label'] }}</td>
                                    <td class="py-1.5 pr-4 font-medium">{{ $row['cheap'] !== null ? '€'.number_format($row['cheap'], 0) : '—' }}</td>
                                    <td class="py-1.5 pr-4 font-medium">{{ $row['quality'] !== null ? '€'.number_format($row['quality'], 0) : '—' }}</td>
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

        <x-filament::section class="mt-6">
            <x-slot name="heading">Single week override</x-slot>
            <x-slot name="description">
                For one specific week that shouldn't follow the straight-line estimate above. View Source (Ctrl+U) on the Hotels.com search results for this city + week (2 adults, 1 room), find the filter panel, and paste its HTML below. Jeftino = the cheapest ordinary stay in the "Property type" list (Hotel / Resort / Apartment / Aparthotel / Villa / Vacation home / Townhouse — a lone B&B, guesthouse or hostel is ignored); if that list is missing from the paste it falls back to the lower of the price-slider floor and "Guest rating: Any". Kvalitet = the "Guest rating: Very good 8+" row, never below jeftino. Each is (total price ÷ nights) × 1.10, rounded up to the nearest €10. Property class rows are shown below for a sanity check only — not saved.
            </x-slot>

            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Week</label>
                    <select wire:model.live="week" class="fi-input mt-1 block w-56 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500">
                        {{-- Options render in the BROWSER's own native (always light) dropdown popup,
                             which ignores our dark: text classes above — without an explicit color
                             here the dark:text-white on the closed <select> bleeds into the popup
                             list too, giving white text on that white popup background. --}}
                        <option value="" style="color: #111827; background-color: #fff;">—</option>
                        @foreach ($this->seasonWeekOptions() as $value => $label)
                            <option value="{{ $value }}" style="color: #111827; background-color: #fff;">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Nights searched</label>
                    <input type="number" min="1" wire:model.live="nights" class="fi-input mt-1 block w-24 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500" />
                </div>
                @if ($this->hotelsSearchUrlFor($week))
                    <x-filament::button tag="a" :href="$this->hotelsSearchUrlFor($week)" target="_blank" rel="noopener" icon="heroicon-o-arrow-top-right-on-square" color="gray">
                        Open Hotels.com search in new tab
                    </x-filament::button>
                @endif
            </div>

            <form wire:submit.prevent="extractAndSave" class="mt-4">
                <textarea
                    wire:model="pastedHtml"
                    rows="8"
                    placeholder="Paste the filter sidebar page source here…"
                    class="fi-input mt-1 block w-full rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
                ></textarea>

                <x-filament::button type="submit" class="mt-4">
                    Extract & save this week
                </x-filament::button>
            </form>

            @if ($extractedCheapTotal !== null || $extractedQualityTotal !== null)
                <div class="mt-4 flex flex-wrap gap-3 text-sm">
                    <div class="rounded-lg bg-success-50 px-3 py-1.5 text-success-700 dark:bg-success-400/10 dark:text-success-400">
                        Jeftino: <strong>{{ $extractedCheapTotal !== null ? '€'.number_format($extractedCheapTotal, 0) : '—' }}</strong>/night → saved
                    </div>
                    <div class="rounded-lg bg-success-50 px-3 py-1.5 text-success-700 dark:bg-success-400/10 dark:text-success-400">
                        Kvalitet: <strong>{{ $extractedQualityTotal !== null ? '€'.number_format($extractedQualityTotal, 0) : '—' }}</strong>/night → saved
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <div>Cheapest hotel/apartment/villa (jeftino source): <strong>{{ $extractedMainstreamFloor !== null ? '$'.$extractedMainstreamFloor : '— (fell back to the overall floor)' }}</strong></div>
                    <div>Price-slider floor: <strong>{{ $extractedPriceFloor !== null ? '$'.$extractedPriceFloor : '—' }}</strong></div>
                    <div>Guest rating "Any": <strong>{{ $extractedGuestRatingAny !== null ? '$'.$extractedGuestRatingAny : '—' }}</strong></div>
                </div>

                @if (! empty($extractedStarPrices))
                    <div class="mt-3">
                        <p class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Property class (sanity check only, not saved)</p>
                        <div class="flex flex-wrap gap-2 text-xs">
                            @foreach (['50' => '5★', '40' => '4★', '30' => '3★', '20' => '2★', '10' => '1★'] as $value => $label)
                                @if (isset($extractedStarPrices[$value]))
                                    <div class="rounded-lg bg-gray-50 px-2 py-1 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                        {{ $label }}: ${{ $extractedStarPrices[$value] }}
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </x-filament::section>

        <div class="mt-6 border-t border-gray-100 pt-4 dark:border-white/5">
            <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Currently saved for this city (€/night)</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">Week</th>
                            <th class="py-2 pr-4">Jeftino</th>
                            <th class="py-2 pr-4">Kvalitet</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->currentWeeklyPricesFor() as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $row['label'] }}</td>
                                <td class="py-1.5 pr-4 font-medium {{ $row['cheap'] === null ? 'text-gray-400' : '' }}">{{ $row['cheap'] !== null ? '€'.number_format($row['cheap'], 0) : '—' }}</td>
                                <td class="py-1.5 pr-4 font-medium {{ $row['quality'] === null ? 'text-gray-400' : '' }}">{{ $row['quality'] !== null ? '€'.number_format($row['quality'], 0) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
