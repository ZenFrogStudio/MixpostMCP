<?php

namespace OneMediaLabs\MixpostMcp\Actions;

use Illuminate\Support\Facades\Bus;
use OneMediaLabs\MixpostMcp\Jobs\AccountPublishPostJob;
use OneMediaLabs\MixpostMcp\Models\Post;

class PublishPost
{
    public function __invoke(Post $post): void
    {
        if ($post->isScheduleProcessing()) {
            return;
        }

        $post->setScheduleProcessing();

        $jobs = $post->accounts->map(function ($account) use ($post) {
            return new AccountPublishPostJob($account, $post);
        });

        Bus::batch($jobs)
            ->allowFailures()
            ->finally(function () use ($post) {
                if ($post->hasErrors()) {
                    $post->setFailed();

                    return;
                }

                $post->setPublished();
            })
            ->onQueue('publish-post')
            ->dispatch();
    }
}
