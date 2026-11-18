<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;

/**
 * What one acquiring call holds: its token, the records it created (exactly the slots it may clear), and a strong lease on the provider
 * of its domain until the last release. The registry and the token stay weak; the lease lives in the call frame.
 *
 * @internal
 */
final class CallScope
{
    /** @var list<array{object, SlotRecord}> */
    private array $taken = [];

    private bool $released = false;

    public function __construct(
        public readonly CallToken $token,
        private ?ExecutionContextProviderInterface $lease,
    ) {}

    public function add(object $resource, SlotRecord $record): void
    {
        $this->taken[] = [$resource, $record];
    }

    /** @return list<SlotRecord> */
    public function records(): array
    {
        return array_map(static fn(array $entry): SlotRecord => $entry[1], $this->taken);
    }

    /** Clears exactly the records this call created, in reverse order, only where the slot still holds that record. Idempotent. */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        foreach (array_reverse($this->taken) as [$resource, $record]) {
            OwnershipRegistry::clear($resource, $record);
        }
        $this->taken = [];
        $this->token->state = CallToken::RELEASED;
        $this->lease = null;
    }

    public function lease(): ?ExecutionContextProviderInterface
    {
        return $this->lease;
    }
}
