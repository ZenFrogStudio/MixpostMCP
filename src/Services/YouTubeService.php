<?php

namespace Inovector\Mixpost\Services;

use Inovector\Mixpost\Abstracts\Service;
use Inovector\Mixpost\Enums\ServiceGroup;

class YouTubeService extends Service
{
    public static function group(): ServiceGroup
    {
        return ServiceGroup::SOCIAL;
    }

    /**
     * The default derives `you_tube` from the class name.
     * The provider is registered as `youtube`.
     */
    public static function name(): string
    {
        return 'youtube';
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
            'client_secret' => 'The Client secret is required.',
        ];
    }
}
