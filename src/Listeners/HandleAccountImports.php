<?php

namespace OneMediaLabs\MixpostMcp\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use OneMediaLabs\MixpostMcp\Commands\ImportAccountAudience;
use OneMediaLabs\MixpostMcp\Commands\ImportAccountData;
use OneMediaLabs\MixpostMcp\Commands\ProcessMetrics;

class HandleAccountImports implements ShouldQueue
{
    public function handle(object $event): void
    {
        Artisan::call(ImportAccountAudience::class, [
            '--accounts' => $event->account->id,
        ]);

        Artisan::call(ImportAccountData::class, [
            '--accounts' => $event->account->id,
        ]);

        Artisan::call(ProcessMetrics::class, [
            '--accounts' => $event->account->id,
        ]);
    }
}
