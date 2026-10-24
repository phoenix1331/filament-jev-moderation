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
    'filters' => [
        'model' => 'Type',
        'category' => 'Category',
        'confidence_from' => 'Confidence from',
        'confidence_until' => 'Confidence up to',
        'source' => 'Source',
        'created_from' => 'Queued from',
        'created_until' => 'Queued until',
        'indicators' => [
            'confidence_from' => 'Confidence from :value',
            'confidence_until' => 'Confidence up to :value',
            'created_from' => 'Queued from :date',
            'created_until' => 'Queued until :date',
        ],
    ],
    'empty' => [
        'heading' => 'Nothing waiting for review',
        'description' => 'Flagged content appears here until a moderator approves or rejects it.',
    ],
];
