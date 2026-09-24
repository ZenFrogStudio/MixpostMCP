# MixpostMCP

**Self-hosted social media management.** Write once, tailor per network, schedule it, and watch how
it performed — all from a calendar you own, on a server you control.

MixpostMCP is a fork of [Mixpost Lite](https://github.com/inovector/mixpost), extended with
first-class support for Instagram, LinkedIn and TikTok alongside the networks it already handled.

## What it does

**One place for every account.** Connect Facebook Pages, Instagram, X, LinkedIn, TikTok and
Mastodon, and manage them side by side instead of tab by tab.

**Per-network post versions.** One idea, written differently for each network — different copy,
different media, different options — published from a single post.

**A calendar you can plan in.** Month and week views, drag to reschedule, and a queue that builds a
natural posting rhythm rather than dumping everything at once.

**A media library that remembers.** Reuse images, GIFs and video you have already uploaded, or pull
from Unsplash and Tenor without leaving the composer.

**Analytics per platform.** Audience growth and post performance for each network, as far as each
network's API is willing to share it.

**Templates, hashtag groups and dynamic variables.** The parts of a post you write over and over,
written once.

## Installation

There are two ways to run MixpostMCP: as a desktop app on your own computer, or on a server.

### Desktop app

Download the installer for Windows (`.exe`) or macOS (`.dmg`) from the
[Releases](https://github.com/ZenFrogStudio/MixpostMCP/releases) page and run it. It needs nothing
else — no Docker, no database server, no PHP. The app bundles its own PHP and keeps everything in
one SQLite file in your app-data folder. There is no login screen: the app opens straight onto the
dashboard, and the queue worker and scheduler run inside it, so scheduled posts go out while it is
open.

Two things stay server-only:

- **Instagram publishing.** Instagram fetches media from a public URL, and the desktop app serves
  media only to itself.
- **Publishing while the computer is off.** Posts are sent by the worker inside the app, so nothing
  goes out while it is closed. Anything that came due while it was closed is published shortly after
  you open it again.

To build the app from source, see [desktop/README.md](desktop/README.md).

### On a server

MixpostMCP installs the same way as upstream Mixpost Lite, so the
[Mixpost Lite documentation](https://docs.mixpost.app/lite/) covers getting a server up and running.
The package name, config file, routes and database tables are unchanged from upstream, so you can
follow it step for step.

Once it is running, the sections below cover what is specific to this fork — connecting each social
network, and keeping those connections alive.

## Connecting social networks

Every network needs an app of your own in that network's developer portal. You put the app's
credentials on MixpostMCP's **Services** page, and you register MixpostMCP's callback URL on the app.

The callback URL depends on how you run MixpostMCP. On a server it is your own domain:

```
https://<your-domain>/mixpostmcp/callback/<provider>
```

The **desktop app** has no address the networks can reach, so it uses a fixed relay page instead —
note the trailing slash, it is part of the URL:

```
https://zenfrogstudio.github.io/MixpostMCP/callback/<provider>/
```

| Network | Provider key | Callback URL — server | Callback URL — desktop app | Developer portal |
| --- | --- | --- | --- | --- |
| Facebook Page | `facebook_page` | `https://<your-domain>/mixpostmcp/callback/facebook_page` | `https://zenfrogstudio.github.io/MixpostMCP/callback/facebook_page/` | [Meta for Developers](https://developers.facebook.com/apps) |
| Instagram | `instagram` | `https://<your-domain>/mixpostmcp/callback/instagram` | `https://zenfrogstudio.github.io/MixpostMCP/callback/instagram/` | Same Meta app as Facebook |
| X (Twitter) | `twitter` | `https://<your-domain>/mixpostmcp/callback/twitter` | `https://zenfrogstudio.github.io/MixpostMCP/callback/twitter/` | [X Developer Portal](https://developer.x.com/en/portal/dashboard) |
| LinkedIn | `linkedin` | `https://<your-domain>/mixpostmcp/callback/linkedin` | `https://zenfrogstudio.github.io/MixpostMCP/callback/linkedin/` | [LinkedIn Developers](https://www.linkedin.com/developers/apps) |
| TikTok | `tiktok` | `https://<your-domain>/mixpostmcp/callback/tiktok` | `https://zenfrogstudio.github.io/MixpostMCP/callback/tiktok/` | [TikTok for Developers](https://developers.tiktok.com/) |
| YouTube | `youtube` | `https://<your-domain>/mixpostmcp/callback/youtube` | `https://zenfrogstudio.github.io/MixpostMCP/callback/youtube/` | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) |

Register **both** Facebook Page and Instagram callback URLs on the same Meta app — they are two
different URLs even though they share one app.

Every network in this table can be connected and posted to. The dashboard's follower counts and
reports currently cover X, Facebook Pages and Mastodon only; reporting for Instagram, LinkedIn,
TikTok and YouTube is scheduled work landing across 2.23.2 to 2.24.0.

`<your-domain>` must be the domain in your `APP_URL`, over HTTPS, and must match character for
character what you register — every one of these portals rejects a callback that differs by so much
as a trailing slash.

### From the desktop app

Clicking **Connect** in the desktop app opens the network's sign-in page in your normal web browser
(Google refuses to sign in inside embedded browsers, and your existing logins are there anyway). When
you approve, the network sends your browser to the relay page above, which hands the result straight
to the app and tells you the tab can be closed. **The app must be open when you come back** — it is,
since you just clicked Connect in it. The relay page holds no credentials and stores nothing; it only
forwards the network's reply.

Everything else in this section still applies on desktop: Meta apps in Development mode, TikTok's
content posting audit, the YouTube consent screen, and so on.

If you host MixpostMCP somewhere the networks cannot reach and want your own fixed callback address,
set `MIXPOSTMCP_OAUTH_CALLBACK_BASE` to the base URL of a copy of the `relay/callback` pages; redirects
then go to `<base>/<provider>/`.

### Facebook Pages

Create an app at [Meta for Developers](https://developers.facebook.com/apps), choose the
**Business** app type, and add the **Facebook Login** product. Put the App ID and App Secret on
MixpostMCP's **Services** page under Facebook, and add the callback URL to Facebook Login's **Valid
OAuth Redirect URIs**.

Permissions to request: `business_management`, `pages_show_list`, `pages_manage_posts`,
`pages_read_engagement`, `pages_manage_engagement` and `read_insights`.

You can post to your own Pages while the app is in **Development** mode. Publishing on behalf of
anyone else needs the app reviewed and switched to **Live**.

### Instagram

Instagram is connected through the same Facebook app used for Pages — **there is no separate
Instagram service on the Services page.** In your Meta app, add the `instagram_basic`,
`instagram_content_publish`, `instagram_manage_insights` and `instagram_manage_comments`
permissions, register the `instagram` callback URL alongside the `facebook_page` one, then use
**Connect Instagram** on the Accounts page.

**Only Instagram Business or Creator accounts that are linked to a Facebook Page will appear in the
account picker.** Personal Instagram accounts are not supported by the Graph API, and a Business
account with no Page linked to it is invisible to MixpostMCP — it will not show up, and there is no
error message explaining why. Link the Page in the Instagram app under *Settings → Account type and
tools* before you try to connect.

**Your MixpostMCP installation must be reachable from the public internet.** Meta downloads your
images and videos from a URL your server hands it, so publishing will fail if MixpostMCP runs on
`localhost`, on a private network, or behind an access gateway such as Cloudflare Access. Your
media disk must also serve files publicly.

### X (Twitter)

Create a Project and an App in the [X Developer Portal](https://developer.x.com/en/portal/dashboard),
then put the **API Key** and **API Secret** on MixpostMCP's **Services** page. Under the app's **User
authentication settings**, turn on OAuth 1.0a, set **App permissions** to *Read and write*, set the
**Type of App** to *Web App*, and add the callback URL.

Also set the **Tier** on the Services page to match your app's actual access level in the portal.
It is not cosmetic — the tier decides whether MixpostMCP publishes through API v1.1 or v2, and it is
what tells you how many posts you may make.

**Free-tier write caps are low enough to matter.** A free app is limited to roughly 1,500 posts a
month; Basic to roughly 50,000 app-wide. Once you hit the cap X rejects every publish for the rest
of the billing cycle, and from MixpostMCP that looks like posts failing for no reason. Check your
usage in the portal dashboard before assuming something is broken.

**Media uploads use the v2 endpoint.** X sunset the v1.1 upload host on 9 June 2025, so photos, GIFs
and video all go through `POST https://api.x.com/2/media/upload` as an INIT / APPEND / FINALIZE /
STATUS sequence.

X documents two contradictory shapes for that flow, so to save the next person re-deriving it:
MixpostMCP uses the **single-URL form**, sending `command=INIT|APPEND|FINALIZE` as multipart form
fields to `/2/media/upload`, per the [chunked upload
quickstart](https://docs.x.com/x-api/media/quickstart/media-upload-chunked). The alternative — the
REST paths `/2/media/upload/initialize`, `/append` and `/{id}/finalize` in the API reference — is
not used. Chosen from the docs on **29 August 2026**; it has *not* yet been confirmed against a live
upload with real credentials. If uploads start failing with 404 or 405, the REST paths are the first
thing to try.

Two things to know if uploads fail:

- **Uploading is scoped separately from posting.** A token that publishes text fine can still get a
  403 the first time it is handed a file — that is the `media.write` scope missing. Set the X app to
  *Read and write* in the Developer Portal, then reconnect the account so a new token is issued.
- **Uploads eat your posting allowance.** On the free tier the INIT and FINALIZE steps share the
  same 17-per-24-hours budget as `POST /2/tweets`, so a single video costs three requests. When X
  rate limits an upload, MixpostMCP releases the job and retries later rather than failing the post.

### LinkedIn

Create an app at [LinkedIn Developers](https://www.linkedin.com/developers/apps), then put its
Client ID and Client Secret on MixpostMCP's **Services** page. Add your callback URL
(`https://your-mixpostmcp-url/mixpostmcp/callback/linkedin`) to the app's **Authorized redirect URLs**.

Your app needs these products:

| Product | Gives you | Required? |
| --- | --- | --- |
| Sign In with LinkedIn using OpenID Connect | `openid`, `profile`, `email` | Yes |
| Share on LinkedIn | `w_member_social` — posting as yourself | Yes |
| Community Management API | `r_organization_admin`, `r_organization_social`, `w_organization_social` — listing, posting as and reporting on company pages | Only for company pages |

**The Community Management API has to be approved before you connect.** MixpostMCP always asks for the
organization scopes, and LinkedIn refuses the whole authorization if the app is not approved for
them — it does not quietly drop them. If approval is still pending, either finish it first or
remove the three `*_organization_*` entries from `$scopes` in
`src/SocialProviders/LinkedIn/Concerns/ManagesOAuth.php`; you will then be able to connect your own
profile but no company pages.

Company pages connected before 2.23.3 keep posting, but need to be reconnected once before their
follower counts and post statistics start showing on the dashboard. Personal profiles never report
any — LinkedIn offers no statistics for them.

Access tokens last about 60 days. Refresh tokens are only issued to apps LinkedIn has approved for
them, so most self-hosted installs will need to reconnect the account when the token expires.

### TikTok

Create an app at [TikTok for Developers](https://developers.tiktok.com/), then put its **Client Key**
and **Client Secret** on MixpostMCP's **Services** page. Add your callback URL
(`https://your-mixpostmcp-url/mixpostmcp/callback/tiktok`) to the app's redirect URIs.

Your app needs the **Login Kit** and **Content Posting API** products, with the `user.info.basic`,
`video.publish` and `video.upload` scopes.

**Until your app passes TikTok's content posting audit, every video you publish is private.**
Unaudited apps may only post with `SELF_ONLY` viewership, are limited to 5 creators in any 24 hour
window, and require those creators' accounts to be set to private. Nothing fails — the upload
succeeds, TikTok reports the post as published, and nobody but the creator can see it. Posts made
while unaudited stay private permanently, so run the audit before you rely on this. MixpostMCP warns
you in the post composer when TikTok offers a creator no audience other than "Only me".

TikTok posts here are **video only**; one video per post, up to 4 GB. Photo posts are a separate
TikTok product and text-only posts are rejected before any API call is made. TikTok also requires
the audience to be chosen explicitly, so pick a privacy level under **Post options** — a TikTok post
will not publish without one.

Access tokens last 24 hours and refresh tokens last 365 days, so this integration depends on token
refresh being scheduled; an account will otherwise stop posting the day after you connect it.

### YouTube

Create a project in the [Google Cloud Console](https://console.cloud.google.com/apis/credentials),
enable the **YouTube Data API v3**, and create an **OAuth client ID** of type *Web application*.
Put the client ID and client secret on MixpostMCP's **Services** page and add the callback URL to the
client's **Authorised redirect URIs**. Scopes: `youtube.upload` and `youtube.readonly`.

A Google account can own several channels, including Brand Accounts, so connecting one asks you to
pick which channel to add. A Google account that has never created a channel has nothing to pick and
is turned away with "The account has no entities."

YouTube posts here are **video only**; one video per post. Text-only and image-only posts are
rejected before any API call is made. The post body becomes the video's title and description: the
first line is the title, the rest is the description, unless you set a title yourself under **Post
options** — in which case the whole body becomes the description.

**Privacy defaults to Private.** A scheduled post going out publicly to the wrong channel is not
something you can take back, so nothing publishes publicly unless you choose it under **Post
options**. If the video has a generated thumbnail it is uploaded afterwards; custom thumbnails need
a verified YouTube account, and a failure there is logged rather than failing the post, because by
then the video is already live.

**Uploading costs about 1,600 quota units, against a default quota of 10,000 units per day** — so
roughly six videos a day before uploads start failing. Beyond that you need a quota increase from
Google, which is a review process, not a form. MixpostMCP reports a breach as a rate limit and
schedules the retry for the next midnight Pacific time, which is when Google restores the allowance.

While the OAuth consent screen is in **Testing**, refresh tokens expire after 7 days and every
connected channel has to be added as a test user. Publishing the consent screen requires
verification if you use the `youtube.upload` scope.

## Keeping accounts connected

**In the desktop app, everything in this section up to the token table is built in** — the
scheduler and the queue worker run inside the app whenever it is open. The rest is for server
installs.

**The Laravel scheduler must actually be running.** MixpostMCP's scheduled work — publishing queued
posts, importing metrics, and refreshing access tokens — all runs from it. Add this to your
server's crontab:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Without it nothing publishes at its scheduled time, and connected accounts quietly stop working as
their tokens expire.

**And your application has to register the schedule.** MixpostMCP ships its scheduled work as one
registrar rather than adding tasks on its own, so `routes/console.php` needs this:

```php
use Illuminate\Console\Scheduling\Schedule;
use OneMediaLabs\MixpostMcp\Schedule as MixpostMcpSchedule;

MixpostMcpSchedule::register(app(Schedule::class));
```

Miss this and everything looks healthy — the scheduler runs, the queue runs, `php artisan
schedule:list` is simply empty — while every post you schedule sits in the calendar and never goes
out.

**And Horizon has to work the `publish-post` queue.** Every publish is batched onto that queue, not
the default one. Horizon's published `config/horizon.php` only lists `default`, so add it, and give
the supervisor enough time for a video upload:

```php
'supervisor-1' => [
    'queue' => ['default', 'publish-post'],
    'timeout' => 900,
    // ...
],
```

Then restart Horizon. The **System Status** page has a *Publish queue* row that turns red when no
supervisor lists it, because this is the second way a stack can look entirely healthy and never
send a post.

Three networks issue short-lived access tokens and depend on scheduled refresh:

| Network | Access token life | What happens without refresh |
| --- | --- | --- |
| YouTube | about 1 hour | Breaks the same day you connect it |
| TikTok | 24 hours | Breaks overnight |
| LinkedIn | about 60 days | Breaks silently two months later |
| Facebook / Instagram | about 60 days | Exchanged for a long-lived token when you connect, so no refresh needed |
| Mastodon | does not expire | Nothing |

The sweep is `mixpostmcp:refresh-access-tokens`, registered in `src/Schedule.php` to run every thirty
minutes. It renews any token due to expire within the next ten minutes and marks an account
unauthorized if its refresh token has died, which lights up the Unauthorized badge on the accounts
page. All of that depends on the cron entry above being in place.

## Letting an AI agent draft and schedule posts

MixpostMCP ships an optional MCP server, so Claude and other AI agents can read your accounts and your
results, draft posts and put them on the schedule.

**In the desktop app** the server is already there. Open **Help → Copy Claude Desktop config**, paste
the block that lands on your clipboard into `claude_desktop_config.json` (Claude Desktop → Settings →
Developer → Edit Config) and restart Claude Desktop. Skip the rest of this subsection — it is for
server installs — and pick up at [What an agent can do](#what-an-agent-can-do).

**On a server** it is **off unless you install the package it needs**:

```
composer require laravel/mcp
```

That needs Laravel 12.41 or newer. On anything older, MixpostMCP works exactly as before and the MCP
server is simply not registered.

The server runs over stdio, one process per agent, launched by the agent itself:

```
php artisan mcp:start mixpostmcp
```

In Claude Desktop, add it to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "mixpostmcp": {
      "command": "php",
      "args": ["/path-to-your-project/artisan", "mcp:start", "mixpostmcp"]
    }
  }
}
```

If your app runs in Docker, launch it through the container instead:

```json
{
  "mcpServers": {
    "mixpostmcp": {
      "command": "docker",
      "args": ["compose", "-f", "/path-to-your-project/docker-compose.yml",
               "exec", "-T", "app", "php", "artisan", "mcp:start", "mixpostmcp"]
    }
  }
}
```

### What an agent can do

| Tool | What it does |
| --- | --- |
| `list_accounts` | Connected accounts, with each network's character and media limits |
| `list_tags` | Tags available for labelling posts |
| `list_posts` | Browse posts by status, account or keyword; published ones include a link to the live post |
| `get_post` | One post in full — versions, media, per-network options, errors |
| `get_account_metrics` | Daily engagement figures and a total for a date range |
| `get_audience_growth` | Follower count over time, with the net change |
| `add_media_from_url` | Pull an image or video into the media library from a public URL |
| `create_post` | Draft a post, optionally with a thread and per-account overrides |
| `update_post` | Rewrite a draft or scheduled post |
| `schedule_post` | Put a drafted post in the publishing queue |

### What an agent cannot do

**There is no publish-now tool.** An agent can only put a post in the queue, and only far enough
ahead that you have a chance to see it in the calendar and cancel. The window defaults to ten
minutes and is set with `MIXPOSTMCP_SCHEDULE_LEAD`:

```
MIXPOSTMCP_SCHEDULE_LEAD=30
```

An agent also cannot delete posts, connect or disconnect accounts, or change any setting.

### Security

**The server has no authentication of its own.** It runs as your application, with the same reach as
the scheduler, so anyone who can run `php artisan mcp:start mixpostmcp` on that machine can post to your
accounts. It is stdio only — nothing is exposed over HTTP and no port is opened — so the boundary is
the machine itself. Do not wrap it in a network transport without putting real authentication in
front of it.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what has changed recently.

## Contributing

Pull requests for bug fixes and optimizations are welcome. If you want to add a feature, please open
an issue first so it can be discussed before you start writing code.

- Follow the [PSR-12 Coding Standard](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-12-extended-coding-style-guide.md).
- Write clear commit messages and pull request descriptions.
- The golden rule: imitate the surrounding code.

## Security Vulnerabilities

Please see [SECURITY.md](SECURITY.md) for how to report a security vulnerability.

## Credits

MixpostMCP is built on [Mixpost](https://github.com/inovector/mixpost) by
[Dima Botezatu](https://github.com/lao9s) and [Inovector](https://inovector.com), and on the work of
[its contributors](https://github.com/inovector/mixpost/graphs/contributors). Thank you.

## License

MixpostMCP is licensed under the [MIT License](LICENSE.md), as is the Mixpost project it is
derived from.
