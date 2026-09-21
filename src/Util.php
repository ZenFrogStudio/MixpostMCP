<?php

namespace OneMediaLabs\MixpostMcp;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use OneMediaLabs\MixpostMcp\Facades\Settings;

class Util
{
    public static function config(string $key, mixed $default = null)
    {
        return Config::get("mixpostmcp.$key", $default);
    }

    public static function isMixpostRequest(Request $request): bool
    {
        $path = 'mixpostmcp';

        return $request->is($path) ||
            $request->is("$path/*");
    }

    public static function convertTimeToUTC(string|DateTimeInterface|null $time = null, DateTimeZone|string|null $tz = null): Carbon
    {
        return Carbon::parse($time, $tz ?: Settings::get('timezone'))->utc();
    }

    public static function dateTimeFormat(Carbon $datetime, DateTimeZone|string|null $tz = null): string
    {
        $format = $datetime->year === now($tz)->year ? 'M j, '.self::timeFormat() : 'M j, Y, '.self::timeFormat();

        return $datetime->tz($tz ?: Settings::get('timezone'))->translatedFormat($format);
    }

    public static function timeFormat(): string
    {
        return Settings::get('time_format') == 24 ? 'H:i' : 'h:ia';
    }

    public static function removeHtmlTags($string): string
    {
        if (! $string) {
            return '';
        }

        $text = trim(strip_tags($string));

        return html_entity_decode($text);
    }

    /**
     * True only for an http(s) URL whose host is a real public name. This is the gate in front of
     * every server-side fetch of a user- or agent-supplied URL, so it has to hold against the usual
     * tricks: literal IPs, bracketed IPv6, and — the one that matters — a public-looking hostname
     * that resolves to something inside the network.
     */
    public static function isPublicDomainUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';

        if ($host === '' || ! in_array(strtolower($parsedUrl['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        // parse_url keeps the brackets on an IPv6 literal, which is why a plain IP check misses it.
        $host = trim($host, '[]');

        if (strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')) {
            return false;
        }

        // A literal address is refused outright. Anything else is resolved and every address it
        // maps to must be public — a name that points at 10.x or the cloud metadata range is the
        // whole reason this check exists.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolveHost($host);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        // A literal IP passed the range test, but we still only want hostnames here: a bare
        // public IP is never what a media URL looks like, and refusing it keeps the rule simple.
        return ! filter_var($host, FILTER_VALIDATE_IP);
    }

    /**
     * @return array<int, string>
     */
    protected static function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records
        )));
    }

    public static function getDatabaseDriver(?string $connection = null): string
    {
        $key = is_null($connection) ? Config::get('database.default') : $connection;

        return strtolower(Config::get('database.connections.'.$key.'.driver'));
    }

    public static function isMysqlDatabase(?string $connection = null): bool
    {
        return self::getDatabaseDriver($connection) === 'mysql';
    }

    public static function closeAndDeleteStreamResource(array $stream): void
    {
        if (is_resource($stream['stream'])) {
            fclose($stream['stream']);
        }

        if (isset($stream['temporaryDirectory'])) {
            $stream['temporaryDirectory']->delete();
        }
    }

    public static function performTaskWithDelay(callable $task, int $initialDelay = 15, int $maxDelay = 60, int $maxAttempts = 10)
    {
        $delay = $initialDelay;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $result = $task();

            if ($result !== null) {
                return $result;
            }

            sleep($delay);

            // Increase delay for the next iteration, maxing out at maxDelay
            $delay = min($delay * 2, $maxDelay);
            // Add a random jitter to the delay
            $delay += rand(-(int) ($delay * 0.1), (int) ($delay * 0.1));

            $attempt++;
        }

        return null;
    }

    public static function isFFmpegInstalled(): bool
    {
        $ffmpegPath = (string) Util::config('ffmpeg_path');
        $ffprobePath = (string) Util::config('ffprobe_path');

        // The Windows desktop build bundles ffmpeg.exe / ffprobe.exe, hence the optional suffix.
        return file_exists($ffmpegPath) &&
            file_exists($ffprobePath) &&
            preg_match('/ffmpeg(\.exe)?$/i', $ffmpegPath) &&
            preg_match('/ffprobe(\.exe)?$/i', $ffprobePath);
    }
}
