<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Produces keyed, non-reversible fingerprints for derived limiter keys.
 */
class FingerprintHasher
{
    /**
     * @param string $secret Non-blank secret used by the HMAC operation. Not
     *     trimmed or otherwise normalized: accepted surrounding whitespace is
     *     retained byte-for-byte and used as-is in every hash() call.
     * @param string $algo Hash algorithm accepted by hash_hmac().
     * @throws RateLimiterException When $secret is empty or whitespace-only.
     */
    public function __construct(
        private readonly string $secret,
        private readonly string $algo = 'sha256',
    ) {
        if (trim($secret) === '') {
            throw new RateLimiterException('Fingerprint secret must not be empty or whitespace-only.');
        }
    }

    /**
     * Hash one normalized identity string for key derivation.
     */
    public function hash(string $input): string
    {
        return hash_hmac($this->algo, $input, $this->secret);
    }
}
