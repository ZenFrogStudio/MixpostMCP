<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Actions\RedirectAfterDeletedPost;
use OneMediaLabs\MixpostMcp\Models\Post;

class DeletePostsController extends Controller
{
    public function __invoke(Request $request, RedirectAfterDeletedPost $redirectAfterPostDeleted): RedirectResponse
    {
        Post::whereIn('uuid', $request->input('posts'))->delete();

        return $redirectAfterPostDeleted($request);
    }
}
