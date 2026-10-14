<?php

declare(strict_types=1);

// #110 OAAA — reference (in-memory, controlled clock) implementation of the EXPERIMENTAL OutcomeAdmissionStoreInterface that exists ONLY in
// the isolated candidate tree. Loaded by lib/oaaa.php on the OAAA arm only (on S0 the interface does not exist and this file is never read).
// Semantics mirror the two Lua scripts of the patched RedisFullCapabilityStore (validated against them by scenarios/a05_redis.php):
//   tier   {e: epoch id set at creation, u: units in use, exp: fixed expiry}; never refreshed
//   tuple  {fields: p|<tier> unresolved, f|<tier> recorded failures, e|<tier> epoch taken in; exp: fixed expiry}
//   credit {members (bounded), exp: fixed expiry}
// Every operation is one PHP call on one object (atomic in a single process); the multi-process atomicity proof is the Redis one (a06).

use Maatify\RateLimiter\Repository\OutcomeAdmissionStoreInterface;
use Maatify\RateLimiter\Exception\RateLimiterException;

final class OaaaStore extends K1Store implements OutcomeAdmissionStoreInterface
{
    /** @var array<string, array{e: string, u: int, exp: int}> */
    public array $tiers = [];

    /** @var array<string, array{fields: array<string, int|string>, exp: int}> */
    public array $tuples = [];

    /** @var array<string, array{m: array<string, true>, exp: int}> */
    public array $credits = [];

    /** lifetime log: one row per reserve / complete call */
    /** @var list<array<string, mixed>> */
    public array $oaaaLog = [];

    /** TEST-ONLY failure injection (scenario a04 F1): throw BackendFailureException from the two OAAA operations. */
    public bool $failOaaa = false;

    /** TEST-ONLY failure injection (scenario a04 F2): throw BackendFailureException from every correlation operation (whole-store outage). */
    public bool $failAll = false;

    private int $seq = 0;

    public function __construct(private readonly \Maatify\SharedCommon\Contracts\ClockInterface $oaaaClock, string $arm = 'A')
    {
        parent::__construct($oaaaClock, $arm);
    }

    public function resetSignals(): void
    {
        parent::resetSignals();
        $this->sig['oaaaGranted'] = 0;
        $this->sig['oaaaRefused'] = 0;
        $this->sig['oaaaPaired'] = 0;
        $this->sig['oaaaCompletions'] = 0;
        $this->sig['oaaaCancels'] = 0;
    }

    private function now(): int
    {
        return $this->oaaaClock->now()->getTimestamp();
    }

    private function units(string $key): int
    {
        $t = $this->tiers[$key] ?? null;

        return $t === null || $t['exp'] <= $this->now() ? 0 : $t['u'];
    }

    /** @return list<string> */
    private function creditMembers(string $key): array
    {
        $c = $this->credits[$key] ?? null;

        return $c === null || $c['exp'] <= $this->now() ? [] : array_keys($c['m']);
    }

    private function tupleAlive(string $key): bool
    {
        return isset($this->tuples[$key]) && $this->tuples[$key]['exp'] > $this->now();
    }

    private function outage(): void
    {
        if ($this->failAll) {
            throw new \Maatify\RateLimiter\Exception\BackendFailureException('injected correlation-store outage');
        }
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $this->outage();

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $this->outage();

        return parent::addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->outage();

        return parent::incrementWatchFlag($key, $ttlSeconds);
    }

    public function getWatchFlag(string $key): int
    {
        $this->outage();

        return parent::getWatchFlag($key);
    }

    /** @return array{tiers: array<string, mixed>, tuples: array<string, mixed>, credits: array<string, mixed>, seq: int} */
    public function exportOaaa(): array
    {
        return ['tiers' => $this->tiers, 'tuples' => $this->tuples, 'credits' => $this->credits, 'seq' => $this->seq];
    }

    /** @param array{tiers: array<string, mixed>, tuples: array<string, mixed>, credits: array<string, mixed>, seq: int} $state */
    public function importOaaa(array $state): void
    {
        $this->tiers = $state['tiers'];
        $this->tuples = $state['tuples'];
        $this->credits = $state['credits'];
        $this->seq = $state['seq'];
    }

    public function reserveAdmission(string $tupleKey, int $tupleTtlSeconds, array $tiers): bool
    {
        $this->outage();
        if ($this->failOaaa) {
            throw new \Maatify\RateLimiter\Exception\BackendFailureException('injected OAAA reservation backend failure');
        }
        if ($tupleTtlSeconds <= 0) {
            throw new RateLimiterException('Admission tuple TTL must be positive.');
        }
        foreach ($tiers as $tier) {
            $used = $this->units($tier['key']) + ($tier['previousKey'] === null ? 0 : $this->units($tier['previousKey']));
            $effective = $tier['cap'];
            if ($tier['creditKeys'] !== []) {
                $seen = [];
                foreach ($tier['creditKeys'] as $ck) {
                    foreach ($this->creditMembers($ck) as $m) {
                        $seen[$m] = true;
                    }
                }
                $effective = min($effective, count($seen));
            }
            if ($used >= $effective) {
                $this->sig['oaaaRefused'] = ($this->sig['oaaaRefused'] ?? 0) + 1;
                $this->oaaaLog[] = ['op' => 'reserve', 't' => $this->now(), 'granted' => false, 'tiers' => array_column($tiers, 'key'), 'refusedAt' => $tier['key']];

                return false;
            }
        }
        $now = $this->now();
        if (! $this->tupleAlive($tupleKey)) {
            $this->tuples[$tupleKey] = ['fields' => [], 'exp' => $now + $tupleTtlSeconds];
        }
        $epochId = $now . '.' . (++$this->seq);
        foreach ($tiers as $tier) {
            $k = $tier['key'];
            if (! isset($this->tiers[$k]) || $this->tiers[$k]['exp'] <= $now) {
                $this->tiers[$k] = ['e' => $epochId, 'u' => 0, 'exp' => $now + $tier['ttl']];
            }
            $this->tiers[$k]['u']++;
            $f = &$this->tuples[$tupleKey]['fields'];
            if (($f['e|' . $k] ?? null) !== $this->tiers[$k]['e']) {
                $f['e|' . $k] = $this->tiers[$k]['e'];
                $f['p|' . $k] = 0;
                $f['f|' . $k] = 0;
            }
            $f['p|' . $k] = (int) $f['p|' . $k] + 1;
            unset($f);
        }
        $this->sig['oaaaGranted'] = ($this->sig['oaaaGranted'] ?? 0) + 1;
        $this->oaaaLog[] = ['op' => 'reserve', 't' => $now, 'granted' => true, 'tiers' => array_column($tiers, 'key')];

        return true;
    }

