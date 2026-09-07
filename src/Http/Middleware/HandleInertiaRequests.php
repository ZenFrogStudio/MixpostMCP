<?php

namespace OneMediaLabs\MixpostMcp\Http\Middleware;

use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Middleware;
use OneMediaLabs\MixpostMcp\Concerns\UsesAuth;
use OneMediaLabs\MixpostMcp\Facades\Settings;
use OneMediaLabs\MixpostMcp\Http\Resources\UserResource;
use OneMediaLabs\MixpostMcp\Models\User;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    use UsesAuth;

    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'mixpostmcp::app';

    /**
     * Determine the current asset version.
     *
     * @return string|null
     */
    public function version(Request $request)
    {
        if (file_exists($manifest = public_path('vendor/mixpostmcp/manifest.json'))) {
            return md5_file($manifest);
        }

        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array
     */
    public function share(Request $request)
    {
        return array_merge(parent::share($request), [
            'auth' => $this->auth(),
            'ziggy' => function () use ($request) {
                return array_merge((new Ziggy)->toArray(), [
                    'location' => $request->url(),
                ]);
            },
            'flash' => function () use ($request) {
                return [
                    'success' => $request->session()->get('success'),
                    'warning' => $request->session()->get('warning'),
                    'error' => $request->session()->get('error'),
                    'info' => $request->session()->get('info'),
                ];
            },
            'app' => [
                'name' => Config::get('app.name'),
                'horizon_path' => Config::get('horizon.path'),
            ],
            'mixpostmcp' => [
                // Upstream's docs. The install and network-setup guides there still apply to this fork.
                'docs_link' => 'https://docs.mixpost.app',
                'version' => InstalledVersions::getVersion('onemedialabs/mixpostmcp'),
                'mime_types' => Config::get('mixpostmcp.mime_types'),
                'settings' => [
                    'timezone' => Settings::get('timezone'),
                    'time_format' => Settings::get('time_format'),
                    'week_starts_on' => Settings::get('week_starts_on'),
                ],
            ],
        ]);
    }

    protected function auth(): array
    {
        if (! self::getAuthGuard()->check()) {
            return [
                'user' => null,
            ];
        }

        $user = self::getAuthGuard()->user();

        // If `Auth Middleware` was not resolved first
        // return empty auth
        if (! $user instanceof User) {
            return [];
        }

        return [
            'user' => new UserResource($user),
        ];
    }
}
