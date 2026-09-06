<?php

namespace Inovector\Mixpost\Http\Requests;

use Inovector\Mixpost\Actions\SavePost;
use Inovector\Mixpost\Models\Post;

class UpdatePost extends PostFormRequest
{
    public Post $post;

    public function withValidator($validator)
    {
        $this->post = Post::firstOrFailByUuid($this->route('post'));

        $validator->after(function ($validator) {
            if ($this->post->isInHistory()) {
                $validator->errors()->add('in_history', 'in_history');
            }

            if ($this->post->isScheduleProcessing()) {
                $validator->errors()->add('publishing', 'publishing');
            }
        });
    }

    public function handle(): void
    {
        (new SavePost)(
            post: $this->post,
            accounts: $this->input('accounts', []),
            tags: $this->input('tags') ?? [],
            versions: $this->input('versions'),
            localScheduledAt: $this->scheduledAt(),
        );
    }
}
