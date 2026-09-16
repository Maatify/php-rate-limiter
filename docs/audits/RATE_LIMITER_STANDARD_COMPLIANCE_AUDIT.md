# Rate Limiter Standard Compliance & Extraction Audit

## Status

**Verdict:** `NEEDS CHANGES`

This audit defines the extraction direction for the production-validated Rate Limiter core while preserving its proven runtime behavior.

The governing rule for all work that follows is:

```text
PRESERVE BY DEFAULT

A runtime change is allowed only for:

1. STANDARD COMPLIANCE
2. PROVEN HOST DECOUPLING
3. PROVEN DEFECT / CONTRACT BYPASS
```

No implementation should be redesigned merely because a different abstraction appears cleaner.

---

## 1. Audit baseline

Rate Limiter package baseline:

```text
Repository: Maatify/php-rate-limiter
Branch:     draft/first-release
Commit:     8ef00c7fb2baf0a9bd88b277e69d1aa984150919
```

The baseline contains the raw import of the Production-Validated Reference Module.

Production reference inspected during the audit:

```text
Repository: Maatify/athar-platform
Commit:     6caf3634d5b00d8c3eff285ec36e554c2a9a4a8d
```

The locally adopted Applicable Standards Set remains authoritative for package work, especially:

- `PACKAGE_BUILDING_STANDARD.md` v1.4.0
- `COMPOSER_PACKAGE_STANDARD.md` v1.2.0
- `TESTING_STANDARD.md` v1.1.0
- `CI_WORKFLOW_STANDARD.md` v1.1.0
- `LIBRARY_PRESENTATION_STANDARD.md` v1.0.1

---

## 2. Architectural conclusion

The Production-Validated Reference Module is already a strong foundation.

It should **not** be redesigned into a generic counter utility and should **not** lose its production security behavior during extraction.

The correct direction is:

```text
Production Module
    -> preserve proven behavior
    -> package correctly
    -> repair only proven violations/defects
    -> verify behavior
    -> publish
```

Not:

```text
Production Module
    -> redesign from scratch
```

---

## 3. Production behavior that must remain intact

The following behavior is considered package-owned capability and must be preserved unless a later test proves a defect:

- K1-K5 signal model.
- IPv4/IPv6 key strategy.
- Device fingerprinting.
- Fingerprint rotation.
- Ephemeral-device handling.
- Correlation rules.
- Anti-equilibrium behavior.
- Score decay.
- Progressive penalties.
- Fixed budgets.
- Circuit breaker behavior.
- Failure modes.
- Local fallback concept.
- Secret rotation.
- Default package policies:
  - `LoginProtectionPolicy`
  - `OtpProtectionPolicy`
  - `ApiHeavyProtectionPolicy`
- Default thresholds.
- Default score deltas.
- Default budgets.
- Stable policy identifiers.
- Existing extension contracts.
- `ClockInterface` integration.

The default policies are **not Host coupling**.

The production Host already demonstrates the intended extension model by using Host-specific overrides instead of modifying the core defaults.

Therefore:

```text
Default production presets        -> KEEP
Host ability to replace/extend    -> KEEP
Host-specific override values     -> DO NOT import as package defaults
```

Policy identifiers such as:

```text
login_protection
otp_protection
api_heavy_protection
```

are package-owned policy identities and are not sufficient evidence of Host coupling.

---

## 4. Package bootstrap is incomplete

The raw import is intentionally not yet a complete Composer package.

The package still needs the root/package infrastructure required by the adopted standards, including as applicable:

```text
README.md
CHANGELOG.md
RATE_LIMITER_PACKAGE_REFERENCE.md
composer.json
phpstan.neon
src/
tests/
docs/
CI workflow
```

The selected package identity remains:

```text
Repository: php-rate-limiter
Composer:   maatify/php-rate-limiter
Namespace:  Maatify\RateLimiter\
PHP:        >= 8.4
```

`composer.lock` must not be committed for this reusable Composer library.

---

## 5. Documentation contains proven Host coupling

`src/README.md` still describes the code as part of the Admin Control Panel / monorepo and shows monorepo-style autoloading.

That documentation no longer represents the standalone package.

The technical material should be preserved, but reorganized into standalone package documentation:

```text
src/README.md
        ->
/README.md

src/docs/*
        ->
/docs/...
```

Required cleanup includes:

