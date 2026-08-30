# Mixpost Live

**Self-hosted social media management.** Write once, tailor per network, schedule it, and watch how
it performed — all from a calendar you own, on a server you control.

Mixpost Live is a fork of [Mixpost Lite](https://github.com/inovector/mixpost), extended with
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

Mixpost Live installs the same way as upstream Mixpost Lite, so the
[Mixpost Lite documentation](https://docs.mixpost.app/lite/) covers getting a server up and running.
The package name, config file, routes and database tables are unchanged from upstream, so you can
follow it step for step.

Once it is running, the sections below cover what is specific to this fork — connecting each social
network, and keeping those connections alive.

## Connecting social networks

Every network needs an app of your own in that network's developer portal. You put the app's
credentials on Mixpost Live's **Services** page, and you register Mixpost Live's callback URL on the app.

The callback URL is always the same shape:

```
https://<your-domain>/mixpost/callback/<provider>
```

| Network | Provider key | Callback URL to register | Developer portal |
| --- | --- | --- | --- |
| Facebook Page | `facebook_page` | `https://<your-domain>/mixpost/callback/facebook_page` | [Meta for Developers](https://developers.facebook.com/apps) |
| Instagram | `instagram` | `https://<your-domain>/mixpost/callback/instagram` | Same Meta app as Facebook |
| X (Twitter) | `twitter` | `https://<your-domain>/mixpost/callback/twitter` | [X Developer Portal](https://developer.x.com/en/portal/dashboard) |
| LinkedIn | `linkedin` | `https://<your-domain>/mixpost/callback/linkedin` | [LinkedIn Developers](https://www.linkedin.com/developers/apps) |
| TikTok | `tiktok` | `https://<your-domain>/mixpost/callback/tiktok` | [TikTok for Developers](https://developers.tiktok.com/) |
| YouTube | `youtube` | `https://<your-domain>/mixpost/callback/youtube` | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) |

Register **both** Facebook Page and Instagram callback URLs on the same Meta app — they are two
different URLs even though they share one app.

`<your-domain>` must be the domain in your `APP_URL`, over HTTPS, and must match character for
character what you register — every one of these portals rejects a callback that differs by so much
as a trailing slash.

### Facebook Pages

Create an app at [Meta for Developers](https://developers.facebook.com/apps), choose the
**Business** app type, and add the **Facebook Login** product. Put the App ID and App Secret on
Mixpost Live's **Services** page under Facebook, and add the callback URL to Facebook Login's **Valid
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
account with no Page linked to it is invisible to Mixpost Live — it will not show up, and there is no
error message explaining why. Link the Page in the Instagram app under *Settings → Account type and
tools* before you try to connect.

**Your Mixpost Live installation must be reachable from the public internet.** Meta downloads your
images and videos from a URL your server hands it, so publishing will fail if Mixpost Live runs on
`localhost`, on a private network, or behind an access gateway such as Cloudflare Access. Your
media disk must also serve files publicly.

### X (Twitter)

Create a Project and an App in the [X Developer Portal](https://developer.x.com/en/portal/dashboard),
then put the **API Key** and **API Secret** on Mixpost Live's **Services** page. Under the app's **User
authentication settings**, turn on OAuth 1.0a, set **App permissions** to *Read and write*, set the
**Type of App** to *Web App*, and add the callback URL.

Also set the **Tier** on the Services page to match your app's actual access level in the portal.
It is not cosmetic — the tier decides whether Mixpost Live publishes through API v1.1 or v2, and it is
what tells you how many posts you may make.

**Free-tier write caps are low enough to matter.** A free app is limited to roughly 1,500 posts a
month; Basic to roughly 50,000 app-wide. Once you hit the cap X rejects every publish for the rest
of the billing cycle, and from Mixpost Live that looks like posts failing for no reason. Check your
usage in the portal dashboard before assuming something is broken.

> **Known issue: media posting on X is likely broken.**
> X sunset the v1.1 media upload endpoints on 9 June 2025 and Mixpost Live still uploads through them,
> because the `inovector/twitteroauth` package it uses only speaks v1.1
> (`https://upload.twitter.com/1.1/media/upload.json`). Text-only posts go through the v2 `tweets`
> endpoint and are unaffected. Migrating uploads to `/2/media/upload` has not been done yet.

### LinkedIn

Create an app at [LinkedIn Developers](https://www.linkedin.com/developers/apps), then put its
Client ID and Client Secret on Mixpost Live's **Services** page. Add your callback URL
(`https://your-mixpost-url/mixpost/callback/linkedin`) to the app's **Authorized redirect URLs**.

Your app needs these products:

| Product | Gives you | Required? |
| --- | --- | --- |
| Sign In with LinkedIn using OpenID Connect | `openid`, `profile`, `email` | Yes |
| Share on LinkedIn | `w_member_social` — posting as yourself | Yes |
| Community Management API | `r_organization_admin`, `w_organization_social` — listing and posting as company pages | Only for company pages |

**The Community Management API has to be approved before you connect.** Mixpost Live always asks for the
organization scopes, and LinkedIn refuses the whole authorization if the app is not approved for
them — it does not quietly drop them. If approval is still pending, either finish it first or
remove the two `*_organization_*` entries from `$scopes` in
`src/SocialProviders/LinkedIn/Concerns/ManagesOAuth.php`; you will then be able to connect your own
profile but no company pages.

Access tokens last about 60 days. Refresh tokens are only issued to apps LinkedIn has approved for
them, so most self-hosted installs will need to reconnect the account when the token expires.

### TikTok

Create an app at [TikTok for Developers](https://developers.tiktok.com/), then put its **Client Key**
and **Client Secret** on Mixpost Live's **Services** page. Add your callback URL
(`https://your-mixpost-url/mixpost/callback/tiktok`) to the app's redirect URIs.

Your app needs the **Login Kit** and **Content Posting API** products, with the `user.info.basic`,
`video.publish` and `video.upload` scopes.

**Until your app passes TikTok's content posting audit, every video you publish is private.**
Unaudited apps may only post with `SELF_ONLY` viewership, are limited to 5 creators in any 24 hour
window, and require those creators' accounts to be set to private. Nothing fails — the upload
succeeds, TikTok reports the post as published, and nobody but the creator can see it. Posts made
while unaudited stay private permanently, so run the audit before you rely on this. Mixpost Live warns
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
Put the client ID and client secret on Mixpost Live's **Services** page and add the callback URL to the
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
Google, which is a review process, not a form. Mixpost Live reports a breach as a rate limit and
schedules the retry for the next midnight Pacific time, which is when Google restores the allowance.

While the OAuth consent screen is in **Testing**, refresh tokens expire after 7 days and every
connected channel has to be added as a test user. Publishing the consent screen requires
verification if you use the `youtube.upload` scope.

## Keeping accounts connected

**The Laravel scheduler must actually be running.** Mixpost Live's scheduled work — publishing queued
posts, importing metrics, and refreshing access tokens — all runs from it. Add this to your
server's crontab:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Without it nothing publishes at its scheduled time, and connected accounts quietly stop working as
their tokens expire.

Three networks issue short-lived access tokens and depend on scheduled refresh:

| Network | Access token life | What happens without refresh |
| --- | --- | --- |
| YouTube | about 1 hour | Breaks the same day you connect it |
| TikTok | 24 hours | Breaks overnight |
| LinkedIn | about 60 days | Breaks silently two months later |
| Facebook / Instagram | about 60 days | Exchanged for a long-lived token when you connect, so no refresh needed |
| Mastodon | does not expire | Nothing |

> **Not yet implemented.** The `mixpost:refresh-access-tokens` command that performs this sweep has
> not been written, and nothing is registered in `src/Schedule.php` to run it. YouTube, TikTok and
> LinkedIn each implement `refreshAccessToken()`, but no scheduled job calls it — so for now a
> YouTube account stops posting about an hour after you connect it, and a TikTok account a day
> after, and both have to be reconnected by hand.

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

Mixpost Live is built on [Mixpost](https://github.com/inovector/mixpost) by
[Dima Botezatu](https://github.com/lao9s) and [Inovector](https://inovector.com), and on the work of
[its contributors](https://github.com/inovector/mixpost/graphs/contributors). Thank you.

## License

Mixpost Live is licensed under the [MIT License](LICENSE.md), as is the Mixpost project it is
derived from.
