<?php

use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\MediaProbe;

beforeEach(function () {
    Storage::fake('public');

    // Nothing in this file should reach ffmpeg, and pointing both binaries at a path that does not
    // exist turns "an image never gets probed with ffprobe" into something a test can prove rather
    // than something a comment claims.
    config()->set('mixpost.ffmpeg_path', '/nonexistent/ffmpeg');
    config()->set('mixpost.ffprobe_path', '/nonexistent/ffprobe');
});

/**
 * A valid PNG of the given size, with no image data in it.
 *
 * getimagesize() reads the IHDR chunk and stops, so this is enough to measure — and it means these
 * tests do not need the GD extension to build a fixture.
 */
function pngOfSize(int $width, int $height): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NN', $width, $height)."\x08\x02\x00\x00\x00")
        .$chunk('IEND', '');
}

function storedImage(int $width, int $height): Media
{
    Storage::disk('public')->put('photo.png', pngOfSize($width, $height));

    return Media::factory()->create([
        'name' => 'photo.png',
        'mime_type' => 'image/png',
        'disk' => 'public',
        'path' => 'photo.png',
        'size' => 1024,
    ]);
}

it('measures an image without going anywhere near ffprobe', function () {
    $probe = MediaProbe::for(storedImage(1080, 1350));

    expect($probe->width)->toBe(1080)
        ->and($probe->height)->toBe(1350)
        ->and($probe->duration)->toBeNull()
        ->and($probe->aspectRatio())->toBe(0.8);
});

it('caches what it measured on the media row', function () {
    // The same file is checked once per network it is published to, so measuring it again for every
    // one of them would be a process launch each time for video.
    $media = storedImage(1200, 628);

    MediaProbe::for($media);

    expect($media->fresh()->data['probe'])->toBe(['width' => 1200, 'height' => 628]);
});

it('reads the cache without needing the file to still be there', function () {
    $media = mediaWithProbe('video/mp4', ['duration' => 42.5], ['path' => 'gone.mp4']);

    expect(MediaProbe::for($media)->duration)->toBe(42.5);
});

it('gives up rather than guessing when the file is external', function () {
    // External media is a bare URL with no file behind it. Returning null is what tells every rule
    // to let the post through and leave the verdict to the network.
    $media = Media::factory()->create([
        'name' => 'https://example.com/clip.mp4',
        'mime_type' => 'video/mp4',
        'disk' => 'external_media',
        'path' => 'https://example.com/clip.mp4',
    ]);

    expect(MediaProbe::for($media))->toBeNull();
});

it('gives up rather than guessing when the file is missing', function () {
    $media = Media::factory()->create([
        'name' => 'photo.png',
        'mime_type' => 'image/png',
        'disk' => 'public',
        'path' => 'never-uploaded.png',
    ]);

    expect(MediaProbe::for($media))->toBeNull();
});

it('has no aspect ratio when it could not read a frame size', function () {
    $media = mediaWithProbe('video/mp4', ['duration' => 10]);

    expect(MediaProbe::for($media)->aspectRatio())->toBeNull();
});
