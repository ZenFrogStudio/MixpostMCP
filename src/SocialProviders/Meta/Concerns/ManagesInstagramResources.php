<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\Meta\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use OneMediaLabs\MixpostMcp\Enums\SocialProviderResponseStatus;
use OneMediaLabs\MixpostMcp\Models\Media;
use OneMediaLabs\MixpostMcp\Support\MediaProbe;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;
use OneMediaLabs\MixpostMcp\Util;

trait ManagesInstagramResources
{
    /**
     * A feed image has to sit between 4:5 portrait and 1.91:1 landscape. Instagram used to crop
     * anything outside that; it now refuses the container instead, with a bare error code.
     */
    const MIN_IMAGE_RATIO = 0.8; // 4:5

    const MAX_IMAGE_RATIO = 1.91;

    /**
     * Every Instagram video is published as a Reel, and a Reel runs at most 15 minutes.
     */
    const MAX_VIDEO_SECONDS = 900;

    public function getAccount(): SocialProviderResponse
    {
        $response = Http::get("$this->apiUrl/$this->apiVersion/{$this->values['provider_id']}", [
            'fields' => 'id,username,name,profile_picture_url,followers_count',
            'access_token' => $this->getAccessToken()['page_access_token'],
        ]);

        return $this->buildResponse($response, function () use ($response) {
            $data = $response->json();

            return [
                'id' => $data['id'],
                'name' => $data['name'] ?? $data['username'] ?? '',
                'username' => $data['username'] ?? '',
                'image' => $data['profile_picture_url'] ?? null,
            ];
        });
    }

    public function getAudience(): SocialProviderResponse
    {
        $response = Http::get("$this->apiUrl/$this->apiVersion/{$this->values['provider_id']}", [
            'fields' => 'followers_count,media_count',
            'access_token' => $this->getAccessToken()['page_access_token'],
        ]);

        return $this->buildResponse($response);
    }

    /**
     * Daily account insights for the last 30 days, the most Instagram returns per call.
     *
     * Meta retires Instagram metrics one at a time, and a retired metric in the list fails the whole
     * call. Each metric is therefore requested on its own and dropped if it errors, so the context is
     * `['reach' => ['2026-09-21' => 123, …], 'profile_views' => […]]` with only the metrics that came
     * back. Only when every metric fails is the last error returned, so the job can log it.
     *
     * @see https://developers.facebook.com/docs/instagram-platform/insights
     */
    public function getInsights(): SocialProviderResponse
    {
        $url = "$this->apiUrl/$this->apiVersion/{$this->values['provider_id']}/insights";
        $token = $this->getAccessToken()['page_access_token'];

        $insights = [];
        $lastError = null;
        $response = null;

        foreach (['reach', 'profile_views'] as $metric) {
            $response = $this->buildResponse(Http::get($url, [
                'metric' => $metric,
                'period' => 'day',
                'since' => Carbon::today('UTC')->subDays(29)->toDateString(),
                'until' => Carbon::today('UTC')->toDateString(),
                'access_token' => $token,
            ]));

            if ($response->isUnauthorized() || $response->hasExceededRateLimit()) {
                return $response;
            }

            // Meta moves metrics to "total only" one by one; the error asks for `metric_type`. Such a
            // metric has no daily series, so ask for yesterday's total and file it under yesterday.
            if ($response->hasError() && str_contains(Arr::get($response->context(), 'error.message', ''), 'metric_type')) {
                $response = $this->buildResponse(Http::get($url, [
                    'metric' => $metric,
                    'period' => 'day',
                    'metric_type' => 'total_value',
                    'since' => Carbon::yesterday('UTC')->toDateString(),
                    'until' => Carbon::today('UTC')->toDateString(),
                    'access_token' => $token,
                ]));

                if ($response->isUnauthorized() || $response->hasExceededRateLimit()) {
                    return $response;
                }

                if (! $response->hasError()) {
                    $insights[$metric] = [
                        Carbon::yesterday('UTC')->toDateString() => Arr::get($response->context(), 'data.0.total_value.value', 0),
                    ];

                    continue;
                }
            }

            if ($response->hasError()) {
                $lastError = $response;

                continue;
            }

            $insights[$metric] = [];

            foreach (Arr::get($response->context(), 'data.0.values', []) as $item) {
                $insights[$metric][Carbon::parse($item['end_time'], 'UTC')->toDateString()] = $item['value'] ?? 0;
            }
        }

        if (! $insights && $lastError) {
            return $lastError;
        }

        return $this->response(
            SocialProviderResponseStatus::OK,
            $insights,
            $response->rateLimitAboutToBeExceeded(),
            $response->retryAfter(),
            $response->isAppLevel()
        );
    }

