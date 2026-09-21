<?php

return [
    /*
    * This option controls the default authentication "guard" for the Mixpost routes
    */
    'auth_guard' => env('MIXPOSTMCP_AUTH_GUARD', 'web'),

    /*
    * If you use another model for users, you can change it here.
    */
    'user_model' => \OneMediaLabs\MixpostMcp\Models\User::class,

    /*
     * Mixpost will redirect unauthorized users to the route name specified here
     */
    'redirect_unauthorized_users_to_route' => 'login',

    /*
     * The disk on which to store added files.
     * Choose one or more of the disks you've configured in config/filesystems.php.
     */
    'disk' => env('MIXPOSTMCP_DISK', 'public'),

    /*
     * Indicate that the uploaded file should be no more than the given number of kilobytes.
     * Adding a larger file will result in an exception.
     */
    'max_file_size' => [
        'image' => 1024 * 5, // 5MB
        'gif' => 1024 * 15, // 15MB
        'video' => 1024 * 200, // 200MB
    ],

    /*
     * Accepted mime types for media library upload.
     * These are all supported mime types for the image and video files. We do not guarantee that it will work with other types.
     * If you need to remove certain mime types, you are free to do so from here.
     */
    'mime_types' => [
        'image/jpg',
        'image/jpeg',
        'image/gif',
        'image/png',
        'video/mp4',
        'video/x-m4v',
    ],

    /*
     * The path where to store temporary files while performing image conversions.
     * If set to null, storage_path('mixpostmcp-media/temp') will be used.
     */
    'temporary_directory_path' => null,

    /*
     * FFMPEG & FFProbe binaries paths, only used if you try to generate video thumbnails
     */
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),

    /*
     * When set, OAuth redirect URLs are `<base>/<provider>/` instead of this install's own callback
     * route. Used by the desktop app, whose local address is not reachable by the networks.
     */
    'oauth_callback_base' => env('MIXPOSTMCP_OAUTH_CALLBACK_BASE'),

    /*
     * Define cache prefix
     */
    'cache_prefix' => env('MIXPOSTMCP_CACHE_PREFIX', 'mixpostmcp'),

    /*
     * Define log channel
     * Captures connection errors with social networks or third parties used in Mixpost in a separate channel.
     * Leave blank if you want to use Laravel's default log channel
     */
    'log_channel' => env('MIXPOSTMCP_LOG_CHANNEL'),

    /*
     * The media component is integrated with third-party services Unsplash.com and Tenor.com
     * Defines the default terms for displaying media resources
     */
    'external_media_terms' => ['social', 'mix', 'content', 'popular', 'viral', 'trend', 'light', 'marketing', 'self-hosted', 'ambient', 'writer', 'technology'],

    /*
     * Options for each social network
     * We recommend leaving these options unchanged
     * You only change them when the API policy of the social networks changes, and you know what you are doing.
     */
    'social_provider_options' => [
        'twitter' => [
            'simultaneous_posting_on_multiple_accounts' => false,
            'post_character_limit' => 280,
            'media_limit' => [
                'photos' => 4,
                'videos' => 1,
                'gifs' => 1,
                'allow_mixing' => false,
            ],
        ],
        'facebook_page' => [
            'simultaneous_posting_on_multiple_accounts' => true,
            'post_character_limit' => 5000,
            'media_limit' => [
                'photos' => 10,
                'videos' => 1,
                'gifs' => 1,
                'allow_mixing' => false,
            ],
        ],
        'mastodon' => [
            'simultaneous_posting_on_multiple_accounts' => true,
            'post_character_limit' => 500,
            'media_limit' => [
                'photos' => 4,
                'videos' => 1,
                'gifs' => 1,
                'allow_mixing' => false,
            ],
        ],
        'instagram' => [
            'simultaneous_posting_on_multiple_accounts' => true,
            'post_character_limit' => 2200,
            'media_limit' => [
                'photos' => 10, // A carousel allows up to 10 items
                'videos' => 1,
                'gifs' => 0,
                'allow_mixing' => false,
            ],
        ],
        'linkedin' => [
            'simultaneous_posting_on_multiple_accounts' => true,
            'post_character_limit' => 3000,
            'media_limit' => [
                'photos' => 9,
                'videos' => 1,
                'gifs' => 0,
                'allow_mixing' => false,
            ],
        ],
        'tiktok' => [
            'simultaneous_posting_on_multiple_accounts' => true,
            'post_character_limit' => 2200,
            'media_limit' => [
                'photos' => 0, // Video only
                'videos' => 1,
                'gifs' => 0,
                'allow_mixing' => false,
            ],
        ],
        'youtube' => [
            'simultaneous_posting_on_multiple_accounts' => true,
            'post_character_limit' => 5000,
            'media_limit' => [
                'photos' => 0, // Video only
                'videos' => 1,
                'gifs' => 0,
                'allow_mixing' => false,
            ],
        ],
    ],

    /*
     * Model Context Protocol server. Gives AI agents a set of tools to read your accounts and
     * results, draft posts and schedule them. Runs over stdio: `php artisan mcp:start mixpost`.
     * Requires the optional laravel/mcp package; without it the server is not registered.
     */
    'mcp' => [
        /*
         * Agents cannot publish immediately. Anything an agent schedules has to sit at least this
         * many minutes in the future, so there is always a window to see it in the calendar and
         * cancel before it goes out.
         */
        'min_schedule_lead_minutes' => env('MIXPOSTMCP_SCHEDULE_LEAD', 10),

        /*
         * Hard ceiling on the number of rows any list tool returns, so a broad query cannot
         * flood the agent's context window.
         */
        'max_results' => 50,
    ],
];
