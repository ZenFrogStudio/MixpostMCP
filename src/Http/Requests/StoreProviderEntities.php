<?php

namespace OneMediaLabs\MixpostMcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OneMediaLabs\MixpostMcp\Actions\StoreProviderEntitiesAsAccounts as StoreProviderEntitiesAsAccountsAction;

class StoreProviderEntities extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
        ];
    }

    public function handle()
    {
        (new StoreProviderEntitiesAsAccountsAction)($this->route('provider'), $this->input('items'));
    }
}
