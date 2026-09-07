<?php

namespace OneMediaLabs\MixpostMcp\Mcp\Tools;

use OneMediaLabs\MixpostMcp\Models\Tag;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the tags available for labelling posts in MixpostMCP.')]
class ListTags extends Tool
{
    protected string $name = 'list_tags';

    public function handle(): Response
    {
        $tags = Tag::latest()->get()->map(fn (Tag $tag): array => [
            'id' => $tag->id,
            'name' => $tag->name,
        ]);

        return Response::json($tags);
    }
}
