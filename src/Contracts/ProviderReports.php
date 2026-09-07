<?php

namespace OneMediaLabs\MixpostMcp\Contracts;

use OneMediaLabs\MixpostMcp\Models\Account;

interface ProviderReports
{
    public function __invoke(Account $account, string $period): array;
}
