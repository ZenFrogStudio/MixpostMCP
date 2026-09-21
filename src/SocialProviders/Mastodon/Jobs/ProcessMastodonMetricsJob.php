<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\Mastodon\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\ImportedPost;
use OneMediaLabs\MixpostMcp\Models\Metric;

class ProcessMastodonMetricsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deleteWhenMissingModels = true;

    public Account $account;

    public function __construct(Account $account)
    {
        $this->account = $account;
    }

    public function handle()
    {
        $items = ImportedPost::select('created_at',
            DB::raw("SUM(JSON_EXTRACT(metrics, '$.replies')) as replies"),
            DB::raw("SUM(JSON_EXTRACT(metrics, '$.reblogs')) as reblogs"),
            DB::raw("SUM(JSON_EXTRACT(metrics, '$.favourites')) as favourites"))
            ->where('account_id', $this->account->id)
            ->groupBy('created_at')
            ->cursor();

        $data = $items->map(function ($item) {
            return [
                'account_id' => $this->account->id,
                'date' => $item->created_at,
                'data' => json_encode([
                    'replies' => $item->replies,
                    'reblogs' => $item->reblogs,
                    'favourites' => $item->favourites,
                ]),
            ];
        });

        Metric::upsert($data->toArray(), ['data'], ['account_id', 'date']);
    }
}
