<?php

namespace Inovector\Mixpost\SocialProviders\LinkedIn\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Util;

trait ManagesResources
{
    public function getAccount(): SocialProviderResponse
    {
        return $this->isOrganization() ? $this->getOrganizationAccount() : $this->getMemberAccount();
    }

    /**
     * The member plus every organization they administer, as one list for the entity picker.
     *
     * Entity ids are stored as URNs (`urn:li:person:…` / `urn:li:organization:…`) because every
     * publish call names its author by URN, so keeping it means never rebuilding it later.
     */
    public function getEntities(): SocialProviderResponse
    {
        $memberResponse = $this->getMemberAccount();

        // Without a member there is no usable connection at all, so this one is fatal.
        if ($memberResponse->hasError()) {
            return $memberResponse;
        }

        $member = $memberResponse->context();

        return $this->response(SocialProviderResponseStatus::OK, array_merge(
            [$member],
            $this->fetchOrganizations()
        ));
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        $content = $this->buildPostContent($media);

        // Media is uploaded before the post is created, so a failed upload aborts here and no
        // half-finished post reaches the feed.
        if ($content instanceof SocialProviderResponse) {
            return $content;
        }

        $payload = [
            'author' => $this->authorUrn(),
            'commentary' => $this->escapeCommentary($text),
            'visibility' => $this->visibility($params),
            'distribution' => ['feedDistribution' => 'MAIN_FEED'],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        // A text-only post must omit `content` entirely rather than send an empty object.
        if ($content !== null) {
            $payload['content'] = $content;
        }

        $response = $this->restRequest()->post("$this->apiUrl/rest/posts", $payload);

        // The new post's URN is returned in the `x-restli-id` header — the 201 body is empty, so
        // reading the id from the body would silently store nothing and break the post's link.
        return $this->buildResponse($response, fn () => [
            'id' => $response->header('x-restli-id'),
        ]);
    }

    public function deletePost($id): SocialProviderResponse
    {
        // Only a genuine post URN may be interpolated into the request URL. Anything else — most
        // likely a post published before the id was recorded — has nothing to delete on LinkedIn.
        if (! static::isPostUrn($id)) {
            return $this->response(SocialProviderResponseStatus::OK, []);
        }

        $response = $this->restRequest()
            ->withHeaders(['X-RestLi-Method' => 'DELETE'])
            ->delete("$this->apiUrl/rest/posts/".rawurlencode($id));

        // A successful delete answers 204, which buildResponse() does not count as OK, and a 404
        // means the post is already gone. Both leave the post absent, which is the point.
        if (in_array($response->status(), [200, 204, 404], true)) {
            return $this->response(SocialProviderResponseStatus::OK, []);
        }

        return $this->buildResponse($response);
    }

    /**
     * The `content` block for the post, or null for a text-only post — LinkedIn accepts those, so
     * an empty media collection is a normal outcome rather than a rejection.
     *
     * Returns a SocialProviderResponse instead when the media cannot be posted or uploaded.
     */
    protected function buildPostContent(Collection $media): array|SocialProviderResponse|null
    {
        if ($media->isEmpty()) {
            return null;
        }

        if ($video = $media->first(fn (Media $item) => $item->isVideo())) {
            // `content.media` holds exactly one asset, so a video cannot travel with anything else.
            // The composer already blocks this via `allow_mixing`, but publishing is also reachable
            // from a version edited before that setting changed.
            if ($media->count() > 1) {
                return $this->response(SocialProviderResponseStatus::ERROR, [
                    'A LinkedIn post can contain one video and nothing else. Remove the other files from this version.',
                ]);
            }

            $upload = $this->uploadVideo($video);

            return $upload->hasError() ? $upload : ['media' => ['id' => $upload->id()]];
        }

        $maxPhotos = (int) Util::config('social_provider_options.linkedin.media_limit.photos');

        if ($media->count() > $maxPhotos) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                "A LinkedIn post accepts at most $maxPhotos images.",
            ]);
        }

        $urns = $this->uploadImages($media);

        if ($urns instanceof SocialProviderResponse) {
            return $urns;
        }

        // A lone image goes in `media`; `multiImage` is a distinct post type that needs two or more.
        if (count($urns) === 1) {
            return ['media' => ['id' => $urns[0]]];
        }

        return ['multiImage' => ['images' => array_map(fn ($urn) => ['id' => $urn], $urns)]];
    }

    /**
     * Organizations can only post publicly — LinkedIn rejects `CONNECTIONS` for an organization
     * author — so the choice is coerced here. postOptions() says as much in its label, because
     * options are declared once per provider and cannot vary by account.
     *
     * The value is matched against the two known settings rather than passed through, so nothing a
     * user can type reaches LinkedIn as an enum.
     */
    protected function visibility(array $params): string
    {
        if ($this->isOrganization()) {
            return 'PUBLIC';
        }

        return Arr::get($params, 'visibility') === 'CONNECTIONS' ? 'CONNECTIONS' : 'PUBLIC';
    }

    /**
     * `commentary` is not plain text — it is LinkedIn's "little" format, where `( ) [ ] @ #` and
     * friends are markup. An unescaped one is not merely rendered oddly: LinkedIn drops everything
     * from that character onward, so a post containing "(see below)" publishes truncated with no
     * error at all. Every reserved character must be escaped even when it is not part of an element.
     *
     * `#` is deliberately left alone when it opens a word so hashtags still resolve, which is what
     * someone typing "#hiring" into the composer means. Every other `#` is escaped as literal text.
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/little-text-format
     */
    protected function escapeCommentary(string $text): string
    {
        // One pass, so the backslashes added here are never re-escaped by a later pass.
        return preg_replace('/[|{}@\[\]()<>*_~\\\\]|#(?![\p{L}\p{N}_])/u', '\\\\$0', $text);
    }

    protected function getMemberAccount(): SocialProviderResponse

    protected function getMemberAccount(): SocialProviderResponse
    {
        $response = $this->userinfoRequest();

        return $this->buildResponse($response, fn () => $this->memberEntity($response->json()));
    }

    protected function getOrganizationAccount(): SocialProviderResponse
    {
        $id = $this->entityId();

        $response = Http::withToken($this->getAccessToken()['access_token'])
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->get("$this->apiUrl/v2/organizations/$id", [
                'projection' => '(id,localizedName,vanityName,logoV2(original~:playableStreams))',
            ]);

        return $this->buildResponse($response, fn () => $this->organizationEntity($response->json()));
    }

    /**
     * The `organization~` projection expands each ACL's organization URN into the real object.
     * Without it the response is a list of bare URNs with no names or logos. `original~` does the
     * same for the logo asset, turning it into a downloadable image URL.
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control
     */
    protected function fetchOrganizations(): array
    {
        $response = Http::withToken($this->getAccessToken()['access_token'])
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->get("$this->apiUrl/v2/organizationAcls", [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'projection' => '(elements*(organization~(id,localizedName,vanityName,logoV2(original~:playableStreams))))',
            ]);

        // An app without the Community Management API product gets a 403 here. That is a normal
        // setup, not a broken one — the member can still be connected on their own.
        if ($response->failed()) {
            return [];
        }

        return collect($response->json('elements', []))
            ->pluck('organization~')
            ->filter()
            ->map(fn ($organization) => $this->organizationEntity($organization))
            ->values()
            ->toArray();
    }

    protected function userinfoRequest()
    {
        return Http::withToken($this->getAccessToken()['access_token'])
            ->get("$this->apiUrl/v2/userinfo");
    }

    protected function memberEntity(array $data): array
    {
        return [
            'id' => 'urn:li:person:'.$data['sub'],
            'name' => $data['name'] ?? '',
            'username' => '', // LinkedIn's OpenID profile has no handle.
            'image' => $data['picture'] ?? null,
            'data' => [
                // Publishing authors a post differently for a member than for a page, so the kind
                // has to survive on the account record.
                'type' => 'person',
            ],
        ];
    }

    protected function organizationEntity(array $data): array
    {
        return [
            'id' => 'urn:li:organization:'.$data['id'],
            'name' => $data['localizedName'] ?? '',
            'username' => $data['vanityName'] ?? '',
            'image' => Arr::get($data, 'logoV2.original~.elements.0.identifiers.0.identifier'),
            'data' => [
                'type' => 'organization',
            ],
        ];
    }
}
