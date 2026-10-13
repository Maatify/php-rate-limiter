# #110 OAAA — contract feasibility (Stage C)

**Status: STUDY / EVIDENCE ONLY.** Baseline `main@72f6dea8ab0729e63492b622e682e334c527c966`. This verdict is about what the design in
[`ARCHITECTURE.md`](ARCHITECTURE.md) needs from the package contracts. It is **not** an acceptance of the candidate; the security / fairness
result is in [`REPORT.md`](REPORT.md) and is judged against the pre-registered [`SECURITY_ENVELOPE.md`](SECURITY_ENVELOPE.md).

## 1. Verdict

**`FEASIBLE_WITH_OPTIONAL_CONTRACT_EXTENSION`**

OAAA needs exactly one additive, optional Store capability (two atomic operations) and an opt-in switch. It needs **no** change to
`RateLimitCommand`, `RateLimitContextDTO`, `RateLimitResultDTO`, `RateLimiterInterface`, the Builder, Redis key families of S0, or any existing
Store interface, and **no new Host call** for the documented `checkOnly → verify → recordFailure|recordSuccess` flow. A store without the
capability, a policy without the opt-in, `API_OVERUSE`, account-less contexts and `pre`-only Hosts keep S0 behaviour.

It is **not** `FEASIBLE_WITH_EXISTING_CONTRACTS`: §2 shows three required properties that no existing contract can express. It is **not**
`CONTRACT_CHANGE_REQUIRED` in the breaking sense: nothing existing changes meaning. Adopting it would still require the Owner's approval of a
new public Store contract (Owner direction point 5) — that approval is not requested or assumed here.

## 2. What the existing contracts cannot express (code facts)

| Required property | Existing contract | Why it is insufficient |
|---|---|---|
| Atomic all-or-nothing reservation over several keys (K1 spray scope + K2 churn scope + IPv6 macro scopes + day tier + global tiers) | `RateLimitStoreInterface::increment()` (line 26), `CorrelationStoreInterface::incrementWatchFlag()` (line 36), `CorrelationRotationStoreInterface::incrementWatchFlagAcrossRotation()` (line 40) | each is atomic on **one** counter (rotation: one writable counter + one read-only). Two calls are two transactions: the GNH5 over-spend (#115 G06 C4) is the measured consequence. |
| Returning a unit after a Host-verified success | none | no interface defines a decrement or refund; `increment()`'s `$amount` is not documented for negative values; WATCH flags are increment-only. Without a return path, every admitted attempt stays spent — that is GNH5 (successes and failures cost the same). |
| Gating a scope's headroom by the number of distinct accounts with a verified success, inside the same atomic step | `BoundedCorrelationStoreInterface::addDistinctBounded()` | can only read a cardinality by inserting; a read-then-reserve over two calls is not atomic, and inserting the requesting account would let every request credit itself. |
| A per-attempt identity linking `checkOnly` to its outcome | `RateLimitCommand` (lines 27–33) | no attempt / reservation identity. OAAA replaces it with a package-derived **tuple** identity (account + device + K1 + K2) and fungible counters, which needs no command change but needs the capability above to store per-tuple counters atomically with the tiers. |

## 3. The minimal extension (simulated entirely inside the study's isolated tree)

```php
interface OutcomeAdmissionStoreInterface            // optional, additive; discovered with instanceof like the existing capabilities
{
    /** all-or-nothing: one unit on every tier (cap, read-only previous generation, optional credit gate), or none */
    public function reserveAdmission(string $tupleKey, int $tupleTtlSeconds, array $tiers): bool;

    /** resolve one reservation of the tuple: failure | success (return + bounded same-tuple refund + credit) | cancel */
    public function completeAdmission(string $tupleKey, string $outcome, array $tierKeys, int $refundFailures,
        array $creditKeys, string $creditMember, int $creditCap, int $creditTtlSeconds): array;
}
```

* Study implementations: reference store `lib/oaaa_store.php`; official Redis adapter (isolated copy) two Lua scripts, validate-then-write so a
  malformed state never leaves a partial write (`patches/oaaa-candidate.patch`).
* Redis Cluster: the reservation touches several keys in one script; the official adapter is documented non-clustered. A clustered
  implementation would need hash-tagging all OAAA keys of a policy into one slot (and the global tiers make that slot hot). Not built.
* Opt-in (proposal, **not** built in the package): a new `PolicyCapabilityEnum` case, e.g. `OUTCOME_AWARE_ADMISSION`, declared by an
  authentication policy (DEC-008 extension model), so the default presets stay S0 until a Host opts in. In the study the switch is the
  environment variable `OAAA_ARM=OAAA`, read by the patched pipeline, which refuses to run without it (it can never run as S0).

### Migration / compatibility plan (paper only)

1. Ship the interface and the Redis implementation; no preset declares the capability → every deployment unchanged.
2. A Host opts in per policy. Old workers (no OAAA) and new workers on one Redis: old workers ignore OAAA keys and persist S0 blocks that
   new workers honour; measured in `a04` R2 / `a05` P4 (decisions and state are **not** identical to an S0-only fleet).
3. Rolling back: OAAA keys expire by TTL (≤ 86 400 s); S0 code never reads them. S0-family state written while OAAA ran differs from S0
   history (measured, `a04` R3/R4).
4. Custom stores: no capability → S0 (no exception).

## 4. What stays impossible even with the extension

* **Distinguishing identical streams.** A co-located attacker or an attacker with a few own accounts uses the same credit as legitimate users;
  the extension bounds that use (2 / 600 s, 6 / day per scope; 12 / 48 globally), it does not remove it.
* **The bound during an outage.** DEC-015 `DEGRADED_MODE` ALLOWs without consulting any store; the extension cannot change that without
  changing DEC-015 (out of scope; recorded UNPROVEN).
* **A single deployment-independent global cap.** The global tiers are per policy and environment; their correct size depends on deployment
  volume (Owner decision).
