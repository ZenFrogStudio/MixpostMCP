<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\LinkedIn\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use OneMediaLabs\MixpostMcp\Enums\SocialProviderResponseStatus;
use OneMediaLabs\MixpostMcp\Models\Media;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;
use OneMediaLabs\MixpostMcp\Util;

/**
 * Uploading to LinkedIn is the same three-step dance for images and video: initialize the upload to
 * get a signed URL plus an asset URN, PUT the raw bytes to that URL, then name the URN in the post.
 *
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/videos-api
 */
trait ManagesLinkedInMedia
{
    /**
     * Uploads every image and returns their URNs in the given order, or the response that failed.
     */
    protected function uploadImages(Collection $media): array|SocialProviderResponse
    {
        $urns = [];

        foreach ($media as $item) {
            $response = $this->uploadImage($item);

            if ($response->hasError()) {
                return $response;
            }

            $urns[] = $response->id();
        }

        return $urns;
    }

    protected function uploadImage(Media $item): SocialProviderResponse
    {
        $init = $this->buildResponse(
            $this->restRequest()->post("$this->apiUrl/rest/images?action=initializeUpload", [
                'initializeUploadRequest' => ['owner' => $this->authorUrn()],
            ])
        );

        if ($init->hasError()) {
            return $this->mediaError($item, $init);
        }

        $uploadUrl = Arr::get($init->context(), 'value.uploadUrl');
        $urn = Arr::get($init->context(), 'value.image');

        if (! $urn || ! $this->isSecureUploadUrl($uploadUrl)) {
            return $this->uploadUrlMissing($item);
        }

        $stream = $item->readStream();

        if (! $stream) {
            return $this->unreadableFile($item);
        }

        // The image upload is documented as an authenticated plain binary PUT — no multipart, and
        // unlike the video part upload it does carry the bearer token.
        $response = $this->buildResponse(
            Http::withToken($this->getAccessToken()['access_token'])
                ->timeout(60 * 10)
                ->withBody($stream['stream'], 'application/octet-stream')
                ->put($uploadUrl)
        );

        Util::closeAndDeleteStreamResource($stream);

        if ($response->hasError()) {
            return $this->mediaError($item, $response);
        }

        return $this->response(SocialProviderResponseStatus::OK, ['id' => $urn]);
    }

    protected function uploadVideo(Media $item): SocialProviderResponse
    {
        $init = $this->buildResponse(
            $this->restRequest()->post("$this->apiUrl/rest/videos?action=initializeUpload", [
                'initializeUploadRequest' => [
                    'owner' => $this->authorUrn(),
                    // LinkedIn decides how many parts to split the upload into from this number,
                    // so a wrong size means byte ranges that do not line up with the file.
                    'fileSizeBytes' => (int) $item->size,
                    'uploadCaptions' => false,
                    'uploadThumbnail' => false,
                ],
            ])
        );

        if ($init->hasError()) {
            return $this->mediaError($item, $init);
        }

        $urn = Arr::get($init->context(), 'value.video');
        $instructions = Arr::get($init->context(), 'value.uploadInstructions', []);

        if (! $urn || empty($instructions)) {
            return $this->uploadUrlMissing($item);
        }

        $partIds = $this->uploadVideoParts($item, $instructions);

        if ($partIds instanceof SocialProviderResponse) {
            return $partIds;
        }

        $finalize = $this->buildResponse(
            $this->restRequest()->post("$this->apiUrl/rest/videos?action=finalizeUpload", [
                'finalizeUploadRequest' => [
                    'video' => $urn,
                    'uploadToken' => (string) Arr::get($init->context(), 'value.uploadToken', ''),
                    'uploadedPartIds' => $partIds,
                ],
            ])
        );

        if ($finalize->hasError()) {
            return $this->mediaError($item, $finalize);
        }

        return $this->awaitVideoProcessing($item, $urn);
    }

