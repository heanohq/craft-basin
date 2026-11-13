<?php

namespace heano\basin\api\exceptions;

/**
 * Basin rate-limited the request (HTTP 429).
 */
class RateLimitException extends ApiException
{
    public function __construct(
        string $message = '',
        int $code = 429,
        ?\Throwable $previous = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
