<?php

namespace Inovector\Mixpost\SocialProviders\TikTok\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\TikTok\TikTokProvider;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Util;

/**
 * Moves one video file into TikTok and waits for the post to actually exist.
 *
 * Three steps, in order:
 *   1. init   — declare the post and the file's chunking up front; TikTok replies with a publish id
 *               and a pre-signed upload URL.
 *   2. upload — PUT the bytes to that URL, one Content-Range chunk at a time.
 *   3. poll   — ask /status/fetch/ until the post reaches PUBLISH_COMPLETE. A finished upload is
 *               not a published video; TikTok still has to transcode and run its own checks.
 */
trait ManagesTikTokVideoUpload
{
    /**
     * TikTok's transfer limits. A chunk must be at least 5 MB and at most 64 MB, except that the
     * final chunk carries the remainder and may run up to 128 MB.
     *
     * @see https://developers.tiktok.com/doc/content-posting-api-media-transfer-guide
     */
    const MIN_CHUNK_BYTES = 5242880; // 5 MB

    const MAX_CHUNK_BYTES = 67108864; // 64 MB

    const MAX_VIDEO_BYTES = 4294967296; // 4 GB

    /**
     * TikTok stops transcoding long before this, but the loop has to end somewhere. With
     * performTaskWithDelay's backoff this is roughly 18 minutes of waiting.
     */
    const PUBLISH_POLL_ATTEMPTS = 20;

    /**
     * Chunk size is derived from the file, never fixed. A 2 MB video sent as a "5 MB chunk" and a
     * 300 MB video sent as one chunk both fail, and that mismatch is the usual reason an upload
     * dies part-way with nothing useful in the response.
     *
     * Anything that fits inside one legal chunk goes as one chunk — that covers the sub-5 MB case,
     * where TikTok explicitly wants chunk_size to equal the whole file rather than the minimum.
     * Above 64 MB the file is cut into 64 MB pieces and the last one absorbs the remainder, which
     * is why total_chunk_count rounds *down*.
     */
    protected function planVideoChunks(int $videoSizeBytes): array
    {
        if ($videoSizeBytes <= self::MAX_CHUNK_BYTES) {
            return [
                'chunk_size' => $videoSizeBytes,
                'total_chunk_count' => 1,
            ];
        }

        return [
            'chunk_size' => self::MAX_CHUNK_BYTES,
            'total_chunk_count' => intdiv($videoSizeBytes, self::MAX_CHUNK_BYTES),
        ];
    }

    protected function initVideoPublish(array $postInfo, int $videoSizeBytes, array $chunkPlan): SocialProviderResponse
    {
        return $this->buildResponse(
            Http::withToken($this->getAccessToken()['access_token'])
                ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
                ->post(TikTokProvider::API_URL.'/post/publish/video/init/', [
                    'post_info' => $postInfo,
                    'source_info' => [
                        'source' => 'FILE_UPLOAD',
                        'video_size' => $videoSizeBytes,
                        'chunk_size' => $chunkPlan['chunk_size'],
                        'total_chunk_count' => $chunkPlan['total_chunk_count'],
                    ],
                ])
        );
    }

