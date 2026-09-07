<?php

use Illuminate\Support\Facades\Http;
use OneMediaLabs\MixpostMcp\Enums\PostStatus;
use OneMediaLabs\MixpostMcp\Mcp\MixpostServer;
use OneMediaLabs\MixpostMcp\Mcp\Tools\AddMediaFromUrl;
use OneMediaLabs\MixpostMcp\Mcp\Tools\CreatePost;
use OneMediaLabs\MixpostMcp\Mcp\Tools\GetPost;
use OneMediaLabs\MixpostMcp\Mcp\Tools\ListAccounts;
use OneMediaLabs\MixpostMcp\Mcp\Tools\ListPosts;
use OneMediaLabs\MixpostMcp\Mcp\Tools\SchedulePost;
use OneMediaLabs\MixpostMcp\Mcp\Tools\UpdatePost;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\Post;

beforeEach(function () {
    // laravel/mcp needs Laravel 12.41+, so on a testbench 9 run it will not have been installed.
    if (! class_exists(\Laravel\Mcp\Server::class)) {
        $this->markTestSkipped('laravel/mcp is not installed.');
    }
});

function twitterAccount(array $attributes = []): Account
{
    return Account::factory()->create(array_merge(['provider' => 'twitter'], $attributes));
}

it('lists connected accounts with the limits their network enforces', function () {
    twitterAccount(['name' => 'Test Handle']);

    MixpostServer::tool(ListAccounts::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Test Handle')
        // The character limit is the whole reason an agent is told to call this first.
        ->assertSee('text_char_limit');
});

it('creates a post as a draft', function () {
    $account = twitterAccount();

    MixpostServer::tool(CreatePost::class, [
        'account_ids' => [$account->id],
        'body' => 'Hello from an agent.',
    ])->assertOk()->assertHasNoErrors();

    $post = Post::firstOrFail();

    expect($post->status)->toBe(PostStatus::DRAFT)
        ->and($post->scheduled_at)->toBeNull()
        ->and($post->accounts()->pluck('mixpost_accounts.id')->all())->toBe([$account->id])
        ->and($post->versions()->first()->content[0]['body'])->toBe('Hello from an agent.');
});

it('stores a thread as separate content entries', function () {
    $account = twitterAccount();

    MixpostServer::tool(CreatePost::class, [
        'account_ids' => [$account->id],
        'body' => 'First.',
        'thread' => ['Second.', 'Third.'],
    ])->assertOk()->assertHasNoErrors();

    expect(Post::firstOrFail()->versions()->first()->content)
        ->toHaveCount(3)
        ->and(Post::firstOrFail()->versions()->first()->content[2]['body'])->toBe('Third.');
});

it('keeps a per-account override alongside the shared version', function () {
    $shared = twitterAccount();
    $tailored = twitterAccount();

    MixpostServer::tool(CreatePost::class, [
        'account_ids' => [$shared->id, $tailored->id],
        'body' => 'Shared wording.',
        'versions' => [
            ['account_id' => $tailored->id, 'body' => 'Tailored wording.'],
        ],
    ])->assertOk()->assertHasNoErrors();

    $versions = Post::firstOrFail()->versions()->get()->keyBy('account_id');

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->is_original)->toBeTrue()
        ->and($versions[0]->content[0]['body'])->toBe('Shared wording.')
        ->and($versions[$tailored->id]->is_original)->toBeFalse()
        ->and($versions[$tailored->id]->content[0]['body'])->toBe('Tailored wording.');
});

it('refuses an account id that does not exist', function () {
    // Attaching an unknown id would leave a pivot row pointing at nothing and the post would
    // silently never publish.
    MixpostServer::tool(CreatePost::class, [
        'account_ids' => [999],
        'body' => 'Nowhere to send this.',
    ])->assertHasErrors();

    expect(Post::count())->toBe(0);
});