    /**
     * Every video upload is a multi-part upload, even a small one. Each instruction names its own
     * byte range and URL, so reading one range at a time keeps a single 4 MB part in memory rather
     * than a file that LinkedIn allows to be 5 GB.
     *
     * The ETag each part returns is what finalizeUpload stitches the parts back together with, and
     * the order has to match the order of the instructions.
     */
    protected function uploadVideoParts(Media $item, array $instructions): array|SocialProviderResponse
    {
        $stream = $item->readStream();

        if (! $stream) {
            return $this->unreadableFile($item);
        }

        $partIds = [];

        foreach ($instructions as $instruction) {
            $uploadUrl = Arr::get($instruction, 'uploadUrl');

            if (! $this->isSecureUploadUrl($uploadUrl)) {
                Util::closeAndDeleteStreamResource($stream);

                return $this->uploadUrlMissing($item);
            }

            $firstByte = (int) Arr::get($instruction, 'firstByte', 0);
            $lastByte = (int) Arr::get($instruction, 'lastByte', 0);

            // The video part upload is documented without an Authorization header — the signed URL
            // is the credential — so sending the bearer token here would be guessing.
            $httpResponse = Http::timeout(60 * 10)
                ->withBody(
                    stream_get_contents($stream['stream'], $lastByte - $firstByte + 1, $firstByte),
                    'application/octet-stream'
                )
                ->put($uploadUrl);

            $response = $this->buildResponse($httpResponse);

            // LinkedIn quotes the ETag in the header; finalizeUpload wants the bare value.
            $partId = trim((string) $httpResponse->header('etag'), '"');

            if ($response->hasError() || ! $partId) {
                Util::closeAndDeleteStreamResource($stream);

                return $response->hasError()
                    ? $this->mediaError($item, $response)
                    : $this->response(SocialProviderResponseStatus::ERROR, [
                        "File $item->name: LinkedIn did not acknowledge part of the video upload.",
                    ]);
            }

            $partIds[] = $partId;
        }

        Util::closeAndDeleteStreamResource($stream);

        return $partIds;
    }

    /**
     * A post that names a video which is still processing is rejected outright, so wait for the
     * asset to report AVAILABLE before creating the post.
     *
     * A token holding only `w_member_social` is write-only and cannot read the asset back. That is
     * a normal app setup rather than a failure, so an unreadable status ends the wait and lets the
     * post go ahead — the post request itself will surface a real problem if there is one.
     */
    protected function awaitVideoProcessing(Media $item, string $urn): SocialProviderResponse
    {
        $encodedUrn = rawurlencode($urn);

        $status = Util::performTaskWithDelay(function () use ($encodedUrn) {
            $response = $this->restRequest()->get("$this->apiUrl/rest/videos/$encodedUrn");

            if ($response->failed()) {
                return 'UNREADABLE';
            }

            $status = (string) $response->json('status', '');

            // Returning null is what tells performTaskWithDelay to wait and ask again.
            return in_array($status, ['PROCESSING', 'WAITING_UPLOAD'], true) ? null : $status;
        }, 10, 30, 8);

        if ($status === 'PROCESSING_FAILED') {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                "File $item->name: LinkedIn could not process the video.",
            ]);
        }

        return $this->response(SocialProviderResponseStatus::OK, ['id' => $urn]);
    }

    /**
     * The upload URL comes from LinkedIn's own authenticated response, but it is still a URL out of
     * a remote body that we are about to PUT file bytes to, so require https before using it.
     */
    protected function isSecureUploadUrl(mixed $url): bool
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://');
    }

    // Keeps the file name attached to whatever LinkedIn said, so a failure names the offending file.
    // Rate-limit and expired-token contexts are structured for the caller and must pass through.
    protected function mediaError(Media $item, SocialProviderResponse $response): SocialProviderResponse
    {
        if ($response->hasExceededRateLimit() || $response->isUnauthorized()) {
            return $response;
        }

        return $response->useContext(array_map(
            fn ($message) => "File $item->name: $message",
            $response->context()
        ));
    }

    protected function uploadUrlMissing(Media $item): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::ERROR, [
            "File $item->name: LinkedIn did not return a usable upload URL.",
        ]);
    }

    protected function unreadableFile(Media $item): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::ERROR, [
            "File $item->name: the file could not be read for upload.",
        ]);
    }
}
