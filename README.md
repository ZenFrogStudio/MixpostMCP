[<img src="./art/standwithua.png" />](https://supportukrainenow.org)

* * *

[<img src="./art/page-cover.png" alt="Cover" />](https://mixpost.app)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/inovector/mixpost.svg?style=flat-square)](https://packagist.org/packages/inovector/mixpost)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/inovector/mixpost/run-tests?label=tests)](https://github.com/inovector/mixpost/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/inovector/mixpost.svg?style=flat-square)](https://packagist.org/packages/inovector/mixpost)

## Introduction

Mixpost is a robust and versatile **social media management platform**, designed to streamline **social media operations** and enhance **content marketing strategies**. Our platform empowers brands and businesses to effectively manage their **online presence**, leading them to success in the dynamic digital landscape. Mixpost's mission is to offer a comprehensive and powerful solution, enabling users to elevate their **social media management** and achieve tangible results.

The platform allows users to craft, organize, and schedule their content for times when their audience is most engaged and active. Mixpost's user-friendly **scheduling system** ensures that content publishing is seamless and efficient. It also facilitates team collaboration by allowing users to assign tasks, manage permissions, and monitor team performance, optimizing team interactions and workflow. Additionally, Mixpost automates post scheduling to ensure maximum audience reach and engagement, significantly boosting interaction and customer engagement.

Trusted by a wide range of users, Mixpost stands out as a proficient and influential tool for social media management and content marketing. It is perfectly suited for enterprises, small to medium businesses, marketing agencies, solopreneurs, and e-commerce stores.

**_Highlighting Features of Mixpost_**

**Mixpost** offers a multitude of features, making **social media management** more effective and simpler:

**Streamlined Social Account Management:**
Bring all your social media accounts together in one place for smarter and more efficient management.

**Advanced Analytics:**
Gain insights into your audience's behavior and preferences. Mixpost provides detailed analytics, for each platform according to the data shared. We do our best to make sure our API integrations are up to date, to provide seamless analytics experience accross all social media platforms.

**Post Versions and Conditions:**
Tailor your content for each social network and automate follow-up comments on high-performing posts, enhancing engagement and reach.

**Efficient Media Library:**
Quickly access and reuse media files like images, GIFs, and videos, and integrate with stock image sources for a diverse range of content.

**Team Collaboration and Workspaces:**
Foster team collaboration with dedicated workspaces. Discuss ideas, manage tasks, and monitor performance, all from a centralized platform.

**Queue and Calendar Management:**
Build a natural content posting schedule and visualize your strategy with an easy-to-use calendar.

**Customizable Post Templates:**
Boost efficiency with reusable post templates, perfect for maintaining consistency across your social media channels.

**Dynamic Variables and Hashtag Groups:**
Insert dynamic text and organize your hashtags strategically for increased post effectiveness.
And many more features that make Mixpost a standout choice for managing social media and content marketing. Discover all the features in detail at Mixpost Features.

It is the ideal social media management software for bloggers, artisans, entrepreneurs, and marketing teams looking to optimize internal costs.

**[Unlock the full potential of Mixpost with Mixpost Pro/Enterprise](https://mixpost.app/pricing)**

Join our community:

- [Discord](https://mixpost.app/discord)
- [Facebook Private Group](https://www.facebook.com/groups/getmixpost)

[<img src="./art/demo.png" />](https://mixpost.app)

## Installation

Read our [documentation](https://docs.mixpost.app/lite/) on how to get started.

## Connecting social networks

Every network needs an app of your own in that network's developer portal. You put the app's
credentials on Mixpost's **Services** page, and you register Mixpost's callback URL on the app.

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
Mixpost's **Services** page under Facebook, and add the callback URL to Facebook Login's **Valid
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
account with no Page linked to it is invisible to Mixpost — it will not show up, and there is no
error message explaining why. Link the Page in the Instagram app under *Settings → Account type and
tools* before you try to connect.

**Your Mixpost installation must be reachable from the public internet.** Meta downloads your
images and videos from a URL your server hands it, so publishing will fail if Mixpost runs on
`localhost`, on a private network, or behind an access gateway such as Cloudflare Access. Your
media disk must also serve files publicly.

### X (Twitter)

Create a Project and an App in the [X Developer Portal](https://developer.x.com/en/portal/dashboard),
then put the **API Key** and **API Secret** on Mixpost's **Services** page. Under the app's **User
authentication settings**, turn on OAuth 1.0a, set **App permissions** to *Read and write*, set the
**Type of App** to *Web App*, and add the callback URL.

Also set the **Tier** on the Services page to match your app's actual access level in the portal.
It is not cosmetic — the tier decides whether Mixpost publishes through API v1.1 or v2, and it is
what tells you how many posts you may make.

**Free-tier write caps are low enough to matter.** A free app is limited to roughly 1,500 posts a
month; Basic to roughly 50,000 app-wide. Once you hit the cap X rejects every publish for the rest
of the billing cycle, and from Mixpost that looks like posts failing for no reason. Check your
usage in the portal dashboard before assuming something is broken.

> **Known issue: media posting on X is likely broken.**
> X sunset the v1.1 media upload endpoints on 9 June 2025 and Mixpost still uploads through them,
> because the `inovector/twitteroauth` package it uses only speaks v1.1
> (`https://upload.twitter.com/1.1/media/upload.json`). Text-only posts go through the v2 `tweets`
> endpoint and are unaffected. Migrating uploads to `/2/media/upload` has not been done yet.

### LinkedIn

Create an app at [LinkedIn Developers](https://www.linkedin.com/developers/apps), then put its
Client ID and Client Secret on Mixpost's **Services** page. Add your callback URL
(`https://your-mixpost-url/mixpost/callback/linkedin`) to the app's **Authorized redirect URLs**.

Your app needs these products:

| Product | Gives you | Required? |
| --- | --- | --- |
| Sign In with LinkedIn using OpenID Connect | `openid`, `profile`, `email` | Yes |
| Share on LinkedIn | `w_member_social` — posting as yourself | Yes |
| Community Management API | `r_organization_admin`, `w_organization_social` — listing and posting as company pages | Only for company pages |

**The Community Management API has to be approved before you connect.** Mixpost always asks for the
organization scopes, and LinkedIn refuses the whole authorization if the app is not approved for
them — it does not quietly drop them. If approval is still pending, either finish it first or
remove the two `*_organization_*` entries from `$scopes` in
`src/SocialProviders/LinkedIn/Concerns/ManagesOAuth.php`; you will then be able to connect your own
profile but no company pages.

Access tokens last about 60 days. Refresh tokens are only issued to apps LinkedIn has approved for
them, so most self-hosted installs will need to reconnect the account when the token expires.

### TikTok

Create an app at [TikTok for Developers](https://developers.tiktok.com/), then put its **Client Key**
and **Client Secret** on Mixpost's **Services** page. Add your callback URL
(`https://your-mixpost-url/mixpost/callback/tiktok`) to the app's redirect URIs.

Your app needs the **Login Kit** and **Content Posting API** products, with the `user.info.basic`,
`video.publish` and `video.upload` scopes.

**Until your app passes TikTok's content posting audit, every video you publish is private.**
Unaudited apps may only post with `SELF_ONLY` viewership, are limited to 5 creators in any 24 hour
window, and require those creators' accounts to be set to private. Nothing fails — the upload
succeeds, TikTok reports the post as published, and nobody but the creator can see it. Posts made
while unaudited stay private permanently, so run the audit before you rely on this. Mixpost warns
you in the post composer when TikTok offers a creator no audience other than "Only me".

TikTok posts here are **video only**; one video per post, up to 4 GB. Photo posts are a separate
TikTok product and text-only posts are rejected before any API call is made. TikTok also requires
the audience to be chosen explicitly, so pick a privacy level under **Post options** — a TikTok post
will not publish without one.

Access tokens last 24 hours and refresh tokens last 365 days, so this integration depends on token
refresh being scheduled; an account will otherwise stop posting the day after you connect it.

### YouTube

**Not yet available.** The Services page accepts YouTube credentials and the composer can preview a
YouTube post, but `YouTubeProvider` has not been written, so YouTube does not appear on the Accounts
page and no account can be connected. The section below is what the app will need once it does.

Create a project in the [Google Cloud Console](https://console.cloud.google.com/apis/credentials),
enable the **YouTube Data API v3**, and create an **OAuth client ID** of type *Web application*.
Put the client ID and client secret on Mixpost's **Services** page and add the callback URL to the
client's **Authorised redirect URIs**. Scopes: `youtube.upload` and `youtube.readonly`.

**Uploading costs about 1,600 quota units, against a default quota of 10,000 units per day** — so
roughly six videos a day before uploads start failing. Beyond that you need a quota increase from
Google, which is a review process, not a form.

While the OAuth consent screen is in **Testing**, refresh tokens expire after 7 days and every
connected channel has to be added as a test user. Publishing the consent screen requires
verification if you use the `youtube.upload` scope.

## Keeping accounts connected

**The Laravel scheduler must actually be running.** Mixpost's scheduled work — publishing queued
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
> not been written, and nothing is registered in `src/Schedule.php` to run it. TikTok and LinkedIn
> each implement `refreshAccessToken()`, but no scheduled job calls it — so for now a TikTok account
> stops posting roughly a day after you connect it and has to be reconnected by hand.

## Changelog

Please see [Releases](../../releases) for more information what has changed recently.

## Contributing

By participating in this project, you agree to the following terms 👇

This repository contains the Lite version of Mixpost Pro, a [commercial product](https://mixpost.app/) product. We’re committed to providing the community with the best free social media management solution. Please read the information below carefully.

- If you’d like to add a feature, please open an issue first to discuss it before you begin coding. It’s essential that Lite version features remain distinct from those in Mixpost Pro.
- Pull requests (PRs) for optimizations and bug fixes are always welcome.
- Make sure your commit messages and pull request descriptions are clear and informative. PRs with empty descriptions may be rejected.
- When contributing code to Mixpost, you must follow
  the [PSR-12 Coding Standard](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-12-extended-coding-style-guide.md).

The golden rule is: Imitate the existing Mixpost code.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Dima Botezatu](https://github.com/lao9s)
- [All Contributors](../../contributors)

## License

Mixpost is licensed under the [MIT License](LICENSE.md), sponsored and supported by [Inovector](https://inovector.com).
