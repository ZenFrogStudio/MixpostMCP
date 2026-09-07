<?php

namespace OneMediaLabs\MixpostMcp\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\Metric;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read the daily engagement figures MixpostMCP has collected for one account, plus a total for the range. The available figures differ per network (likes, retweets and impressions on X; reactions and reach on a Facebook page).')]
class GetAccountMetrics extends Tool
{
    protected string $name = 'get_account_metrics';

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

        $rows = Metric::account($account->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        return Response::json([
            'account' => ['id' => $account->id, 'name' => $account->name, 'provider' => $account->provider],
            'from' => $from,
            'to' => $to,
            // MixpostMCP stores one row per day per account. Nothing back-fills days that were
            // never imported, so gaps mean "not collected", not "zero".
            'daily' => $rows->map(fn (Metric $metric): array => [
                'date' => $metric->date->format('Y-m-d'),
                'data' => $metric->data,
            ]),
            'totals' => $this->totals($rows),
        ]);
    }

    protected function totals($rows): array
    {
        $totals = [];

        foreach ($rows as $metric) {
            foreach ($metric->data ?? [] as $key => $value) {
                if (is_numeric($value)) {
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                }
            }
        }

        return $totals;
    }
}
