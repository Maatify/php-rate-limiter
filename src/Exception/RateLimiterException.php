<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Exception;

use Maatify\Exceptions\Exception\Validation\InvalidArgumentMaatifyException;

/**
 * Signals invalid rate-limiter commands, policies, or configuration inputs.
 */
class RateLimiterException extends InvalidArgumentMaatifyException implements RateLimiterExceptionInterface {}
