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

    private ?BindingLedger $ledger = null;

    /**
     * @param bool $joined true for a scope that extends the ownership of an existing call: it shares that call's token (so one crossing and
     *     one state cover every resource of the call), clears only its own records and never ends the token
     */
    public function __construct(
        public readonly CallToken $token,
        private ?ExecutionContextProviderInterface $lease,
        private readonly bool $joined = false,
    ) {}

    /** The bindings this scope created; undone when the scope ends and nobody else stands on them. */
    public function bindings(BindingLedger $ledger): void
    {
        $this->ledger = $ledger;
    }

    public function add(object $resource, SlotRecord $record): void
    {
        $this->taken[] = [$resource, $record];
        if ($this->joined) {
            $this->token->joins[] = [\WeakReference::create($resource), $record];
        }
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
        $mine = $this->records();
        if (! $this->joined) {
            // The owner ends: the reservations that joined it end first (weak references, so a collected resource is simply skipped).
            foreach (array_reverse($this->token->joins) as [$ref, $record]) {
                $resource = $ref->get();
                if ($resource !== null) {
                    OwnershipRegistry::clear($resource, $record);
                }
            }
            $this->token->joins = [];
        }
        foreach (array_reverse($this->taken) as [$resource, $record]) {
            OwnershipRegistry::clear($resource, $record);
        }
        $this->taken = [];
        if (! $this->joined) {
            $this->token->state = CallToken::RELEASED;
        } else {
            $this->token->joins = array_values(array_filter(
                $this->token->joins,
                static fn(array $join): bool => ! in_array($join[1], $mine, true),
            ));
        }
        if ($this->ledger !== null) {
            OwnershipRegistry::rollback($this->ledger);
            $this->ledger = null;
        }
        $this->lease = null;
    }

    public function lease(): ?ExecutionContextProviderInterface
    {
        return $this->lease;
    }
}
