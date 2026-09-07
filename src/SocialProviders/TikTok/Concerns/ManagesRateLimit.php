<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\TikTok\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use OneMediaLabs\MixpostMcp\Enums\SocialProviderResponseStatus;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;

/**
 * Turns a TikTok HTTP response into a SocialProviderResponse.
 *
 * TikTok wraps every Open API reply as `{"data": {...}, "error": {"code": "ok", ...}}` and returns
 * that envelope with HTTP 200 even when the call failed, so the status line alone cannot be
 * trusted. Everything here exists to make a failure look like a failure.
 */
trait ManagesRateLimit
{
    /**
     * Error codes that mean the access token is dead. The scheduler reacts to UNAUTHORIZED by
     * flagging the account rather than retrying, which is what should happen for all of these.
     */
    protected array $tiktokUnauthorizedCodes = [
        'access_token_invalid',
        'access_token_expired',
        'scope_not_authorized',
        'scope_permission_missed',
    ];

    /**
     * @param  $response  Response
     */
    public function buildResponse($response, ?Closure $okResult = null): SocialProviderResponse
    {
        $body = $response->json() ?? [];
        $errorCode = (string) Arr::get($body, 'error.code', '');
        $succeeded = in_array($errorCode, ['', 'ok'], true);

        $usage = $this->getRateLimitUsage($response->headers());
        $rateLimitAboutToBeExceeded = $usage['remaining'] < 5;
        $retryAfter = $rateLimitAboutToBeExceeded ? 5 * 60 : $usage['retry_after'];

        // 206 is how the upload host acknowledges a chunk that is not the last one, and its body is
        // empty rather than a TikTok envelope.
        if ($succeeded && in_array($response->status(), [200, 201, 202, 204, 206])) {
            return $this->response(
                SocialProviderResponseStatus::OK,
                // The envelope's `data` is unwrapped so callers read `publish_id` or `upload_url`
                // directly instead of digging through a wrapper on every single call.
                $okResult ? $okResult() : (Arr::get($body, 'data') ?? $body),
                $rateLimitAboutToBeExceeded,
                $retryAfter
            );
        }

        if ($response->status() === 429 || $errorCode === 'rate_limit_exceeded') {
            $headers = array_change_key_case($response->headers(), CASE_LOWER);
            $retryAfter = (int) (Arr::get($headers, 'retry-after.0') ?: 5 * 60);

            return $this->response(
                SocialProviderResponseStatus::EXCEEDED_RATE_LIMIT,
                $this->rateLimitExceedContext($retryAfter, $this->errorMessage($response)),
                true,
                $retryAfter
            );
        }

        if ($response->status() === 401 || in_array($errorCode, $this->tiktokUnauthorizedCodes, true)) {
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
     * TikTok publishes its quotas as fixed per-endpoint limits (creator_info is 20 requests per
     * minute per token, for instance) and sends no rate-limit headers to read them from. Inventing
     * a remaining count would make the scheduler back off for no reason, so these defaults keep
     * `rateLimitAboutToBeExceeded` false until a real 429 arrives.
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
     * @param  $response  Response
     */
    protected function errorMessage($response): string
    {
        $body = $response->json() ?? [];

        $code = (string) Arr::get($body, 'error.code', '');
        $message = Arr::get($body, 'error.message')
            ?: Arr::get($body, 'error_description')
            ?: Arr::get($body, 'message');

        if (! $message && ! $code) {
            return "TikTok returned an unexpected {$response->status()} response.";
        }

        if ($readable = $this->explainErrorCode($code)) {
            return $readable;
        }

        return $message ? "$message (TikTok error $code)" : "TikTok returned the error `$code`.";
    }

    /**
     * TikTok's own messages for these are terse to the point of being useless — `invalid_params`
     * with no field name, `spam_risk_too_many_posts` with no count. These replacements say what the
     * operator can actually do about it.
     */
    protected function explainErrorCode(string $code): ?string
    {
        return match ($code) {
            'spam_risk_too_many_posts' => 'This TikTok account has hit its daily posting limit. Try again tomorrow.',
            'spam_risk_user_banned_from_posting' => 'TikTok has blocked this account from posting.',
            'reached_active_user_cap' => 'This TikTok app has reached its cap on creators posting in a 24 hour window. An unaudited app is limited to 5.',
            'unaudited_client_can_only_post_to_private_accounts' => 'This TikTok app has not passed the content posting audit, so it can only post to accounts that are set to private.',
            'url_ownership_unverified' => 'The media URL is on a domain this TikTok app has not verified.',
            'privacy_level_option_mismatch' => 'TikTok does not allow that privacy level for this creator. Reopen the post options to reload the available levels.',
            default => null,
        };
    }
}
