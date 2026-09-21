<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use Native\Desktop\Facades\Shell;
use OneMediaLabs\MixpostMcp\Facades\SocialProviderManager;
use OneMediaLabs\MixpostMcp\Http\Controllers\AddAccountController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces the package's Connect handler inside the desktop app.
 *
 * The package answers Connect by navigating the current window to the network's sign-in page. In
 * the app that window is the app itself, and Google (for one) refuses to sign in inside an embedded
 * browser, so the sign-in opens in the user's system browser instead. The network then sends the user
 * back through the relay page and the mixpostmcp:// deep link, which the layout's script finishes.
 */
class DesktopAddAccountController extends AddAccountController
{
    public function __invoke(string $providerName): Response|RedirectResponse
    {
        try {
            $provider = SocialProviderManager::connect($providerName);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('mixpostmcp.accounts.index')
                ->with('error', "The $providerName network is not available in this installation.");
        }

        $url = $provider->getAuthUrl();

        // The URL comes from a provider's own code, but openExternal hands whatever it gets to the OS,
        // so only ever pass it a web address.
        if (! str_starts_with($url, 'https://')) {
            return redirect()->route('mixpostmcp.accounts.index')
                ->with('error', "The $providerName network did not return a sign-in address.");
        }

        Shell::openExternal($url);

        return redirect()->route('mixpostmcp.accounts.index')
            ->with('success', 'Finish signing in to the network in your browser, then come back here.');
    }
}
