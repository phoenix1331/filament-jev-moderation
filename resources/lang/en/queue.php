<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Review queue',
    'model_label' => 'queued item',
    'plural_model_label' => 'Review queue',
    'columns' => [
        'excerpt' => 'Content',
        'author' => 'Author',
        'category' => 'Category',
        'confidence' => 'Confidence',
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
    'actions' => [
        'view_record' => 'View',
        'approve' => [
            'label' => 'Approve',
            'bulk_label' => 'Approve selected',
            'modal_heading' => 'Approve this content',
            'bulk_modal_heading' => 'Approve the selected content',
            'modal_description' => 'The flag is overturned and the content is shown. The review is recorded with your name.',
            'done' => '{1} Approved :count item|[2,*] Approved :count items',
            'none' => 'Nothing was approved',
        ],
        'reject' => [
            'label' => 'Reject',
            'bulk_label' => 'Reject selected',
            'modal_heading' => 'Reject this content',
            'bulk_modal_heading' => 'Reject the selected content',
            'modal_description' => 'The flag is confirmed and the content is removed. The review is recorded with your name.',
            'done' => '{1} Rejected :count item|[2,*] Rejected :count items',
            'none' => 'Nothing was rejected',
        ],
        'reclassify' => [
            'label' => 'Reclassify',
            'modal_heading' => 'Reclassify and remove this content',
            'modal_description' => 'Jev picked the wrong category, but the content should still go. It is removed under the category you choose, and the review is recorded with your name.',
            'category' => 'Correct category',
            'done' => '{1} Reclassified :count item|[2,*] Reclassified :count items',
            'none' => 'Nothing was reclassified',
        ],
        'note' => [
            'label' => 'Note',
            'helper' => 'Optional. Kept with the decision in its history.',
        ],
        'skipped' => '{1} Skipped :count item that was already reviewed, has been removed, or is not yours to review.|[2,*] Skipped :count items that were already reviewed, have been removed, or are not yours to review.',
        'failed' => '{1} :count item could not be recorded: :reason|[2,*] :count items could not be recorded. First reason: :reason',
    ],
    'empty' => [
        'heading' => 'Nothing waiting for review',
        'description' => 'Flagged content appears here until a moderator approves or rejects it.',
    ],
];
