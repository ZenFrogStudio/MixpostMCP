<?php

namespace OneMediaLabs\MixpostMcp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use OneMediaLabs\MixpostMcp\Concerns\UsesAuth;
use OneMediaLabs\MixpostMcp\Concerns\UsesUserModel;
use OneMediaLabs\MixpostMcp\Models\User;
use Symfony\Component\HttpFoundation\Response;

class Auth
{
    use UsesAuth;
    use UsesUserModel;

    public function handle(Request $request, Closure $next)
    {
        AuthFacade::shouldUse(self::getAuthGuardName());

        if (! auth()->check()) {
            return $this->redirect($request);
        }

        if (! Gate::allows('viewMixpostMcp')) {
            abort(403);
        }

        // TODO: find a better way to use the custom model instance
        if (! auth()->user() instanceof User) {
            $user = self::getUserClass()::make(auth()->user()->only('name', 'email'))->setAttribute('id', auth()->id());

            AuthFacade::setUser($user);
        }

        return $next($request);
    }

    protected function redirect(Request $request): JsonResponse|Response
    {
        if (! $request->expectsJson()) {
            $request->session()->put('url.intended', url()->current());

            return Inertia::location(route(config('mixpostmcp.redirect_unauthorized_users_to_route')));
        }

        return response()->json('Unauthenticated.', Response::HTTP_UNAUTHORIZED);
    }
}
