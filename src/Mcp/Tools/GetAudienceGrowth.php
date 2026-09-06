<?php

namespace Inovector\Mixpost\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Audience;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read the follower count Mixpost recorded for one account over time, with the net change across the range.')]
class GetAudienceGrowth extends Tool
{
    protected string $name = 'get_audience_growth';

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()
                ->required()
                ->description('Account to report on. Ids come from list_accounts.'),
            'from' => $schema->string()
                ->description('Start date, YYYY-MM-DD. Defaults to 30 days ago.'),
            'to' => $schema->string()
                ->description('End date, YYYY-MM-DD. Defaults to today.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $accountId = (int) $request->get('account_id');

        if (! $account = Account::find($accountId)) {
            return Response::error("No account found with id [$accountId].");
        }

        $from = $request->get('from') ?: now()->subDays(30)->format('Y-m-d');
        $to = $request->get('to') ?: now()->format('Y-m-d');

        $rows = Audience::account($account->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        return Response::json([
            'account' => ['id' => $account->id, 'name' => $account->name, 'provider' => $account->provider],
            'from' => $from,
            'to' => $to,
            'daily' => $rows->map(fn (Audience $audience): array => [
                'date' => $audience->date->format('Y-m-d'),
                'total' => $audience->total,
            ]),
            'net_change' => $rows->isEmpty() ? 0 : $rows->last()->total - $rows->first()->total,
        ]);
    }
}
