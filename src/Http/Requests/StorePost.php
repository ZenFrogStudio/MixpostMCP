<?php

namespace Inovector\Mixpost\Http\Requests;

use Inovector\Mixpost\Actions\CreatePost;
use Inovector\Mixpost\Models\Post;

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
