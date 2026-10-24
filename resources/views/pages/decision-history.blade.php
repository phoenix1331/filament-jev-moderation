@use('Illuminate\Support\Number')
@use('Illuminate\Support\Str')
@use('Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource')
@use('Phoenix1331\JevModeration\Enums\ModerationSource')
@use('Phoenix1331\JevModeration\Enums\ModerationStatus')

<x-filament-panels::page>
    <x-filament::section
        :heading="__('filament-jev-moderation::decision.content.heading')"
        :description="__('filament-jev-moderation::decision.content.description')"
    >
        @if ($author !== null)
            <p class="fi-jev-moderation-muted fi-jev-moderation-author">
                {{ __('filament-jev-moderation::decision.content.author') }}: {{ $author }}
            </p>
        @endif

        @if ($content === null)
            <p class="fi-jev-moderation-muted">{{ __('filament-jev-moderation::decision.content.deleted') }}</p>
        @elseif ($content === [])
            <p class="fi-jev-moderation-muted">{{ __('filament-jev-moderation::decision.content.empty') }}</p>
        @else
            <dl class="fi-jev-moderation-content">
                @foreach ($content as $field => $text)
                    <div>
                        <dt class="fi-jev-moderation-label">{{ Str::headline($field) }}</dt>
                        <dd class="fi-jev-moderation-text">{{ $text }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </x-filament::section>

    <x-filament::section
        :heading="__('filament-jev-moderation::decision.timeline.heading')"
        :description="__('filament-jev-moderation::decision.timeline.description')"
    >
        <ol class="fi-jev-moderation-timeline">
            @foreach ($entries as $entry)
                <li
                    id="decision-{{ $entry->id }}"
                    @class([
                        'fi-jev-moderation-timeline-item',
                        'fi-jev-moderation-timeline-item-current' => $entry->current,
                    ])
                >
                    <div class="fi-jev-moderation-timeline-header">
                        <x-filament::badge :color="$entry->statusColor()">
                            {{ __("filament-jev-moderation::statuses.{$entry->status->value}") }}
                        </x-filament::badge>

                        <x-filament::badge color="gray">
                            {{ __("filament-jev-moderation::sources.{$entry->source->value}") }}
                        </x-filament::badge>

                        @if ($entry->current)
                            <span class="fi-jev-moderation-muted">{{ __('filament-jev-moderation::decision.timeline.opened') }}</span>
                        @endif

                        @if ($entry->at !== null)
                            <time
                                class="fi-jev-moderation-muted fi-jev-moderation-timeline-time"
                                datetime="{{ $entry->at->toIso8601String() }}"
                                title="{{ $entry->at->toDayDateTimeString() }}"
                            >{{ $entry->at->diffForHumans() }}</time>
                        @endif
                    </div>

                    <dl class="fi-jev-moderation-facts">
                        @if ($entry->category !== null)
                            <div>
                                <dt>{{ __('filament-jev-moderation::decision.timeline.category') }}</dt>
                                <dd>{{ ReviewQueueResource::categoryLabel($entry->category) }}</dd>
                            </div>
                        @endif

                        @if ($entry->confidence !== null)
                            <div>
                                <dt>{{ __('filament-jev-moderation::decision.timeline.confidence') }}</dt>
                                <dd>{{ Number::percentage($entry->confidence * 100, maxPrecision: 1) }}</dd>
                            </div>
                        @endif

                        @if ($entry->jevConfidence !== null && $entry->reason === 'low_confidence')
                            <div>
                                <dt>{{ __('filament-jev-moderation::decision.timeline.jev_confidence') }}</dt>
                                <dd>{{ Number::percentage($entry->jevConfidence * 100, maxPrecision: 1) }}</dd>
                            </div>
                        @endif

                        @if ($entry->reasonLabel() !== null)
                            <div>
                                <dt>{{ __('filament-jev-moderation::decision.timeline.reason') }}</dt>
                                <dd>
                                    {{ $entry->reasonLabel() }}
                                    @if ($entry->retryable !== null)
                                        <span class="fi-jev-moderation-muted">({{ $entry->retryable ? __('filament-jev-moderation::decision.timeline.retryable') : __('filament-jev-moderation::decision.timeline.not_retryable') }})</span>
                                    @endif
                                </dd>
                            </div>
                        @endif

                        @if ($entry->person !== null)
                            <div>
                                <dt>{{ $entry->source === ModerationSource::Report ? __('filament-jev-moderation::decision.timeline.reporter') : __('filament-jev-moderation::decision.timeline.reviewer') }}</dt>
                                <dd>{{ $entry->person }}</dd>
                            </div>
                        @endif

                        @if ($entry->note !== null)
                            <div>
                                <dt>{{ $entry->source === ModerationSource::Report ? __('filament-jev-moderation::decision.timeline.report_reason') : __('filament-jev-moderation::decision.timeline.note') }}</dt>
                                <dd class="fi-jev-moderation-text">{{ $entry->note }}</dd>
                            </div>
                        @endif

                        @if ($entry->model !== null)
                            <div>
                                <dt>{{ __('filament-jev-moderation::decision.timeline.model') }}</dt>
                                <dd>{{ $entry->model }}</dd>
                            </div>
                        @endif

                        <div>
                            <dt>{{ __('filament-jev-moderation::decision.timeline.policy') }}</dt>
                            <dd>
                                {{ $entry->policy }}
                                <code class="fi-jev-moderation-muted" title="{{ $entry->policyVersion }}">{{ Str::limit($entry->policyVersion, 12, '') }}</code>
                            </dd>
                        </div>

                        @if ($entry->content !== null)
                            <div class="fi-jev-moderation-facts-wide">
                                <dt>{{ __('filament-jev-moderation::decision.timeline.stored_content') }}</dt>
                                <dd class="fi-jev-moderation-text">{{ $entry->content }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($entry->probabilities !== [])
                        <div class="fi-jev-moderation-probabilities">
                            <p class="fi-jev-moderation-label">{{ __('filament-jev-moderation::decision.timeline.probabilities') }}</p>

                            <ul class="fi-jev-moderation-bars">
                                @foreach ($entry->probabilities as $bar)
                                    <li @class(['fi-jev-moderation-bar', 'fi-jev-moderation-bar-deciding' => $bar->deciding])>
                                        <span class="fi-jev-moderation-bar-label">{{ ReviewQueueResource::categoryLabel($bar->category) }}</span>
                                        <span class="fi-jev-moderation-bar-track" aria-hidden="true">
                                            <span class="fi-jev-moderation-bar-fill" style="width: {{ round($bar->probability * 100, 1) }}%"></span>
                                        </span>
                                        <span class="fi-jev-moderation-bar-value">{{ Number::percentage($bar->probability * 100, maxPrecision: 1) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-panels::page>
