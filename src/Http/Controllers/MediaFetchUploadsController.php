<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Http\Resources\MediaResource;
use OneMediaLabs\MixpostMcp\Models\Media;

class MediaFetchUploadsController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        $records = Media::latest('created_at')->simplePaginate(30);

        return MediaResource::collection($records);
    }
}
