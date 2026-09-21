<?php

use Illuminate\Http\Request;
use OneMediaLabs\MixpostMcp\Builders\PostQuery;
use OneMediaLabs\MixpostMcp\Database\Factories\PostVersionFactory;
use OneMediaLabs\MixpostMcp\Models\Media;
use OneMediaLabs\MixpostMcp\Models\Post;
use OneMediaLabs\MixpostMcp\Models\PostVersion;

/*
 * The two raw JSON queries in the package were written for MySQL. The suite runs on SQLite, which
 * is also what the desktop build runs on, so these prove the SQLite branch of each does what the
 * MySQL one always did.
 */

function postWithBodies(string ...$bodies): Post
{
    $post = Post::factory()->create();

    foreach ($bodies as $body) {
        PostVersionFactory::new()->create([
            'post_id' => $post->id,
            'content' => [['body' => $body, 'media' => []]],
        ]);
    }

    return $post;
}

function postsMatching(string $keyword): array
{
    return PostQuery::apply(Request::create('/', 'GET', ['keyword' => $keyword]))
        ->pluck('id')
        ->all();
}

it('finds a post by a keyword in any of its versions', function () {
    $wanted = postWithBodies('Launch day is here', 'Second draft, now about coffee');
    postWithBodies('Nothing relevant in this one');

    expect(postsMatching('coffee'))->toBe([$wanted->id])
        ->and(postsMatching('launch'))->toBe([$wanted->id]);
});

it('matches a keyword regardless of case', function () {
    $wanted = postWithBodies('Big Announcement Tomorrow');

    expect(postsMatching('ANNOUNCEMENT'))->toBe([$wanted->id]);
});

it('looks past the first entry of a thread', function () {
    $post = Post::factory()->create();

    PostVersionFactory::new()->create([
        'post_id' => $post->id,
        'content' => [
            ['body' => 'First message', 'media' => []],
            ['body' => 'Reply with the keyword pineapple', 'media' => []],
        ],
    ]);

    expect(postsMatching('pineapple'))->toBe([$post->id]);
});

it('returns nothing when no version mentions the keyword', function () {
    postWithBodies('Hello world');

    expect(postsMatching('goodbye'))->toBe([]);
});

it('finds the versions that reference a media id and ignores the rest', function () {
    $media = Media::factory()->create();
    $other = Media::factory()->create();

    $post = Post::factory()->create();

    $usesIt = PostVersionFactory::new()->create([
        'post_id' => $post->id,
        'content' => [['body' => 'with media', 'media' => [$media->id]]],
    ]);
    $usesItAsString = PostVersionFactory::new()->create([
        'post_id' => $post->id,
        'content' => [
            ['body' => 'first', 'media' => []],
            ['body' => 'second', 'media' => [(string) $media->id]],
        ],
    ]);
    PostVersionFactory::new()->create([
        'post_id' => $post->id,
        'content' => [['body' => 'without media', 'media' => [$other->id]]],
    ]);

    expect(PostVersion::hasMedia($media)->pluck('id')->all())
        ->toBe([$usesIt->id, $usesItAsString->id]);
});
