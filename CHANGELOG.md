# Changelog

All notable changes to MixpostMCP will be documented in this file.

## 2.22.0 - 2026-09-06

**Added**

- **An MCP server, so AI agents can draft and schedule posts.** MixpostMCP had no way in other than the
  browser — no API, no tokens, and every write path buried inside an HTTP form request. It now ships
  a Model Context Protocol server that gives Claude and other agents ten typed tools: read the
  connected accounts and what each network allows, browse posts and how they performed, pull media in
  from a URL, draft a post, and put it on the schedule.
  - **stdio only.** `php artisan mcp:start mixpostmcp`, one process per agent, launched by the agent.
    Nothing is exposed over HTTP and no port is opened, so there is no token to mint or leak — the
    security boundary is the machine. The flip side is that it has no authentication of its own:
    anyone who can run that command can post to your accounts.
  - **An agent cannot publish.** There is no publish-now tool, and `schedule_post` refuses any time
    closer than `MIXPOSTMCP_SCHEDULE_LEAD` minutes (ten by default). Everything an agent queues
    appears in the calendar with a window to cancel it first. It also cannot delete posts, touch
    accounts, or change a setting.
  - Over-length bodies are caught **before** the post is saved, named per account: *"The body is 300
    characters, over the 280 character limit for Test Handle (twitter)."* The same check at publish
    time would surface hours later inside a queued job.
  - `add_media_from_url` is the only way to attach media, since files cannot be handed over MCP. It
    reuses the media library's own public-address gate, so an agent cannot pull files off the LAN,
    and applies the same mime and size caps as the upload form.
- **`laravel/mcp` is optional.** It needs Laravel 12.41+, while MixpostMCP still supports 10.47 and
  11, so it is a `suggest` rather than a `require` and the server registers only when the class is
  there. Nothing changes for anyone who does not install it.

**Changed**

- **The application is now called MixpostMCP, and this rename goes all the way down.** Unlike the
  2.17.0 rename, which was cosmetic, this one moves the identifiers as well:

  | Was | Is now |
  | --- | --- |
  | `Inovector\Mixpost` namespace | `OneMediaLabs\MixpostMcp` |
  | `inovector/mixpost` package | `onemedialabs/mixpostmcp` |
  | `MixpostLiveServiceProvider` | `MixpostMcpServiceProvider` |
  | `MixpostLiveExceptionHandler` | `MixpostMcpExceptionHandler` |
  | `config/mixpost.php` | `config/mixpostmcp.php` |
  | `/mixpost` route prefix | `/mixpostmcp` |
  | `mixpost.*` route names | `mixpostmcp.*` |
  | `mixpost:*` artisan commands | `mixpostmcp:*` |
  | `mixpost::` view namespace | `mixpostmcp::` |
  | `MIXPOST_*` env vars | `MIXPOSTMCP_*` |
  | `public/vendor/mixpost` assets | `public/vendor/mixpostmcp` |
  | `viewMixpost` gate | `viewMixpostMcp` |
  | `mixpostAssets()` helper | `mixpostMcpAssets()` |

  **The `mixpost_*` database tables are untouched**, so there is no migration and no data to move.
  Media already in the library keeps working: the `path` column stores each file's own location, so
  existing rows are unaffected and only new uploads land under `mixpostmcp/`.

  **This is a breaking upgrade.** Every `use Inovector\Mixpost\...` in a host application has to
  change, `MIXPOST_*` entries in `.env` have to be renamed, bookmarks to `/mixpost` move to
  `/mixpostmcp`, the cron entry and any queue tooling calling `mixpost:*` commands need the new
  prefix, and assets must be re-published with `php artisan mixpostmcp:publish-assets`.

- **A dead facade alias was dropped.** `composer.json` aliased `Mixpost` to
  `Inovector\Mixpost\Facades\Mixpost`, a class that has never existed in this repository. The three
  real facades — `Settings`, `ServiceManager`, `SocialProviderManager` — are unaffected, though
  their container binding keys are now prefixed `MixpostMcp`.

- **Post writes moved out of the form requests** into `Actions\CreatePost` and `Actions\SavePost`,
  joining `PublishPost` in `src/Actions/`. `StorePost` and `UpdatePost` now just validate and
  delegate. The MCP tools run in a console process with no HTTP request to build a form request
  from, and duplicating the writes would have let the two paths drift apart.

**Fixed**

- **`Util::isPublicDomainUrl()` could be walked around.** It is the gate in front of every
  server-side fetch of a user-supplied URL — stock images, GIFs, and now `add_media_from_url` for AI
  agents — and it only checked for a literal IP or the word `localhost`. A bracketed IPv6 literal
  (`http://[::1]/`) passed because `parse_url()` keeps the brackets, and any hostname that
  *resolves* to a private address passed because nothing resolved it. It now resolves the host and
  requires every address to be public, refuses non-http schemes, and refuses `*.localhost`.
  `add_media_from_url` also re-checks every redirect hop, since a public URL is free to 302 to a
  private one.
