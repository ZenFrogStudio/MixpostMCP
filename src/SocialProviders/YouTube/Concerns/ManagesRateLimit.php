<?php

namespace Inovector\Mixpost\SocialProviders\YouTube\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Support\SocialProviderResponse;

/**
 * Turns a YouTube Data API response into a SocialProviderResponse.
 *
 * YouTube's real constraint is a **daily quota**, not a per-second rate limit. An upload costs about
 * 1,600 units against a default allowance of 10,000 a day — roughly six videos — and nothing in the
 * response says how much is left. The only signal is the 403 that arrives once it runs out, so
 * everything here is built around reacting to that rather than predicting it.
 */
trait ManagesRateLimit
{
    // Google's quotas reset at midnight Pacific time, not UTC and not on a rolling window.
    const QUOTA_TIMEZONE = 'America/Los_Angeles';

    /**
     * The daily allowance is gone. Retrying before the reset only burns attempts, so these get a
     * retry set to the reset rather than a few minutes.
     */
    protected array $youtubeQuotaReasons = [
        'quotaExceeded',
        'dailyLimitExceeded',
    ];

    // Short-term throttling rather than the daily quota — worth retrying soon.
    protected array $youtubeThrottleReasons = [
        'rateLimitExceeded',
        'userRateLimitExceeded',
    ];

    /**
     * Reasons that mean the token is dead. The scheduler reacts to UNAUTHORIZED by flagging the
     * account rather than retrying, which is what should happen for all of these.
     */
    protected array $youtubeUnauthorizedReasons = [
        'authError',
        'invalidCredentials',
        'unauthorized',
    ];

    /**
     * @param  $response  Response
     */
    public function buildResponse($response, ?Closure $okResult = null): SocialProviderResponse
    {
        // A successful delete answers 204 with no body, so the body is never the success test.
        if ($response->successful()) {
            return $this->response(
                SocialProviderResponseStatus::OK,
                $okResult ? $okResult() : ($response->json() ?? [])
            );
        }

        $reason = (string) Arr::get($response->json() ?? [], 'error.errors.0.reason', '');

        if (in_array($reason, $this->youtubeQuotaReasons, true)) {
            $retryAfter = $this->secondsUntilQuotaReset();

            return $this->response(
                SocialProviderResponseStatus::EXCEEDED_RATE_LIMIT,
                $this->rateLimitExceedContext($retryAfter, $this->errorMessage($response)),
                true,
                $retryAfter
            );
        }

        if ($response->status() === 429 || in_array($reason, $this->youtubeThrottleReasons, true)) {
            $retryAfter = 5 * 60;

            return $this->response(
                SocialProviderResponseStatus::EXCEEDED_RATE_LIMIT,
                $this->rateLimitExceedContext($retryAfter, $this->errorMessage($response)),
                true,
                $retryAfter
            );
        }

        if ($response->status() === 401 || in_array($reason, $this->youtubeUnauthorizedReasons, true)) {
            return $this->response(
                SocialProviderResponseStatus::UNAUTHORIZED,
                ['access_token_expired']
            );
        }

        return $this->response(SocialProviderResponseStatus::ERROR, [$this->errorMessage($response)]);
    }

    /**
     * YouTube publishes no quota figures in response headers — the count lives only in the Google
     * Cloud console. Inventing a remaining count would make the scheduler back off for no reason,
     * so these defaults keep `rateLimitAboutToBeExceeded` false until a real 403 arrives.
     */
    public function getRateLimitUsage(array $headers = []): array
    {
        return [
            'limit' => 10000,
            'remaining' => 10000,
            'retry_after' => 0,
        ];
    }

    /**
     * Seconds until the next midnight Pacific, which is when Google restores the daily allowance.
     */
    protected function secondsUntilQuotaReset(): int
    {
        $reset = Carbon::now(self::QUOTA_TIMEZONE)->addDay()->startOfDay();

        // Never zero: a retry scheduled for right now would spend the next attempt on the same 403.
        return max(60, (int) Carbon::now('UTC')->diffInSeconds($reset));
    }

    /**
     * @param  $response  Response
     */
    protected function errorMessage($response): string
    {
        $body = $response->json() ?? [];

        $reason = (string) Arr::get($body, 'error.errors.0.reason', '');
        $message = Arr::get($body, 'error.message') ?: Arr::get($body, 'error_description');

        if ($readable = $this->explainErrorReason($reason)) {
            return $readable;
        }

        if (! $message && ! $reason) {
            return "YouTube returned an unexpected {$response->status()} response.";
        }

        return $message ? "$message (YouTube error $reason)" : "YouTube returned the error `$reason`.";
    }

    /**
     * Google's own messages for these say what happened but not what to do about it, and several
     * describe an account setup problem rather than anything wrong with the post.
     */
    protected function explainErrorReason(string $reason): ?string
    {
        return match ($reason) {
            'quotaExceeded', 'dailyLimitExceeded' => 'This YouTube app has used its daily API quota. An upload costs about 1,600 of the default 10,000 units a day, so roughly six videos. The quota resets at midnight Pacific time.',
            'uploadLimitExceeded' => 'This channel has reached YouTube\'s limit on uploads for the day. Try again tomorrow.',
            'youtubeSignupRequired' => 'The connected Google account has no YouTube channel. Create one on YouTube and reconnect the account.',
            'invalidCategoryId' => 'YouTube does not offer that category in this channel\'s region. Pick another one under Post options.',
            'invalidTitle', 'invalidDescription' => 'YouTube rejected the title or description. Angle brackets are not allowed anywhere in either.',
            'invalidVideoMetadata' => 'YouTube rejected the video details. Check the title, description and category under Post options.',
            'mediaBodyRequired' => 'The upload reached YouTube with no file attached.',
            'forbidden' => 'YouTube refused this request. The channel may not be verified for this action, or the connected account may no longer manage it.',
            'videoNotFound' => 'That video no longer exists on YouTube.',
            default => null,
        };
    }
}
