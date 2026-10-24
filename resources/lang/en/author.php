<?php

declare(strict_types=1);

return [
    'breadcrumb' => 'Author',
    'subheading' => 'Every decision about their content, across every moderated type they author.',
    'counts' => [
        'heading' => 'Recent decisions',
        'description' => 'Decisions about their content by status. A record that was flagged and then reviewed counts once for each.',
        'status' => 'Status',
        'window' => '{1} Last day|[2,*] Last :days days',
    ],
    'columns' => [
        'model' => 'Type',
        'excerpt' => 'Content',
        'status' => 'Status',
        'source' => 'Source',
        'category' => 'Category',
        'confidence' => 'Confidence',
        'when' => 'When',
    ],
    'empty' => [
        'heading' => 'No decisions yet',
        'description' => 'Nothing they have written has been checked, reported or reviewed.',
    ],
];