    public function getEntities(bool $withAccessToken = false): SocialProviderResponse
    {
        // Instagram business accounts are reached through the Facebook Pages they are linked to.
        $url = "$this->apiUrl/$this->apiVersion/me/accounts";
        $params = [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 200,
        ];

        $pages = [];
        $requests = 0;

        do {
            $response = Http::withToken($this->getAccessToken()['access_token'])->get($url, $params);

            if (Arr::has($response->json(), 'error')) {
                return $this->buildResponse($response);
            }

            $pages = array_merge($pages, $response->json('data', []));

            // Facebook returns a fully-formed `next` URL, so follow-up requests need no params.
            $url = $response->json('paging.next');
            $params = [];
            $requests++;
            // The cursor comes from Facebook, so stop rather than loop forever on a bad one.
        } while ($url && $requests < 20);

        return $this->buildResponse($response, function () use ($pages, $withAccessToken) {
            $entities = [];

            foreach ($pages as $page) {
                $instagram = Arr::get($page, 'instagram_business_account');

                // Skip Pages that have no Instagram business account linked to them.
                if (! $instagram) {
                    continue;
                }

                $entity = [
                    'id' => $instagram['id'],
                    'name' => $instagram['name'] ?? $instagram['username'] ?? '',
                    'username' => $instagram['username'] ?? '',
                    'image' => $instagram['profile_picture_url'] ?? null,
                ];

                // Instagram Graph calls are authorized with the linked Page's token, not the user token.
                if ($withAccessToken) {
                    $entity['access_token'] = [
                        'access_token' => $page['access_token'],
                    ];
                }

                $entities[] = $entity;
            }

            return $entities;
        });
    }