    public function completeAdmission(string $tupleKey, string $outcome, array $tierKeys, int $refundFailures, array $creditKeys, string $creditMember, int $creditCap, int $creditTtlSeconds): array
    {
        $this->outage();
        if ($this->failOaaa) {
            throw new \Maatify\RateLimiter\Exception\BackendFailureException('injected OAAA completion backend failure');
        }
        if (! in_array($outcome, ['success', 'failure', 'cancel'], true) || $refundFailures < 0 || $creditCap <= 0 || $creditTtlSeconds <= 0) {
            throw new RateLimiterException('Invalid admission completion.');
        }
        $now = $this->now();
        $paired = [];
        $refunded = 0;
        if ($this->tupleAlive($tupleKey)) {
            $plan = [];
            foreach ($tierKeys as $k) {
                $f = $this->tuples[$tupleKey]['fields'];
                $p = (int) ($f['p|' . $k] ?? 0);
                $fl = (int) ($f['f|' . $k] ?? 0);
                if ($p <= 0 && $fl <= 0) {
                    continue;
                }
                $live = isset($this->tiers[$k]) && $this->tiers[$k]['exp'] > $now && $this->tiers[$k]['e'] === ($f['e|' . $k] ?? null);
                $refund = 0;
                if ($p > 0) {
                    $paired[] = $k;
                }
                if ($outcome === 'success') {
                    if ($p > 0) {
                        $p--;
                        $refund = 1;
                    }
                    $rf = min($fl, $refundFailures);
                    $refund += $rf;
                    $fl -= $rf;
                } elseif ($outcome === 'failure') {
                    if ($p > 0) {
                        $p--;
                        $fl++;
                    }
                } elseif ($p > 0) {
                    $p--;
                    $refund = 1;
                }
                if (! $live) {
                    [$p, $fl, $refund] = [0, 0, 0];
                }
                if ($refund > 0 && $this->tiers[$k]['u'] < $refund) {
                    throw new RateLimiterException('malformed admission tier');
                }
                $plan[] = [$k, $p, $fl, $refund];
            }
            foreach ($plan as [$k, $p, $fl, $refund]) {
                if ($refund > 0) {
                    $this->tiers[$k]['u'] -= $refund;
                    $refunded += $refund;
                }
                $this->tuples[$tupleKey]['fields']['p|' . $k] = $p;
                $this->tuples[$tupleKey]['fields']['f|' . $k] = $fl;
            }
        }
        if ($outcome === 'success') {
            foreach ($creditKeys as $ck) {
                if (! isset($this->credits[$ck]) || $this->credits[$ck]['exp'] <= $now) {
                    $this->credits[$ck] = ['m' => [], 'exp' => $now + $creditTtlSeconds];
                }
                if (! isset($this->credits[$ck]['m'][$creditMember]) && count($this->credits[$ck]['m']) < $creditCap) {
                    $this->credits[$ck]['m'][$creditMember] = true;
                }
            }
        }
        $this->sig['oaaaCompletions'] = ($this->sig['oaaaCompletions'] ?? 0) + 1;
        if ($paired !== []) {
            $this->sig['oaaaPaired'] = ($this->sig['oaaaPaired'] ?? 0) + 1;
        }
        if ($outcome === 'cancel') {
            $this->sig['oaaaCancels'] = ($this->sig['oaaaCancels'] ?? 0) + 1;
        }
        $this->oaaaLog[] = ['op' => 'complete', 't' => $now, 'outcome' => $outcome, 'paired' => count($paired), 'refunded' => $refunded];

        return $paired;
    }

    /** Alive OAAA state at $now: [tiers, tuples, credit sets, max credit members, max tier units, every alive key has a finite expiry]. */
    public function oaaaInventory(): array
    {
        $now = $this->now();
        $tiers = array_filter($this->tiers, static fn(array $t): bool => $t['exp'] > $now);
        $tuples = array_filter($this->tuples, static fn(array $t): bool => $t['exp'] > $now);
        $credits = array_filter($this->credits, static fn(array $c): bool => $c['exp'] > $now);
        $maxCredit = 0;
        foreach ($credits as $c) {
            $maxCredit = max($maxCredit, count($c['m']));
        }
        $maxTtl = 0;
        foreach ([$tiers, $tuples, $credits] as $family) {
            foreach ($family as $x) {
                $maxTtl = max($maxTtl, $x['exp'] - $now);
            }
        }

        return ['tiers' => count($tiers), 'tuples' => count($tuples), 'credits' => count($credits), 'maxCreditMembers' => $maxCredit, 'maxTierUnits' => $tiers === [] ? 0 : max(array_column($tiers, 'u')), 'maxTtl' => $maxTtl];
    }
}
