<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use OneMediaLabs\MixpostMcp\Builders\PostQuery;
use OneMediaLabs\MixpostMcp\Http\Requests\Calendar;
use OneMediaLabs\MixpostMcp\Http\Resources\AccountResource;
use OneMediaLabs\MixpostMcp\Http\Resources\PostResource;
use OneMediaLabs\MixpostMcp\Http\Resources\TagResource;
use OneMediaLabs\MixpostMcp\Models\Account;
use OneMediaLabs\MixpostMcp\Models\Tag;
use OneMediaLabs\MixpostMcp\Support\EagerLoadPostVersionsMedia;

class CalendarController extends Controller
{
    public function index(Calendar $request): Response
    {
        $request->handle();

        $posts = PostQuery::apply($request)->oldest('scheduled_at')->get();

        EagerLoadPostVersionsMedia::apply($posts);

        return Inertia::render('Calendar', [
            'accounts' => fn () => AccountResource::collection(Account::oldest()->get())->resolve(),
            'tags' => fn () => TagResource::collection(Tag::latest()->get())->resolve(),
            'posts' => fn () => PostResource::collection($posts)->additional([
                'filter' => [
                    'accounts' => Arr::map($request->get('accounts', []), 'intval'),
                ],
            ]),
            'type' => $request->type(),
            'selected_date' => $request->selectedDate(),
            'filter' => [
                'keyword' => $request->get('keyword', ''),
                'status' => $request->get('status'),
                'tags' => $request->get('tags', []),
                'accounts' => $request->get('accounts', []),
            ],
        ]);
    }
}
