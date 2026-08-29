<?php

namespace Inovector\Mixpost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Models\Account;

/**
 * Serves one TikTok creator's live posting constraints to the post composer.
 *
 * TikTok requires the audience dropdown to be built from `privacy_level_options` for the specific
 * creator being posted to, and requires interaction toggles the creator has switched off to be
 * greyed out. Neither is knowable from the static postOptions() schema, which is declared per
 * provider rather than per account — hence this call.
 *
 * Failures answer 200 with `error` set. The composer then keeps its fallback list and the
 * pre-publish validation in TikTokProvider catches anything wrong, which is a better outcome than
 * blocking the editor because TikTok was briefly unreachable.
 */
class TikTokCreatorInfoController extends Controller
{
    use UsesSocialProviderManager;

    protected array $privacyLevelLabels = [
        'PUBLIC_TO_EVERYONE' => 'Everyone',
        'MUTUAL_FOLLOW_FRIENDS' => 'Friends',
        'FOLLOWER_OF_CREATOR' => 'Followers',
        'SELF_ONLY' => 'Only me',
    ];

    public function __invoke(Account $account): JsonResponse
    {
        if ($account->provider !== 'tiktok') {
            abort(404);
        }

        $response = $this->connectProvider($account)->getCreatorInfo();

        if ($response->hasError()) {
            return response()->json([
                'error' => implode(' ', $response->context()),
            ]);
        }

        $constraints = $response->context();
        $levels = Arr::get($constraints, 'privacy_level_options', []);
        $levels = is_array($levels) ? $levels : [];

        return response()->json([
            'account_id' => $account->id,
            'creator_nickname' => Arr::get($constraints, 'creator_nickname'),
            'creator_username' => Arr::get($constraints, 'creator_username'),
            'privacy_level_options' => $this->labelledPrivacyLevels($levels),
            'comment_disabled' => (bool) Arr::get($constraints, 'comment_disabled', false),
            'duet_disabled' => (bool) Arr::get($constraints, 'duet_disabled', false),
            'stitch_disabled' => (bool) Arr::get($constraints, 'stitch_disabled', false),
            'max_video_post_duration_sec' => (int) Arr::get($constraints, 'max_video_post_duration_sec', 0),
            // The strongest signal available that the app has not passed TikTok's content posting
            // audit, which silently confines every post to the creator's own eyes.
            'private_only' => $levels === ['SELF_ONLY'],
        ]);
    }

    /**
     * Keeps TikTok's own ordering and drops nothing: an unrecognised level is passed through under
     * its raw name so a new TikTok audience type still reaches the dropdown.
     */
    protected function labelledPrivacyLevels(array $levels): array
    {
        $labelled = [];

        foreach ($levels as $level) {
            $labelled[$level] = $this->privacyLevelLabels[$level] ?? $level;
        }

        return $labelled;
    }
}
