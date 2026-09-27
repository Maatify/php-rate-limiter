<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * Single public runtime contract returned by RateLimiterBuilder::build() (DEC-010).
 *
 * It extends the existing score-based runtime contract with the new simple
 * fixed-window throttling contract without changing any existing method
 * semantics. A composite runtime instance remains assignable to
 * RateLimiterRuntimeInterface for existing consumer code.
 */
interface CompositeRateLimiterRuntimeInterface extends RateLimiterRuntimeInterface, SimpleRateLimiterInterface {}
