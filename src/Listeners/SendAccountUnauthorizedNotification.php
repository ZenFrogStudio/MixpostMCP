<?php

namespace OneMediaLabs\MixpostMcp\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use OneMediaLabs\MixpostMcp\Events\AccountUnauthorized as AccountUnauthorizedEvent;
use OneMediaLabs\MixpostMcp\Facades\Settings;
use OneMediaLabs\MixpostMcp\Mail\AccountUnauthorizedMail;

class SendAccountUnauthorizedNotification implements ShouldQueue
{
    public function handle(AccountUnauthorizedEvent $event): void
    {
        $adminEmail = Settings::get('admin_email');

        if (! $adminEmail) {
            return;
        }

        Mail::to($adminEmail)->send(new AccountUnauthorizedMail($event->account));
    }
}
