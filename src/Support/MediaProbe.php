<?php

namespace Inovector\Mixpost\Support;

use FFMpeg\FFProbe;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Util;
use Throwable;

/**
 * How long a file runs and how big its frame is — the two properties every network writes its media
 * rules against, and the only ones Mixpost Live does not already record when a file is uploaded.
 *
 * Measuring is best effort. A file on a remote disk would have to be downloaded first, and ffmpeg is
 * an optional dependency, so `for()` returns null rather than guessing. Callers treat null as
 * "cannot tell" and let the post through: a network rejecting a file we could not read is a bad
 * outcome, but blocking a valid post because ffprobe is missing is a worse one.
 *
 * Video is measured with ffprobe, which costs a process launch. Images are measured with
 * getimagesize(), which reads a few bytes of header — so an image-only post never touches ffmpeg at
 * all. Either result is cached on the media row, because the same file is checked once per network
 * it is published to.
 */
class MediaProbe
{
    private function __construct(
        public readonly ?float $duration,
        public readonly ?int $width,
        public readonly ?int $height
    ) {}

    public static function for(Media $media): ?self
    {
        // The cache is read before anything else, so a file that has already been measured needs
        // neither a readable path nor a local disk.
        if ($cached = self::cached($media)) {
            return $cached;
        }

        // External media is a bare URL with no file behind it.
        if ($media->disk === 'external_media') {
            return null;
        }

        try {
            if (! $media->isLocalAdapter()) {
                return null;
            }

            $path = $media->getFullPath();

            if (! is_file($path)) {
                return null;
            }

            $probe = $media->isVideo() ? self::probeVideo($path) : self::probeImage($path);
        } catch (Throwable) {
            // A file we cannot read is not automatically a file the network cannot read, so this
            // never blocks a post on its own.
            return null;
        }

        if ($probe) {
            self::remember($media, $probe);
        }

        return $probe;
    }

    /**
     * Width divided by height: 0.8 for a 4:5 portrait, 1.91 for the widest landscape Instagram
     * takes. Null when the frame size could not be read, which is normal for an audio-only file.
     */
    public function aspectRatio(): ?float
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return $this->width / $this->height;
    }

    private static function probeVideo(string $path): ?self
    {
        if (! Util::isFFmpegInstalled()) {
            return null;
        }

        $ffprobe = FFProbe::create([
            'ffmpeg.binaries' => Util::config('ffmpeg_path'),
            'ffprobe.binaries' => Util::config('ffprobe_path'),
        ]);

        $duration = $ffprobe->format($path)->get('duration');
        $stream = $ffprobe->streams($path)->videos()->first();

        return new self(
            $duration !== null ? (float) $duration : null,
            $stream?->get('width') !== null ? (int) $stream->get('width') : null,
            $stream?->get('height') !== null ? (int) $stream->get('height') : null
        );
    }

    private static function probeImage(string $path): ?self
    {
        // Suppressed because getimagesize() warns rather than returning false on a file that is not
        // an image, and an unreadable file is already handled by returning null.
        $size = @getimagesize($path);

        if (! $size) {
            return null;
        }

        return new self(null, (int) $size[0], (int) $size[1]);
    }

    private static function cached(Media $media): ?self
    {
        $probe = data_get($media->data, 'probe');

        if (! is_array($probe) || ! $probe) {
            return null;
        }

        return new self(
            isset($probe['duration']) ? (float) $probe['duration'] : null,
            isset($probe['width']) ? (int) $probe['width'] : null,
            isset($probe['height']) ? (int) $probe['height'] : null
        );
    }

    private static function remember(Media $media, self $probe): void
    {
        // A media item built in memory has no row to write to.
        if (! $media->exists) {
            return;
        }

        $media->forceFill([
            'data' => array_merge((array) $media->data, [
                'probe' => array_filter([
                    'duration' => $probe->duration,
                    'width' => $probe->width,
                    'height' => $probe->height,
                ], fn ($value) => $value !== null),
            ]),
        ])->saveQuietly();
    }
}
