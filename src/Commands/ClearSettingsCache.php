<?php

namespace OneMediaLabs\MixpostMcp\Commands;

use Illuminate\Console\Command;
use OneMediaLabs\MixpostMcp\Facades\Settings;

class ClearSettingsCache extends Command
{
    public $signature = 'mixpostmcp:clear-settings-cache';

    public $description = 'Clear the settings from cache';

    public function handle(): int
    {
        Settings::forgetAll();

        $this->info('Cache settings has been cleared!');

        return self::SUCCESS;
    }
}