- **The System Status page checked for a queue connection nothing uses.** It wanted a connection
  literally named `mixpost-redis`, a convention from upstream's install guide that this fork never
  documented, so the row was red on every install. It now checks the thing Horizon needs — that the
  default queue connection is Redis — and gains a second row, **Publish queue**, that goes red when
  no Horizon supervisor lists `publish-post`. That queue is where every publish is batched, and a
  stack can look entirely healthy while never sending a post if it is missing. The bug-report copy
  on that page also printed `undefined` for the FFmpeg line.
- **`add_media_from_url` streams to disk** instead of reading the whole file into a string and
  base64 round-tripping it, which for a 200 MB video meant three or four copies in memory at once.
- **`list_posts` excerpts come from the shared version**, not whichever version row loaded first,
  so an account-specific override no longer shows up as the excerpt for the whole post.
- **Help links on the Status page pointed at `docs.mixpostmcp.app`**, a domain that does not exist —
  a casualty of the rename. They point at upstream's docs again, which still apply.

## 2.21.0 - 2026-08-29

**Added**

- **Per-platform media validation.** Every network enforces its own rules about the media itself —
  how long a video may run, what shape an image may be, which container formats it accepts — and a
  violation used to surface as an opaque API rejection inside a queued job, long after whoever wrote
  the post had moved on. Each provider now checks the file against its own rules inside
  `publishPost()`, before a single byte is uploaded, and every message names the real limit and the
  real value: *"The video is 1s long. TikTok requires at least 3s."*
  - Validation lives in the provider, not in the media library. The same file is valid for LinkedIn
    and invalid for TikTok, so a single verdict at upload time would be wrong by construction.
  - **Instagram** — feed images must sit between 0.8:1 (4:5 portrait) and 1.91:1 landscape; video is
    published as a Reel and capped at 15 minutes; JPEG and PNG images, MP4 and MOV video.
  - **TikTok** — a 3 second floor, which is TikTok's own. The *ceiling* is read from the creator
    info query rather than fixed in code, because it varies per creator. MP4, MOV and WEBM.
  - **YouTube** — descriptions over 5000 characters, and `<` or `>` anywhere in the title or
    description, are now rejected by name rather than silently stripped and truncated. YouTube
    refuses both outright, and only after the whole file has been uploaded — which costs the
    transfer and a slice of the channel's daily quota as well.
  - **LinkedIn** — video must run between 3 seconds and 30 minutes.
  - **X** — a GIF over 15 MB is rejected up front. `max_file_size.gif` guards the library at upload
    time, but that is one operator setting covering every network, and a GIF can be in the library
    and still be too big for X.
- **`MediaProbe`.** One small helper in `src/Support/` that reports a file's duration, width, height
  and aspect ratio. Video is measured with ffprobe; images are measured with `getimagesize()`, so an
  image-only post never launches ffmpeg. Results are cached on the media row, because the same file
  is checked once per network it goes to. It is best effort by design: media on a remote disk, or an
  install without ffmpeg, returns nothing at all and every rule lets the post through rather than
  blocking work that would have succeeded.

**Note**

- Non-conforming video is **rejected, not re-encoded.** `php-ffmpeg` could transcode it
  automatically, but silent re-encoding is slow, lossy and surprising — someone who uploaded a
  specific file expects that file to be posted. If transcoding is wanted it should be an explicit,
  opt-in action.

## 2.20.0 - 2026-08-29

**Fixed**

- **Media posting on X.** Photos, GIFs and video uploaded to `upload.twitter.com/1.1`, which X
  sunset on 9 June 2025, so every post with an attachment failed. Uploads now run X's v2 chunked
  flow — INIT, an APPEND per chunk, FINALIZE, then STATUS while X transcodes — against
  `https://api.x.com/2/media/upload`. Text-only posting is untouched.
  - The `inovector/twitteroauth` SDK cannot reach the v2 endpoint at all: `upload()` and
    `mediaStatus()` hardcode the retired host, the host constants are `private` so there is nothing
    to override, and the request builder only writes urlencoded or JSON bodies while APPEND needs a
    multipart file part. Transport is now Laravel's HTTP client. **The OAuth 1.0a signing is still
    the SDK's** — `Request`, `HmacSha1`, `Consumer` and `Token` driven directly — so no signing code
    was hand-rolled. Body fields are correctly left out of the signature base string, which OAuth
    1.0a only covers for urlencoded bodies.
  - X publishes two contradictory shapes for this flow. We use the single-URL form with a `command`
    field, per the chunked-upload quickstart; the alternative REST paths (`/initialize`, `/append`,
    `/{id}/finalize`) are recorded in `README.md` as the fallback to try. **This choice comes from
    the docs, not from a live call** — see the caveat below.
  - Chunks are capped at 4 MiB, under X's 5 MB APPEND limit, so a large video is split across
    several APPEND requests.
  - Photos now take the chunked path too, because v2 has no single-shot upload.
  - `media_category` for video is now `amplify_video` rather than v1.1's `tweet_video`.
  - A 403 no longer reads as a generic auth failure: it names the `media.write` scope and says to
    set the X app to *Read and write* and reconnect the account. Uploading is scoped separately from
    posting, so a token that publishes text happily still 403s the first time it sees a file.
  - A 429 during upload now releases the queued job with the right retry delay instead of marking
    the post failed. On the free tier INIT and FINALIZE share the 17-per-24-hours allowance with
    `POST /2/tweets`, so hitting the cap mid-upload is routine and the media is still uploadable
    later.
  - The processing-status poll is unchanged apart from the response shape — v2 nests everything
    under `data` — except that a FINALIZE which is already terminal no longer sleeps before checking.
  - `TwitterProvider` now keeps the access token on the provider as well as inside the SDK. The
    Twitter override of `useAccessToken()`/`setAccessToken()` only ever handed it to the SDK, so
    `getAccessToken()` fell through to the session and threw inside a queued job.

