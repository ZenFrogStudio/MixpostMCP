<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use OneMediaLabs\MixpostMcp\Facades\SocialProviderManager;
use OneMediaLabs\MixpostMcp\Http\Requests\StoreProviderEntities;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Support\SocialProviderResponse;

class AccountEntitiesController extends Controller
{
    public function index(Request $request): RedirectResponse|Response
    {
        $providerName = $request->route('provider');

        if (! $request->session()->has('mixpost_callback_response')) {
            return redirect()->route('mixpostmcp.accounts.index');
        }

        $provider = SocialProviderManager::connect($providerName);

        $accessToken = $provider->requestAccessToken($request->session()->get('mixpost_callback_response'));

        if ($error = Arr::get($accessToken, 'error')) {
            return redirect()->route('mixpostmcp.accounts.index')
                ->with('error', $error);
        }

        $provider->setAccessToken($accessToken);

        /** @var SocialProviderResponse $response * */
        $response = $provider->getEntities();

        if ($response->hasError()) {
            return redirect()->route('mixpostmcp.accounts.index')
                ->with('warning', "It's something wrong. Try again.");
        }

        $existingAccounts = Account::select('provider', 'provider_id')->get();

        $entities = collect($response->context())->map(function ($entity) use ($providerName, $existingAccounts) {
            $entity['connected'] = (bool) $existingAccounts
                ->where('provider', $providerName)
                ->where('provider_id', $entity['id'])
                ->first();

            return $entity;
        })->sort(function ($account) {
            return $account['connected'];
        })->values();

        // A Collection is an object, so empty() was always false here and an account with nothing to
        // pick rendered an empty picker instead of saying so. A Google account with no YouTube
        // channel is the ordinary way to reach this.
        if ($entities->isEmpty()) {
            return redirect()->route('mixpostmcp.accounts.index')
                ->with('warning', 'The account has no entities.');
        }

        return Inertia::render('Accounts/AccountEntities', [
            'provider' => $providerName,
            'entities' => $entities,
        ]);
    }

    public function store(StoreProviderEntities $storeAccountEntities): RedirectResponse
    {
        $storeAccountEntities->handle();

        return redirect()->route('mixpostmcp.accounts.index');
    }
}
