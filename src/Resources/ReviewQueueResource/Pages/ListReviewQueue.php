<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;

class ListReviewQueue extends ListRecords
{
    protected static string $resource = ReviewQueueResource::class;
}