> **Not verified against live X credentials.** This checkout has no X app credentials, and no PHP
> runtime or `vendor/` directory, so neither the test suite nor a real photo/GIF/video post could be
> run here. The wire format was derived from X's published docs and from reading
> `inovector/twitteroauth` v7.1.0's source directly. Confirm with a real photo, GIF and video post
> before trusting this.

## 2.19.0 - 2026-08-29

**Added**

- **Scheduled access token refresh.** `mixpost:refresh-access-tokens` renews any access token due to
  expire in the next ten minutes, and `Schedule` runs it every thirty minutes. Without it a post
  scheduled today fails when it fires: a YouTube token lasts about an hour and a TikTok token 24
  hours, so those accounts died within a day of being connected and had to be reconnected by hand.
  - Thirty minutes rather than hourly because a YouTube token against a ten-minute lookahead leaves
    a gap where the token dies between two hourly runs.
  - Accounts already marked unauthorized are skipped — their refresh token is dead, so retrying
    spends rate limit and fixes nothing.
  - A provider with no `refreshAccessToken()` is skipped by asking the class, not by a hard-coded
    list, so a future network is picked up on its own. Mastodon tokens do not expire and Meta's are
    exchanged for long-lived ones at connect time.
  - Every account is wrapped in its own try/catch and logged through `Support\Log`, so one network
    being down cannot abort the sweep and let every other token expire.
  - A refused refresh marks the account unauthorized, which lights up the existing Unauthorized
    badge on the accounts page.
- `LinkedInProvider::refreshAccessToken()`, which did not exist. LinkedIn only issues refresh tokens
  to apps approved for them, so an account without one returns a clear "reconnect the account"
  error rather than sending a null and retrying forever.
- `AccountPublishPost` now renews an about-to-expire token immediately before publishing, which
  closes the gap where a backed-up queue hands a job a token that expired while it waited.

**Fixed**

- `Account::updateAccessToken()` did not exist, though `SocialProvider::updateToken()` has always
  called it. Every token refresh would have succeeded against the network and then thrown while
  saving, leaving the account presenting the dead token — and for TikTok, which rotates its refresh
  token on every refresh, discarding the only replacement it will issue.

## 2.18.0 - 2026-08-29

**Added**

- **YouTube.** Channels can now be connected and videos published to them. Everything around the
  provider had already shipped — the service, the credentials form, the composer preview, the icon,
  the README section — so this is the piece that switches the network on.
  - Connecting goes through Google OAuth 2.0 with `access_type=offline` and `prompt=consent`, which
    together are what make Google issue a refresh token on a first *and* a repeat connection. A
    `state` parameter is generated before the redirect and verified on return.
  - A Google account can own several channels, including Brand Accounts, so connecting one opens the
    channel picker. `StoreProviderEntitiesAsAccounts::storeYoutubes()` stores the chosen channel
    against the single Google token, which covers all of them.
  - Publishing is video only, one video per post, through a resumable upload. Text-only and
    image-only posts are rejected before any API call is made, so they cost no quota. The post body
    becomes the title and description — first line as the title, the rest as the description —
    unless a title is set under Post options, and `<` and `>` are stripped from both because YouTube
    rejects them outright.
  - Privacy defaults to **Private**. An unattended scheduler publishing to the wrong channel
    publicly is not a recoverable mistake.
  - A generated video thumbnail is uploaded after publishing. Custom thumbnails need a verified
    YouTube account, so a failure there is logged and the post still succeeds — the video is already
    live by that point.
  - A quota breach (403 `quotaExceeded`) is reported as a rate limit with the retry set to the next
    midnight Pacific, which is when Google restores the daily allowance. An upload costs about 1,600
    of the default 10,000 units a day, so roughly six videos.

**Fixed**

- `LinkedIn\Concerns\ManagesResources` carried a duplicated `getMemberAccount()` signature, which is
  a PHP parse error — it would have taken down anything that loaded the file, including the whole
  test suite. Found while reading the file as a pattern to copy.
- The account entity picker checked `empty()` on a Collection, which is always false, so a provider
  that returned no entities rendered an empty picker instead of redirecting with "The account has no
  entities." A Google account with no YouTube channel is the ordinary way to reach that.

**Changed**

- `SocialProviderManager::providers()` no longer filters the registry through `class_exists()`. That
  scaffolding existed only because `YouTubeProvider` was missing; every registered network now has a
  class, and the registry is the plain list it was meant to be.

