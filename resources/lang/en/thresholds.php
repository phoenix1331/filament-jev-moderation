<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Thresholds',
    'title' => 'Thresholds',
    'subheading' => 'Read from config/jev-moderation.php. Change thresholds there; this page shows where moderators disagree with them.',
    'calibration' => [
        'heading' => 'Calibration',
    ],
    'models' => 'Used by :models',
    'no_models' => 'No moderated model shown here uses this policy.',
    'unreadable' => 'This policy could not be read from config: :reason',
    'version' => 'Policy version',
    'uncertain_below' => 'uncertain_below',
    'suggestions' => 'Bands to look at',
    'off' => 'Off',
    'never' => 'Never',
    'rules' => 'Thresholds',
    'review_above' => 'review_above',
    'block_above' => 'block_above',
    'bands' => 'Calibration bands',
    'rescan_note' => 'After changing config, re-check the affected content with the command shown. Each check is a billed Jev call, and content a moderator approved or rejected is never re-checked.',
];