- remove Admin Control Panel ownership language;
- remove monorepo installation instructions;
- document real Composer installation;
- keep valid security guarantees and usage semantics;
- keep production policy behavior unless a later defect is proven.

This is Host decoupling / package presentation work, not runtime redesign.

---

## 6. Exception architecture requires Standards compliance

The current package exception is based directly on `RuntimeException`.

The adopted package standard requires package-owned exceptions to use the stable `maatify/exceptions` hierarchy and expose a package marker contract.

Required direction:

```text
RateLimiterExceptionInterface
        extends Throwable

RateLimiter package exceptions
        use the appropriate stable maatify/exceptions hierarchy
```

Existing semantic failures should be preserved.

This does **not** require wrapping every infrastructure exception indiscriminately.

---

## 7. DTO layer requires Standards compliance

The imported DTOs preserve typed data correctly, but their class form does not yet satisfy the adopted DTO rules.

Data DTOs should move toward the required form without changing their meaning:

```text
existing fields          -> KEEP
existing meaning         -> KEEP
final readonly           -> APPLY
JsonSerializable         -> APPLY
explicit jsonSerialize() -> APPLY
```

This applies to data snapshots such as:

- `RateLimitContextDTO`
- `RateLimitResultDTO`
- `DeviceIdentityDTO`
- `PolicyThresholdsDTO`
- `ScoreThresholdsDTO`
- `BudgetConfigDTO`
- Store DTOs
- other true data-transfer snapshots

DTO compliance must not become an excuse to replace the DTO layer with untyped arrays.

---

## 8. `RateLimitRequestDTO` is execution intent, not a true DTO

The current request object contains execution flags such as:

```text
isPreCheck
isFailure
isSuccess
```

and exposes operations such as:

```text
checkOnly()
recordFailure()
recordSuccess()
```

This object represents execution intent rather than a passive data snapshot.

The public constructor also permits contradictory states such as multiple intent flags being true simultaneously.

The correct extraction direction is to preserve the three operations while reclassifying the object into the package's execution-intent model, for example:

```text
RateLimitRequestCommand
```

The final name must follow the adopted standard, but the important requirements are:

- it must no longer be represented as a passive DTO;
- contradictory intent states must be impossible;
- existing public behavior must remain equivalent.

This is Standards compliance, not a policy redesign.

---

## 9. Shared Clock integration is already correct

The production core already uses:

```text
Maatify\SharedCommon\Contracts\ClockInterface
```

This should be preserved.

The standalone package should declare the stable `maatify/shared-common` dependency providing that contract.

Do not create a second package-local clock abstraction.

---

## 10. Proven defect: fallback UA double-normalization

The engine normalizes the User-Agent before invoking the local fallback limiter.

The fallback limiter then normalizes that already-normalized value a second time.

The second normalizer expects raw browser/OS patterns, so the second pass may collapse meaningful browser/platform information into a coarse fallback value.

This weakens K2 differentiation in fallback mode.

Classification:

```text
PROVEN DEFECT / CONTRACT BYPASS
```

Required fix:

- remove the double-normalization path;
- preserve the intended normalization semantics;
- add a regression test proving K2 fallback differentiation remains meaningful.

Do **not** redesign `LocalFallbackLimiter` as part of this fix.

---

## 11. Local fallback GC requires verification before change

The current fallback limiter periodically clears its static counter state globally.

That behavior may reset a still-relevant active bucket depending on process lifetime and bucket timing.

This is currently classified as:

```text
CONTRACT RISK — VERIFY WITH TEST
```

No runtime change is authorized yet.

The correct sequence is:

1. write characterization/regression coverage;
2. prove whether the current behavior violates the expected window semantics;
3. change it only if the test proves the defect.

---

## 12. Broad `Throwable` failure boundary is not automatically a defect

The engine catches execution failures and then applies package-defined failure semantics:

```text
FAIL_CLOSED
FAIL_OPEN
DEGRADED_MODE
```

The imported failure semantics explicitly include backend, timeout, state, internal, and contract failures inside the failure domain.

Therefore the broad failure boundary is currently part of the production security design.

Decision:

```text
KEEP
```

It must not be narrowed simply because a broad catch looks stylistically undesirable.

---

## 13. Redis architecture — new dependency boundary

The audit exposed an important dependency concern that should be resolved deliberately before public Redis support is finalized.

