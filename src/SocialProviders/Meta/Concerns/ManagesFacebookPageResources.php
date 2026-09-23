<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\Meta\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use OneMediaLabs\MixpostMcp\Enums\SocialProviderResponseStatus;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;

trait ManagesFacebookPageResources
{
    public function getAccount(): SocialProviderResponse
    {
        $response = Http::get("$this->apiUrl/$this->apiVersion/me", [
            'fields' => 'id,name,username,picture{url}',
            'access_token' => $this->getAccessToken()['page_access_token'],
        ]);

        return $this->buildResponse($response, function () use ($response) {
            $data = $response->json();

            return [
                'id' => $data['id'],
                'name' => $data['name'],
                'username' => $data['username'] ?? '',
                'image' => Arr::get($data, 'picture.data.url'),
            ];
        });
    }

    public function getEntities(bool $withAccessToken = false): SocialProviderResponse
    {
        $response = Http::withToken($this->getAccessToken()['access_token'])
            ->get("$this->apiUrl/$this->apiVersion/me/accounts", [
                'fields' => 'id,name,username,picture{url}'.($withAccessToken ? ',access_token' : ''),
                'limit' => 200,
            ]);

        return $this->buildResponse($response, function () use ($response, $withAccessToken) {
            return $response->collect('data')->map(function ($item) use ($withAccessToken) {
                $array = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'username' => $item['username'] ?? '',
                    'image' => Arr::get($item, 'picture.data.url'),
                ];

                if ($withAccessToken) {
                    $array['access_token'] = [
                        'access_token' => $item['access_token'],
                    ];
                }

                return $array;
            })->toArray();
        });
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        return parent::publishFacebookPost($text, $media, $params, $this->getAccessToken()['page_access_token']);
    }

    public function getPageAudience(): SocialProviderResponse
    {
        $response = Http::get("$this->apiUrl/$this->apiVersion/{$this->values['provider_id']}", [
            'fields' => 'fan_count,followers_count',
            'access_token' => $this->getAccessToken()['page_access_token'],
        ]);

        return $this->buildResponse($response);
    }

    public function getPageInsights(): SocialProviderResponse
    {
        $data = [
            'access_token' => $this->getAccessToken()['page_access_token'],
            'metric' => 'page_post_engagements,page_media_view', // Meta retired `page_engaged_users`, then `page_posts_impressions` (June 2025); `page_media_view` is its documented successor family
            'period' => 'day',
            'since' => Carbon::today('UTC')->subDays(90)->toDateString(),
            'until' => Carbon::today('UTC')->toDateString(),
        ];

        $response = Http::get("$this->apiUrl/$this->apiVersion/{$this->values['provider_id']}/insights", $data);

        return $this->buildResponse($response);
    }

    public function deletePost($id): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }
}