## 2.17.0 - 2026-08-29

**Changed**

- The application is now called **Mixpost Live**. Everything a user sees carries the new name: the
  browser tab title, the version row on the System Status page, the installer's console output, the
  `mixpost:publish-assets` output, and the service setup copy for Tenor and Unsplash.
- `MixpostServiceProvider` is now `MixpostLiveServiceProvider`, and `MixpostExceptionHandler` is now
  `MixpostLiveExceptionHandler`. Their files were renamed to match. The Laravel auto-discovery entry
  in `composer.json` and the provider list in `tests/TestCase.php` were updated with them.
- `README.md` was rewritten for this fork: the upstream marketing copy, the Pro/Enterprise upsell
  and the Packagist badges are gone, along with three header images whose `art/` directory does not
  exist in this repository. The network setup and token-refresh documentation is unchanged apart
  from the name. `SECURITY.md` now points reports at this repository first.

Nothing else moved. The `Inovector\Mixpost` namespace, the `inovector/mixpost` package name, the
`mixpost_*` database tables, `config/mixpost.php`, the `/mixpost` route prefix, the `mixpost.*`
route names, the `MIXPOST_*` environment variables and the `public/vendor/mixpost` asset path are
all as they were, so existing installations upgrade with no migration and no reconfiguration.

## 2.16.1 - 2026-08-29

**Fixed**

- Connecting a network whose provider class does not exist crashed with a fatal error instead of
  simply not being offered. The YouTube service, its credentials form, its Add-account button and
  its README setup section had all shipped, but `YouTubeProvider` had not — so following the
  documented setup ended at an uncatchable `Class not found`. The registry
  (`SocialProviderManager::providers()`) is now the single authority on which networks are
  available: `createConnection()` checks it before dispatching to a `connect<Name>Provider()`
  method, the accounts page receives the same list as `available_providers`, and every
  Add-account button is gated on it as well as on the service being configured. Previously three
  places decided availability independently and disagreed.
- `AddAccountController` answers a request for an unavailable network with the accounts page and a
  message rather than a 500. The route is reachable directly even though the UI no longer links it.

**Added**

- Registry tests covering both halves of the contract that broke: every registered provider has a
  connect method, and connecting an unregistered provider raises a catchable exception instead of
  instantiating a class that may not exist.

## 2.16.0 - 2026-08-25

**Added**

- A Pest test suite — `phpunit.xml`, `tests/TestCase.php` and `tests/Pest.php` — where none existed
  before, plus provider tests for X, Meta (Facebook Page and Instagram), LinkedIn and TikTok. They
  cover the parts that need no live credentials: authorization URLs, the text-only publish guards,
  the `postOptions()` schema, and that token expiry is stored as an absolute timestamp
  `tokenIsAboutToExpire()` can read. Every test fakes HTTP; none touches a real API.
  The TikTok tests pin `code_challenge_method=S256` and the hex-encoded challenge specifically,
  since that is the parameter whose absence only fails later, at the token exchange.
- README setup instructions for every network: which portal, which app type and product, which
  scopes, and the exact callback URL to register, with the pattern and a table of all six provider
  keys in one place. Each section records the constraint that costs hours if you do not know it —
  Instagram needing a Business account linked to a Page and a publicly reachable media URL, TikTok's
  unaudited apps posting `SELF_ONLY`, YouTube's ~1,600 quota units per upload against 10,000 a day,
  LinkedIn's Community Management API approval, and X's free-tier write cap.
- A README section on keeping accounts connected, with the crontab line for the Laravel scheduler
  and a table of how long each network's access token lasts.
- The Tier field on the X service form now states each tier's write cap and links to the developer
  portal dashboard. The cap was previously invisible: `getTier()` only chose between API v1.1 and
  v2, so an account that had exhausted its monthly posts looked like a bug rather than a limit.

**Fixed**

- `postConfigs()` for X, Facebook Pages and Mastodon read `allow_mixing` from
  `social_provider_options.<provider>.allow_mixing`, but the key is declared one level down inside
  `media_limit`. The lookup returned null and fell through to the built-in default, so editing the
  published config had no effect for those three. Instagram, LinkedIn and TikTok already used the
  correct path. Behaviour is unchanged for anyone running the shipped defaults.

**Notes**

- **Media posting on X is very likely broken and was not fixed here.** X sunset the v1.1 media
  upload endpoints on 9 June 2025; `Twitter/Concerns/ManagesResources::uploadMedia()` still calls
  `media/upload` on API v1.1, and the `inovector/twitteroauth` package only speaks v1.1 — its
  `upload()` method is hardcoded to `https://upload.twitter.com` and appends the API version, so
  there is no v2 path available through it. Text-only posts use the v2 `tweets` endpoint and are
  unaffected. Migrating to `/2/media/upload` means replacing the SDK's upload path with a direct
  initialize/append/finalize/status implementation — too large to fold in here, and untestable
  without live X credentials. Recorded in README as a known issue.
- The X character limit was left at a fixed 280 rather than following the `tier` setting. The two
  are unrelated: `tier` describes the *app's* API access level, while the longer limit comes from
  the *posting account's* X Premium subscription. Deriving one from the other would let a
  non-Premium account on a paid tier compose a post X then rejects.