### 13.1 Generic Redis capability should become a standalone reusable library

Generic Redis concerns should not be reimplemented independently inside every Maatify package.

A separate reusable Redis library should own generic infrastructure concerns such as, as applicable after its own design/audit:

- Redis client/connection ownership;
- connection configuration contracts;
- health/ping primitives;
- safe command/script execution primitives;
- atomic Lua/equivalent execution support;
- common error translation where appropriate;
- reusable test/integration infrastructure around Redis itself.

The exact repository/package identity is intentionally **not invented in this document**. It must be established as a separate package project and must adopt the applicable engineering standards before becoming a stable dependency.

### 13.2 Rate Limiter-specific Redis semantics remain in `php-rate-limiter`

The generic Redis library must **not** own Rate Limiter behavior.

The following remain Rate Limiter package responsibilities:

- Rate Limiter key namespace/schema;
- Rate Limiter TTL rules;
- counter semantics;
- budget epoch semantics;
- block semantics;
- correlation semantics;
- conversion to/from Rate Limiter DTOs;
- conformance to `RateLimitStoreInterface`, `CorrelationStoreInterface`, and `CircuitBreakerStoreInterface`;
- Rate Limiter-specific Lua scripts or atomic algorithms.

The intended dependency direction is:

```text
Standalone Redis Library
        ↓
Rate Limiter Redis Adapter(s)
        ↓
Rate Limiter contracts / semantics
```

Not:

```text
Rate Limiter
        -> reimplements generic Redis infrastructure
```

and not:

```text
Generic Redis Library
        -> owns Rate Limiter policy/domain semantics
```

### 13.3 Existing production Redis adapters are donors, not final package implementations

The production Host contains generic-looking implementations such as:

```text
RedisRateLimitStore
RedisCorrelationStore
RedisCircuitBreakerStore
```

Their Rate Limiter-specific semantics are valid donor material.

However they must not be copied unchanged into the public package because the currently inspected production implementation does not meet the strict atomicity contract for all relevant operations.

---

## 14. Proven Redis atomicity contract violation

`RateLimitStoreInterface::increment()` explicitly requires:

```text
- atomic counter increment;
- create with TTL when absent;
- existing key TTL must not be refreshed.
```

The inspected production Redis implementation performs multiple commands for initialization, expiry, increment, and metadata updates.

That sequence is not one atomic operation.

A failure between creation and TTL assignment can leave invalid persistent state.

The same class of risk must be reviewed for:

- budget creation/increment;
- block creation/state;
- correlation state;
- any other multi-command operation carrying an atomic contract.

Required direction:

```text
Production Redis semantics
        ↓
PORT
        ↓
use the standalone Redis library primitives
        ↓
repair Rate Limiter atomicity
        ↓
real Redis conformance tests
```

Lua is one acceptable implementation mechanism, but the requirement is the atomic contract itself, not Lua as a goal.

No MySQL/Mongo/other backend claim should be made without real implementation and conformance evidence.

---

## 15. Host overrides demonstrate valid extension behavior

Host-specific implementations such as:

```text
AppLoginProtectionPolicy
AppOtpProtectionPolicy
AppDecayCalculator
AppPenaltyLadder
AppEvaluationPipeline
```

show that the current core already exposes meaningful replaceability.

The larger Host-specific `AppEvaluationPipeline` duplication suggests possible extensibility debt, but it does not justify an immediate refactor.

Classification:

```text
EXTENSIBILITY DEBT
```

Required sequence:

1. preserve behavior;
2. add characterization tests;
3. extract/package the core;
4. inspect whether the Host still needs broad pipeline replacement;
5. only then introduce a narrower extension seam if the runtime requirement is proven.

No speculative abstraction should be introduced before that evidence exists.

---

## 16. Characterization tests must precede runtime refactoring

The package should first lock the behavior inherited from production.

Coverage should include at minimum:

```text
Policies/default values
K1-K5
key generation
IPv6 hierarchy
decay
budgets
penalty progression
anti-equilibrium
correlation
device/fingerprint behavior
secret rotation
circuit breaker
failure modes
fallback limits
retry-after
```

Production Host scenarios should be converted into package-level invariants rather than copied as HTTP/Auth integration tests.

The goal is:

```text
same logical input sequence
        ↓
same decisions / levels / retry-after / persisted state
```

