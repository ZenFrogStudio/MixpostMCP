<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\TikTok\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\SocialProviders\TikTok\TikTokProvider;
use OneMediaLabs\MixpostMcp\Util;

/**
 * The authorization half of the TikTok integration: build the consent URL with a PKCE challenge,
 * exchange the returned code for a token, and refresh that token before it dies.
 *
 * Two values have to survive the redirect to TikTok and back, so both are stashed in the session
 * under the install's cache prefix: the PKCE code verifier and the CSRF state.
 */
trait ManagesOAuth
{
    /**
     * `video.publish` is what posts straight to the creator's profile. `video.upload` only drops the
     * file into their inbox to finish by hand — it is kept because TikTok's app review expects the
     * pair, and dropping it would change what an existing app is approved for.
     *
     * Metrics scopes (`user.info.stats`, `video.list`) are deliberately absent: every extra scope is
     * another thing TikTok's reviewers must approve, and nothing here reads them yet.
     */
    protected array $tiktokScopes = [
        'user.info.basic',
        'video.publish',
        'video.upload',
    ];

    public function getAuthUrl(): string
    {
        $codeVerifier = $this->generateCodeVerifier();
        $state = Str::random(40);

        $this->request->session()->put($this->codeVerifierSessionKey(), $codeVerifier);
        $this->request->session()->put($this->stateSessionKey(), $state);

        return $this->buildUrlFromBase('https://www.tiktok.com/v2/auth/authorize/', [
            // TikTok names this `client_key`, not `client_id` like every other provider here.
            'client_key' => $this->clientId,
            'response_type' => 'code',
            'scope' => implode(',', $this->tiktokScopes),
            'redirect_uri' => $this->redirectUrl,
            'state' => $state,
            'code_challenge' => $this->deriveCodeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function requestAccessToken(array $params): array
    {
        if ($error = $this->verifyState(Arr::get($params, 'state'))) {
            return ['error' => $error];
        }

        // Pulled, not read: a verifier is single-use, and leaving it behind would let a replayed
        // callback reuse it.
        $codeVerifier = $this->request->session()->pull($this->codeVerifierSessionKey());

        if (! $codeVerifier) {
            return ['error' => 'The TikTok connection has expired. Please start again.'];
        }

        $response = Http::asForm()->post(TikTokProvider::API_URL.'/oauth/token/', [
            'client_key' => $this->clientId,
            'client_secret' => $this->clientSecret,
            // TikTok returns the code percent-encoded (a trailing `*` arrives as `%2A`) and rejects
            // the exchange if it is passed through as-is.
            'code' => urldecode((string) Arr::get($params, 'code', '')),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUrl,
            'code_verifier' => $codeVerifier,
        ]);

        $body = $response->json() ?? [];

        // The token endpoint reports failures both by HTTP status and by an `error` key on a 200,
        // so neither check alone is enough.
        if ($response->failed() || Arr::get($body, 'error')) {
            return ['error' => $this->authorizationErrorMessage($body)];
        }

        return $this->buildToken($body);
    }

    /**
     * TikTok access tokens live 24 hours, so an account connected today stops working tomorrow
     * without this. Refresh tokens last 365 days and TikTok issues a new one on every refresh —
     * storing the replacement is what keeps the chain alive.
     *
     * Returns the persisted token, or `['error' => ...]` when the refresh was refused.
     */
    public function refreshAccessToken(): array
    {
        $refreshToken = Arr::get($this->getAccessToken(), 'refresh_token');

        if (! $refreshToken) {
            return ['error' => 'This TikTok account has no refresh token. Reconnect the account.'];
        }

        $response = Http::asForm()->post(TikTokProvider::API_URL.'/oauth/token/', [
            'client_key' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $body = $response->json() ?? [];

        if ($response->failed() || Arr::get($body, 'error')) {
            return ['error' => $this->authorizationErrorMessage($body)];
        }

        $token = $this->buildToken($body);

        $this->updateToken($token);

        return $token;
    }

    /**
     * `expires_in` is stored as an absolute UTC timestamp rather than the duration TikTok sends,
     * because that is the shape SocialProvider::tokenIsAboutToExpire() reads.
     */
    protected function buildToken(array $result): array
    {
        $token = [
            'access_token' => Arr::get($result, 'access_token'),
            'expires_in' => Carbon::now('UTC')->addSeconds((int) Arr::get($result, 'expires_in', 0))->timestamp,
            'open_id' => Arr::get($result, 'open_id'),
            'scope' => Arr::get($result, 'scope'),
        ];

        if ($refreshToken = Arr::get($result, 'refresh_token')) {
            $token['refresh_token'] = $refreshToken;
            $token['refresh_expires_in'] = Carbon::now('UTC')
                ->addSeconds((int) Arr::get($result, 'refresh_expires_in', 0))
                ->timestamp;
        }

        return $token;
    }

    /**
     * A high-entropy string of unreserved characters, 43–128 long, generated fresh per attempt.
     * Str::random() is alphanumeric, so every character is already unreserved.
     */
    protected function generateCodeVerifier(): string
    {
        return Str::random(64);
    }

    /**
     * TikTok departs from RFC 7636 here: it advertises `S256` but expects the SHA-256 digest
     * **hex-encoded**, not base64url. A standard base64url challenge produces a bare
     * `invalid_request` at the token step with nothing to point at the cause, so this one line is
     * the single most breakable part of the connection.
     *
     * @see https://developers.tiktok.com/doc/login-kit-desktop
     */
    protected function deriveCodeChallenge(string $codeVerifier): string
    {
        return hash('sha256', $codeVerifier);
    }

    /**
     * A single-use random value put in the session before the redirect and compared on return,
     * matching how LinkedInProvider guards the same boundary.
     */
    protected function verifyState(mixed $state): ?string
    {
        $expected = $this->request->session()->get($this->stateSessionKey());

        if (! $expected || ! is_string($state) || ! hash_equals($expected, $state)) {
            return 'The TikTok authorization could not be verified. Please start the connection again.';
        }

        $this->request->session()->forget($this->stateSessionKey());

        return null;
    }

    protected function codeVerifierSessionKey(): string
    {
        return Util::config('cache_prefix').'.tiktok_code_verifier';
    }

    protected function stateSessionKey(): string
    {
        return Util::config('cache_prefix').'.tiktok_oauth_state';
    }

    protected function authorizationErrorMessage(array $body): string
    {
        $description = Arr::get($body, 'error_description')
            ?: Arr::get($body, 'message')
            ?: Arr::get($body, 'error');

        if (! $description) {
            return 'TikTok rejected the authorization.';
        }

        // TikTok reports both a bad PKCE pair and a stale code this way, and the raw text does not
        // hint at what to do about it.
        if (str_contains(strtolower((string) $description), 'code_verifier')) {
            return "$description This usually means the authorization was started in another browser session — please try connecting again.";
        }

        return (string) $description;
    }
}