- **The `class_exists()` guard in `SocialProviderManager::providers()` could not be removed.**
  `YouTubeProvider` does not exist — plans 11 and 12 never ran — so the registry still names a class
  that is not there, and removing the filter would fatal the Accounts page. The comment now says
  YouTube is the only one outstanding. A new registry test asserts every registered provider class
  actually exists, which is the check the guard was standing in for.
- `RefreshAccessTokens` from plan 15 does not exist, so it could not be documented as working.
  README says so plainly rather than describing a command that is not there — TikTok accounts
  currently stop posting about a day after they are connected.
- `facebook_group` and `FacebookGroup` return no hits anywhere in `src/`, `config/`, `resources/js/`
  or `routes/`. Plan 02's removal was complete; the only remaining matches are in `.chronos/`
  planning records.
- No PHP runtime is installed in this environment, so **the new test suite has never been run** and
  the PHP changes could not be linted. `npm run build` passes. The X media, tier-cap and character-
  limit questions were answered by reading the code and X's published deprecation notices, not by
  publishing to a live account.

## 2.15.0 - 2026-08-25

**Added**

- LinkedIn publishing via the versioned Posts API (`POST /rest/posts`), pinned to
  `LinkedInProvider::API_VERSION`. Text-only, single-image, multi-image and video posts are all
  supported, for both personal profiles and company pages — the `author` is the URN stored when the
  account was connected, so the same code path serves both.
- The published post's URN is read from the **`x-restli-id` response header**. LinkedIn returns an
  empty body on 201, so reading the id from the body would store nothing and leave the
  external-post link pointing at `#`.
- `Concerns/ManagesLinkedInMedia` implementing the three-step upload for images and video:
  `initializeUpload` for a signed URL and asset URN, a binary `PUT` of the bytes, then the URN named
  in the post. Video uploads are always multi-part — each part is read one byte range at a time so a
  large file never lands in memory whole — and the post waits for the asset to report `AVAILABLE`,
  because naming a still-processing video gets the post rejected outright.
- A `visibility` post option (`PUBLIC` / `CONNECTIONS`, default `PUBLIC`). Organization authors are
  coerced to `PUBLIC` since LinkedIn rejects `CONNECTIONS` for a company page, and the value is
  matched against the two known settings rather than passed through.
- `deletePost()` now really deletes, via `DELETE /rest/posts/{encoded urn}`. A 404 counts as success
  — a post already gone is the desired end state — and only a well-formed post URN is ever
  interpolated into the URL.

**Fixed**

- Post text is escaped for LinkedIn's `little` text format before it is sent as `commentary`. This
  is not cosmetic: `commentary` treats ``| { } @ [ ] ( ) < > # * _ ~ \`` as markup, and LinkedIn
  silently drops everything from an unescaped one onward — a post containing "(see below)" would
  publish truncated with no error reported anywhere. `#` is deliberately left unescaped when it
  opens a word so hashtags still resolve, which is what someone typing "#hiring" means.

**Notes**

- `deletePost()` has no caller in Mixpost Lite. `DeletePostsController` removes the local post row
  only and never asks the provider to delete remotely, which is true for every provider here, not
  just LinkedIn. The method is implemented and correct, but deleting a post in the UI will not
  remove it from LinkedIn until something calls it.
- Publishing was verified by inspection and by exercising the commentary escaper against LinkedIn's
  documented grammar. No PHP runtime is installed in this environment, so the file could not be
  linted and no post was published against a live LinkedIn app — the end-to-end checks in the plan
  (posting as a profile and as a page, the four media shapes, `CONNECTIONS` visibility, delete)
  still need to be run against a real account.

## 2.14.0 - 2026-08-25

**Added**

- `TikTokProvider` with `Concerns/{ManagesOAuth,ManagesRateLimit,ManagesResources,ManagesTikTokVideoUpload}`.
  This lands the OAuth foundation and the publishing flow together, because the provider class did
  not exist yet and publishing has nowhere to live without it.
- OAuth 2.0 with PKCE. The code verifier and a CSRF `state` are stashed in the session across the
  redirect. **TikTok's `code_challenge` is the hex-encoded SHA-256 digest, not the base64url one
  RFC 7636 specifies** — it advertises `S256` either way, and a base64url challenge fails at the
  token step with a bare `invalid_request`. The authorization code also arrives percent-encoded and
  is decoded before the exchange.
- `refreshAccessToken()`. TikTok access tokens live 24 hours, so an account connected today stops
  working tomorrow without it; refresh tokens last 365 days and are rotated on every refresh.
  Expiry is stored as an absolute UTC timestamp, the shape `tokenIsAboutToExpire()` reads.
- Video publishing through the Content Posting API: initialise, PUT the file in `Content-Range`
  chunks, then poll `/status/fetch/` until `PUBLISH_COMPLETE`. Success is only reported when TikTok
  says the post exists — a finished upload is not a published video, and treating it as one would
  mark posts published that TikTok later discards for duration, format or spam reasons.
