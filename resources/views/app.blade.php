<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth overflow-x-hidden">
<head>
    <title inertia>MixpostMCP{{ config('app.name') ? ' - ' . config('app.name') : '' }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('/vendor/mixpostmcp/favicon.ico') }}">
    @routes
    {{ mixpostMcpAssets() }}
    @inertiaHead
    <script>
        // Desktop app only: a network's OAuth callback arrives as a mixpostmcp://callback/<provider>?…
        // deep link. Finish it on this same origin so the session that started the sign-in ends it.
        // `Native` exists only inside NativePHP; on a server this registers nothing.
        (function () {
            function onOpenedFromUrl(payload) {
                var raw = Array.isArray(payload) ? payload[0] : (payload && payload.url);
                var url;

                try { url = new URL(raw); } catch (e) { return; }

                if (url.protocol !== 'mixpostmcp:' || url.hostname !== 'callback' || !/^\/[a-z_]{1,32}$/.test(url.pathname)) {
                    return;
                }

                window.location.href = '/mixpostmcp/callback' + url.pathname + url.search;
            }

            function listen() {
                window.Native.on('Native\\Desktop\\Events\\App\\OpenedFromURL', onOpenedFromUrl);
            }

            if (window.Native) {
                listen();
            } else {
                window.addEventListener('native:init', listen);
            }
        })();
    </script>
</head>
<body class="font-sans">
@inertia
</body>
</html>
