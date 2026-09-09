<x-filament-panels::page>
    {{ $this->form }}

    @if (! empty($data['taxonomy_node_id'] ?? null))
        <div class="mt-6 flex flex-wrap items-end gap-4">
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Week</label>
                <select wire:model="week" class="fi-input mt-1 block w-56 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500">
                    <option value="">—</option>
                    @foreach ($this->seasonWeekOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Nights searched</label>
                <input type="number" min="1" wire:model="nights" class="fi-input mt-1 block w-24 rounded-lg border-none bg-white text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500" />
            </div>
        </div>

        <x-filament::section class="mt-6">
            <x-slot name="heading">Paste the filter sidebar</x-slot>
            <x-slot name="description">
                View Source (Ctrl+U) on the Hotels.com search results for this city + week (2 adults, 1 room), find the filter panel, and paste its HTML below. Reads the "Guest rating" section's "From" price next to "Any" (→ jeftino) and "Very good 8+" (→ kvalitet) — no sorting or filtering needed on your end. Each is (total price ÷ nights) × 1.10, rounded up to the nearest €10.
            </x-slot>

            <form wire:submit.prevent="extractAndSave">
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
                        Jeftino: <strong>${{ $extractedCheapTotal }}</strong> total → last saved
                    </div>
                    <div class="rounded-lg bg-success-50 px-3 py-1.5 text-success-700 dark:bg-success-400/10 dark:text-success-400">
                        Kvalitet: <strong>${{ $extractedQualityTotal }}</strong> total → last saved
                    </div>
                </div>
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
