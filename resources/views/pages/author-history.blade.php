<x-filament-panels::page>
    <x-filament::section
        :heading="__('filament-jev-moderation::author.counts.heading')"
        :description="__('filament-jev-moderation::author.counts.description')"
    >
        <table class="fi-jev-moderation-counts">
            <thead>
                <tr>
                    <th scope="col">{{ __('filament-jev-moderation::author.counts.status') }}</th>
                    @foreach ($windows as $days)
                        <th scope="col">{{ trans_choice('filament-jev-moderation::author.counts.window', $days, ['days' => $days]) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($counts as $status => $byWindow)
                    <tr @class(['fi-jev-moderation-counts-empty' => max($byWindow) === 0])>
                        <th scope="row">{{ __("filament-jev-moderation::statuses.{$status}") }}</th>
                        @foreach ($windows as $days)
                            <td>{{ $byWindow[$days] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
