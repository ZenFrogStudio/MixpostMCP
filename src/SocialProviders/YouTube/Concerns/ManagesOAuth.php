<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\YouTube\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The authorization half of the YouTube integration: build Google's consent URL, exchange the
 * returned code for a token, and refresh that token before it dies.
 *
 * Google access tokens last about an hour, so this integration is only usable with scheduled
 * refresh — which is what makes the two parameters in getAuthUrl() below load-bearing rather than
 * optional.
 */
trait ManagesOAuth
{
    const STATE_SESSION_NAME = 'mixpost_youtube_oauth_state';

    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * `youtube.upload` is what publishes a video. `youtube.readonly` is what lets getEntities() list
     * the channels the account owns, which is the whole point of the channel picker.
     *
     * Both are "restricted" scopes: publishing the OAuth consent screen with either of them
     * requires Google's verification review. See README.md.
     */
    protected array $scopes = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];

    public function getAuthUrl(): string
    {
        $state = Str::random(40);

        $this->request->session()->put(self::STATE_SESSION_NAME, $state);

        return $this->buildUrlFromBase('https://accounts.google.com/o/oauth2/v2/auth', [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'state' => $state,
            'scope' => implode(' ', $this->scopes),

            // Without this Google issues an access token and no refresh token, so the connection
            // dies about an hour later with nothing to renew it from.
            'access_type' => 'offline',

            // And without this, a user who has authorised this app before gets no refresh token on
            // reconnect even with access_type=offline — Google only sends one on the *first*
            // consent. The bug that causes appears on the second connection and never the first,
            // which is exactly the case casual testing does not cover.
            'prompt' => 'consent',
        ]);
    }

    public function requestAccessToken(array $params): array
    {
        if ($error = $this->verifyState(Arr::get($params, 'state'))) {
            return ['error' => $error];
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => (string) Arr::get($params, 'code', ''),
            'redirect_uri' => $this->redirectUrl,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $body = $response->json() ?? [];

        if ($response->failed() || Arr::get($body, 'error')) {
            return ['error' => $this->authorizationErrorMessage($body)];
        }

        return $this->buildToken($body);
    }

    /**
     * Google access tokens live about an hour, so a channel connected now stops working this
     * afternoon without this. The scheduled sweep is what calls it.
     *
     * Returns the persisted token, or `['error' => ...]` when the refresh was refused.
     */
    public function refreshAccessToken(): array
    {
        $refreshToken = Arr::get($this->getAccessToken(), 'refresh_token');

        if (! $refreshToken) {
            return ['error' => 'This YouTube account has no refresh token. Reconnect the channel.'];
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
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
     * `expires_in` is stored as an absolute UTC timestamp rather than the duration Google sends,
     * because that is the shape SocialProvider::tokenIsAboutToExpire() reads.
     *
     * Google omits `refresh_token` from refresh responses, so the key is only set when one is
     * present. updateToken() merges rather than replaces, which is what preserves the refresh token
     * issued at connection time across every subsequent refresh.
     */
    protected function buildToken(array $result): array
    {
        $token = [
            'access_token' => Arr::get($result, 'access_token'),
            'expires_in' => Carbon::now('UTC')->addSeconds((int) Arr::get($result, 'expires_in', 0))->timestamp,
            'scope' => Arr::get($result, 'scope'),
        ];

        if ($refreshToken = Arr::get($result, 'refresh_token')) {
            $token['refresh_token'] = $refreshToken;
        }

        return $token;
    }

    /**
     * A single-use random value put in the session before the redirect and compared on return,
     * matching how LinkedInProvider and TikTokProvider guard the same boundary.
     */
    protected function verifyState(mixed $state): ?string
    {
        $expected = $this->request->session()->get(self::STATE_SESSION_NAME);

        if (! $expected || ! is_string($state) || ! hash_equals($expected, $state)) {
            return 'The YouTube authorization could not be verified. Please start the connection again.';
        }

        $this->request->session()->forget(self::STATE_SESSION_NAME);

        return null;
    }

    protected function authorizationErrorMessage(array $body): string
    {
        $error = (string) Arr::get($body, 'error', '');
        $description = (string) (Arr::get($body, 'error_description') ?: 'Google rejected the authorization.');

        // `invalid_grant` is Google's answer to an expired code, a revoked app and a refresh token
        // that aged out — and the description says none of that.
        if ($error === 'invalid_grant') {
            return "$description This usually means the authorization was revoked, or that the OAuth consent screen is still in Testing — refresh tokens expire after 7 days while it is. Reconnect the channel.";
        }

        if ($error === 'redirect_uri_mismatch') {
            return "$description The callback URL on the Services page must be listed exactly in the OAuth client's Authorised redirect URIs.";
        }

        return $description;
    }
}
