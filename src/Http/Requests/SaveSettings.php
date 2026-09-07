<?php

namespace OneMediaLabs\MixpostMcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OneMediaLabs\MixpostMcp\Facades\Settings as SettingsFacade;
use OneMediaLabs\MixpostMcp\Models\Setting as SettingModel;

class SaveSettings extends FormRequest
{
    public function rules(): array
    {
        return SettingsFacade::rules();
    }

    public function handle(): void
    {
        $schema = SettingsFacade::form();

        foreach ($schema as $name => $defaultPayload) {
            $payload = $this->input($name, $defaultPayload);

            SettingModel::updateOrCreate(['name' => $name], ['payload' => $payload]);

            SettingsFacade::put($name, $payload);
        }
    }
}
