<?php

namespace Inovector\Mixpost\SocialProviders\LinkedIn\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesRateLimit
{
    /**
     * @param  $response  Response
     */
    public function buildResponse($response, ?Closure $okResult = null): SocialProviderResponse
    {
        $usage = $this->getRateLimitUsage($response->headers());

        $rateLimitAboutToBeExceeded = $usage['remaining'] < 5;
        $retryAfter = $rateLimitAboutToBeExceeded ? 5 * 60 : $usage['retry_after'];

        if (in_array($response->status(), [200, 201, 202])) {
            return $this->response(
                SocialProviderResponseStatus::OK,
                $okResult ? $okResult() : ($response->json() ?? []),
                $rateLimitAboutToBeExceeded,
                $retryAfter
            );
        }

        if ($response->status() === 429) {
            $headers = array_change_key_case($response->headers(), CASE_LOWER);
            $retryAfter = (int) (Arr::get($headers, 'retry-after.0') ?: 5 * 60);

            return $this->response(
                SocialProviderResponseStatus::EXCEEDED_RATE_LIMIT,
                $this->rateLimitExceedContext($retryAfter, $this->errorMessage($response)),
                true,
                $retryAfter
            );
        }

        if ($response->status() === 401) {
            return $this->response(
                SocialProviderResponseStatus::UNAUTHORIZED,
                ['access_token_expired']
            );
        }

        return $this->response(
            SocialProviderResponseStatus::ERROR,
            [$this->errorMessage($response)],
            $rateLimitAboutToBeExceeded,
            $retryAfter
        );
    }

    /**
     * LinkedIn does not return rate-limit headers on ordinary responses — quotas are daily and
     * visible only in the developer portal. So there is nothing to parse, and inventing a
     * remaining count would make the scheduler back off for no reason. These defaults keep
     * `rateLimitAboutToBeExceeded` false until an actual 429 arrives.
     *
     * @see https://learn.microsoft.com/en-us/linkedin/shared/api-guide/concepts/rate-limits
     */
    public function getRateLimitUsage(array $headers = []): array
    {
        return [
            'limit' => 100,
            'remaining' => 100,
            'retry_after' => 0,
        ];
    }

    /**
     * LinkedIn error bodies look like {"message": "...", "serviceErrorCode": 100, "status": 403}.
     * The message is the only readable part, so that is what gets shown.
     *
     * @param  $response  Response
     */
    protected function errorMessage($response): string
    {
        $body = $response->json() ?? [];

        $message = Arr::get($body, 'message')
            ?: Arr::get($body, 'error_description')
            ?: Arr::get($body, 'error');

        if (! $message) {
            return "LinkedIn returned an unexpected {$response->status()} response.";
        }

        if ($code = Arr::get($body, 'serviceErrorCode')) {
            return "$message (LinkedIn error $code)";
        }

        return $message;
    }
}
