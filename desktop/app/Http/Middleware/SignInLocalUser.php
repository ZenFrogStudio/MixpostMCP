<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The desktop app is single-user and local-only, so there is no login screen:
 * whoever opens the window is signed in as the one local user.
 */
class SignInLocalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            Auth::login(User::first() ?? User::create([
                'name' => 'Me',
                'email' => 'me@mixpostmcp.local',
                'password' => Str::random(40),
            ]));
        }

        return $next($request);
    }
}
