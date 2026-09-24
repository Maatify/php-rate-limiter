<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Exception;

/** A bounded optimistic-concurrency retry budget was exhausted. */
final class RateLimitConcurrencyException extends RateLimiterException {}
