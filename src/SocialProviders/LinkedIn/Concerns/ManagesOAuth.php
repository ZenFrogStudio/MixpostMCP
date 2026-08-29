<?php

namespace Inovector\Mixpost\SocialProviders\LinkedIn\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait ManagesOAuth
{
    const STATE_SESSION_NAME = 'mixpost_linkedin_oauth_state';

    /**
     * `openid`, `profile`, `email` and `w_member_social` come with the Sign In with LinkedIn and
     * Share on LinkedIn products. The two organization scopes come with the Community Management
     * API, which has to be approved on the app separately — see README.md.
     */
    protected array $scopes = [
        'openid',
        'profile',
        'email',
        'w_member_social',
        'r_organization_admin',
        'w_organization_social',
    ];

    public function getAuthUrl(): string
    {
        $state = Str::random(40);

        $this->request->session()->put(self::STATE_SESSION_NAME, $state);

        return $this->buildUrlFromBase('https://www.linkedin.com/oauth/v2/authorization', [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'state' => $state,
            'scope' => implode(' ', $this->scopes),
        ]);
    }

    public function requestAccessToken(array $params): array
    {
        if ($error = $this->verifyState(Arr::get($params, 'state'))) {
            return ['error' => $error];
        }

        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => Arr::get($params, 'code', ''),
            'redirect_uri' => $this->redirectUrl,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if ($response->failed()) {
            return ['error' => $this->authorizationErrorMessage($response->json() ?? [])];
        }

        return $this->buildToken($response->json());
    }

    /**
     * LinkedIn access tokens last about 60 days. Refresh tokens are only issued to apps that have
     * been approved for programmatic refresh, so `refresh_token` is stored only when it is present.
     *
     * `expires_in` is deliberately stored as an absolute UTC timestamp rather than a duration —
     * that is what SocialProvider::tokenIsAboutToExpire() reads.
     */
    protected function buildToken(array $result): array
    {
        $token = [
            'access_token' => $result['access_token'],
            'expires_in' => Carbon::now('UTC')->addSeconds((int) Arr::get($result, 'expires_in', 0))->timestamp,
        ];

        if ($refreshToken = Arr::get($result, 'refresh_token')) {
            $token['refresh_token'] = $refreshToken;
            $token['refresh_token_expires_in'] = Carbon::now('UTC')
                ->addSeconds((int) Arr::get($result, 'refresh_token_expires_in', 0))
                ->timestamp;
        }

        return $token;
    }

    /**
     * A single-use random value put in the session before the redirect and compared on return.
     * Without it, a third party could hand the user a crafted callback URL and attach their own
     * LinkedIn account to this Mixpost install.
     */
    protected function verifyState(mixed $state): ?string
    {
        $expected = $this->request->session()->get(self::STATE_SESSION_NAME);

        if (! $expected || ! is_string($state) || ! hash_equals($expected, $state)) {
            return 'The LinkedIn authorization could not be verified. Please start the connection again.';
        }

        $this->request->session()->forget(self::STATE_SESSION_NAME);

        return null;
    }

    protected function authorizationErrorMessage(array $body): string
    {
        $error = (string) Arr::get($body, 'error', '');
        $description = Arr::get($body, 'error_description') ?: 'LinkedIn rejected the authorization.';

        if (str_contains(strtolower("$error $description"), 'scope')) {
            return "$description This usually means the LinkedIn app has not been approved for the Community Management API, which the `r_organization_admin` and `w_organization_social` scopes require.";
        }

        return $description;
    }
}
