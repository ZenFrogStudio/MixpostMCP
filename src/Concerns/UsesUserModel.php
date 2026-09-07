<?php

namespace OneMediaLabs\MixpostMcp\Concerns;

use OneMediaLabs\MixpostMcp\Models\User;

trait UsesUserModel
{
    public static function getUserClass(): string
    {
        return config('mixpostmcp.user_model', User::class);
    }
}
