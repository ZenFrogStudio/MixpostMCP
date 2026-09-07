<?php

namespace OneMediaLabs\MixpostMcp\Support;

use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Util;
use Spatie\TemporaryDirectory\TemporaryDirectory as BaseTemporaryDirectory;

class MediaTemporaryDirectory
{
    public static function create(): BaseTemporaryDirectory
    {
        return new BaseTemporaryDirectory(static::getTemporaryDirectoryPath());
    }

    public static function getParentTemporaryDirectoryPath()
    {
        return Util::config('temporary_directory_path') ?? storage_path('mixpostmcp-media/temp');
    }

    public static function getTemporaryDirectoryPath(): string
    {
        return self::getParentTemporaryDirectoryPath().DIRECTORY_SEPARATOR.Str::random(32);
    }
}