    /**
     * Instagram never accepts a direct upload. Every post is: create a container, wait for it to
     * reach FINISHED, then publish it. Meta downloads the media itself, so every file has to be
     * reachable over the public internet by URL.
     *
     * @see https://developers.facebook.com/docs/instagram-platform/content-publishing
     */
    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        if ($media->isEmpty()) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'Instagram posts must include at least one photo or video. Add media to this post and try again.',
            ]);
        }

        // A carousel is 2 to 10 items: one item is published as a single post below rather than
        // rejected, so only the upper bound needs checking here.
        $maxItems = Util::config('social_provider_options.instagram.media_limit.photos', 10);

        if ($media->count() > $maxItems) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                "Instagram allows up to $maxItems items in a carousel, this post has {$media->count()}.",
            ]);
        }

        if ($rejection = $this->rejectUnpostableMedia($media)) {
            return $rejection;
        }

        // Resolve every URL before calling the API, so an unreachable file fails with a clear
        // message instead of an opaque Graph error halfway through the flow.
        $urls = [];

        foreach ($media as $item) {
            $url = $item->getUrl();

            if (! Util::isPublicDomainUrl($url)) {
                return $this->response(SocialProviderResponseStatus::ERROR, [
                    "Instagram downloads media from a URL rather than accepting an upload, so \"$item->name\" must be reachable from the public internet. It currently resolves to \"$url\", which Instagram cannot reach. Host your media on a public domain or a remote disk and point `mixpostmcp.disk` at it.",
                ]);
            }

            $urls[$item->id] = $url;
        }

        if ($media->count() > 1) {
            return $this->publishInstagramCarousel($text, $media, $urls);
        }

        $item = $media->first();

        // An option the user never touched is not saved, so the default has to be applied here too.
        $shareToFeed = (bool) Arr::get($params, 'share_to_feed', true);

        return $this->publishInstagramSingle($text, $item, $urls[$item->id], $shareToFeed);
    }

    public function deletePost($id): SocialProviderResponse
    {
        // The Graph API cannot delete published Instagram posts.
        return $this->response(SocialProviderResponseStatus::OK, []);
    }

    /**
     * Instagram's rules for the files themselves, checked before the first container is created.
     * A container that violates one still costs a Graph call and comes back as an error code with
     * no indication of which file or which limit was the problem.
     */
    protected function rejectUnpostableMedia(Collection $media): ?SocialProviderResponse
    {
        foreach ($media as $item) {
            if ($rejection = $this->rejectUnpostableFormat($item)) {
                return $rejection;
            }

            $probe = MediaProbe::for($item);

            // Nothing could be measured — a remote disk, or ffmpeg not installed. Let Instagram be
            // the judge rather than blocking a post that is probably fine.
            if (! $probe) {
                continue;
            }

            if ($item->isVideo()) {
                if ($probe->duration !== null && $probe->duration > self::MAX_VIDEO_SECONDS) {
                    $minutes = round($probe->duration / 60, 1);

                    return $this->response(SocialProviderResponseStatus::ERROR, [
                        "\"$item->name\" runs $minutes minutes. Instagram publishes video as a Reel, which is capped at 15 minutes.",
                    ]);
                }

                continue;
            }

            $ratio = $probe->aspectRatio();

            if ($ratio !== null && ($ratio < self::MIN_IMAGE_RATIO || $ratio > self::MAX_IMAGE_RATIO)) {
                return $this->response(SocialProviderResponseStatus::ERROR, [
                    sprintf(
                        '"%s" is %d×%d, an aspect ratio of %s:1. Instagram accepts feed images between 0.8:1 (4:5 portrait) and 1.91:1 (landscape).',
                        $item->name,
                        $probe->width,
                        $probe->height,
                        round($ratio, 2)
                    ),
                ]);
            }
        }

        return null;
    }

    protected function rejectUnpostableFormat(Media $item): ?SocialProviderResponse
    {
        $allowed = $item->isVideo()
            ? ['video/mp4', 'video/quicktime']
            : ['image/jpg', 'image/jpeg', 'image/png'];

        if (in_array($item->mime_type, $allowed, true)) {
            return null;
        }

        $readable = $item->isVideo() ? 'MP4 and MOV video' : 'JPEG and PNG images';

        return $this->response(SocialProviderResponseStatus::ERROR, [
            "\"$item->name\" is a $item->mime_type file. Instagram accepts $readable.",
        ]);
    }

    protected function publishInstagramSingle(string $text, Media $item, string $url, bool $shareToFeed): SocialProviderResponse
    {
        // Meta folded standalone video posts into Reels, so REELS is the only media type a video
        // container accepts.
        $containerParams = $item->isVideo()
            ? ['media_type' => 'REELS', 'video_url' => $url, 'caption' => $text, 'share_to_feed' => $shareToFeed]
            : ['image_url' => $url, 'caption' => $text];

        $container = $this->createInstagramContainer($containerParams);

        if ($container->hasError()) {
            return $container;
        }

        $ready = $this->awaitInstagramContainer($container->id(), $item->isVideo());

        if ($ready->hasError()) {
            return $ready;
        }

        return $this->publishInstagramContainer($container->id());
    }

    protected function publishInstagramCarousel(string $text, Collection $media, array $urls): SocialProviderResponse
    {
        $childIds = [];

        foreach ($media as $item) {
            // Children carry no caption and no media type — Instagram infers it from the URL key.
            $child = $this->createInstagramContainer([
                'is_carousel_item' => true,
                ($item->isVideo() ? 'video_url' : 'image_url') => $urls[$item->id],
            ]);

            if ($child->hasError()) {
                return $child;
            }

            $ready = $this->awaitInstagramContainer($child->id(), $item->isVideo());

            if ($ready->hasError()) {
                return $ready;
            }

            $childIds[] = $child->id();
        }

        $parent = $this->createInstagramContainer([
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $text,
        ]);

        if ($parent->hasError()) {
            return $parent;
        }

        $ready = $this->awaitInstagramContainer($parent->id(), false);

        if ($ready->hasError()) {
            return $ready;
        }

        return $this->publishInstagramContainer($parent->id());
    }

    protected function createInstagramContainer(array $params): SocialProviderResponse
    {
        $response = Http::post("$this->apiUrl/$this->apiVersion/{$this->values['provider_id']}/media", array_merge($params, [
            'access_token' => $this->getAccessToken()['page_access_token'],
        ]));

        return $this->buildResponse($response);
    }

    protected function publishInstagramContainer(string $containerId): SocialProviderResponse
    {
        $response = Http::post("$this->apiUrl/$this->apiVersion/{$this->values['provider_id']}/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $this->getAccessToken()['page_access_token'],
        ]);

        return $this->buildResponse($response, function () use ($response) {
            return [
                'id' => $response->json('id'),
            ];
        });
    }

    /**
     * Poll a container until Instagram finishes downloading and transcoding the media. Images are
     * usually ready on the first check, video regularly takes minutes.
     */
    protected function awaitInstagramContainer(string $containerId, bool $isVideo): SocialProviderResponse
    {
        $result = Util::performTaskWithDelay(
            task: function () use ($containerId) {
                $status = $this->buildResponse(Http::get("$this->apiUrl/$this->apiVersion/$containerId", [
                    'fields' => 'status_code,status',
                    'access_token' => $this->getAccessToken()['page_access_token'],
                ]));

                if ($status->hasError()) {
                    return $status;
                }

                $context = $status->context();
                $code = Arr::get($context, 'status_code');

                if ($code === 'FINISHED') {
                    return $this->response(SocialProviderResponseStatus::OK, ['id' => $containerId]);
                }

                // ERROR and EXPIRED are both terminal, `status` carries the human-readable reason.
                if ($code === 'ERROR' || $code === 'EXPIRED') {
                    return $this->response(SocialProviderResponseStatus::ERROR, [
                        'Instagram could not process the media: '.(Arr::get($context, 'status') ?: $code),
                    ]);
                }

                // IN_PROGRESS — returning null keeps the poll running.
                return null;
            },
            initialDelay: $isVideo ? 10 : 3,
            maxDelay: $isVideo ? 60 : 15,
            maxAttempts: $isVideo ? 15 : 10
        );

        if ($result) {
            return $result;
        }

        $waited = $isVideo ? '13 minutes' : '2 minutes';
        $type = $isVideo ? 'video' : 'image';

        return $this->response(SocialProviderResponseStatus::ERROR, [
            "Instagram was still processing this $type after $waited and the post was not published. Large files take longer, try a smaller or shorter one.",
        ]);
    }
}
