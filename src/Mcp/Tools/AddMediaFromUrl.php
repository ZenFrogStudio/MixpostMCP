<?php

namespace OneMediaLabs\MixpostMcp\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\MediaConversions\MediaImageResizeConversion;
use OneMediaLabs\MixpostMcp\MediaConversions\MediaVideoThumbConversion;
use OneMediaLabs\MixpostMcp\Support\File;
use OneMediaLabs\MixpostMcp\Support\MediaUploader;
use OneMediaLabs\MixpostMcp\Util;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Download an image or video from a public URL into the MixpostMCP media library and return its id, for use in the media_ids of create_post or update_post. Files cannot be uploaded directly over MCP, so this is the only way to attach media.')]
class AddMediaFromUrl extends Tool
{
    protected string $name = 'add_media_from_url';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->required()
                ->description('Publicly reachable URL of the image or video. Private and local addresses are refused.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $url = (string) $request->get('url');

        // The same gate the media library applies to stock images. It rejects anything that is
        // not a public host, which is what keeps an agent from pulling files off the LAN.
        if (! Util::isPublicDomainUrl($url)) {
            return Response::error("The URL [$url] is not a public address, so it will not be fetched.");
        }

        // Streamed straight to a temp file. Reading a 200 MB video into a string and then base64
        // round-tripping it, the way the stock-image path does for small JPEGs, would hold three
        // to four copies in memory at once.
        $tempPath = tempnam(sys_get_temp_dir(), 'mixpostmcp-');

        app()->terminating(fn () => @unlink($tempPath));

        try {
            $response = Http::timeout(60)
                ->withOptions([
                    'sink' => $tempPath,
                    'allow_redirects' => [
                        'max' => 5,
                        // A public URL can 302 to a private one. Every hop gets the same gate.
                        'on_redirect' => function ($request, $response, $uri): void {
                            if (! Util::isPublicDomainUrl((string) $uri)) {
                                throw new \RuntimeException("Redirected to a non-public address [$uri].");
                            }
                        },
                    ],
                ])
                ->get($url);
        } catch (\Throwable $e) {
            return Response::error("Could not download [$url]. ".$e->getMessage());
        }

        if (! $response->successful()) {
            return Response::error("Could not download [$url]. The server answered with HTTP {$response->status()}.");
        }

        $file = File::fromPath($tempPath, basename(parse_url($url, PHP_URL_PATH) ?: '') ?: 'download');

        if (! in_array($file->getMimeType(), Util::config('mime_types'), true)) {
            return Response::error("Files of type [{$file->getMimeType()}] are not accepted. Allowed types: ".implode(', ', Util::config('mime_types')).'.');
        }

        if ($limit = $this->sizeLimitKb($file->getMimeType())) {
            $sizeKb = $file->getSize() / 1024;

            if ($sizeKb > $limit) {
                return Response::error(sprintf('That file is %.1fMB, over the %.0fMB limit for this type.', $sizeKb / 1024, $limit / 1024));
            }
        }

        $media = MediaUploader::fromFile($file)
            ->path('mixpostmcp/'.now()->format('m-Y'))
            ->conversions([
                MediaImageResizeConversion::name('thumb')->width(430),
                MediaVideoThumbConversion::name('thumb')->atSecond(5),
            ])
            ->uploadAndInsert();

        return Response::json([
            'id' => $media->id,
            'type' => $media->type(),
            'mime_type' => $media->mime_type,
            'url' => $media->getUrl(),
        ]);
    }

    /**
     * Mirrors the per-type caps the upload form applies, in kilobytes.
     */
    protected function sizeLimitKb(string $mimeType): ?int
    {
        return match (true) {
            Str::after($mimeType, '/') === 'gif' => config('mixpostmcp.max_file_size.gif'),
            Str::before($mimeType, '/') === 'image' => config('mixpostmcp.max_file_size.image'),
            Str::before($mimeType, '/') === 'video' => config('mixpostmcp.max_file_size.video'),
            default => null,
        };
    }
}
