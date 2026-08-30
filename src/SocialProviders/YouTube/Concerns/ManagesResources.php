<?php

namespace Inovector\Mixpost\SocialProviders\YouTube\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\YouTube\YouTubeProvider;
use Inovector\Mixpost\Support\SocialProviderResponse;

/**
 * Reads the channels a Google account owns and publishes to the connected one.
 *
 * One Google account can own several channels — its own plus any Brand Account it manages — and a
 * single token covers all of them. That is why there is a channel picker and why there is no
 * per-channel token to store.
 */
trait ManagesResources
{
    /**
     * Every channel the connected account owns, for the entity picker.
     *
     * An account with no channel comes back as an empty list rather than an error; that is a normal
     * Google account that has simply never created a channel, and AccountEntitiesController turns
     * it into "The account has no entities."
     */
    public function getEntities(): SocialProviderResponse
    {
        $response = $this->buildResponse(
            $this->apiRequest()->get(YouTubeProvider::API_URL.'/channels', [
                'part' => 'snippet,contentDetails',
                'mine' => 'true',
            ])
        );

        if ($response->hasError()) {
            return $response;
        }

        return $this->response(SocialProviderResponseStatus::OK, array_map(
            fn ($item) => $this->channelEntity($item),
            Arr::get($response->context(), 'items', [])
        ));
    }

    public function getAccount(): SocialProviderResponse
    {
        $response = $this->buildResponse(
            $this->apiRequest()->get(YouTubeProvider::API_URL.'/channels', [
                'part' => 'snippet,statistics',
                'id' => $this->channelId(),
            ])
        );

        if ($response->hasError()) {
            return $response;
        }

        $item = Arr::first(Arr::get($response->context(), 'items', []));

        if (! $item) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'This YouTube channel is no longer visible to the connected Google account.',
            ]);
        }

        $account = $this->channelEntity($item);

        // This call does not ask for contentDetails, and UpdateOrCreateAccount overwrites `data`
        // wholesale — so merge onto what is already stored rather than dropping the uploads
        // playlist id every time an account is refreshed.
        $account['data'] = array_merge(collect(Arr::get($this->values, 'data'))->toArray(), $account['data']);

        return $this->response(SocialProviderResponseStatus::OK, $account);
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        $video = $media->first(fn (Media $item) => $item->isVideo());

        if ($rejection = $this->rejectUnpostableMedia($media, $video)) {
            return $rejection;
        }

        [$title, $description] = YouTubeProvider::deriveTitleAndDescription($text, $params);

        $session = $this->initResumableUpload($video, [
            // YouTube refuses an untitled video, and a video posted with no caption is a normal
            // post here, so the file name stands in for one.
            'title' => $title !== '' ? $title : $this->fallbackTitle($video),
            'description' => $description,
            'categoryId' => $this->chooseOption($params, 'category_id', 22),
        ], [
            'privacyStatus' => $this->chooseOption($params, 'privacy_status', 'private'),
            // YouTube requires every upload to declare this. It is a legal compliance answer rather
            // than a preference, so it is always sent rather than left to whatever YouTube defaults
            // to for the channel.
            'selfDeclaredMadeForKids' => (bool) Arr::get($params, 'made_for_kids', false),
        ]);

        if ($session->hasError()) {
            return $session;
        }

        $upload = $this->uploadVideoBytes((string) Arr::get($session->context(), 'upload_url'), $video);

        if ($upload->hasError()) {
            return $upload;
        }

        $this->setThumbnail((string) $upload->id(), $video);

        return $upload;
    }

    public function deletePost($id): SocialProviderResponse
    {
        // A post published before the id was recorded has nothing to delete on YouTube.
        if (! is_string($id) || $id === '') {
            return $this->response(SocialProviderResponseStatus::OK, []);
        }

        $response = $this->apiRequest()->delete(YouTubeProvider::API_URL.'/videos?id='.urlencode($id));

        // 204 is the success answer and 404 means it is already gone. Neither should stop the post
        // being removed from Mixpost Live.
        if (in_array($response->status(), [204, 404], true)) {
            return $this->response(SocialProviderResponseStatus::OK, []);
        }

        return $this->buildResponse($response);
    }

    /**
     * Everything that can be rejected without talking to YouTube at all. A text-only or image post
     * has no chance of succeeding, so it should cost nothing — and no quota — to find that out.
     */
    protected function rejectUnpostableMedia(Collection $media, ?Media $video): ?SocialProviderResponse
    {
        if ($media->isEmpty()) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'YouTube posts must include a video. Text-only posts cannot be published to YouTube.',
            ]);
        }

        if (! $video) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'YouTube posts must include a video. Images cannot be published to YouTube.',
            ]);
        }

        if ($media->count() > 1) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'A YouTube post is one video and nothing else. Remove the other files from this version.',
            ]);
        }

        // The bytes have to be readable from here: a resumable upload sends the file, and external
        // media is a bare URL with no file behind it.
        if ($video->disk === 'external_media' || (int) $video->size <= 0) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video {$video->name} is not stored in Mixpost Live, so its file cannot be uploaded to YouTube.",
            ]);
        }

        if ((int) $video->size > self::MAX_VIDEO_BYTES) {
            $sizeInGb = round($video->size / 1024 / 1024 / 1024, 2);

            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video is {$sizeInGb} GB. YouTube's limit is 128 GB.",
            ]);
        }

        return null;
    }

    /**
     * Post options arrive from the composer as saved JSON, so a stale version — or one edited by
     * hand — can carry a value YouTube has never heard of, and a select saves its key as a string
     * while an untouched default saves as whatever type the schema declared. Matching against the
     * schema settles both: anything unrecognised falls back to the default rather than reaching the
     * API.
     */
    protected function chooseOption(array $params, string $key, string|int $default): string
    {
        $field = collect(YouTubeProvider::postOptions())->firstWhere('key', $key);
        $allowed = array_map('strval', array_keys($field['choices'] ?? []));
        $value = (string) Arr::get($params, $key, $default);

        return in_array($value, $allowed, true) ? $value : (string) $default;
    }

    protected function fallbackTitle(Media $video): string
    {
        return YouTubeProvider::deriveTitleAndDescription($video->name)[0] ?: 'Untitled video';
    }

    protected function channelEntity(array $item): array
    {
        return [
            'id' => (string) Arr::get($item, 'id', ''),
            'name' => (string) Arr::get($item, 'snippet.title', ''),
            // v3 returns the handle as `@name`, and only for a channel that has claimed one.
            'username' => (string) Arr::get($item, 'snippet.customUrl', ''),
            'image' => Arr::get($item, 'snippet.thumbnails.default.url'),
            'data' => array_filter([
                // contentDetails is fetched anyway to list the channels; keeping the uploads
                // playlist id saves paying another quota unit for it later.
                'uploads_playlist_id' => Arr::get($item, 'contentDetails.relatedPlaylists.uploads'),
                'subscriber_count' => Arr::get($item, 'statistics.subscriberCount'),
                'video_count' => Arr::get($item, 'statistics.videoCount'),
            ], fn ($value) => $value !== null),
        ];
    }

    protected function apiRequest(): PendingRequest
    {
        return Http::withToken($this->getAccessToken()['access_token']);
    }

    protected function channelId(): string
    {
        return (string) Arr::get($this->values, 'provider_id', '');
    }
}
