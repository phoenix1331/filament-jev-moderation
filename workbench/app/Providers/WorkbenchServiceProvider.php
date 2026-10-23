<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Settings a host app would put in its own config, for the workbench only.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The fake driver and inline checks: content saved in the demo is
        // moderated without a Jev key or a queue worker.
        config([
            'jev-moderation.driver' => 'fake',
            'jev-moderation.sync' => true,
            'jev-moderation.mode' => 'post',
        ]);
    }
}
