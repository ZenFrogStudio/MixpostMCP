<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use OneMediaLabs\MixpostMcp\Enums\PostStatus;
use OneMediaLabs\MixpostMcp\Models\Post;

class DuplicatePostController extends Controller
{
    public function __invoke(Post $post): RedirectResponse
    {
        DB::transaction(function () use ($post) {
            $newPost = Post::create([
                'status' => PostStatus::DRAFT,
            ]);

            $newPost->accounts()->attach($post->accounts->pluck('id'));
            $newPost->tags()->attach($post->tags->pluck('id'));
            $newPost->versions()->createMany($post->versions->map(function ($version) {
                return [
                    'account_id' => $version->account_id,
                    'is_original' => $version->is_original,
                    'content' => $version->content,
                    'options' => $version->options,
                ];
            })->toArray());
        });

        return redirect()->route('mixpostmcp.posts.index');
    }
}
