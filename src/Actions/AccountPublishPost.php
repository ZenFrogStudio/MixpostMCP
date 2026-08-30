<?php

namespace Inovector\Mixpost\Actions;

use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Support\PostContentParser;
use Inovector\Mixpost\Support\SocialProviderResponse;

class AccountPublishPost
{
    use UsesSocialProviderManager;

    public function __invoke(Account $account, Post $post): SocialProviderResponse
    {
        $parser = new PostContentParser($account, $post);

        $content = $parser->getVersionContent();

        if (empty($content)) {
            $errors = ['This account version has no content.'];

            $post->insertErrors($account, $errors);

            return new SocialProviderResponse(SocialProviderResponseStatus::ERROR, $errors);
        }

        $provider = $this->connectProvider($account);

        // The scheduled sweep renews tokens every thirty minutes, but a backed-up queue can still
        // hand this job a token that died while it waited. Renewing here closes that gap.
        if (method_exists($provider, 'refreshAccessToken') && $provider->tokenIsAboutToExpire()) {
            $provider->refreshAccessToken();
        }

        $response = $provider->publishPost(
            text: $parser->formatBody($content[0]['body']),
            media: $parser->formatMedia($content[0]['media']),
            params: $parser->getVersionOptions()
        );

        if ($response->hasError()) {
            $post->insertErrors($account, $response->context());

            return $response;
        }

        $post->insertProviderData($account, $response);

        return $response;
    }
}
