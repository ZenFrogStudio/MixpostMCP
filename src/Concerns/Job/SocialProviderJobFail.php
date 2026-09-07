<?php

namespace OneMediaLabs\MixpostMcp\Concerns\Job;

use OneMediaLabs\MixpostMcp\Support\Log;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;

trait SocialProviderJobFail
{
    public function makeFail(SocialProviderResponse $response): void
    {
        Log::error($this->job->payload()['displayName'], array_merge($response->context(), ['payload' => $this->job->payload()]));

        $this->fail();
    }
}