- Chunk size is computed from the file rather than fixed: anything up to 64 MB goes as a single
  chunk (which is what TikTok wants for files under its 5 MB minimum), and larger files are cut
  into 64 MB pieces with the final one absorbing the remainder.
- `getCreatorInfo()` and a `mixpost.accounts.tiktokCreatorInfo` endpoint. TikTok's posting rules are
  per creator, not per app, so `creator_info/query` runs before every publish and the post is
  checked against the answer — privacy level, video duration — before a byte is uploaded. The post
  composer reads the same data to build the audience dropdown from the creator's real
  `privacy_level_options` and to grey out interaction toggles the creator has already switched off.
- Four post options: `privacy_level`, `disable_comment`, `disable_duet`, `disable_stitch`. Per
  TikTok's content-sharing guidelines the audience has no default and must be chosen explicitly;
  publishing refuses an empty value rather than picking one. Where the creator has comments, Duet or
  Stitch switched off, publishing forces the flag on regardless of what was saved.
- An unaudited-app warning in the composer and on the publish result. An app that has not passed
  TikTok's content posting audit can only post `SELF_ONLY`, and every step still reports success —
  the video simply exists where nobody can see it.

**Notes**

- Text-only and photo posts are rejected before any API call, as are files over 4 GB and media that
  is only a URL. TikTok photo posts are a separate product with their own approval.
- `deletePost()` returns OK without calling TikTok. The Content Posting API cannot delete a
  published video — only the creator can, in the app — matching how `FacebookPageProvider` and
  `MastodonProvider` handle the same gap.
- `getAccount()` requests only `user.info.basic` fields, so no handle is stored and
  `externalPostUrl()` returns `#`. Asking for `username` needs the `user.info.profile` scope, and a
  field the token does not cover fails the whole call rather than being omitted.
- Duration is checked against `max_video_post_duration_sec` only when ffmpeg is installed and the
  file is on a local disk; probing remote media would mean downloading it twice. Otherwise TikTok's
  own `duration_check_failed` is surfaced with a readable message.
- `getRateLimitUsage()` returns fixed defaults. TikTok publishes fixed per-endpoint quotas and sends
  no rate-limit headers to read, so a made-up remaining count would make the scheduler back off for
  nothing. A real 429 is still honoured.
- TikTok returns `{"error": {"code": ...}}` with HTTP 200 on some failures, so `buildResponse()`
  checks the body's error code as well as the status line; without that, successes and failures are
  indistinguishable.

## 2.13.0 - 2026-08-25

**Added**

- `LinkedInProvider` with the usual `Concerns/{ManagesOAuth,ManagesRateLimit,ManagesResources}`
  layout. LinkedIn uses OAuth 2.0 authorization code: a redirect to
  `linkedin.com/oauth/v2/authorization`, then a form POST to `.../accessToken`.
- Member **and** company page selection. `getEntities()` returns the signed-in member from
  `/v2/userinfo` alongside every organization they administer from `/v2/organizationAcls`, so
  `onlyUserAccount` is `false` and the callback lands on the existing entity picker.
- Entity ids are stored as LinkedIn URNs (`urn:li:person:…`, `urn:li:organization:…`) because every
  publish call names its author by URN. Which kind an account is lands in `data.type`, since
  posting as a page differs from posting as a member.
- A `state` parameter on the authorization URL, generated per attempt and compared with
  `hash_equals` on return. No other provider does this; LinkedIn's flow makes it cheap, and without
  it a crafted callback URL could attach someone else's LinkedIn account to the install.
- Token expiry is stored as an absolute UTC timestamp under `expires_in`, which is the shape
  `SocialProvider::tokenIsAboutToExpire()` reads. `refresh_token` is stored only when LinkedIn
  issues one — it only does so for apps approved for programmatic refresh.
- `storeLinkedins()` on `StoreProviderEntitiesAsAccounts`, so picking entities actually saves them.
  One member token authorises both member and page posting, so unlike Facebook there is no
  per-entity token to merge in.
- A LinkedIn section in `README.md` covering the three required app products and the callback URL.

**Notes**

- The organization scopes require LinkedIn's **Community Management API** product. If the app is
  not approved for it, LinkedIn rejects the entire authorization rather than dropping the scopes —
  the token-exchange error message now says so. Once connected, a 403 from the organization call is
  treated as a normal setup and the member entity is still returned rather than failing the
  connection.
- `getRateLimitUsage()` returns fixed defaults. LinkedIn does not send rate-limit headers on
  ordinary responses — quotas are daily and only visible in the developer portal — so there is
  nothing to parse, and a made-up remaining count would make the scheduler back off for no reason.
  A real 429 is still honoured, using `Retry-After` when present.
- `publishPost()` is a placeholder that returns an error. LinkedIn publishing is the next step; it
  fails loudly rather than marking posts as published.

## 2.12.0 - 2026-08-25

**Added**

