<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Exception;

/**
 * Signals that bounded optimistic-concurrency retries were exhausted.
 *
 * This is distinct from an ordinary stale storage snapshot, which is returned
 * as an unapplied mutation or transition so the caller may recompute safely.
 */
final class RateLimitConcurrencyException extends RateLimiterException {}
