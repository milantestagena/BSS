<x-filament-panels::page>
    <div class="space-y-3">
        @foreach ($rows as $row)
            <div class="flex items-center gap-4">
                <div class="w-48 shrink-0 text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ $row['label'] }}
                </div>
                <div class="h-6 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-gray-800">
                    <div
                        class="h-full rounded bg-primary-500"
                        style="width: {{ $row['percent'] }}%"
                    ></div>
                </div>
                <div class="w-16 shrink-0 text-right text-sm font-semibold text-gray-900 dark:text-white">
                    {{ $row['count'] }}
                </div>
            </div>
        @endforeach
    </div>
    <p class="mt-6 text-xs text-gray-400">
        Distinct sessions that reached each step. Not strictly decreasing — some steps are
        preset/skipped depending on the campaign, so a later step can show more sessions than an
        earlier one that's commonly bypassed.
    </p>
</x-filament-panels::page>
