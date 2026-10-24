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
    'calibration' => [
        'heading' => 'Calibration',
        'flagged_heading' => 'Calibration: bands to look at',
        'see_all' => 'Every band and threshold',
        'none_flagged' => '{1} The one band with reviews looks fine.|[2,*] All :count bands with reviews look fine.',
        'description' => 'How often moderators overturned Jev in each confidence band over the last :days days. A band is highlighted when they overturned more than :rate of at least :sample checked flags and blocks.',
        'version' => 'Jev version',
        'policy' => 'Policy: :policy',
        'low_confidence' => 'Low-confidence flags',
        'no_rate' => 'None checked',
        'columns' => [
            'category' => 'Category',
            'band' => 'Confidence',
            'checked' => 'Checked',
            'overturned' => 'Overturned',
            'upheld' => 'Upheld',
            'rate' => 'Override rate',
            'missed' => 'Missed',
        ],
        'advice' => [
            'raise_review_above' => 'Moderators overturn most flags in this band: consider raising review_above to :threshold.',
            'raise_block_above' => 'Moderators overturn most blocks in this band: consider raising block_above to :threshold.',
            'lower_uncertain_below' => 'Moderators overturn most low-confidence flags in this band: consider lowering uncertain_below to :threshold.',
        ],
        'empty' => [
            'heading' => 'Nothing to calibrate yet',
            'description' => 'Bands fill in as moderators approve and reject content Jev checked.',
        ],
    ],
    'volume' => [
        'heading' => 'Decisions per day',
        'window' => 'Last :days days',
        'total' => ':status :count',
    ],
];
