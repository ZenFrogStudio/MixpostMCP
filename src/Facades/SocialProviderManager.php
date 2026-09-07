<?php

namespace OneMediaLabs\MixpostMcp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \OneMediaLabs\MixpostMcp\Contracts\SocialProvider connect(string $provider, array $values = [])
 * @method static \OneMediaLabs\MixpostMcp\Contracts\SocialProvider useAccessToken(array $token = [])
 * @method static array providers()
 *
 * @see \OneMediaLabs\MixpostMcp\Abstracts\SocialProviderManager
 */
class SocialProviderManager extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'MixpostMcpSocialProviderManager';
    }
}
