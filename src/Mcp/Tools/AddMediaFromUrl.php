<?php

namespace Inovector\Mixpost\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inovector\Mixpost\MediaConversions\MediaImageResizeConversion;
use Inovector\Mixpost\MediaConversions\MediaVideoThumbConversion;
use Inovector\Mixpost\Support\File;
use Inovector\Mixpost\Support\MediaUploader;
use Inovector\Mixpost\Util;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Download an image or video from a public URL into the Mixpost media library and return its id, for use in the media_ids of create_post or update_post. Files cannot be uploaded directly over MCP, so this is the only way to attach media.')]
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

        $response = Http::timeout(60)->get($url);

        if (! $response->successful()) {
            return Response::error("Could not download [$url]. The server answered with HTTP {$response->status()}.");
        }

        $file = File::fromBase64(base64_encode($response->body()));

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
            ->path('mixpost/'.now()->format('m-Y'))
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
            Str::after($mimeType, '/') === 'gif' => config('mixpost.max_file_size.gif'),
            Str::before($mimeType, '/') === 'image' => config('mixpost.max_file_size.image'),
            Str::before($mimeType, '/') === 'video' => config('mixpost.max_file_size.video'),
            default => null,
        };
    }
}
