<?php

namespace Inovector\Mixpost\SocialProviders\Twitter\Concerns;

use Abraham\TwitterOAuth\Consumer;
use Abraham\TwitterOAuth\HmacSha1;
use Abraham\TwitterOAuth\Request as OAuthRequest;
use Abraham\TwitterOAuth\Token;
use Exception;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Exceptions\TwitterMediaUploadRateLimit;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesResources
{
    /**
     * X's v2 media upload endpoint.
     *
     * X documents two contradictory shapes for this flow. The chunked-upload quickstart posts
     * `command=INIT|APPEND|FINALIZE` as form fields to this one URL; the API reference splits the
     * same flow across `/2/media/upload/initialize`, `/append` and `/{id}/finalize`. We use the
     * quickstart form.
     *
     * Chosen from the docs on 29 August 2026, NOT confirmed against a live call — this checkout has
     * no X credentials. If uploads start coming back 404 or 405, try the REST paths instead.
     *
     * @see https://docs.x.com/x-api/media/quickstart/media-upload-chunked
     */
    protected const MEDIA_UPLOAD_URL = 'https://api.x.com/2/media/upload';

    /**
     * X caps an APPEND chunk at 5 MB. 4 MiB stays under that on either reading of "MB".
     */
    protected const MEDIA_CHUNK_BYTES = 4194304;

    public function getAccount(): SocialProviderResponse
    {
        $response = $this->connection->get('users/me', ['user.fields' => 'profile_image_url,created_at']);

        return $this->buildResponse($response, function () use ($response) {
            return [
                'id' => $response->data->id,
                'name' => $response->data->name,
                'username' => $response->data->username,
                'image' => str_replace('normal', '400x400', $response->data->profile_image_url),
            ];
        });
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        try {
            $mediaResult = $this->uploadMedia($media);
        } catch (TwitterMediaUploadRateLimit $exception) {
            // Release the job rather than failing the post — the media is still uploadable later.
            return $this->response(
                SocialProviderResponseStatus::EXCEEDED_RATE_LIMIT,
                $this->rateLimitExceedContext($exception->retryAfter),
                true,
                $exception->retryAfter
            );
        } catch (Exception $exception) {
            return $this->response(SocialProviderResponseStatus::ERROR, [$exception->getMessage()]);
        }

        if (! empty($mediaResult['errors'])) {
            return $this->response(SocialProviderResponseStatus::ERROR, $mediaResult['errors']);
        }

        return match ($this->getTier()) {
            'legacy' => $this->storePostWithApiV1($text, $mediaResult),
            default => $this->storePostWithApiV2($text, $mediaResult),
        };
    }

    protected function storePostWithApiV1(string $text, array $mediaResult): SocialProviderResponse
    {
        $this->connection->setApiVersion('1.1');

        $postParameters = ['status' => $text];

        if (! empty($mediaResult['ids'])) {
            $postParameters['media_ids'] = implode(',', $mediaResult['ids']);
        }

        $postResult = $this->connection->post('statuses/update', $postParameters);

        $httpCode = $this->connection->getLastHttpCode();

        if ($httpCode !== 201) {
            $error = $postResult->errors[0]->message ?? (json_encode($postResult) ?: 'An error occurred while creating the post.');

            return $this->response(SocialProviderResponseStatus::ERROR, [$error]);
        }

        return $this->buildResponse($postResult, function () use ($postResult) {
            return [
                'id' => $postResult->id,
            ];
        });
    }

    protected function storePostWithApiV2(string $text, array $mediaResult): SocialProviderResponse
    {
        $this->connection->setApiVersion(2);

        $postParameters = ['text' => $text];

        if (! empty($mediaResult['ids'])) {
            $postParameters['media']['media_ids'] = $mediaResult['ids'];
        }

        $postResult = $this->connection->post('tweets', $postParameters, ['jsonPayload' => true]);

        if ($this->connection->getLastHttpCode() !== 201) {
            $title = $postResult->title ?? '';
            $detail = $postResult->detail ?? '';

            $error = (trim("$title: $detail") ?: 'An error occurred while creating the post.');

            return $this->response(SocialProviderResponseStatus::ERROR, [$error]);
        }

        return $this->buildResponse($postResult, function () use ($postResult) {
            return [
                'id' => $postResult->data->id,
            ];
        });
    }

    /**
     * Upload media to X.
     *
     * Everything goes through the v2 chunked flow — INIT, APPEND per chunk, FINALIZE, then STATUS
     * while X is still processing. X retired the v1.1 upload host in June 2025, and photos take the
     * same path as video because v2 has no single-shot alternative.
     *
     * The `inovector/twitteroauth` SDK cannot reach v2 uploads: `upload()` and `mediaStatus()`
     * hardcode `upload.twitter.com/1.1`, its host constants are private, and its request builder
     * only emits urlencoded or JSON bodies while APPEND needs a multipart file part. So the
     * transport here is Laravel's HTTP client — but the OAuth 1.0a signing is still the SDK's, see
     * mediaUploadAuthorization().
     */
    public function uploadMedia(Collection $media): array
    {
        $ids = [];
        $errors = [];

        foreach ($media as $item) {
            $temporaryDirectory = null;

            try {
                if ($item->isLocalAdapter()) {
                    $path = $item->getFullPath();
                } else {
                    ['fullPath' => $path, 'temporaryDirectory' => $temporaryDirectory] = $item->downloadToTemp();
                }

                $ids[] = $this->uploadMediaItem($item, $path);
            } catch (TwitterMediaUploadRateLimit $exception) {
                // Not an error on this item — abandon the whole post so the job can be retried.
                throw $exception;
            } catch (Exception $exception) {
                $errors[] = $exception->getMessage();
            } finally {
                $temporaryDirectory?->delete();
            }
        }

        return [
            'ids' => $ids,
            'errors' => $errors,
        ];
    }

    protected function uploadMediaItem(Media $item, string $path): string
    {
        $init = $this->mediaUploadCommand([
            'command' => 'INIT',
            'media_type' => $item->mime_type,
            'total_bytes' => filesize($path),
            'media_category' => $this->mediaCategory($item),
        ]);

        if (! $mediaId = data_get($init, 'data.id')) {
            throw new Exception("X did not return a media id for `{$item->name}`.");
        }

        if (! $handle = fopen($path, 'rb')) {
            throw new Exception("Could not read `{$item->name}` to upload it.");
        }

        try {
            for ($segmentIndex = 0; ! feof($handle); $segmentIndex++) {
                $chunk = fread($handle, self::MEDIA_CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $this->mediaUploadCommand([
                    'command' => 'APPEND',
                    'media_id' => $mediaId,
                    'segment_index' => $segmentIndex,
                ], $chunk);
            }
        } finally {
            fclose($handle);
        }

        $finalize = $this->mediaUploadCommand([
            'command' => 'FINALIZE',
            'media_id' => $mediaId,
        ]);

        $this->awaitMediaProcessing($item, $mediaId, data_get($finalize, 'data.processing_info'));

        return $mediaId;
    }

    /**
     * Wait for X to finish transcoding, if it says it is still working.
     *
     * FINALIZE may already be terminal — for a photo it carries no `processing_info` at all — so the
     * state is checked before the first sleep rather than after.
     */
    protected function awaitMediaProcessing(Media $item, string $mediaId, mixed $processingInfo): void
    {
        $state = data_get($processingInfo, 'state');
        $sleepSeconds = (int) data_get($processingInfo, 'check_after_secs', 1);

        while (in_array($state, ['pending', 'in_progress'])) {
            sleep($sleepSeconds);

            $status = $this->mediaUploadStatus($mediaId);

            $state = data_get($status, 'data.processing_info.state', 'failed');
            $sleepSeconds = (int) data_get($status, 'data.processing_info.check_after_secs', 1);
        }

        if ($state === 'failed') {
            throw new Exception("Failed to upload `{$item->name}` file.");
        }
    }

    /**
     * Send one INIT / APPEND / FINALIZE command. Passing $chunk attaches it as the `media` file part.
     */
    protected function mediaUploadCommand(array $fields, ?string $chunk = null): ?object
    {
        $request = Http::withHeaders([
            'Authorization' => $this->mediaUploadAuthorization('POST', self::MEDIA_UPLOAD_URL),
        ])->asMultipart();

        if ($chunk !== null) {
            $request = $request->attach('media', $chunk, 'chunk');
        }

        // Guzzle's multipart writer rejects non-string field values, and total_bytes is an int.
        return $this->mediaUploadResult($request->post(self::MEDIA_UPLOAD_URL, array_map('strval', $fields)));
    }

    protected function mediaUploadStatus(string $mediaId): ?object
    {
        $url = self::MEDIA_UPLOAD_URL.'?'.http_build_query(['command' => 'STATUS', 'media_id' => $mediaId]);

        return $this->mediaUploadResult(
            Http::withHeaders(['Authorization' => $this->mediaUploadAuthorization('GET', $url)])->get($url)
        );
    }

    /**
     * Decode an upload response, or turn its status code into the right exception.
     *
     * APPEND answers 2xx with an empty body, which decodes to null — callers ignore its return.
     */
    protected function mediaUploadResult(HttpResponse $response): ?object
    {
        if ($response->status() === 429) {
            throw new TwitterMediaUploadRateLimit($this->mediaUploadRetryAfter($response));
        }

        // Media upload is scoped separately from posting, so a token that publishes text happily
        // still 403s the first time it is handed a file. Name the scope, or this reads as a generic
        // auth failure and sends people to the wrong settings page.
        if ($response->status() === 403) {
            throw new Exception('X refused the media upload (403). Uploading needs the `media.write` scope, which an X app set to read-only when the account was connected never granted. Set the app to *Read and write* in the X Developer Portal, then reconnect the account.');
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->json('title') ?? $response->body();

            throw new Exception("X rejected the media upload ({$response->status()}): $detail");
        }

        return $response->object();
    }

    protected function mediaUploadRetryAfter(HttpResponse $response): int
    {
        // Same fallback as getRateLimitUsage(): without a reset header, wait a fixed five minutes
        // rather than block the queue on a value we cannot read.
        if (! $reset = $response->header('x-rate-limit-reset')) {
            return 300;
        }

        return (int) Carbon::now('UTC')->diffInSeconds(Carbon::createFromTimestamp((int) $reset, 'UTC'));
    }

    protected function mediaCategory(Media $item): string
    {
        return match (true) {
            $item->isVideo() => 'amplify_video',
            $item->isImageGif() => 'tweet_gif',
            default => 'tweet_image',
        };
    }

    /**
     * Build the OAuth 1.0a Authorization header for a media upload request.
     *
     * This is the twitteroauth SDK's own signer, driven directly — only the transport around it is
     * ours. Body fields are deliberately left out of the signature: OAuth 1.0a only signs a request
     * body when it is `application/x-www-form-urlencoded`, and these are multipart. Query string
     * parameters still count, and Request's constructor picks those out of the URL itself.
     */
    protected function mediaUploadAuthorization(string $method, string $url): string
    {
        $token = $this->getAccessToken();

        $consumer = new Consumer($this->clientId, $this->clientSecret);
        $accessToken = new Token($token['oauth_token'], $token['oauth_token_secret']);

        $request = OAuthRequest::fromConsumerAndToken($consumer, $accessToken, $method, $url, [], ['jsonPayload' => true]);
        $request->signRequest(new HmacSha1(), $consumer, $accessToken);

        return Str::after($request->toHeader(), 'Authorization: ');
    }

    public function getAccountMetrics(): SocialProviderResponse
    {
        $response = $this->connection->get('users/me', ['user.fields' => 'public_metrics']);

        return $this->buildResponse($response, function () use ($response) {
            return [
                'followers_count' => $response->data->public_metrics->followers_count,
                'following_count' => $response->data->public_metrics->following_count,
                'tweet_count' => $response->data->public_metrics->tweet_count,
                'listed_count' => $response->data->public_metrics->listed_count,
            ];
        });
    }

    public function getUserTweetTimeline(string $userId, string $paginationToken = ''): SocialProviderResponse
    {
        $params = [
            'tweet.fields' => 'public_metrics,created_at,in_reply_to_user_id',
            'start_time' => Carbon::now('UTC')->subMonths(3)->startOfDay()->toRfc3339String(),
            'exclude' => 'retweets,replies',
            'max_results' => 100,
        ];

        if ($paginationToken) {
            $params['pagination_token'] = $paginationToken;
        }

        $response = $this->connection->get("users/$userId/tweets", $params);

        return $this->buildResponse($response, function () use ($response) {
            return [
                'data' => $response->data ?? [],
                'meta' => $response->meta ?? null,
            ];
        });
    }

    public function deletePost($id): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }
}
