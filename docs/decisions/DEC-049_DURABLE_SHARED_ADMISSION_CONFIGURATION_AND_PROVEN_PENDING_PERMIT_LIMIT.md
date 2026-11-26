# DEC-049 — Optional Proven-Lane Pending-Permit Limit and Durable Shared Admission Configuration (successor of DEC-038)

## Decision ID

`DEC-049`

The carried provisions of `DEC-038` are restated intact with one Owner-approved architectural change (G3 `D4`): the registered shared configuration (`pcfg`) uses a single durable non-expiring key derived under `admissionStateSecret` (`DEC-048`) instead of outer-secret current/previous lookup.

## Status

`ACTIVE` — approved by the Project Owner on 2026-11-26 in [#284 comment 7185799202](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185799202) (G3 `D4` from [#284 comment 7185756131](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185756131)), superseding `DEC-038` in full under Decision Governance Standard §9.

## Date

2026-11-26

## Decision Authority / Deciders

**Project Owner**, explicit approval of G3 `D4` in [#284 comment 7185799202](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185799202) (formal reconciliation required by [#284 comment 7185756131](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185756131) §2 `D4` and §3). Original `DEC-038` authority (Owner, 2026-11-13, PR [#207](https://github.com/Maatify/php-rate-limiter/pull/207)) is preserved in the historical record [DEC-038](DEC-038_PROVEN_PENDING_PERMIT_LIMIT_AND_SHARED_ADMISSION_CONFIGURATION.md).

## Scope / Concern

An optional, Host-set bound on the permits one source holds at once on the proven lane (P) of a budget group, and the rule that every worker sharing one Redis holds one effective value of it, `"off"` included, persisted durably across arbitrary outer key-secret rotations.

## Context

`DEC-038` established the optional proven-lane pending-permit limit and required that every worker sharing one Redis agree on one effective value per budget group (`"off"` included), changed only by explicit operator rebind. However, `DEC-038` Decision 5 implemented persistence by reading the shared configuration under the rotating outer `keySecret` (current else previous). As proven by source inspection of `RedisAdmissionOperations` at `main@1d87036c` ([#284 comment 7185756131](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7185756131) `D4`), if two outer key-secret rotations complete without an accepted reservation promoting the registration into the intermediate generation, the registered value becomes invisible under both the current and previous outer keys, allowing a mismatched worker to silently register a different limit.

Under `DEC-048` (`D1`–`D4`), Admission introduces a stable `admissionStateSecret` and `admissionTrustDomain` for durable Admission state. Keying `pcfg` directly under `admissionStateSecret` fulfills `DEC-038`'s invariant ("workers never change the shared value; explicit rebind alone changes it") across any number of outer key-secret rotations without relying on accepted-reservation promotion.

## Decision

1. **The limit (carried from DEC-038).** `RateLimiterBuilder::withProvenPendingPermitLimit(int $limit)`, an integer from 1 to `9007199254740991` (`2^53 − 1`, `AdmissionBudgetsDTO::PROVEN_PENDING_PERMIT_LIMIT_MAX_DECIMAL`), compared by length and then lexicographically, never through a native integer; on 32-bit PHP the `int` argument is limited to `PHP_INT_MAX`, while the registered shared configuration accepts the full decimal range. A value outside the range is rejected by the method itself, before `build()` and before any Redis access. The limit applies per canonical source independently in a budget group on lane P. A permit is pending from its reservation until its outcome is claimed (success or failure) or its deadline `D` passes. A reservation over the limit is refused with the cause `source_pending_permits` and writes nothing.
2. **No default (carried from DEC-038).** The package supplies no value. Not calling the method means the option is disabled (`off`): the lane is bounded as before.
3. **Independent of failure counters (carried from DEC-038).** The slot is its own counter (`ppend`, member = derived permit record id per `DEC-048`, lifetime `D`). It never enters, and is never read by, the failure sets; a failure outcome frees the slot and enters `pfail` per `DEC-033`.
4. **Release (carried from DEC-038).** The slot is freed by the accepted outcome of either kind inside the atomic claim of `DEC-036`, once: a repeated outcome of the same permit is `permit_duplicate` or `permit_conflict` and applies nothing. After an outer key-secret rotation, the outcome frees the slot under the previous outer secret's `ppend` key as well, so a permit reserved before the rotation frees its slot after it. Expiry of the permit frees it without an outcome. Request preservation stays as `DEC-037` states.
5. **One durable effective value per budget group (amended by G3 D4).** All workers in the same `(cohort, env, authority)` sharing one Redis hold the same effective value, `off` included. Every reservation, on either lane, carries the worker's value in `AdmissionSharedConfigDTO($key, $value)` (with **no** `previousKey`), where `$key` is derived durably under `admissionStateSecret` over `(cohort, env, authority, budgetGroup, 'pcfg')` (`DEC-048` `D2`/`D4`) and wrapped physically via checked `KeyPreimageFraming::encodeTuple()` in `:adm:a1:cfg:` (`DEC-048` `D3`). The store compares the carried value with the registered one in the same script, before any gate and before any write, and raises `AdmissionConfigurationException` when they differ. The first accepted reservation of a group registers its value. Because the key depends only on the stable `admissionStateSecret` and trust context, the registration persists across any number of outer, fingerprint, or binding key rotations even with zero accepted reservations during those rotations.
6. **Workers never change the shared value (carried from DEC-038).** A mismatched worker fails; it does not overwrite. The value changes only through the explicit operator call `AdmissionSharedConfigControlInterface::rebindAdmissionSharedConfig($budgetGroup)`, which registers the calling worker's value at the durable `pcfg` key.
7. **A store that cannot hold it (carried from DEC-038).** `build()` refuses an active limit on a store that does not implement `SharedAdmissionConfigStoreInterface` (the consistency of the workers could not be proven). A disabled (`off`) worker on such a store is not checked.
8. **Relationship to DEC-034 and DEC-035 (carried from DEC-038 + 2026-11-14 correction note).** The optional Host-set pending-permit limit is the only admission bound a Host may set; every other budget and the permit timing remain package constants under `DEC-034` and `DEC-035`.

## Rationale

- Carrying all pending-permit semantics verbatim preserves the measured trade-offs and operator transition procedure of `DEC-038`.
- Moving `pcfg` from outer-secret current/previous keys to a single durable `admissionStateSecret` key removes the silent registration-loss gap across two outer rotations without idle-group traffic, eliminating `previousKey` on `AdmissionSharedConfigDTO` and simplifying the Redis `RESERVE` script while strengthening the shared-configuration invariant.
- Formal supersession of `DEC-038` satisfies Decision Governance Standard §9 without partial supersession or editing `DEC-038`'s historical decision text.

## Consequences

- **Coordinated value transitions (carried verbatim from DEC-038):** The five-step transition procedure (Pause → Isolate → Wait `T_pause + D` + clock skew → Rebind → Resume) and its guarantees for `off`→limit, limit→limit, and limit→`off` remain unchanged.
- **Outer key rotation:** Rotating `keySecret` (even twice or more with zero reservations) no longer risks losing the registered `pcfg` value. Re-establishing a fresh isolated cohort (`DEC-048` `D1`) starts with an unregistered `pcfg` in the new cohort.
- **Boundary honesty:** The store detects mismatches among workers sharing the same `(cohort, env, authority)` and Redis store; it does not claim detection of split configuration across separate stores or separate cohorts.
- **API delta:** `AdmissionSharedConfigDTO` drops `$previousKey` (`new AdmissionSharedConfigDTO(string $key, string $value)` and `AdmissionSharedConfigDTO::forLimit(string $key, ?int $limit)`).

## Relationship to Other Decisions

- **Supersedes `DEC-038`** in full (Decision Governance Standard §9).
- **Paired with `DEC-048`:** uses `DEC-048`'s `admissionStateSecret`, `cohort`, checked `KeyPreimageFraming`, and `:adm:a1:cfg:` layout.
- **Preserves `DEC-033`, `DEC-034`, `DEC-035`, `DEC-036`, `DEC-037`, `DEC-015`, `DEC-041`, `DEC-042`, `DEC-043`, `DEC-045`.**

## Canonical Contract / Current Owner

`RateLimiterBuilder::withProvenPendingPermitLimit()`, `AdmissionBudgetsDTO::$provenPendingPermitCap`, `AdmissionSharedConfigDTO`, `AdmissionRuntime`, `RedisAdmissionOperations`, `AdmissionSharedConfigControlInterface`, `docs/KEY_STRATEGY.md` §4.8, `docs/guides/USAGE_GUIDE.md`.

## Supersedes

`DEC-038`

## Superseded By

None.
