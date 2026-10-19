# Stage 5-b2.1 plan — settlement of expired permits in the admission store (#97, step 5, before 5-b3b)

> **Status:** PRE-REGISTRATION v1, written before any red test and before any line of the implementation. Base: `main@d6aea4d`. Input: the Owner's decision on LT-15 (comment on #147): option (3). Binding: `RUNTIME_IMPLEMENTATION_CONTRACT.md` §2.4, §3 row (b), §8.3, §8.4, §8.5; `SECURITY_FEASIBILITY.md` §2.3; the 5-b2 `PLAN.md` / `RESULTS.md`; DEC-008 Backward Compatibility 1–4, DEC-016, DEC-018.
> **This plan stops before the tests:** §3 raises three questions (LT-S1, LT-S2, LT-S3) that change the build. Nothing is built on them before they are answered. No gate may be weakened after a run; a detector that did not fire is "not evidenced".

## 1. Semantics (the assignment, literally, and nothing more)

An admission that reserved a permit and reached its `deadline` with no claimed outcome counts as **one failure** in the failure sets it reserved: the U net set `F` and the D8 set on P. Member = the admission subject, same source key and window as at reservation.

| Rule | Statement | Gate |
| --- | --- | --- |
| R1 | Never before `deadline`: a member is counted iff its deadline `≤ now` (caller time) | SETTLE-1 |
| R2 | Once per admission: membership is idempotent by member; a second reservation, a late claim or a repeated read never adds a second count | SETTLE-3 |
| R3 | A success claimed before `deadline` leaves no membership. An explicit failure counts immediately, as today | SETTLE-2 |
| R4 | A late outcome (after settlement) is `late` (the existing claim) and does **not** remove the membership | SETTLE-3 |
| R5 | Counters and the bucket are never touched by settlement: an expired permit is not refunded | SETTLE-4 |
| R6 | The meaning of `F` is unchanged: it counts net failures; a pending (not yet due) member is **not counted** | SETTLE-1 |

## 2. Design (candidate of the assignment, with one deviation)

* **Lazy, inside the existing operations.** No scheduled job. `reserveAdmission` and `applyAdmissionOutcome` change; `claimAdmissionPermit` is unchanged (it already returns `late` from `deadline` and `unknown` from `deadline + grace`). One Lua script per operation, caller `now`, no `TIME`, relative TTLs, validation before the first write, a refusal writes nothing, previous generation read-only, `BackendFailureException` typed vs `RateLimiterException` for malformed state: exactly the 5-b2 rules.
* **Deviation from the "one key" candidate (reason first):** the `s` family **stays a Redis set** and a new family **`p` (pending)** is added, a zset whose member is scored by the permit `deadline`, under the same hash tag and the same logical key as its set. Reason: the merged 5-b2 tests are not to be edited (`git diff --diff-filter=M -- tests` empty) and they assert the `s` key as a set (`SCARD` in `AdmissionStoreNm7RedisTest`, `SMEMBERS` in the dumps, a set-typed fixture in NM-6). Changing `s` to a zset breaks them. The cost is one extra key per set scope that carries a permit; it is in the same slot and the same script, so atomicity is unchanged.
* **Counted size** of a set scope at a gate = `SCARD(s)` + the members of `p` with score `≤ now` that are not in `s`. A member is "existing" (not refused by the cap) iff it is in `s` or pending with score `≤ now`. The loop is bounded by the pending members of one source window, which are bounded by the counters that gate those admissions; stated, not assumed.
* **Reserve with a permit** (a permit is present ⇒ the request is a T1 admission) adds, for every `SET_SIZE` scope: if the member is in `s`: nothing; else `ZADD p LT deadline member` (an absent member is added, an existing one keeps the **earlier** deadline), `EXPIRE` set only when the key is created. No pruning, nothing refreshed, nothing written on a refusal (phase 3 only, after every gate). A reserve **without** a permit writes no pending member (T0 unchanged; the existing "reserve never writes the set" tests stay true).
* **Outcome.** `failure`: `SADD s`, `ZREM p` (the member is settled by the explicit failure). `success`: `SREM s` + `ZREM p` for a scope that applies the net rule (U); for a scope flagged "success keeps settled" (D8): `ZREM p` and **no** `SREM s` (a settled failure of the same account stays: "success neither counts nor refunds"). **In both cases `ZREM p` applies only to a pending member that has not fallen due (`score > now`): a success never removes a due pending member (Lead addition 2).**
* **Lifetime of `p` (Lead addition 1):** `ttlSeconds` of the scope, set when the key is created and never refreshed.
* **Reference store** (`AdmissionInMemoryReferenceStore`) gets the same semantics in the same change; the contract tests run on both.

## 3. Questions raised before the build

**LT-S1 — the `p` family (key-strategy change).** Adds `adm:p:{sha256(verifierClass \0 key)}` in `KEY_STRATEGY.md` §4.8 as reserved/dormant (LOCKED status rides 5-c). Alternative: `s` becomes a zset (breaks the merged tests, rejected above). *If unanswered: `p` is built.*

**LT-S2 — one optional trailing field on `AdmissionScopeDTO`** (`bool $successKeepsSettled = false`, valid on `SET_SIZE` only, `RateLimiterException` otherwise). It is needed because the D8 rule and the U net rule differ on success and the store receives only a scope DTO. **DEC-008 analysis:** BC1 (no existing signature changes): the constructor gains an optional trailing parameter, so every existing call is valid; BC2 (no existing implementer breaks): no interface method is added or changed, `AdmissionReservationStoreInterface` / `AdmissionPermitStoreInterface` are untouched; BC3 (defaults preserve behaviour): `false` is today's behaviour for every scope; BC4 (additive docs): CHANGELOG and Package Reference. The DTO is already `final readonly` and `@internal`-grade (it is built only by the future runtime). The assignment allows a new interface method only after an LT; this is **not** a method, but it is a shape, so it is raised. *If unanswered: the field is built.* Alternative with no new shape: none found (the same account in the D8 set cannot be both "cancelled by success" and "kept by success" without the store being told which rule applies).

