<?php

declare(strict_types=1);

return [
    'overview' => [
        'none' => 'None yet',
        'waiting' => 'Waiting for review',
        'waiting_none' => 'The queue is clear',
        'waiting_oldest' => 'Oldest waiting :age',
        'blocked' => 'Blocked today',
        'blocked_description' => 'Removed by Jev since midnight',
        'review_time' => 'Median time to review',
        'review_time_description' => '{0} No reviews in the last :days days|{1} From :count review in the last :days days|[2,*] From :count reviews in the last :days days',
        'override_rate' => 'Override rate',
        'override_rate_description' => 'Moderators overturned :overturned of :checked flags and blocks in the last :days days',
    ],
];
