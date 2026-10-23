<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Review queue',
    'model_label' => 'queued item',
    'plural_model_label' => 'Review queue',
    'columns' => [
        'model' => 'Type',
        'excerpt' => 'Content',
        'author' => 'Author',
        'category' => 'Category',
        'confidence' => 'Confidence',
        'source' => 'Source',
        'age' => 'Waiting',
    ],
    'empty' => [
        'heading' => 'Nothing waiting for review',
        'description' => 'Flagged content appears here until a moderator approves or rejects it.',
    ],
];
