<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;

/**
 * A context domain: the default Fiber domain or one provider instance. Whoever holds this object holds the provider strongly.
 *
 * @internal
 */
final class ContextDomain
{
    private function __construct(private readonly ?ExecutionContextProviderInterface $provider) {}

    public static function default(): self
    {
        return new self(null);
    }

    public static function forProvider(?ExecutionContextProviderInterface $provider): self
    {
        return new self($provider);
    }

    public function isDefault(): bool
    {
        return $this->provider === null;
    }

    public function provider(): ExecutionContextProviderInterface
    {
        return $this->provider ?? FiberContextProvider::instance();
    }

    public function rawProvider(): ?ExecutionContextProviderInterface
    {
        return $this->provider;
    }
}
