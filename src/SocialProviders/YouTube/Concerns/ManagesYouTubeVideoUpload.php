<?php

namespace Inovector\Mixpost\SocialProviders\YouTube\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\YouTube\YouTubeProvider;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Util;

/**
 * Moves one video file into YouTube with a resumable upload, then sets its thumbnail.
 *
 * Three steps, in order:
 *   1. init      — POST the video's metadata and declare the file's size and type up front. YouTube
 *                  answers with a session URL **in the Location header**, not in the body.
 *   2. upload    — PUT the bytes to that URL with a Content-Range. Anything short of the whole file
 *                  is answered with HTTP 308 and a Range header saying how much actually landed.
 *   3. thumbnail — best effort, and deliberately unable to fail the post.
 *
 * @see https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol
 */
trait ManagesYouTubeVideoUpload
{
    /**
     * Google requires every chunk except the last to be a multiple of 256 KB. 8 MB is a common
     * choice: large enough that a big file is not thousands of requests, small enough that a failed
     * chunk is cheap to resend.
     */
    const CHUNK_BYTES = 8388608; // 8 MB

    const MAX_VIDEO_BYTES = 137438953472; // 128 GB

    // Custom thumbnails are capped at 2 MB by YouTube.
    const MAX_THUMBNAIL_BYTES = 2097152;

