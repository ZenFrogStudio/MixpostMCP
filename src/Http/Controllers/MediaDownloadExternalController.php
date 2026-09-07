<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Http\Requests\MediaDownloadExternal;
use OneMediaLabs\MixpostMcp\Http\Resources\MediaResource;

class MediaDownloadExternalController extends Controller
{
    public function __invoke(MediaDownloadExternal $downloadMedia): array
    {
        $media = $downloadMedia->handle();

        return MediaResource::collection($media)->resolve();
    }
}
