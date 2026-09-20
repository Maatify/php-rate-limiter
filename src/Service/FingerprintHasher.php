<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * Produces keyed, non-reversible fingerprints for derived limiter keys.
 */
class FingerprintHasher
{
    /**
     * @param string $secret Secret used by the HMAC operation.
     * @param string $algo Hash algorithm accepted by hash_hmac().
     */
    public function __construct(
        private readonly string $secret,
        private readonly string $algo = 'sha256',
    ) {}

    /**
     * Hash one normalized identity string for key derivation.
     */
    public function hash(string $input): string
    {
        return hash_hmac($this->algo, $input, $this->secret);
    }
}
