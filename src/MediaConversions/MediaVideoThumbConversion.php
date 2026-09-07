<?php

namespace OneMediaLabs\MixpostMcp\MediaConversions;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use Illuminate\Support\Facades\File;
use OneMediaLabs\MixpostMcp\Abstracts\MediaConversion;
use OneMediaLabs\MixpostMcp\Support\MediaConversionData;
use OneMediaLabs\MixpostMcp\Support\MediaFilesystem;
use OneMediaLabs\MixpostMcp\Support\MediaTemporaryDirectory;

class MediaVideoThumbConversion extends MediaConversion
{
    protected float $atSecond = 0;

    public function getEngineName(): string
    {
        return 'VideoThumb';
    }

    public function canPerform(): bool
    {
        return $this->isVideo();
    }

    public function getPath(): string
    {
        return $this->getFilePathWithSuffix('jpg');
    }

    public function atSecond(float $value = 0): static
    {
        $this->atSecond = $value;

        return $this;
    }

    public function handle(): ?MediaConversionData
    {
        // Create & copy to temporary directory
        $temporaryDirectory = MediaTemporaryDirectory::create();

        $file = $temporaryDirectory->path($this->getFilepath());
        $thumbFilepath = $this->getFilePathWithSuffix('jpg', $file);

        MediaFilesystem::copyFromDisk($this->getFilepath(), $this->getFromDisk(), $file);

        // Convert
        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('mixpostmcp.ffmpeg_path'),
            'ffprobe.binaries' => config('mixpostmcp.ffprobe_path'),
        ]);

        $video = $ffmpeg->open($file);
        $duration = $ffmpeg->getFFProbe()->format($file)->get('duration');

        // Ensure $seconds is within valid bounds
        $seconds = ($duration > 0 && $this->atSecond > 0) ? min($this->atSecond, floor($duration)) : 0;

        $frame = $video->frame(TimeCode::fromSeconds($seconds));
        $frame->save($thumbFilepath);

        // Sometimes the frame is not saved, so we save it again with the first frame
        // This is a workaround for the issue
        if ($this->atSecond !== 0 && ! File::exists($thumbFilepath)) {
            $frame = $video->frame(TimeCode::fromSeconds(0));
            $frame->save($thumbFilepath);
        }

        // Copy
        MediaFilesystem::copyToDisk($this->getToDisk(), $this->getPath(), $thumbFilepath);

        // Delete temporary directory
        $temporaryDirectory->delete();

        return MediaConversionData::conversion($this);
    }
}
