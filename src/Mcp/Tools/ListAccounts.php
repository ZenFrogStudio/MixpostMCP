<?php

namespace Inovector\Mixpost\Mcp\Tools;

use Inovector\Mixpost\Models\Account;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the social accounts connected to Mixpost, along with the character limits, media limits and per-network options each one enforces. Call this before drafting a post.')]
class ListAccounts extends Tool
{
    protected string $name = 'list_accounts';

    public function handle(): Response
    {
        $accounts = Account::oldest()->get()->map(fn (Account $account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'username' => $account->username,
            'provider' => $account->provider,
            'provider_name' => $account->providerName(),
            // Goes false when a token expires or the user revokes access. Posts to an
            // unauthorized account will fail until it is reconnected in the Mixpost UI.
            'authorized' => $account->authorized,
            'post_configs' => $account->postConfigs(),
            'post_options' => $account->postOptions(),
        ]);

        return Response::json($accounts);
    }
}
