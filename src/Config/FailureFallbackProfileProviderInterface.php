<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/** Declares the typed fallback profile for a reusable policy. */
interface FailureFallbackProfileProviderInterface
{
    public function getFailureFallbackProfile(): FailureFallbackProfile;
}