- Instagram publishing. `ManagesInstagramResources::publishPost()` implements the container flow —
  create a media container, poll `status_code` until `FINISHED`, then call `media_publish` — for
  single images, single videos (as `REELS`, which is what Meta now requires for standalone video)
  and carousels of 2–10 items. Carousels create one child container per item with
  `is_carousel_item=true`, then a `CAROUSEL` parent that carries the caption. Every call is
  authorised with the linked Page's access token. Polling reuses `Util::performTaskWithDelay`,
  waiting roughly 2 minutes for an image and 13 for a video before giving up with a message that
  says so.
- A `share_to_feed` post option on `InstagramProvider`, on by default, controlling whether a Reel
  also appears in the profile grid.
- Two guards that turn the integration's most common failures into readable errors instead of
  opaque Graph responses: a text-only post is rejected before any API call, and media whose URL
  resolves to `localhost` or a bare IP is rejected with an explanation that Instagram downloads
  media by URL and therefore needs a publicly reachable host.

**Fixed**

- Instagram inherited `MetaProvider`'s Facebook Page post limits, so the composer allowed 5000
  characters and a GIF. `InstagramProvider` now has its own `postConfigs()` reading the
  `social_provider_options.instagram` config block — 2200 characters, 10 photos, 1 video, no GIFs.

**Notes**

- The composer still cannot mark media as *required*, only as a minimum that the text may satisfy
  instead, so the text-only case is caught at publish time rather than in the editor.
- `post_type` (Feed / Reel) was considered and left out. Meta folded standalone video posts into
  Reels, so a video is always `media_type=REELS` and an image can never be a Reel — the field would
  not have changed any request.

## 2.11.0 - 2026-08-24

**Added**

- Vue layer support for Instagram, LinkedIn, TikTok and YouTube. Each has a brand icon, an entry in
  the `ProviderIcon` map, brand colours in `app.css` and all three maps in `useProviderClassesColor`,
  and an Add-account button on the Accounts page. Before this a connected account of any of these
  networks rendered as a blank tile with no icon and no colour.
- Credential forms for LinkedIn, TikTok and YouTube on the Services page, each with its own tab.
  Instagram has no form because it uses the Facebook credentials.
- A note on the Facebook service form that the same credentials also enable Instagram. The shared
  Meta app is not obvious from the UI and is the most common point of confusion in this setup.

**Notes**

- The Add-account buttons for LinkedIn, TikTok and YouTube will fail after redirecting to the
  network until their provider classes land. Instagram is gated on the `facebook` service being
  active rather than an `instagram` one, since it shares the Meta app.
- Character counting needed no change. `usePostCharacterLimit.getTextLength()` special-cases only
  Mastodon and Twitter; the four new networks all count plain characters and fall through to the
  existing default branch.

## 2.10.0 - 2026-08-24

**Added**

- Credential services for LinkedIn, TikTok and YouTube. Each stores a client id and secret, and is
  registered in `ServiceManager`. All three override `name()` because the default would derive
  `linked_in`, `tik_tok` and `you_tube` from the class names, which do not match the provider keys.
  Instagram deliberately has no service of its own — it authenticates through the same Meta app as
  Facebook and reads the `facebook` configuration.
- The unconfigured-service alert on the Accounts page now names LinkedIn, TikTok and YouTube.
  Without this it would have shown an empty warning box, since it flags any inactive service but
  only had wording for Facebook and Twitter.

## 2.9.0 - 2026-08-24

**Added**

- Registry and config wiring for Instagram, LinkedIn, TikTok and YouTube. `SocialProviderManager`
  now lists all four and has a `connect` method for each, and `config/mixpost.php` carries their
  character and media limits. Instagram authenticates through the same Meta app as Facebook Pages.
  LinkedIn, TikTok and YouTube are skipped by the registry until their provider classes land.

**Fixed**

- Connecting an Instagram account threw "Provider [instagram] not supported" because
  `SocialProviderManager` had no `connectInstagramProvider()` method.

**Removed**

- The dead `facebook_group` provider. It had config, an account button, an icon mapping, a preview
  mapping and a reports panel, but no provider class behind any of it. Meta's Groups API no longer
  allows third-party publishing to groups, so it cannot be implemented.

## 2.8.0 - 2026-08-24

**Added**

- Per-network post options. Post versions now store an `options` JSON column, providers declare the
  fields they accept via `postOptions()`, and the post composer renders them in a collapsible
  "Post options" panel below the editor. No provider declares options yet.

**Fixed**

- `usePostMediaLimit` crashed when reading a post option type off a version that had no options.

## 2.7.0 - 2026-08-24

**Added**

- Instagram account connection through Meta Graph. Instagram Business/Creator accounts linked to a
  Facebook Page are discovered in the account picker and stored with their Page access token.
  Publishing is not enabled yet.

## 2.6.0 - 2026-03-16

**Added**

- Support for the X pay-as-you-go tier

## 2.5.0 - 2026-02-24

**Added**

- Add Facebook API v25.0 support

## 2.4.0 - 2025-11-13

**Miscellaneous**

- Added support for Facebook Graph API v24.0
- Migrated fonts from ttf to woff2 for better performance

## v2.3.0 - 2025-06-06

**Miscellaneous**

- Added support for Facebook Graph API v23.0

## v2.2.0 - 2025-05-28

**Changes**

