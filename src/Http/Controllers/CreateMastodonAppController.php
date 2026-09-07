<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Http\Requests\CreateMastodonApp;

class CreateMastodonAppController extends Controller
{
    public function __invoke(CreateMastodonApp $createMastodonApp): Response
    {
        $createMastodonApp->handle();

        return response()->noContent();
    }
}
