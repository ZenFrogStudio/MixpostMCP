<?php

use Illuminate\Support\Facades\Config;
use OneMediaLabs\MixpostMcp\Util;

/*
 * Util::isFFmpegInstalled() checks the configured binaries exist and are actually named ffmpeg
 * and ffprobe. The Windows desktop build bundles them as .exe, which the old check refused.
 */

function ffmpegBinaries(string $ffmpeg, string $ffprobe): void
{
    $dir = sys_get_temp_dir().'/mixpostmcp-ffmpeg-'.uniqid();
    mkdir($dir);

    touch("$dir/$ffmpeg");
    touch("$dir/$ffprobe");

    Config::set('mixpostmcp.ffmpeg_path', "$dir/$ffmpeg");
    Config::set('mixpostmcp.ffprobe_path', "$dir/$ffprobe");
}

it('accepts ffmpeg.exe and ffprobe.exe', function () {
    ffmpegBinaries('ffmpeg.exe', 'ffprobe.exe');

    expect(Util::isFFmpegInstalled())->toBeTrue();
});

it('still accepts the bare unix names', function () {
    ffmpegBinaries('ffmpeg', 'ffprobe');

    expect(Util::isFFmpegInstalled())->toBeTrue();
});

it('refuses a file that exists but is not ffmpeg', function () {
    ffmpegBinaries('convert.exe', 'ffprobe.exe');

    expect(Util::isFFmpegInstalled())->toBeFalse();
});

it('refuses a path that does not exist', function () {
    Config::set('mixpostmcp.ffmpeg_path', '/nowhere/ffmpeg.exe');
    Config::set('mixpostmcp.ffprobe_path', '/nowhere/ffprobe.exe');

    expect(Util::isFFmpegInstalled())->toBeFalse();
});
