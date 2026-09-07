<?php

namespace OneMediaLabs\MixpostMcp\Concerns;

use OneMediaLabs\MixpostMcp\Enums\SocialProviderResponseStatus;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;

trait UsesSocialProviderResponse
{
    public function response(
        SocialProviderResponseStatus $status,
        array $context,
        bool $rateLimitAboutToBeExceeded = false,
        int $retryAfter = 0,
        bool $isAppLevel = false): SocialProviderResponse
    {
        return new SocialProviderResponse($status, $context, $rateLimitAboutToBeExceeded, $retryAfter, $isAppLevel);
    }
}
