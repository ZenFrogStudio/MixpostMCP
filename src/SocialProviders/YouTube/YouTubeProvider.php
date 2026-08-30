<?php

namespace Inovector\Mixpost\SocialProviders\YouTube;

use Illuminate\Support\Arr;
use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\YouTubeService;
use Inovector\Mixpost\SocialProviders\YouTube\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\YouTube\Concerns\ManagesRateLimit;
use Inovector\Mixpost\SocialProviders\YouTube\Concerns\ManagesResources;
use Inovector\Mixpost\SocialProviders\YouTube\Concerns\ManagesYouTubeVideoUpload;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

/**
 * Connects a YouTube channel through Google OAuth 2.0 and publishes videos to it with the YouTube
 * Data API v3.
 *
 * The publish path is: derive a title and description from the post body, open a resumable upload
 * session, PUT the file to the session URL, then set a thumbnail if one was generated. Each step
 * lives in a concern: ManagesOAuth (connect and refresh), ManagesResources (channels, publish) and
 * ManagesYouTubeVideoUpload (session, transfer, thumbnail).
 *
 * This integration is video-only. YouTube has no text or photo post, and the `youtube` media limits
 * in config allow 0 photos to match.
 */
class YouTubeProvider extends SocialProvider
{
    use ManagesOAuth;
    use ManagesRateLimit;
    use ManagesResources;
    use ManagesYouTubeVideoUpload;

    const API_URL = 'https://www.googleapis.com/youtube/v3';

    // Uploads go to a different host to the rest of the API, and sending them to the API host
    // returns a 404 with nothing that hints at the cause.
    const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3';

    const TITLE_LIMIT = 100;

    const DESCRIPTION_LIMIT = 5000;

    /**
     * `state` travels with the code so requestAccessToken() can run the CSRF check. Without it a
     * crafted callback URL could attach a stranger's channel to this install.
     *
     * Both keys are needed because getCallbackResponse() is what feeds requestAccessToken(): with
     * `code` alone the state never reaches the check and every connection would be refused.
     */
    public array $callbackResponseKeys = ['code', 'state'];

    // A Google account can own several channels, including Brand Accounts, so the callback has to
    // go through the entity picker rather than straight to account creation.
    public bool $onlyUserAccount = false;

    public static function name(): string
    {
        return 'YouTube';
    }

    public static function service(): string
    {
        return YouTubeService::class;
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.youtube.simultaneous_posting_on_multiple_accounts'))
            ->minTextChar(0) // YouTube requires a video, not text. A video with no caption is still publishable.
            ->maxTextChar(Util::config('social_provider_options.youtube.post_character_limit'))
            ->minPhotos(1)
            ->minVideos(1)
            ->minGifs(1)
            ->maxPhotos(Util::config('social_provider_options.youtube.media_limit.photos'))
            ->maxVideos(Util::config('social_provider_options.youtube.media_limit.videos'))
            ->maxGifs(Util::config('social_provider_options.youtube.media_limit.gifs'))
            ->allowMixingMediaTypes(Util::config('social_provider_options.youtube.media_limit.allow_mixing'));
    }

    /**
     * `privacy_status` defaults to private on purpose. An unattended scheduler publishing to the
     * wrong channel publicly is not a recoverable mistake — the video is indexed, mailed to
     * subscribers and possibly downloaded before anyone notices. Making it public is one click.
     *
     * The category keys are integers because PHP casts numeric array keys to integers anyway, so
     * `default` has to be an integer to match one of them.
     */
    public static function postOptions(): array
    {
        return [
            [
                'key' => 'title',
                'label' => 'Video title (leave empty to use the first line of the post)',
                'type' => 'text',
                'default' => '',
            ],
            [
                'key' => 'privacy_status',
                'label' => 'Privacy',
                'type' => 'select',
                'choices' => [
                    'private' => 'Private',
                    'unlisted' => 'Unlisted',
                    'public' => 'Public',
                ],
                'default' => 'private',
            ],
            [
                'key' => 'category_id',
                'label' => 'Category',
                'type' => 'select',
                // YouTube's assignable categories. The list is regional, and a category a channel's
                // region does not assign comes back as `invalidCategoryId` at upload time.
                'choices' => [
                    1 => 'Film & Animation',
                    2 => 'Autos & Vehicles',
                    10 => 'Music',
                    15 => 'Pets & Animals',
                    17 => 'Sports',
                    19 => 'Travel & Events',
                    20 => 'Gaming',
                    22 => 'People & Blogs',
                    23 => 'Comedy',
                    24 => 'Entertainment',
                    25 => 'News & Politics',
                    26 => 'Howto & Style',
                    27 => 'Education',
                    28 => 'Science & Technology',
                    29 => 'Nonprofits & Activism',
                ],
                'default' => 22,
            ],
            [
                'key' => 'made_for_kids',
                'label' => 'This video is made for kids',
                'type' => 'checkbox',
                'default' => false,
            ],
        ];
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        $id = $accountResource->pivot->provider_post_id;

        // Only something shaped like a video id may be interpolated into the URL. YouTube ids have
        // been 11 URL-safe base64 characters for as long as the API has existed; the wider range
        // here tolerates a change without letting anything else through.
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            return '#';
        }

        return "https://www.youtube.com/watch?v=$id";
    }

    /**
     * YouTube wants a title and a description; the composer produces one block of text.
     *
     * The first line becomes the title and the rest becomes the description, unless the options
     * panel supplies a title — in which case the whole body is the description and nothing is
     * quietly eaten from the post.
     *
     * `<` and `>` are stripped from both because YouTube rejects them outright rather than escaping
     * them, and it does so only after the file has been uploaded.
     *
     * Returns `[$title, $description]`. The title can come back empty when the post has no text at
     * all; publishPost() substitutes the file name, because YouTube refuses an untitled video.
     */
    public static function deriveTitleAndDescription(string $text, array $params = []): array
    {
        $body = trim(self::stripAngleBrackets($text));
        $explicitTitle = trim(self::stripAngleBrackets((string) Arr::get($params, 'title', '')));

        if ($explicitTitle !== '') {
            return [
                self::truncateOnWordBoundary($explicitTitle, self::TITLE_LIMIT),
                mb_substr($body, 0, self::DESCRIPTION_LIMIT),
            ];
        }

        $lines = preg_split("/\r\n|\n|\r/", $body, 2);

        return [
            self::truncateOnWordBoundary(trim($lines[0] ?? ''), self::TITLE_LIMIT),
            mb_substr(trim($lines[1] ?? ''), 0, self::DESCRIPTION_LIMIT),
        ];
    }

    protected static function stripAngleBrackets(string $text): string
    {
        return str_replace(['<', '>'], '', $text);
    }

    /**
     * Cut on a space rather than mid-word, so a truncated title reads as a shortened sentence
     * instead of a broken one.
     */
    protected static function truncateOnWordBoundary(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        // A single word longer than the limit has no boundary to cut on, so it is cut mid-word
        // rather than returned empty.
        return rtrim($lastSpace > 0 ? mb_substr($cut, 0, $lastSpace) : $cut);
    }
}
