<?php

namespace OneMediaLabs\MixpostMcp\Mcp;

use OneMediaLabs\MixpostMcp\Mcp\Tools\AddMediaFromUrl;
use OneMediaLabs\MixpostMcp\Mcp\Tools\CreatePost;
use OneMediaLabs\MixpostMcp\Mcp\Tools\GetAccountMetrics;
use OneMediaLabs\MixpostMcp\Mcp\Tools\GetAudienceGrowth;
use OneMediaLabs\MixpostMcp\Mcp\Tools\GetPost;
use OneMediaLabs\MixpostMcp\Mcp\Tools\ListAccounts;
use OneMediaLabs\MixpostMcp\Mcp\Tools\ListPosts;
use OneMediaLabs\MixpostMcp\Mcp\Tools\ListTags;
use OneMediaLabs\MixpostMcp\Mcp\Tools\SchedulePost;
use OneMediaLabs\MixpostMcp\Mcp\Tools\UpdatePost;
use Laravel\Mcp\Server;

class MixpostServer extends Server
{
    protected string $name = 'MixpostMCP';

    protected string $version = '2.23.2';

    /**
     * The house rules, stated up front so an agent does not have to discover them by failing.
     */
    protected string $instructions = <<<'MARKDOWN'
        MixpostMCP is a self-hosted social media manager. These tools let you read the connected
        accounts and their results, draft posts, and put them on the schedule.

        Rules of the road:

        - Call `list_accounts` before drafting. Each account carries its own character limit and
          media limits, and a body that is fine for LinkedIn will be rejected by X.
        - `create_post` always produces a draft. Nothing is sent to a social network by it.
        - `schedule_post` is the only way to queue a post, and it only accepts times far enough in
          the future for a human to review them first. There is no way to publish immediately —
          that is deliberate, so do not look for one or try to work around it.
        - Media cannot be uploaded directly. Use `add_media_from_url` with a public URL, then pass
          the returned id in `media_ids`.
        - All dates and times are in the user's local timezone as configured in MixpostMCP, not UTC.
        MARKDOWN;

    protected function boot(): void
    {
        $this->tools[] = ListAccounts::class;
        $this->tools[] = ListTags::class;
        $this->tools[] = ListPosts::class;
        $this->tools[] = GetPost::class;
        $this->tools[] = GetAccountMetrics::class;
        $this->tools[] = GetAudienceGrowth::class;
        $this->tools[] = AddMediaFromUrl::class;
        $this->tools[] = CreatePost::class;
        $this->tools[] = UpdatePost::class;
        $this->tools[] = SchedulePost::class;
    }
}
