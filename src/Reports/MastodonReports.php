<?php

namespace OneMediaLabs\MixpostMcp\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use OneMediaLabs\MixpostMcp\Abstracts\Report;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\Metric;

class MastodonReports extends Report
{
    public function __invoke(Account $account, string $period): array
    {
        return [
            'metrics' => $this->metrics($account, $period),
            'audience' => $this->audience($account, $period),
        ];
    }

    protected function metrics(Account $account, string $period): array
    {
        $report = Metric::account($account->id)->select(
            DB::raw('SUM(JSON_EXTRACT(data, "$.replies")) as replies'),
            DB::raw('SUM(JSON_EXTRACT(data, "$.reblogs")) as reblogs'),
            DB::raw('SUM(JSON_EXTRACT(data, "$.favourites")) as favourites')
        )->when($period, function (Builder $query) use ($period) {
            return $this->queryPeriod($query, $period);
        })->first();

        return [
            'replies' => $report->replies ?? 0,
            'reblogs' => $report->reblogs ?? 0,
            'favourites' => $report->favourites ?? 0,
        ];
    }
}
