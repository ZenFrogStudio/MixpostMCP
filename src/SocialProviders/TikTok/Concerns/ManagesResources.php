<?php

namespace Inovector\Mixpost\SocialProviders\TikTok\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\TikTok\TikTokProvider;
use Inovector\Mixpost\Support\MediaProbe;
use Inovector\Mixpost\Support\SocialProviderResponse;

/**
 * Reads the connected creator and publishes to them.
 *
 * The important idea here is that TikTok's posting rules are per-creator, not per-app: what
 * audiences they may publish to, whether Duet or Stitch are available, how long a video they are
 * allowed to post. creator_info/query is the only source of that, so it is queried before every
 * publish and the post is checked against the answer before a single byte is uploaded. Failing here
 * with a reason beats TikTok discarding the post asynchronously an hour later.
 */
trait ManagesResources
{
    /**
     * TikTok caps the title at 2200 UTF-16 code units. This is the API's own limit, so it is fixed
     * here rather than read from `social_provider_options.tiktok.post_character_limit` — that
     * setting governs the composer and an operator could lower it without changing what TikTok
     * accepts.
     */
    const TITLE_LIMIT_UTF16 = 2200;

    /**
     * TikTok's floor for any video. The ceiling is deliberately not a constant — it varies per
     * creator and is read from creator_info/query, see rejectAgainstCreatorConstraints().
     */
    const MIN_VIDEO_SECONDS = 3;

