<?php

namespace OneMediaLabs\MixpostMcp\Services;

use OneMediaLabs\MixpostMcp\Abstracts\Service;
use OneMediaLabs\MixpostMcp\Enums\ServiceGroup;

class LinkedInService extends Service
{
    public static function group(): ServiceGroup
    {
        return ServiceGroup::SOCIAL;
    }

    /**
     * The default derives `linked_in` from the class name.
     * The provider is registered as `linkedin`.
     */
    public static function name(): string
    {
        return 'linkedin';
    }

    public static function form(): array
    {
        return [
            'client_id' => '',
            'client_secret' => '',
        ];
    }

    public static function formRules(): array
    {
        return [
            'client_id' => ['required'],
            'client_secret' => ['required'],
        ];
    }

    public static function formMessages(): array
    {
        return [
            'client_id' => 'The Client ID is required.',
            'client_secret' => 'The Client Secret is required.',
        ];
    }
}
