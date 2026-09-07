<?php

namespace OneMediaLabs\MixpostMcp\Http\Requests;

use OneMediaLabs\MixpostMcp\Actions\CreatePost;
use OneMediaLabs\MixpostMcp\Models\Post;

class StorePost extends PostFormRequest
{
    public function handle(): Post
    {
        return (new CreatePost)(
            accounts: $this->input('accounts', []),
            tags: $this->input('tags') ?? [],
            versions: $this->input('versions'),
            localScheduledAt: $this->scheduledAt(),
        );
    }
}
