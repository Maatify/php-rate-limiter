<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Exception;

/**
 * Signals an explicitly classified operational persistence failure.
 *
 * Only infrastructure adapters may create this exception. Invalid input,
 * malformed package state, contract violations, and programming failures use
 * their own exception contracts and must not be translated into this type.
 */
final class BackendFailureException extends \RuntimeException {}
