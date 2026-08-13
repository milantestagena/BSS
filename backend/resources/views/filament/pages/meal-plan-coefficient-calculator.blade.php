<x-filament-panels::page>
    <form wire:submit.prevent="compute">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap items-center gap-4">
            <x-filament::button type="submit">
                Compute coefficient
            </x-filament::button>

            @if ($computedCoefficient !== null)
                <span class="text-lg font-semibold">
                    Computed coefficient: {{ $computedCoefficient }}
                </span>

                <x-filament::button color="success" wire:click="save">
                    Save to country
                </x-filament::button>
            @endif
        </div>

        @if ($currentCoefficient !== null)
            <p class="mt-4 text-sm text-gray-500">
                Currently saved for this country: {{ $currentCoefficient }}
            </p>
        @endif
    </form>
</x-filament-panels::page>
