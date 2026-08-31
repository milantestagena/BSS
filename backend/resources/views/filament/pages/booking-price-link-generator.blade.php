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
</x-filament-panels::page>
