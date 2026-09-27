<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Maintains the per-policy circuit-breaker state machine and transition signals.
 *
 * Normal backend failures are accepted only while CLOSED. Recovery is driven by
 * read-only health probes guarded by the store-owned atomic probe lease.
 */
class CircuitBreaker
{
    private const TRIP_THRESHOLD = 3;
    private const TRIP_WINDOW = 10;
    private const MIN_DEGRADED_DURATION = 300;
    private const MIN_HEALTHY_INTERVAL = 120;
    private const PROBE_LEASE = 120;
    private const RE_ENTRY_LIMIT = 2;
    private const RE_ENTRY_WINDOW = 1800;
    private const FAIL_CLOSED_DURATION = 600;

    /**
     * @param CircuitBreakerStoreInterface $store State persistence boundary.
     * @param FailureSignalEmitterInterface $emitter Transition notification sink.
     * @param ClockInterface $clock Source of timestamps used by the state machine.
     */
    public function __construct(
        private readonly CircuitBreakerStoreInterface $store,
        private readonly FailureSignalEmitterInterface $emitter,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Record a normal backend failure while CLOSED and trip after the locked
     * three-failures-in-ten-seconds threshold.
     *
     * Requests short-circuited by OPEN or HALF_OPEN are never sent here and are
     * deliberately ignored if a caller invokes this method directly.
     */
    public function reportFailure(string $policyName): void
    {
        $state = $this->loadState($policyName);
        if ($state->status !== FailureStateDTO::STATE_CLOSED) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $failures = array_values(array_filter(
            [...$state->failures, $now],
            static fn(int $timestamp): bool => $timestamp >= $now - self::TRIP_WINDOW,
        ));

        $status = FailureStateDTO::STATE_CLOSED;
        $openSince = $state->openSince;
        $lastSuccess = $state->lastSuccess;
        $reEntries = $state->reEntries;
        $failClosedUntil = $state->failClosedUntil;

        if (count($failures) >= self::TRIP_THRESHOLD) {
            $status = FailureStateDTO::STATE_OPEN;
            $openSince = $now;
            $lastSuccess = 0;
            [$reEntries, $failClosedUntil] = $this->enterOpen($policyName, $reEntries, $failClosedUntil, $now);
        }

        $this->saveState($policyName, new CircuitBreakerStateDTO(
            $status,
            $failures,
            $now,
            $openSince,
            $lastSuccess,
            array_values($reEntries),
            $failClosedUntil,
        ));
    }

    /**
     * Normal CLOSED success is intentionally not a recovery mechanism.
     *
     * OPEN and HALF_OPEN can only recover through attemptRecoveryProbe().
     */
    public function reportSuccess(string $policyName): void
    {
        $state = $this->loadState($policyName);
        if ($state->status !== FailureStateDTO::STATE_CLOSED) {
            return;
        }
    }

    /**
     * Attempt the next eligible read-only recovery probe.
     *
     * The callback is invoked only after the minimum degraded/healthy interval
     * and after atomic lease acquisition. Probe failures, including thrown health
     * checks, restart or re-enter the state machine without entering normal
     * request failure handling. The return value is true only when a HALF_OPEN
     * probe has closed the circuit and the current request may continue normally.
     *
     * @param callable():bool $probe Read-only backend health probe.
     * @throws RateLimiterException When an eligible probe lacks the lease capability.
     */
    public function attemptRecoveryProbe(string $policyName, callable $probe): bool
    {
        $state = $this->loadState($policyName);
        $probeStartedAt = $this->clock->now()->getTimestamp();

        // The persisted guard is authoritative inside the state machine too.
        // Check it before every recovery fast path and before the additive
        // capability/lease boundaries so a guarded policy cannot probe or
        // mutate state even when the caller bypasses Engine preflight.
        if ($state->failClosedUntil > $probeStartedAt) {
            return false;
        }

        if ($state->status === FailureStateDTO::STATE_CLOSED) {
            return true;
        }

        $eligible = match ($state->status) {
            FailureStateDTO::STATE_OPEN => $probeStartedAt - $state->openSince >= self::MIN_DEGRADED_DURATION,
            FailureStateDTO::STATE_HALF_OPEN => $probeStartedAt - $state->lastSuccess >= self::MIN_HEALTHY_INTERVAL,
            default => false,
        };

        if (! $eligible) {
            return false;
        }

        if (! $this->store instanceof CircuitBreakerProbeStoreInterface) {
            throw new RateLimiterException(
                'Circuit-breaker recovery requires CircuitBreakerProbeStoreInterface when a probe is eligible.',
            );
        }

        if (! $this->store->acquireProbeLease($policyName, $probeStartedAt, self::PROBE_LEASE)) {
            return false;
        }

        try {
            $healthy = $probe();
        } catch (\Throwable) {
            $healthy = false;
        }
        $transitionAt = $this->clock->now()->getTimestamp();

        if (! $healthy) {
            $this->recordProbeFailure($policyName, $state, $transitionAt);
            return false;
        }

        if ($state->status === FailureStateDTO::STATE_OPEN) {
            $this->saveState($policyName, new CircuitBreakerStateDTO(
                FailureStateDTO::STATE_HALF_OPEN,
                $state->failures,
                $state->lastFailure,
                $state->openSince,
                $transitionAt,
                $state->reEntries,
                $state->failClosedUntil,
            ));

            return false;
        }

        $this->saveState($policyName, new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_CLOSED,
            [],
            0,
            0,
            $transitionAt,
            $state->reEntries,
            $state->failClosedUntil,
        ));
        $this->emitter->emit(new FailureSignalDTO(FailureSignalDTO::TYPE_CB_RECOVERED, $policyName));

        return true;
    }