for every behavior not explicitly classified as a proven defect.

The adopted Testing Standard also requires a separate Consumer Verification Harness that installs the package from a clean consumer root and exercises its documented public API/workflow.

---

## 17. Forbidden extraction shortcuts

The following must not happen without new evidence and explicit architectural justification:

```text
- remove production presets;
- convert all thresholds into Host configuration;
- remove login/otp/api-heavy policy identities;
- invent interfaces for every concrete class;
- reduce the engine to a generic counter utility;
- change failure semantics;
- remove LocalFallback behavior;
- change the K1-K5 model;
- change penalty/decay/budget rules merely for cleaner architecture;
- replace the production core with the old maatify/rate-limiter package;
- claim MySQL/Mongo support without real verified implementations;
- import Host-specific App* overrides as package defaults;
- move Rate Limiter domain semantics into the standalone Redis library.
```

---

## 18. Proposed work units

### Work Unit 1 — Package Bootstrap

No runtime behavior change.

Create/complete:

```text
composer.json
README.md
CHANGELOG.md
RATE_LIMITER_PACKAGE_REFERENCE.md
phpstan.neon
test/bootstrap infrastructure
CI skeleton
docs relocation
```

Declare only dependencies actually required by the package.

Expected stable shared dependencies include, as applicable after version verification:

```text
maatify/shared-common
maatify/exceptions
```

The Redis dependency should be introduced only after the standalone Redis library has an approved stable contract suitable for the Rate Limiter adapter.

### Work Unit 2 — Characterization Tests

Freeze current non-defective behavior before compliance refactoring.

### Work Unit 3 — Runtime Standards Compliance

Under characterization coverage:

```text
DTO compliance
RateLimitRequest execution-intent correction
maatify/exceptions integration
PHP 8.4 / PHPStan max cleanup
fallback UA double-normalization fix
```

No policy redesign.

### Work Unit 4 — Standalone Redis Library Track

Separate project/workstream:

```text
standards adoption
Redis public contract
atomic execution capability
real Redis integration tests
stable dependency release
```

This workstream must remain generic and must not absorb Rate Limiter domain logic.

### Work Unit 5 — Rate Limiter Redis Adapter Extraction

After the Redis library contract is stable:

- port Rate Limiter-specific Redis semantics;
- depend on the standalone Redis library;
- repair atomicity;
- add real Redis conformance/integration coverage.

Critical verification includes:

```text
create + TTL atomicity
existing counter TTL not refreshed
budget epoch correctness
block TTL/state correctness
correlation TTL correctness
concurrency
```

### Work Unit 6 — Extension Seam Review

Only after the core is stable and tested.

Review Host overrides and introduce a narrower seam only if the production requirement is proven.

### Work Unit 7 — Consumer Verification / Release Readiness

Before the first externally published RC:

```text
Consumer Verification Harness
Composer install from clean consumer root
production PSR-4
real Redis workflow where Redis support is part of the released surface
documented public API
repeatable clean runs
full CI
PHPStan max
release-facing docs
```

Only after these gates are satisfied should the project proceed toward:

```text
v1.0.0-rc.1
```

---

## 19. Final architecture map

```text
Production-Validated Rate Limiter Core
    │
    ├── strong behavior                  -> KEEP
    ├── production presets               -> KEEP
    ├── extension points                 -> KEEP
    ├── failure semantics                -> KEEP
    ├── Clock abstraction                -> KEEP
    │
    ├── DTO form                         -> STANDARD FIX
    ├── Request DTO classification       -> STANDARD FIX
    ├── Exception hierarchy              -> STANDARD FIX
    ├── package/document structure       -> STANDARD FIX
    ├── Host README references           -> HOST DECOUPLE
    ├── fallback UA double-normalization -> PROVEN DEFECT FIX
    │
    └── Redis integration
            │
            ├── generic Redis infrastructure
            │       -> STANDALONE REDIS LIBRARY
            │
            └── Rate Limiter-specific adapters/semantics
                    -> KEEP IN php-rate-limiter
                    -> DEPEND ON Redis library
                    -> REPAIR ATOMICITY
                    -> REAL REDIS CONFORMANCE TESTS
```

The extraction objective is therefore:

```text
Preserve production behavior
        +
meet adopted package standards
        +
separate generic Redis infrastructure
        +
repair proven defects/contracts
        +
verify before release
```
