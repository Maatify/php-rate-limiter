<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;

/**
 * Signals an explicitly classified operational persistence failure.
 *
 * Only infrastructure adapters may create this exception. Invalid input,
 * malformed package state, contract violations, and programming failures use
 * their own exception contracts and must not be translated into this type.
 */
final class BackendFailureException extends SystemMaatifyException implements RateLimiterExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }

    protected function defaultHttpStatus(): int
    {
        return 503;
    }

    protected function defaultIsRetryable(): bool
    {
        return true;
    }
}