    /**
     * Return the summarized state consumed by failure-mode resolution.
     */
    public function getState(string $policyName): FailureStateDTO
    {
        $data = $this->loadState($policyName);
        $now = $this->clock->now()->getTimestamp();
        $isGuarded = $data->failClosedUntil > $now;

        return new FailureStateDTO(
            $data->status,
            count($data->failures),
            $data->lastFailure,
            $isGuarded
                || $data->status === FailureStateDTO::STATE_OPEN
                || $data->status === FailureStateDTO::STATE_HALF_OPEN,
        );
    }

    /**
     * Return whether the policy is inside the authoritative fail-closed guard.
     */
    public function isReEntryGuardViolated(string $policyName): bool
    {
        return $this->loadState($policyName)->failClosedUntil > $this->clock->now()->getTimestamp();
    }

    /**
     * Return the current guard TTL without replacing it with a fresh duration.
     */
    public function getReEntryGuardRemaining(string $policyName): int
    {
        $remaining = $this->loadState($policyName)->failClosedUntil - $this->clock->now()->getTimestamp();

        return max(0, $remaining);
    }

    private function recordProbeFailure(
        string $policyName,
        CircuitBreakerStateDTO $state,
        int $now,
    ): void {
        if ($state->status === FailureStateDTO::STATE_OPEN) {
            $this->saveState($policyName, new CircuitBreakerStateDTO(
                FailureStateDTO::STATE_OPEN,
                $state->failures,
                $state->lastFailure,
                $now,
                0,
                $state->reEntries,
                $state->failClosedUntil,
            ));

            return;
        }

        [$reEntries, $failClosedUntil] = $this->enterOpen(
            $policyName,
            $state->reEntries,
            $state->failClosedUntil,
            $now,
        );

        $this->saveState($policyName, new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            $state->failures,
            $state->lastFailure,
            $now,
            0,
            $reEntries,
            $failClosedUntil,
        ));
    }

    /**
     * Enter OPEN exactly once for the current transition and activate the guard
     * only when the third qualifying entry is observed.
     *
     * @param array<int, int> $reEntries
     * @return array{array<int, int>, int}
     */
    private function enterOpen(
        string $policyName,
        array $reEntries,
        int $failClosedUntil,
        int $now,
    ): array {
        $reEntries = array_values(array_filter(
            $reEntries,
            static fn(int $timestamp): bool => $timestamp >= $now - self::RE_ENTRY_WINDOW,
        ));
        $reEntries[] = $now;

        $this->emitter->emit(new FailureSignalDTO(FailureSignalDTO::TYPE_CB_OPENED, $policyName));

        if (count($reEntries) > self::RE_ENTRY_LIMIT && $failClosedUntil <= $now) {
            $failClosedUntil = $now + self::FAIL_CLOSED_DURATION;
            $this->emitter->emit(new FailureSignalDTO(FailureSignalDTO::TYPE_CB_RE_ENTRY_VIOLATION, $policyName));
        }

        return [$reEntries, $failClosedUntil];
    }

    private function loadState(string $policyName): CircuitBreakerStateDTO
    {
        return $this->store->load($policyName) ?? new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_CLOSED,
            [],
            0,
            0,
            0,
            [],
            0,
        );
    }

    private function saveState(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->store->save($policyName, $state);
    }
}
