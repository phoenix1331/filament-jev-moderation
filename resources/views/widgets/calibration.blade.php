<x-filament-widgets::widget>
    <x-filament::section
        :heading="$onlyFlagged ? __('filament-jev-moderation::widgets.calibration.flagged_heading') : __('filament-jev-moderation::widgets.calibration.heading')"
        :description="__('filament-jev-moderation::widgets.calibration.description', ['days' => $days, 'rate' => $rate, 'sample' => $sample])"
    >
        <x-slot name="afterHeader">
            <div class="fi-jev-moderation-calibration-controls">
                @if ($onlyFlagged && $thresholdsUrl !== null)
                    <x-filament::link :href="$thresholdsUrl" icon="heroicon-m-arrow-right" icon-position="after">
                        {{ __('filament-jev-moderation::widgets.calibration.see_all') }}
                    </x-filament::link>
                @endif

                @if (count($calibration->models) > 1)
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            wire:model.live="model"
                            :aria-label="__('filament-jev-moderation::widgets.calibration.version')"
                        >
                            @foreach ($calibration->models as $version)
                                <option value="{{ $version }}" @selected($version === $calibration->model)>{{ $version }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif
            </div>
        </x-slot>

        @if ($calibration->bands === [])
            <x-filament::empty-state
                icon="heroicon-o-scale"
                :heading="__('filament-jev-moderation::widgets.calibration.empty.heading')"
                :description="__('filament-jev-moderation::widgets.calibration.empty.description')"
                :contained="false"
            />
        @elseif ($policies === [])
            <p class="fi-jev-moderation-muted fi-jev-moderation-calibration-clear">
                {{ __('filament-jev-moderation::widgets.calibration.none_flagged', ['count' => count($calibration->bands)]) }}
            </p>
        @else
            @if ($calibration->model !== null && count($calibration->models) === 1)
                <p class="fi-jev-moderation-muted fi-jev-moderation-calibration-version">
                    {{ __('filament-jev-moderation::widgets.calibration.version') }}: {{ $calibration->model }}
                </p>
            @endif

            @foreach ($policies as $policy => $bands)
                @include('filament-jev-moderation::partials.calibration-bands', [
                    'caption' => __('filament-jev-moderation::widgets.calibration.policy', ['policy' => $policy]),
                    'bands' => $bands,
                    'sample' => $sample,
                    'commands' => [],
                ])
            @endforeach
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
