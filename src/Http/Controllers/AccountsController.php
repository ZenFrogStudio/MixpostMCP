<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use OneMediaLabs\MixpostMcp\Actions\UpdateOrCreateAccount;
use OneMediaLabs\MixpostMcp\Concerns\UsesSocialProviderManager;
use OneMediaLabs\MixpostMcp\Enums\ServiceGroup;
use OneMediaLabs\MixpostMcp\Facades\ServiceManager;
use OneMediaLabs\MixpostMcp\Facades\SocialProviderManager;
use OneMediaLabs\MixpostMcp\Http\Resources\AccountResource;
use OneMediaLabs\MixpostMcp\Models\Account;

class AccountsController extends Controller
{
    use UsesSocialProviderManager;

    public function index(): Response
    {
        $socialServices = ServiceManager::services()->group(ServiceGroup::SOCIAL)->getNames();

        return Inertia::render('Accounts/Accounts', [
            'accounts' => AccountResource::collection(Account::latest()->get())->resolve(),
            'is_configured_service' => ServiceManager::isConfigured($socialServices),
            'is_service_active' => ServiceManager::isActive($socialServices),
            // A configured service is not enough to offer a network: the provider class has to
            // exist too. Offering one without the other is how the connect button became a fatal.
            'available_providers' => array_keys(SocialProviderManager::providers()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $account = Account::firstOrFailByUuid($request->route('account'));

        $connection = $this->connectProvider($account);

        $response = $connection->getAccount();

        if ($response->hasError()) {
            if ($response->isUnauthorized()) {
                return redirect()->back()->with('error', 'The account cannot be updated. Re-authenticate your account.');
            }

            return redirect()->back()->with('error', 'The account cannot be updated.');
        }

        (new UpdateOrCreateAccount)($account->provider, $response->context(), $account->access_token->toArray());

        return redirect()->back();
    }

    public function delete(Request $request): RedirectResponse
    {
        $account = Account::firstOrFailByUuid($request->route('account'));

        $connection = $this->connectProvider($account);

        if (method_exists($connection, 'revokeToken')) {
            $connection->revokeToken();
        }

        $account->delete();

        return redirect()->back();
    }
}
