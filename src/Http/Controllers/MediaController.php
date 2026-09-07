<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use OneMediaLabs\MixpostMcp\Enums\ServiceGroup;
use OneMediaLabs\MixpostMcp\Facades\ServiceManager;
use OneMediaLabs\MixpostMcp\Http\Requests\DeleteMedia;

class MediaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Media', [
            'is_configured_service' => ServiceManager::isActive(
                ServiceManager::services()->group(ServiceGroup::MEDIA)->getNames()
            ),
        ]);
    }

    public function destroy(DeleteMedia $deleteMediaFiles): HttpResponse
    {
        $deleteMediaFiles->handle();

        return response()->noContent();
    }
}