it('refuses a body longer than the account allows', function () {
    $account = twitterAccount(['name' => 'Over Limit']);

    MixpostServer::tool(CreatePost::class, [
        'account_ids' => [$account->id],
        'body' => str_repeat('a', 300),
    ])->assertHasErrors();

    expect(Post::count())->toBe(0);
});

it('refuses to edit a post that has already been published', function () {
    $account = twitterAccount();
    $post = Post::factory()->create(['status' => PostStatus::PUBLISHED->value]);

    MixpostServer::tool(UpdatePost::class, [
        'uuid' => $post->uuid,
        'account_ids' => [$account->id],
        'body' => 'Too late.',
    ])->assertHasErrors();
});

it('reports a uuid it cannot find rather than failing opaquely', function () {
    MixpostServer::tool(GetPost::class, ['uuid' => 'not-a-real-uuid'])
        ->assertHasErrors()
        ->assertSee('not-a-real-uuid');
});

it('schedules a post far enough ahead to be reviewed', function () {
    $account = twitterAccount();
    $when = now()->addDay();

    MixpostServer::tool(CreatePost::class, [
        'account_ids' => [$account->id],
        'body' => 'Going out tomorrow.',
        'date' => $when->format('Y-m-d'),
        'time' => $when->format('H:i'),
    ])->assertOk()->assertHasNoErrors();

    $post = Post::firstOrFail();

    MixpostServer::tool(SchedulePost::class, ['uuid' => $post->uuid])
        ->assertOk()
        ->assertHasNoErrors();

    expect($post->fresh()->status)->toBe(PostStatus::SCHEDULED);
});

it('refuses to schedule inside the review window', function () {
    // The whole point of the lead time: nothing an agent queues can go out before a human
    // could plausibly see it in the calendar.
    config()->set('mixpostmcp.mcp.min_schedule_lead_minutes', 30);

    $account = twitterAccount();
    $when = now()->addMinutes(5);

    MixpostServer::tool(CreatePost::class, [
        'account_ids' => [$account->id],
        'body' => 'Trying to sneak this out.',
        'date' => $when->format('Y-m-d'),
        'time' => $when->format('H:i'),
    ])->assertOk();

    $post = Post::firstOrFail();

    MixpostServer::tool(SchedulePost::class, ['uuid' => $post->uuid])
        ->assertHasErrors()
        ->assertSee('30 minutes');

    expect($post->fresh()->status)->toBe(PostStatus::DRAFT);
});

it('refuses to schedule a post with no accounts attached', function () {
    $post = Post::factory()->create([
        'status' => PostStatus::DRAFT->value,
        'scheduled_at' => now()->addDay(),
    ]);

    MixpostServer::tool(SchedulePost::class, ['uuid' => $post->uuid])->assertHasErrors();
});

it('refuses to fetch media from an address that is not public', function () {
    Http::fake();

    MixpostServer::tool(AddMediaFromUrl::class, ['url' => 'http://192.168.1.10/payload.png'])
        ->assertHasErrors();

    // The guard has to run before the request, not after it.
    Http::assertNothingSent();
});

it('refuses a media type the library does not accept', function () {
    Http::fake(['*' => Http::response('%PDF-1.4 not an image', 200)]);

    MixpostServer::tool(AddMediaFromUrl::class, ['url' => 'https://example.com/report.pdf'])
        ->assertHasErrors();
});

it('reports a failed download instead of storing an empty file', function () {
    Http::fake(['*' => Http::response('', 404)]);

    MixpostServer::tool(AddMediaFromUrl::class, ['url' => 'https://example.com/missing.png'])
        ->assertHasErrors()
        ->assertSee('404');
});

it('filters posts by status', function () {
    $account = twitterAccount();

    $draft = Post::factory()->create(['status' => PostStatus::DRAFT->value]);
    $published = Post::factory()->create(['status' => PostStatus::PUBLISHED->value]);

    $draft->accounts()->attach($account->id);
    $published->accounts()->attach($account->id);

    MixpostServer::tool(ListPosts::class, ['status' => 'published'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee($published->uuid)
        ->assertDontSee($draft->uuid);
});
