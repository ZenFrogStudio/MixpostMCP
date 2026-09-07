<?php

namespace OneMediaLabs\MixpostMcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use OneMediaLabs\MixpostMcp\MediaConversions\MediaImageResizeConversion;
use OneMediaLabs\MixpostMcp\MediaConversions\MediaVideoThumbConversion;
use OneMediaLabs\MixpostMcp\Models\Media;
use OneMediaLabs\MixpostMcp\Support\MediaUploader;
use OneMediaLabs\MixpostMcp\Util;

class MediaUploadFile extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', File::types($this->allowedTypes())->max($this->max())],
        ];
    }

    public function handle(): Media
    {
        return MediaUploader::fromFile($this->file('file'))
            ->path(now()->format('m-Y'))
            ->conversions([
                MediaImageResizeConversion::name('thumb')->width(430),
                MediaVideoThumbConversion::name('thumb')->atSecond(5),
            ])
            ->uploadAndInsert();
    }

    public function messages(): array
    {
        if (! $this->file('file')) {
            return [
                'file.required' => 'File is required',
            ];
        }

        $fileType = $this->isImage() ? 'image' : 'video';
        $max = $this->max() / 1024;

        return [
            'file.max' => "The $fileType must not be greater than {$max}MB.",
        ];
    }

    private function isImage(): bool
    {
        return Str::before($this->file('file')->getMimeType(), '/') === 'image';
    }

    private function isVideo(): bool
    {
        return Str::before($this->file('file')->getMimeType(), '/') === 'video';
    }

    private function isGif(): bool
    {
        return Str::after($this->file('file')->getMimeType(), '/') === 'gif';
    }

    private function max()
    {
        $max = 0;

        if (! $this->file('file')) {
            return $max;
        }

        if ($this->isImage()) {
            $max = config('mixpostmcp.max_file_size.image');
        }

        if ($this->isGif()) {
            $max = config('mixpostmcp.max_file_size.gif');
        }

        if ($this->isVideo()) {
            $max = config('mixpostmcp.max_file_size.video');
        }

        return $max;
    }

    private function allowedTypes(): array
    {
        return Util::config('mime_types');
    }
}
