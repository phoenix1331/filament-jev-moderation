{{-- One policy's bands. Expects $caption, $bands, $sample, and $commands (advice value to rescan commands, may be empty). --}}
@use('Phoenix1331\FilamentJevModeration\Widgets\CalibrationTable')

<div class="fi-jev-moderation-calibration-policy">
    <table class="fi-jev-moderation-calibration">
        <caption>{{ $caption }}</caption>
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

                            @foreach ($commands[$band->advice->value] ?? [] as $command)
                                <code class="fi-jev-moderation-command">{{ $command }}</code>
                            @endforeach
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
</div>
