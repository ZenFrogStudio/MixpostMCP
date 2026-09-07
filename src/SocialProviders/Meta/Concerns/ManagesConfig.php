<?php

namespace OneMediaLabs\MixpostMcp\SocialProviders\Meta\Concerns;

use OneMediaLabs\MixpostMcp\Facades\ServiceManager;
use OneMediaLabs\MixpostMcp\Services\FacebookService;

trait ManagesConfig
{
    public static function getApiVersionConfig(): string
    {
        $versions = FacebookService::versions();

        return ServiceManager::get('facebook', 'api_version') ?? current($versions);
    }
}
