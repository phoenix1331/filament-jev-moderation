<?php

declare(strict_types=1);

return [
    'title' => ':model #:key',
    'breadcrumb' => 'History',
    'content' => [
        'heading' => 'Content',
        'description' => 'As it is now. Edits saved without moderation can differ from what was checked.',
        'author' => 'Author',
        'deleted' => 'This content has been deleted. Its history is kept.',
        'empty' => 'Every moderated field is empty.',
    ],
    'timeline' => [
        'heading' => 'History',
        'description' => 'Every check, report and review, newest first.',
        'opened' => 'Opened from here',
        'category' => 'Category',
        'confidence' => 'Confidence',
        'jev_confidence' => 'Jev\'s confidence',
        'reason' => 'Why',
        'reviewer' => 'Moderator',
        'reporter' => 'Reported by',
        'note' => 'Note',
        'report_reason' => 'Reason given',
        'model' => 'Jev version',
        'policy' => 'Policy',
        'policy_version' => 'Policy version',
        'stored_content' => 'Content checked',
        'retryable' => 'Will be retried',
        'not_retryable' => 'Will not be retried',
        'probabilities' => 'Category probabilities',
    ],
    'reasons' => [
        'clear' => 'No category reached a threshold',
        'review_threshold' => 'A category reached its review threshold',
        'block_threshold' => 'A category reached its block threshold',
        'low_confidence' => 'Jev was not confident enough in its answer',
    ],
];
