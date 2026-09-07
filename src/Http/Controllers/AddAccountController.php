<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use OneMediaLabs\MixpostMcp\Facades\SocialProviderManager;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class AddAccountController extends Controller
{
    public function __invoke(string $providerName): Response|RedirectResponse
    {
        // The accounts UI only offers providers the registry lists, but the route is reachable
        // directly. Answer an unavailable network with the accounts page and a message rather
        // than a 500.
        try {
            $provider = SocialProviderManager::connect($providerName);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('mixpostmcp.accounts.index')
                ->with('error', "The $providerName network is not available in this installation.");
        }

        $url = $provider->getAuthUrl();

        if (Request::inertia()) {
            return Inertia::location($url);
        }

        return redirect()->away($url);
    }
}
