<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\FilamentJevModeration\Widgets\CalibrationTable;
use Phoenix1331\FilamentJevModeration\Widgets\DecisionVolumeChart;
use Phoenix1331\FilamentJevModeration\Widgets\ModerationOverview;

class ListReviewQueue extends ListRecords
{
    protected static string $resource = ReviewQueueResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ModerationOverview::class];
    }

    protected function getFooterWidgets(): array
    {
        return [DecisionVolumeChart::class, CalibrationTable::class];
    }
}
