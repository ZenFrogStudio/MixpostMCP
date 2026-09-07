<?php

namespace OneMediaLabs\MixpostMcp\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OneMediaLabs\MixpostMcp\Models\Account;

class AccountAdded
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Account $account) {}
}
