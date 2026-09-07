<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use OneMediaLabs\MixpostMcp\Facades\Settings;
use OneMediaLabs\MixpostMcp\Http\Requests\SaveSettings;
use OneMediaLabs\MixpostMcp\Support\TimezoneList;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings', [
            'settings' => Settings::all(),
            'timezone_list' => (new TimezoneList)->splitGroup()->list(),
        ]);
    }

    public function update(SaveSettings $saveSettings): RedirectResponse
    {
        $saveSettings->handle();

        return back();
    }
}
