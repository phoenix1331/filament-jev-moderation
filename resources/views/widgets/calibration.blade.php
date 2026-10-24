@use('Phoenix1331\FilamentJevModeration\Widgets\CalibrationTable')

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('filament-jev-moderation::widgets.calibration.heading')"
        :description="__('filament-jev-moderation::widgets.calibration.description', ['days' => $days, 'rate' => $rate, 'sample' => $sample])"
    >
        @if (count($calibration->models) > 1)
            <x-slot name="afterHeader">
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
            </x-slot>
        @endif

        @if ($calibration->bands === [])
            <x-filament::empty-state
                icon="heroicon-o-scale"
                :heading="__('filament-jev-moderation::widgets.calibration.empty.heading')"
                :description="__('filament-jev-moderation::widgets.calibration.empty.description')"
                :contained="false"
            />
        @else
            @if ($calibration->model !== null && count($calibration->models) === 1)
                <p class="fi-jev-moderation-muted fi-jev-moderation-calibration-version">
                    {{ __('filament-jev-moderation::widgets.calibration.version') }}: {{ $calibration->model }}
                </p>
            @endif

            @foreach ($policies as $policy => $bands)
                <div class="fi-jev-moderation-calibration-policy">
                    <table class="fi-jev-moderation-calibration">
                        <caption>{{ __('filament-jev-moderation::widgets.calibration.policy', ['policy' => $policy]) }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('filament-jev-moderation::widgets.calibration.columns.category') }}</th>
                                <th scope="col">{{ __('filament-jev-moderation::widgets.calibration.columns.band') }}</th>
                                <th scope="col">{{ __('filament-jev-moderation::widgets.calibration.columns.checked') }}</th>
                                <th scope="col">{{ __('filament-jev-moderation::widgets.calibration.columns.overturned') }}</th>
                                <th scope="col">{{ __('filament-jev-moderation::widgets.calibration.columns.upheld') }}</th>
                                <th scope="col">{{ __('filament-jev-moderation::widgets.calibration.columns.rate') }}</th>
                                <th scope="col">{{ __('filament-jev-moderation::widgets.calibration.columns.missed') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bands as $band)
                                <tr @class(['fi-jev-moderation-calibration-flagged' => $band->advice !== null])>
                                    <th scope="row">{{ CalibrationTable::bandLabel($band) }}</th>
                                    <td>{{ number_format($band->lower, 1) }} – {{ number_format($band->upper, 1) }}</td>
                                    <td>{{ $band->sample() }}</td>
                                    <td>{{ $band->overturned }}</td>
                                    <td>{{ $band->upheld }}</td>
                                    <td>
                                        @if ($band->overrideRate() === null)
                                            <span class="fi-jev-moderation-muted">{{ __('filament-jev-moderation::widgets.calibration.no_rate') }}</span>
                                        @else
                                            <span @class(['fi-jev-moderation-muted' => $band->sample() < $sample])>{{ CalibrationTable::percent($band->overrideRate()) }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $band->missed }}</td>
                                </tr>

                                @if (CalibrationTable::advice($band) !== null)
                                    <tr class="fi-jev-moderation-calibration-advice">
                                        <td colspan="7">
                                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="fi-jev-moderation-calibration-advice-icon" />
                                            <span>{{ CalibrationTable::advice($band) }}</span>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
