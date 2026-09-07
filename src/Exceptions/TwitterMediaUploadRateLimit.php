<?php

namespace OneMediaLabs\MixpostMcp\Exceptions;

use Exception;

/**
 * Thrown when X rate limits a media upload.
 *
 * It is separate from a plain failure so `publishPost()` can turn it into an EXCEEDED_RATE_LIMIT
 * response, which releases the job back to the queue instead of marking the post as failed. On the
 * free tier INIT and FINALIZE share the 17-per-24-hours allowance with `POST /2/tweets`, so a
 * single video costs three requests and hitting the cap mid-upload is routine.
 */
class TwitterMediaUploadRateLimit extends Exception
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("X rate limit reached while uploading media. Next attempt in $retryAfter seconds.");
    }
}