    /**
     * Returns null when every chunk landed, or the failing response otherwise.
     *
     * The stream is opened once and read by offset so a 4 GB file never sits in memory whole — only
     * one chunk does. It is closed on every path out, including the failures.
     */
    protected function uploadVideoChunks(string $uploadUrl, Media $video, int $videoSizeBytes, array $chunkPlan): ?SocialProviderResponse
    {
        $stream = $video->readStream();

        if (! $stream || ! is_resource($stream['stream'])) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video {$video->name} could not be read for upload.",
            ]);
        }

        $chunkSize = $chunkPlan['chunk_size'];
        $totalChunks = $chunkPlan['total_chunk_count'];

        for ($index = 0; $index < $totalChunks; $index++) {
            $firstByte = $index * $chunkSize;

            // The final chunk runs to the end of the file, which is what makes it legal for it to
            // be larger than chunk_size.
            $lastByte = $index === $totalChunks - 1
                ? $videoSizeBytes - 1
                : $firstByte + $chunkSize - 1;

            $chunk = stream_get_contents($stream['stream'], $lastByte - $firstByte + 1, $firstByte);

            if ($chunk === false) {
                Util::closeAndDeleteStreamResource($stream);

                return $this->response(SocialProviderResponseStatus::ERROR, [
                    "The video {$video->name} could not be read past byte $firstByte.",
                ]);
            }

            // No bearer token here: upload_url is pre-signed, and TikTok rejects the PUT if an
            // Authorization header is attached to it.
            $response = $this->buildResponse(
                Http::timeout(60 * 10)
                    ->withHeaders([
                        'Content-Range' => "bytes $firstByte-$lastByte/$videoSizeBytes",
                    ])
                    ->withBody($chunk, $video->mime_type)
                    ->put($uploadUrl)
            );

            if ($response->hasError()) {
                Util::closeAndDeleteStreamResource($stream);

                $chunkNumber = $index + 1;

                return $response->useContext([
                    "Uploading {$video->name} failed on chunk $chunkNumber of $totalChunks: ".implode(' ', $response->context()),
                ]);
            }
        }

        Util::closeAndDeleteStreamResource($stream);

        return null;
    }

    /**
     * Waits for the post to become real. Reporting success when the last chunk was accepted would
     * mark posts as published that TikTok later rejects for duration, format or spam reasons — so
     * only PUBLISH_COMPLETE counts.
     */
    protected function awaitPublishCompletion(string $publishId): SocialProviderResponse
    {
        $result = Util::performTaskWithDelay(function () use ($publishId) {
            $response = $this->buildResponse(
                Http::withToken($this->getAccessToken()['access_token'])
                    ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
                    ->post(TikTokProvider::API_URL.'/post/publish/status/fetch/', [
                        'publish_id' => $publishId,
                    ])
            );

            if ($response->hasError()) {
                return $response;
            }

            $context = $response->context();
            $status = (string) Arr::get($context, 'status', '');

            if ($status === 'PUBLISH_COMPLETE') {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $this->extractPublishedPostId($context, $publishId),
                ]);
            }

            if ($status === 'FAILED') {
                return $this->response(SocialProviderResponseStatus::ERROR, [
                    $this->explainFailReason((string) Arr::get($context, 'fail_reason', '')),
                ]);
            }

            // PROCESSING_UPLOAD, PROCESSING_DOWNLOAD and SEND_TO_USER_INBOX are all still in
            // flight. Returning null keeps performTaskWithDelay backing off and asking again.
            return null;
        }, 10, 60, self::PUBLISH_POLL_ATTEMPTS);

        if ($result === null) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'TikTok was still processing this video after about 18 minutes. It may still finish publishing on its own — check the account before posting it again.',
            ]);
        }

        return $result;
    }

    /**
     * TikTok only returns a post id once the video is publicly viewable, so a SELF_ONLY post has
     * none. Falling back to the publish id keeps a reference on the post; externalPostUrl() will
     * not resolve for it, which is honest — there is no public page to link to.
     *
     * The misspelled key is TikTok's, not a typo here.
     */
    protected function extractPublishedPostId(array $context, string $publishId): string
    {
        $postIds = Arr::get($context, 'publicaly_available_post_id');

        if (is_array($postIds) && ! empty($postIds)) {
            return (string) $postIds[0];
        }

        return $publishId;
    }

    protected function explainFailReason(string $reason): string
    {
        $readable = match ($reason) {
            'file_format_check_failed' => 'TikTok rejected the file format. Use an MP4 or MOV encoded with H.264.',
            'duration_check_failed' => 'The video is longer than this creator is allowed to post.',
            'frame_rate_check_failed' => 'TikTok rejected the frame rate. It must be between 23 and 60 fps.',
            'picture_size_check_failed' => 'TikTok rejected the video dimensions.',
            'video_pull_failed' => 'TikTok could not read the uploaded file.',
            'publish_cancelled' => 'The creator cancelled this post in the TikTok app.',
            'auth_removed' => 'The creator revoked this app\'s access. Reconnect the account.',
            'spam_risk_too_many_posts' => 'This account has hit its daily posting limit on TikTok.',
            'spam_risk_user_banned_from_posting' => 'TikTok has blocked this account from posting.',
            'spam_risk_text', 'spam_risk' => 'TikTok flagged this post as spam and refused to publish it.',
            'internal' => 'TikTok hit an internal error while publishing. Try again.',
            default => '',
        };

        if ($readable) {
            return $readable;
        }

        return $reason
            ? "TikTok failed to publish this video: $reason"
            : 'TikTok failed to publish this video and gave no reason.';
    }
}
