<?php

namespace App\Support;

/**
 * A script that starts the MCP server (`artisan mcp:start mixpostmcp`) against this install's PHP,
 * code and app-data. Paths differ per machine, so the app rewrites it on every boot and the user
 * points Claude Desktop at it (Help → Copy Claude Desktop config).
 */
class McpLauncher
{
    public static function path(): string
    {
        $name = PHP_OS_FAMILY === 'Windows' ? 'mixpostmcp-mcp.cmd' : 'mixpostmcp-mcp.sh';

        return env('NATIVEPHP_USER_DATA_PATH').DIRECTORY_SEPARATOR.'mcp'.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * The JSON block the user pastes into claude_desktop_config.json.
     */
    public static function claudeDesktopConfig(): string
    {
        return json_encode(
            ['mcpServers' => ['mixpostmcp' => ['command' => self::path()]]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    public static function write(): void
    {
        $file = self::path();

        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, PHP_OS_FAMILY === 'Windows' ? self::cmd() : self::sh());
        @chmod($file, 0755);
    }

    /**
     * NativePHP hands the app its paths as NATIVEPHP_* / LARAVEL_* variables; the launcher replays
     * them so the MCP process finds the same database, storage and key file. The two runtime-only
     * ones (the API secret and URL of the running window) are left out: they are never written to disk.
     */
    private static function variables(): array
    {
        $variables = [];

        foreach (getenv() as $name => $value) {
            if (preg_match('/^(NATIVEPHP|LARAVEL)_/', $name) && ! in_array($name, ['NATIVEPHP_SECRET', 'NATIVEPHP_API_URL'])) {
                $variables[$name] = $value;
            }
        }

        return $variables;
    }

    /**
     * The command itself. The CA bundle is passed exactly as NativePHP passes it to every PHP
     * process, otherwise the bundled PHP cannot verify HTTPS (add_media_from_url).
     */
    private static function command(callable $quote): string
    {
        $parts = [$quote(PHP_BINARY)];

        foreach (['curl.cainfo', 'openssl.cafile'] as $ini) {
            if (ini_get($ini)) {
                $parts[] = '-d '.$quote($ini.'='.ini_get($ini));
            }
        }

        $parts[] = $quote(base_path('artisan'));
        $parts[] = 'mcp:start';
        $parts[] = 'mixpostmcp';

        return implode(' ', $parts);
    }

    private static function cmd(): string
    {
        // @echo off is mandatory: anything cmd.exe echoes would land in the MCP stdout stream.
        $lines = ['@echo off'];

        foreach (self::variables() as $name => $value) {
            $lines[] = 'set "'.$name.'='.$value.'"';
        }

        $lines[] = self::command(fn (string $s) => '"'.$s.'"');

        return implode("\r\n", $lines)."\r\n";
    }

    private static function sh(): string
    {
        $quote = fn (string $s) => "'".str_replace("'", "'\\''", $s)."'";
        $lines = ['#!/bin/sh'];

        foreach (self::variables() as $name => $value) {
            $lines[] = 'export '.$name.'='.$quote($value);
        }

        $lines[] = 'exec '.self::command($quote);

        return implode("\n", $lines)."\n";
    }
}