    const ALLOWED_VIDEO_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];

    public function getAccount(): SocialProviderResponse
    {
        $response = Http::withToken($this->getAccessToken()['access_token'])
            // Only fields covered by the `user.info.basic` scope. `username` needs
            // `user.info.profile`, and asking for a field the token does not cover fails the whole
            // call rather than omitting it.
            ->get(TikTokProvider::API_URL.'/user/info/', [
                'fields' => 'open_id,union_id,display_name,avatar_url',
            ]);

        return $this->buildResponse($response, function () use ($response) {
            $user = Arr::get($response->json() ?? [], 'data.user', []);

            return [
                'id' => Arr::get($user, 'open_id'),
                'name' => Arr::get($user, 'display_name'),
                // No handle is available under the basic scope, so externalPostUrl() has no profile
                // to point at. It returns '#' rather than guessing one from the display name.
                'username' => null,
                'image' => Arr::get($user, 'avatar_url'),
                'data' => [
                    'union_id' => Arr::get($user, 'union_id'),
                ],
            ];
        });
    }

    /**
     * The creator's current posting constraints. Called before every publish, and by the composer
     * so the privacy dropdown offers what this creator can actually use.
     *
     * TikTok rate-limits this to 20 calls per minute per token, which is far above what either
     * caller does.
     */
    public function getCreatorInfo(): SocialProviderResponse
    {
        return $this->buildResponse(
            Http::withToken($this->getAccessToken()['access_token'])
                // The endpoint takes no parameters but still wants a JSON content type, and an
                // empty Laravel payload would serialise to `[]` rather than an object.
                ->withBody('{}', 'application/json; charset=UTF-8')
                ->post(TikTokProvider::API_URL.'/post/publish/creator_info/query/')
        );
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        $video = $media->first(fn (Media $item) => $item->isVideo());

        if ($rejection = $this->rejectUnpostableMedia($media, $video)) {
            return $rejection;
        }

        $creatorInfo = $this->getCreatorInfo();

        if ($creatorInfo->hasError()) {
            return $creatorInfo;
        }

        $constraints = $creatorInfo->context();

        if ($rejection = $this->rejectAgainstCreatorConstraints($video, $params, $constraints)) {
            return $rejection;
        }

        $videoSizeBytes = (int) $video->size;
        $chunkPlan = $this->planVideoChunks($videoSizeBytes);

        $initResponse = $this->initVideoPublish(
            $this->buildPostInfo($text, $params, $constraints),
            $videoSizeBytes,
            $chunkPlan
        );

        if ($initResponse->hasError()) {
            return $initResponse;
        }

        $publishId = (string) Arr::get($initResponse->context(), 'publish_id', '');
        $uploadUrl = (string) Arr::get($initResponse->context(), 'upload_url', '');

        if (! $publishId || ! $uploadUrl) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'TikTok accepted the post but did not return an upload URL.',
            ]);
        }

        if ($uploadFailure = $this->uploadVideoChunks($uploadUrl, $video, $videoSizeBytes, $chunkPlan)) {
            return $uploadFailure;
        }

        $published = $this->awaitPublishCompletion($publishId);

        if ($published->hasError()) {
            return $published;
        }

        // A post nobody can see looks identical to a successful one everywhere else in the app, so
        // the warning travels with the result. It also goes under `data`, which is the part of the
        // response Post::insertProviderData() actually persists against the account.
        if ($warning = $this->privateOnlyWarning($params, $constraints)) {
            return $this->response(SocialProviderResponseStatus::OK, array_merge($published->context(), [
                'warning' => $warning,
                'data' => ['warning' => $warning],
            ]));
        }

        return $published;
    }

    /**
     * TikTok's Content Posting API has no delete endpoint — a published video can only be removed
     * by the creator in the app. Reporting OK matches how FacebookPageProvider and MastodonProvider
     * handle the same gap: deleting the post in Mixpost Live should not fail because the network cannot
     * follow.
     */
    public function deletePost($id): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }

    /**
     * Everything that can be rejected without talking to TikTok at all. A text-only or image post
     * has no chance of succeeding, so it should cost nothing to find that out.
     */
    protected function rejectUnpostableMedia(Collection $media, ?Media $video): ?SocialProviderResponse
    {
        if ($media->isEmpty()) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'TikTok posts must include a video. Text-only posts cannot be published to TikTok.',
            ]);
        }

        if (! $video) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'TikTok posts must include a video. Photo posts are a separate TikTok product and are not supported here.',
            ]);
        }

        if ($media->contains(fn (Media $item) => ! $item->isVideo())) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'A TikTok post can contain one video and nothing else. Remove the images from this version.',
            ]);
        }

        if ($media->count() > 1) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'TikTok accepts one video per post.',
            ]);
        }

        // TikTok's other ingest mode, PULL_FROM_URL, needs the domain verified on the app, so the
        // bytes have to be readable from here. External media is a bare URL with no file behind it.
        if ($video->disk === 'external_media' || (int) $video->size <= 0) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video {$video->name} is not stored in Mixpost Live, so its file cannot be uploaded to TikTok.",
            ]);
        }

        if ((int) $video->size > self::MAX_VIDEO_BYTES) {
            $sizeInGb = round($video->size / 1024 / 1024 / 1024, 2);

            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video is {$sizeInGb} GB. TikTok's limit is 4 GB.",
            ]);
        }

        if (! in_array($video->mime_type, self::ALLOWED_VIDEO_TYPES, true)) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video {$video->name} is a {$video->mime_type} file. TikTok accepts MP4, MOV and WEBM.",
            ]);
        }

        $duration = MediaProbe::for($video)?->duration;

        if ($duration !== null && $duration < self::MIN_VIDEO_SECONDS) {
            $rounded = round($duration, 1);

            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video is {$rounded}s long. TikTok requires at least ".self::MIN_VIDEO_SECONDS.'s.',
            ]);
        }

        return null;
    }

    /**
     * The checks that need the creator's own limits. Each message names the real limit, because
     * "invalid_params" from TikTok tells the operator nothing about which value to change.
     */
    protected function rejectAgainstCreatorConstraints(Media $video, array $params, array $constraints): ?SocialProviderResponse
    {
        $allowedLevels = Arr::get($constraints, 'privacy_level_options', []);
        $privacyLevel = (string) Arr::get($params, 'privacy_level', '');

        if (! $privacyLevel) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'Choose who can see this TikTok video in the post options. TikTok requires the audience to be selected explicitly.',
            ]);
        }

        if (is_array($allowedLevels) && ! empty($allowedLevels) && ! in_array($privacyLevel, $allowedLevels, true)) {
            $readable = implode(', ', $allowedLevels);

            return $this->response(SocialProviderResponseStatus::ERROR, [
                "TikTok does not allow the privacy level `$privacyLevel` for this creator. Allowed levels are: $readable.",
            ]);
        }

        // The ceiling belongs to the creator, not to TikTok — a verified account may be allowed ten
        // minutes where a new one is allowed one — so it is read from the answer rather than fixed.
        $maxDuration = (int) Arr::get($constraints, 'max_video_post_duration_sec', 0);
        $duration = MediaProbe::for($video)?->duration;

        if ($maxDuration > 0 && $duration !== null && $duration > $maxDuration) {
            $rounded = (int) ceil($duration);

            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video is {$rounded} seconds long. TikTok allows this creator at most {$maxDuration} seconds.",
            ]);
        }

        return null;
    }

    /**
     * Interaction settings are forced off when the creator has them off. TikTok would reject the
     * post otherwise, and the composer's toggles are declared once per provider — they cannot know
     * which account a version will be published to.
     */
    protected function buildPostInfo(string $text, array $params, array $constraints): array
    {
        return [
            'title' => $this->truncateTitle($text),
            'privacy_level' => (string) Arr::get($params, 'privacy_level', ''),
            'disable_comment' => Arr::get($constraints, 'comment_disabled', false)
                || (bool) Arr::get($params, 'disable_comment', false),
            'disable_duet' => Arr::get($constraints, 'duet_disabled', false)
                || (bool) Arr::get($params, 'disable_duet', false),
            'disable_stitch' => Arr::get($constraints, 'stitch_disabled', false)
                || (bool) Arr::get($params, 'disable_stitch', false),
        ];
    }

    /**
     * An app that has not passed TikTok's content-posting audit can only publish SELF_ONLY videos,
     * and the whole flow still reports success — the video simply exists where nobody can see it.
     * That silent success is the worst outcome this integration can produce, so it is called out on
     * the result as well as in the composer.
     *
     * Note that `privacy_level_options` does not reliably advertise audit status; a creator whose
     * only option is SELF_ONLY is a strong signal, and an actual SELF_ONLY post is worth flagging
     * whatever the cause.
     */
    protected function privateOnlyWarning(array $params, array $constraints): ?string
    {
        $allowedLevels = Arr::get($constraints, 'privacy_level_options', []);

        if (is_array($allowedLevels) && $allowedLevels === ['SELF_ONLY']) {
            return 'This video was posted as private. TikTok offered this creator no other audience, which usually means this TikTok app has not passed the content posting audit yet.';
        }

        if (Arr::get($params, 'privacy_level') === 'SELF_ONLY') {
            return 'This video was posted as private and is visible only to the creator.';
        }

        return null;
    }

    /**
     * Cut to TikTok's limit rather than letting it reject the whole post over a long caption.
     * Counting is done in UTF-16 code units because that is what TikTok counts — an emoji costs two
     * of them, so counting characters would let a caption through that TikTok then refuses.
     */
    protected function truncateTitle(string $text): string
    {
        if ($this->utf16Length($text) <= self::TITLE_LIMIT_UTF16) {
            return $text;
        }

        $title = '';
        $used = 0;

        foreach (mb_str_split($text) as $character) {
            $width = $this->utf16Length($character);

            if ($used + $width > self::TITLE_LIMIT_UTF16) {
                break;
            }

            $title .= $character;
            $used += $width;
        }

        return rtrim($title);
    }

    protected function utf16Length(string $text): int
    {
        return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }
}
