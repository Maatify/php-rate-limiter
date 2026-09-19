<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Exception;

use Maatify\Exceptions\Exception\Validation\InvalidArgumentMaatifyException;

class RateLimiterException extends InvalidArgumentMaatifyException implements RateLimiterExceptionInterface
{
}
