<?php

namespace OneMediaLabs\MixpostMcp\Commands;

use Illuminate\Console\Command;
use OneMediaLabs\MixpostMcp\Facades\ServiceManager;

class ClearServicesCache extends Command
{
    public $signature = 'mixpostmcp:clear-services-cache';

    public $description = 'Clear the services from cache';

    public function handle(): int
    {
        ServiceManager::forgetAll();

        $this->info('Cache services has been cleared!');

        return self::SUCCESS;
    }
}