    /**
     * Opens the upload session. Returns the session URL under `upload_url`, or the failing response.
     */
    protected function initResumableUpload(Media $video, array $snippet, array $status): SocialProviderResponse
    {
        $httpResponse = Http::withToken($this->getAccessToken()['access_token'])
            ->withHeaders([
                // YouTube sizes the session from these two. A wrong length means byte ranges that
                // never line up with the file and an upload that can never complete.
                'X-Upload-Content-Length' => (string) (int) $video->size,
                'X-Upload-Content-Type' => (string) $video->mime_type,
            ])
            ->post(YouTubeProvider::UPLOAD_URL.'/videos?uploadType=resumable&part=snippet,status', [
                'snippet' => $snippet,
                'status' => $status,
            ]);

        $response = $this->buildResponse($httpResponse);

        if ($response->hasError()) {
            return $response;
        }

        // The body of this call is empty; reading the session URL from it would silently upload
        // nowhere.
        $sessionUrl = (string) $httpResponse->header('Location');

        if (! $this->isSecureUploadUrl($sessionUrl)) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'YouTube accepted the video details but did not return an upload session URL.',
            ]);
        }

        return $this->response(SocialProviderResponseStatus::OK, ['upload_url' => $sessionUrl]);
    }

    /**
     * Sends the file and returns the published video's id under `id`, or the failing response.
     *
     * One offset-driven loop covers both cases the protocol allows: a file smaller than a chunk
     * goes in a single PUT, a larger one goes in 8 MB pieces. The offset is never assumed — it is
     * whatever YouTube last said it had received — and the stream is read by offset so only one
     * chunk is ever in memory.
     */
    protected function uploadVideoBytes(string $sessionUrl, Media $video): SocialProviderResponse
    {
        $totalBytes = (int) $video->size;
        $stream = $video->readStream();

        if (! $stream || ! is_resource($stream['stream'])) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                "The video {$video->name} could not be read for upload.",
            ]);
        }

        $offset = 0;
        $attempts = 0;
        // One request per chunk, plus room for YouTube reporting less progress than was sent. The
        // cap is what stops a session that keeps answering "nothing received" from looping forever.
        $maxAttempts = intdiv($totalBytes, self::CHUNK_BYTES) + 10;

        while ($offset < $totalBytes) {
            if (++$attempts > $maxAttempts) {
                Util::closeAndDeleteStreamResource($stream);

                return $this->response(SocialProviderResponseStatus::ERROR, [
                    "Uploading {$video->name} to YouTube stopped making progress and was abandoned.",
                ]);
            }

            $length = min(self::CHUNK_BYTES, $totalBytes - $offset);
            $lastByte = $offset + $length - 1;

            $chunk = stream_get_contents($stream['stream'], $length, $offset);

            if ($chunk === false) {
                Util::closeAndDeleteStreamResource($stream);

                return $this->response(SocialProviderResponseStatus::ERROR, [
                    "The video {$video->name} could not be read past byte $offset.",
                ]);
            }

            $httpResponse = Http::timeout(60 * 10)
                // 308 here means "resume incomplete", not a redirect. Guzzle only follows a 3xx
                // that carries a Location header and this one carries Range instead, but turning
                // redirects off makes that independent of how Guzzle behaves.
                ->withOptions(['allow_redirects' => false])
                ->withToken($this->getAccessToken()['access_token'])
                ->withHeaders(['Content-Range' => "bytes $offset-$lastByte/$totalBytes"])
                ->withBody($chunk, (string) $video->mime_type)
                ->put($sessionUrl);

            // The chunk landed and YouTube wants the rest. Reading this as an error is the classic
            // way a resumable upload breaks.
            if ($httpResponse->status() === 308) {
                $offset = $this->resumeOffset($httpResponse, $offset + $length);

                continue;
            }

            Util::closeAndDeleteStreamResource($stream);

            if (in_array($httpResponse->status(), [200, 201], true)) {
                // The final response carries the whole video resource; its id is the post id.
                $videoId = (string) $httpResponse->json('id', '');

                if (! $videoId) {
                    return $this->response(SocialProviderResponseStatus::ERROR, [
                        'YouTube accepted the upload but returned no video id.',
                    ]);
                }

                return $this->response(SocialProviderResponseStatus::OK, ['id' => $videoId]);
            }

            $response = $this->buildResponse($httpResponse);

            // Rate-limit and expired-token contexts are structured for the caller and must pass
            // through untouched.
            if ($response->hasExceededRateLimit() || $response->isUnauthorized()) {
                return $response;
            }

            return $response->useContext([
                "Uploading {$video->name} to YouTube failed: ".implode(' ', $response->context()),
            ]);
        }

        Util::closeAndDeleteStreamResource($stream);

        // Only reachable when the last chunk was answered with a 308, which means YouTube never
        // returned the finished video resource and there is no id to store.
        return $this->response(SocialProviderResponseStatus::ERROR, [
            "YouTube did not confirm {$video->name} as published.",
        ]);
    }

    /**
     * Where to carry on from after a 308.
     *
     * `Range: bytes=0-262143` names the last byte YouTube actually stored, which can be fewer than
     * were sent — so resuming from what we believe we sent skips bytes and corrupts the video. No
     * Range header at all means nothing was stored and the file starts again from the beginning.
     *
     * @param  $response  Response
     */
    protected function resumeOffset($response, int $sent): int
    {
        $range = (string) $response->header('Range');

        if (preg_match('/bytes=0-(\d+)/', $range, $matches)) {
            return (int) $matches[1] + 1;
        }

        return $range === '' ? 0 : $sent;
    }

    /**
     * Best effort, by design. A custom thumbnail needs a verified YouTube account, so this failing
     * is the normal outcome on a new channel — and by the time it runs the video is already live.
     * Turning a published video into a failed post over a thumbnail would be much worse than the
     * missing thumbnail.
     */
    protected function setThumbnail(string $videoId, Media $video): void
    {
        try {
            $conversion = $video->getConversion('thumb');

            if (! $videoId || ! $conversion) {
                return;
            }

            $contents = $video->contents('thumb');

            if (! $contents || strlen($contents) > self::MAX_THUMBNAIL_BYTES) {
                return;
            }

            $response = Http::withToken($this->getAccessToken()['access_token'])
                ->timeout(60)
                // MediaVideoThumbConversion always writes a .jpg, so the type is known rather than
                // guessed from the path.
                ->withBody($contents, 'image/jpeg')
                ->post(YouTubeProvider::UPLOAD_URL.'/thumbnails/set?videoId='.urlencode($videoId));

            if ($response->failed()) {
                Log::warning("Mixpost Live could not set the YouTube thumbnail for video $videoId.", [
                    'status' => $response->status(),
                    'reason' => Arr::get($response->json() ?? [], 'error.errors.0.reason'),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning("Mixpost Live could not set the YouTube thumbnail for video $videoId.", [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The session URL comes from YouTube's own authenticated response, but it is still a URL out of
     * a remote header that we are about to send file bytes to, so require https before using it.
     */
    protected function isSecureUploadUrl(mixed $url): bool
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://');
    }
}