- Show the status of FFmpeg on the status page.

**Miscellaneous**

- Upload video without FFmpeg
- Support Laravel 12
- Upgraded to Tailwind CSS 4

## v2.1.3 - 2025-03-06

**Fixes**

- Fixed non-registered `PruneTemporaryDirectory` command

## v2.1.2 - 2025-02-21

**Fixes**

- Fixed video thumbnail generation when no keyframe is available at the requested time.

## v2.1.1 - 2025-02-12

**Fixed**

- Fixed URL weight in the post text for Mastodon

## v2.1.0 - 2025-01-29

**Changes**

- Added support for Facebook API v22.0

## v2.0.1 - 2025-01-20

**Fixes**

- Fixed style of media file name

## v2.0.0 - 2025-01-18

**New features**

- Added email notification for broken social account connections
- Begin processing analytics immediately after connecting a social account.
- Added system status page
- Added system logs page

**Fixes**

- Fixed filter by tags for calendar
- Fixed issue with X video/GIF media uploads
- Fixed issue with deleting temporarily downloaded media for the Facebook platform

**Changes**

- Service support status "Active/Inactive"
- Shows media file title
- Improved performance Emoji picker render
- Improved post validator
- Optimized schedule commands
- Improve editor and media layout for post page
- `uuid` instead `id` of resource in the URL

**Miscellaneous**

- Support Laravel 11
- Support Facebook API v21.0
- Support Carbon 3
- New command and schedule for pruning the temporary media directory.

## v1.7.2 - 2024-08-16

**Miscellaneous**

- Removed Unsplash doc link.

## v1.7.1 - 2024-06-14

**Fixes**

- Fixed Facebook service version validation.
- Fixed confirmation process for removing post versions.

## v1.7.0 - 2024-06-06

**Changes**

- Support `v20` for Facebook API

**Miscellaneous**

- Expanded compatibility of the post keyword filter with additional database versions

## v1.6.0 - 2024-05-09

**Fixes**

- Fixed media file item in the Safari browser
- Calendar Month: Fix timezone with (-)
- Calendar Month: Get posts for prev&next month

**Changes**

- Changed documentation URL

**Miscellaneous**

- Support `s3` disk for X (Twitter)

## v1.5.2 - 2024-04-12

**Fixes**

- Fixed pasting empty lines to the editor
- Fixed Mastodon post count of text characters
- Fixed loading media files in the calendar

**Changes**

- Renamed Twitter to X (icon changed)

**Miscellaneous**

- Improved design

## v1.5.1 - 2024-04-05

**Miscellaneous**

- Removed Facebook deprecated`page_engaged_users` metric
- Improved design CSS

## v1.5.0 - 2024-03-05

### Added

- Added support for Facebook v19.0

### Fixed

- Fixed image media library in Safari

### Miscellaneous

- Optimized posts query

## v1.4.0 - 2023-12-02

- Added Unsplash trigger download Job
- Added Unsplash credit attributes
- Optimize provider preview checking video format
- Fixed vulnerability on download media
- Fixed media library item width

**Internal Changes**

- Modified value of `external_media_terms` config

## v1.3.2 - 2023-08-15

- Fix Services when the form is undefined (Mastodon)

## v1.3.1 - 2023-07-12

- Fix logout redirect away

## v1.3.0 - 2023-06-29

- Refractory Services class
- Put Jobs into the social provider folder
- Add `clear-services-cache` command
- Fix modal z-index
- Fix alert configured service
- Fix missing day value of audience report

## v1.2.0 - 2023-05-31

- Twitter API Refactory
- Support Facebook API v.17 (added "business_management" scope)
- Add a "docs" link to the service form
- Add User menu in the sidebar
- Support update profile information
- Support update password
- Social Providers' Rate-Limit optimized
- Customize the error page
- Link to the post on the social platform
- Improve post validation
- Improve preview post
- Improve connecting account entities
- Fix calendar posts order
- Fix account long name
- Fix post tag creation
- Show the current version in the footer of the sidebar
- Prefill the body on creating a post by URL ?body=the text
- Add minor improvements and bug fixes

## v1.1.3 - 2023-03-18

Fix & prevent errors when the app tries to decrypt data with a new key.

## v1.1.2 - 2023-03-16

- Changed the text of the installation command
- Fix Progressbar
- Optimize the scope of the Facebook provider

## v1.1.1 - 2023-03-12

Fix Calendar Month on Safari browser.

## v1.1.0 - 2023-03-09

- Upgraded to Inertia v1
- Fix Mastodon upload media
- Added new command **php artisan mixpost:create-mastodon-app {server}** for a Mastodon server
- Load Google font locally (EU GDPR)
- Fix responsive for page Services
- Added scroll on mobile for list services on page Services
- Fix the post media list on the Safari browser

## v1.0.2 - 2023-03-06

Fixed searching posts by keyword

## v1.0.1 - 2023-03-02

Dashboard Analytics
Posts
Calendar
Media Library
Accounts
Settings
Services

## v1.0.0 - 2023-03-02

- Dashboard Analytics
- Posts
- Calendar
- Media Library
- Accounts
- Settings
- Services
