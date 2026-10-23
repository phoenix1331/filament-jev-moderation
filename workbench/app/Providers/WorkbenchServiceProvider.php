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
        //
    }
}
