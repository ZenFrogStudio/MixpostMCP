<?php

namespace OneMediaLabs\MixpostMcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OneMediaLabs\MixpostMcp\Models\Media;
use OneMediaLabs\MixpostMcp\Models\PostVersion;

class DeleteMedia extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
        ];
    }

    public function handle(): void
    {
        foreach ($this->input('items') as $id) {
            $media = Media::find($id);

            if (! $media) {
                continue;
            }

            $postVersions = PostVersion::hasMedia($media)->get();
            foreach ($postVersions as $postVersion) {
                $postVersion->removeMedia($media);
            }

            $media->deleteFiles();
            $media->delete();
        }
    }
}
