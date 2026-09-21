<?php

namespace OneMediaLabs\MixpostMcp\Support;

use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Util;
use Spatie\TemporaryDirectory\TemporaryDirectory as BaseTemporaryDirectory;

class MediaTemporaryDirectory
{
    public static function create(): BaseTemporaryDirectory
    {
        return new class(static::getTemporaryDirectoryPath()) extends BaseTemporaryDirectory
        {
            /**
             * Media paths use forward slashes ("09-2026/abc.mp4") but Spatie finds the folder part
             * with DIRECTORY_SEPARATOR, so on Windows the sub-folder was never created and the copy
             * into it failed. Normalising the slashes is a no-op elsewhere.
             */
            public function path(string $pathOrFilename = ''): string
            {
                return parent::path(str_replace('/', DIRECTORY_SEPARATOR, $pathOrFilename));
            }
        };
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
