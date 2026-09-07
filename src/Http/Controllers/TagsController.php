<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Http\Requests\StoreTag;
use OneMediaLabs\MixpostMcp\Http\Requests\UpdateTag;
use OneMediaLabs\MixpostMcp\Models\Tag;

class TagsController extends Controller
{
    public function store(StoreTag $storeTag): RedirectResponse
    {
        $storeTag->handle();

        return redirect()->back();
    }

    public function update(UpdateTag $updateTag): RedirectResponse
    {
        $updateTag->handle();

        return redirect()->back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        Tag::where('uuid', $request->route('tag'))->delete();

        return redirect()->back();
    }
}
