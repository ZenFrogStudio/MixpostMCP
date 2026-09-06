<?php

namespace Inovector\Mixpost\Mcp\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Inovector\Mixpost\Http\Requests\PostFormRequest;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Util;
use Laravel\Mcp\Request;

/**
 * Shared by the create_post and update_post tools, which take the same input and turn it into the
 * `versions` structure Mixpost stores.
 */
trait BuildsPostPayload
{
    /**
     * The post fields both tools accept.
     */
    protected function postSchema(JsonSchema $schema): array
    {
        return [
            'account_ids' => $schema->array()
                ->items($schema->integer())
                ->required()
                ->description('Accounts to post to. Ids come from list_accounts.'),
            'body' => $schema->string()
                ->required()
                ->description('The post text, used for every account unless overridden in `versions`.'),
            'thread' => $schema->array()
                ->items($schema->string())
                ->description('Follow-up posts, for networks that support threads. Each entry becomes the next post in the chain.'),
            'media_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Media to attach. Ids come from add_media_from_url.'),
            'tag_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Tags to label the post with. Ids come from list_tags.'),
            'versions' => $schema->array()
                ->items($schema->object([
                    'account_id' => $schema->integer()->required(),
                    'body' => $schema->string(),
                    'thread' => $schema->array()->items($schema->string()),
                    'media_ids' => $schema->array()->items($schema->integer()),
                    'options' => $schema->object(),
                ]))
                ->description('Per-account overrides, for tailoring the wording or media to one network. Anything left out falls back to the shared values above. `options` holds the per-network settings described by post_options in list_accounts.'),
            'date' => $schema->string()
                ->description('Date to publish on, YYYY-MM-DD, in your local timezone. Set this together with `time` to make the post schedulable.'),
            'time' => $schema->string()
                ->description('Time to publish at, HH:MM 24-hour, in your local timezone.'),
        ];
    }

    /**
     * Turn the flat tool input into the versions structure. Account id 0 is the shared version
     * every account falls back to; any other id replaces it for that one account.
     */
    protected function buildVersions(Request $request): array
    {
        $body = (string) $request->get('body', '');
        $mediaIds = $request->get('media_ids') ?? [];

        $versions = [[
            'account_id' => 0,
            'is_original' => true,
            'content' => $this->buildContent($body, $request->get('thread') ?? [], $mediaIds),
            'options' => [],
        ]];

        foreach ($request->get('versions') ?? [] as $override) {
            $versions[] = [
                'account_id' => (int) $override['account_id'],
                'is_original' => false,
                'content' => $this->buildContent(
                    $override['body'] ?? $body,
                    $override['thread'] ?? [],
                    $override['media_ids'] ?? $mediaIds,
                ),
                'options' => $override['options'] ?? [],
            ];
        }

        return $versions;
    }

    protected function buildContent(string $body, array $thread, array $mediaIds): array
    {
        $content = [['body' => $body, 'media' => array_map('intval', $mediaIds)]];

        foreach ($thread as $entry) {
            $content[] = ['body' => (string) $entry, 'media' => []];
        }

        return $content;
    }

    /**
     * Run the assembled payload through the same rules the composer posts against, so the two
     * cannot drift apart.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validatePayload(array $payload): void
    {
        Validator::make($payload, (new PostFormRequest)->rules())->validate();
    }

    /**
     * "Y-m-d H:i" in the user's local timezone, or null when the post is not being given a slot.
     */
    protected function scheduledAt(Request $request): ?string
    {
        return $request->get('date') && $request->get('time')
            ? $request->get('date').' '.$request->get('time')
            : null;
    }

    /**
     * Attaching an id that does not exist leaves a pivot row pointing at nothing, so check first.
     *
     * @return array<int, int>
     */
    protected function unknownAccountIds(array $accountIds): array
    {
        return array_values(array_diff($accountIds, Account::whereIn('id', $accountIds)->pluck('id')->all()));
    }

    /**
     * Networks reject an over-length post outright, and for a scheduled post that failure lands
     * hours later in a queue. Catching it here gives the agent something it can act on.
     *
     * @return array<int, string>
     */
    protected function characterLimitErrors(array $accountIds, array $versions): array
    {
        $original = collect($versions)->firstWhere('account_id', 0);
        $errors = [];

        foreach (Account::whereIn('id', $accountIds)->get() as $account) {
            $version = collect($versions)->firstWhere('account_id', $account->id) ?? $original;
            $limit = $account->postConfigs()['text_char_limit']['max']['default'] ?? null;

            if (! $limit || ! $version) {
                continue;
            }

            foreach ($version['content'] as $index => $item) {
                $length = mb_strlen(Util::removeHtmlTags($item['body']));

                if ($length > $limit) {
                    $part = $index === 0 ? 'The body' : 'Thread entry '.$index;
                    $errors[] = "$part is $length characters, over the $limit character limit for {$account->name} ({$account->provider}).";
                }
            }
        }

        return $errors;
    }
}
