<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Http\Requests\MediaUploadFile;
use OneMediaLabs\MixpostMcp\Http\Resources\MediaResource;

class MediaUploadFileController extends Controller
{
    public function __invoke(MediaUploadFile $upload): MediaResource
    {
        return new MediaResource($upload->handle());
    }
}