**LT-S3 — a pending member must outlive its permit.** A set scope's `ttlSeconds` is its window lifetime; a pending member whose `deadline − now` exceeds the scope lifetime would vanish before it counts. **Rule:** a reserve that carries a permit and a `SET_SIZE` scope with `deadline − now > ttlSeconds` is a `RateLimiterException` before any write (`D ≤ W` is the contract's bound). A deadline at or before `now` is accepted (it is counted at once; used by the race gate). *If unanswered: this is built.*

**Limit stated, not hidden (R2 and the net rule).** One member per account and source: two admissions of the same account from the same source in one window share one pending member (the earlier deadline). A success claimed for **either** removes it on the U door (that is the existing net rule: "a later success of the same account from the same source removes it"). A dropped outcome of the other admission is therefore not counted. This is the net-failure semantics of DEC-025 D1, not a new loss; it is reported, not gated.

## 4. Values

None new. The permit `deadline` and `grace`, the set capacity and lifetime are request parameters, as in 5-b2. Test values: `D` 30 s, `G` 600 s (archive: `g1/scenarios/m05_host_modes.php:55-56`), `F` 4, scope lifetime 1200 s.

## 5. Gates (real Redis for the production store; the same contract fixtures on the reference store)

| Gate | Group | PASS | Detector (must fire) |
| --- | --- | --- | --- |
| **SETTLE-1** exact bound | `settle` | pending member, deadline `T`: at `now = T − 1` the set counts 0 and a new account is admitted at `F` seeded to `F−1`+pending; at `now = T` it counts 1 and, at `F` reached, a new account is refused | the store of `1c23b61` (merged 5-b2, run unmodified as `StudyOriginal`-style fixture: the same scenario against the old Lua) counts nothing at `now = T` and fails SETTLE-1 |
| **SETTLE-2** | `settle` | success before `deadline`: no membership at any later time (U); explicit failure: counted at once (as today); D8 flag: success removes a pending member and keeps a settled one | a store that ignores the flag removes the settled D8 member on success and fails |
| **SETTLE-3** once and late | `settle` | two later reservations and a late claim after settlement add no second count (cardinality stays 1); the late claim is `late` and the member stays | a store whose settlement is not idempotent (member keyed by permit id) counts 2 |
| **SETTLE-4** counters and bucket | `settle` | the full dump of counters and buckets before settlement equals the dump after the clock passes the deadline and after reads | a settlement that refunds fails |
| **NM-6 on the changed operations** | `nm6` | refusal writes nothing (dump before = after, `p` included); the previous generation is never written; no TTL refreshed; the ten malformed cases of 5-b2 plus `p` wrong type / `p` without expiry / `s` and `p` mismatch each give `RateLimiterException` with the dump unchanged | the 5-b2 original study store fails N4b / N5 as before (unchanged); the merged `1c23b61` store fails SETTLE-1 only and passes every other 5-b2 fixture |
| **NM-7 concurrency** | `nm7` | 16 and 64 processes, 3 rounds, reserves with a due permit on distinct members at `F` 4 with one seeded: exactly 3 admitted per round; the counted size never exceeds `F`; concurrent explicit failures and reserves lose no member | a non-atomic control (read the count, write later) admits more than 3 in at least one round |
| **S0 / 5-b2 parity** | — | every 5-b2 test and NM-6 fixture passes **unmodified**; `git diff --diff-filter=M --name-only d6aea4d -- tests` is empty; PHPStan max normal and `--prefer-lowest --prefer-stable`; full suite on Redis with `COMPOSER_PROCESS_TIMEOUT=0`; `composer format:check`; no constant for `D` / `G` / budgets in `src/` (grep shown) | — |

## 6. Documents in this PR

`RUNTIME_IMPLEMENTATION_CONTRACT.md` §2.4 (operations: the reserve adds pending members, the outcome flag, the settlement rule) and §3 row (b) ("expiry rule per permit" becomes implemented, R1–R6); a dated note in `SECURITY_FEASIBILITY.md` §2.3 at I9 pointing to OD-1 / LT-15 without rewriting history; `KEY_STRATEGY.md` §4.8 (`p` family, reserved/dormant); `CHANGELOG.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, `llms.txt`.

## 7. Order of commits

1. this plan; 2. tagged red tests and fixtures (reference-store parity included); 3. implementation (Lua + reference store); 4. documents; 5. `5b2.1/RESULTS.md` at the exact HEAD. Any change to a test after commit 2 is listed line by line with its reason. After merge, the PASS conditions of NM-10 and NM-5 are registered in the 5-b3b plan on the merged mechanism and the plan is frozen.

## 8. Rulings on #148 (frozen after this commit)

LT-S1, LT-S2 and LT-S3 are approved as recommended, with two mandatory additions: (1) the `p` key lifetime is the scope `ttlSeconds`, set at creation only and never renewed; (2) a success never removes a pending member that is due (`score ≤ now`), so R4 is guaranteed inside the store and tested in SETTLE-3. Both are reflected in §2. The plan is frozen; any later change is an amendment listed line by line.
