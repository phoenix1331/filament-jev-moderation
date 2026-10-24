@use('Illuminate\Support\Str')
@use('Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource')

<x-filament-panels::page>
    <x-filament::section
        :heading="__('filament-jev-moderation::thresholds.calibration.heading')"
        :description="__('filament-jev-moderation::widgets.calibration.description', ['days' => $days, 'rate' => $rate, 'sample' => $sample])"
    >
        <div class="fi-jev-moderation-calibration-controls">
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
            @elseif ($calibration->model !== null)
                <p class="fi-jev-moderation-muted">{{ __('filament-jev-moderation::widgets.calibration.version') }}: {{ $calibration->model }}</p>
            @else
                <p class="fi-jev-moderation-muted">{{ __('filament-jev-moderation::widgets.calibration.empty.description') }}</p>
            @endif
        </div>
    </x-filament::section>

    @foreach ($policies as $policy)
        <x-filament::section
            :heading="__('filament-jev-moderation::widgets.calibration.policy', ['policy' => $policy->name])"
            :description="$policy->usedBy()"
        >
            @if ($policy->error !== null)
                <p class="fi-jev-moderation-threshold-error">{{ __('filament-jev-moderation::thresholds.unreadable', ['reason' => $policy->error]) }}</p>
            @else
                <dl class="fi-jev-moderation-facts fi-jev-moderation-threshold-facts">
                    <div>
                        <dt>{{ __('filament-jev-moderation::thresholds.version') }}</dt>
                        <dd><code title="{{ $policy->version }}">{{ Str::limit((string) $policy->version, 12, '') }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('filament-jev-moderation::thresholds.uncertain_below') }}</dt>
                        <dd>{{ $policy->uncertainBelow === null ? __('filament-jev-moderation::thresholds.off') : number_format($policy->uncertainBelow, 2) }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('filament-jev-moderation::thresholds.suggestions') }}</dt>
                        <dd>{{ count($policy->flagged()) }}</dd>
                    </div>
                </dl>

                <div class="fi-jev-moderation-calibration-policy">
                    <table class="fi-jev-moderation-calibration fi-jev-moderation-thresholds">
                        <caption>{{ __('filament-jev-moderation::thresholds.rules') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('filament-jev-moderation::widgets.calibration.columns.category') }}</th>
                                <th scope="col">{{ __('filament-jev-moderation::thresholds.review_above') }}</th>
                                <th scope="col">{{ __('filament-jev-moderation::thresholds.block_above') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($policy->rules as $rule)
                                <tr>
                                    <th scope="row">{{ ReviewQueueResource::categoryLabel($rule['category']) }}</th>
                                    <td>{{ $rule['reviewAbove'] === null ? __('filament-jev-moderation::thresholds.never') : number_format($rule['reviewAbove'], 2) }}</td>
                                    <td>{{ $rule['blockAbove'] === null ? __('filament-jev-moderation::thresholds.never') : number_format($rule['blockAbove'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($policy->bands !== [])
                @if ($policy->flagged() !== [] && $policy->models !== [])
                    <p class="fi-jev-moderation-muted fi-jev-moderation-rescan-note">{{ __('filament-jev-moderation::thresholds.rescan_note') }}</p>
                @endif

                @include('filament-jev-moderation::partials.calibration-bands', [
                    'caption' => __('filament-jev-moderation::thresholds.bands'),
                    'bands' => $policy->bands,
                    'sample' => $sample,
                    'commands' => $policy->commands,
                ])
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
