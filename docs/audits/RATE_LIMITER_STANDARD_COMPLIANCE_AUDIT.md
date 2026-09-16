# Rate Limiter Standard Compliance & Extraction Audit

## Status

**Verdict:** `NEEDS CHANGES`

This audit is intentionally limited to:

1. the code currently imported into `Maatify/php-rate-limiter`;
2. the locally adopted Applicable Standards Set;
3. the Production-Validated Reference Module only where needed to establish original behavior or prove Host coupling.

It does **not** choose, invent, or require persistence backends, cache layers, Redis libraries, SQL implementations, or other infrastructure that the current package does not itself own.

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
Integration branch: draft/first-release
Baseline commit: 8ef00c7fb2baf0a9bd88b277e69d1aa984150919
Audit draft: draft/extraction-blueprint
```

The baseline contains the raw import of the Production-Validated Reference Module.

Production reference inspected where comparison was necessary:

```text
Repository: Maatify/athar-platform
Commit: 6caf3634d5b00d8c3eff285ec36e554c2a9a4a8d
```

The locally adopted Applicable Standards Set remains authoritative, especially:

- `PACKAGE_BUILDING_STANDARD.md` v1.4.0
- `COMPOSER_PACKAGE_STANDARD.md` v1.2.0
- `TESTING_STANDARD.md` v1.1.0
- `CI_WORKFLOW_STANDARD.md` v1.1.0
- `LIBRARY_PRESENTATION_STANDARD.md` v1.0.1

---

## 2. Architectural conclusion

The imported Production-Validated Reference Module is the implementation baseline.

The extraction must preserve its proven Rate Limiter behavior and convert it into a compliant standalone Composer package.

Correct direction:

```text
Production Module
    -> preserve proven behavior
    -> remove proven Host coupling
    -> satisfy adopted package standards
    -> repair only proven defects / contract bypasses
    -> verify behavior
    -> prepare release
```

Incorrect direction:

```text
Production Module
    -> redesign from scratch
```

or:

```text
Production Module
    -> invent new infrastructure dependencies not required by its code
```

---

## 3. Package-owned behavior to preserve

The following imported behavior is treated as package-owned behavior and must remain unless a later test proves a defect or an adopted Standard directly requires a structural correction:

- K1-K5 signal model;
- IPv4/IPv6 key strategy;
- device fingerprinting;
- fingerprint rotation;
- ephemeral-device handling;
- correlation rules;
- anti-equilibrium behavior;
- score decay;
- progressive penalties;
- fixed budgets;
- circuit-breaker behavior;
- explicit failure modes;
- local fallback behavior;
- secret rotation;
- default policies:
  - `LoginProtectionPolicy`
  - `OtpProtectionPolicy`
  - `ApiHeavyProtectionPolicy`
- default thresholds;
- default score deltas;
- default budgets;
- stable policy identifiers;
- existing extension contracts;
- `ClockInterface` integration.

The package defaults are not removed merely because a Host may override them.

The Production-Validated Reference demonstrates that policies can already be replaced or extended by the Host without deleting the package defaults.

Therefore:

```text
Default production presets     -> KEEP
Existing replaceability        -> KEEP
Host-specific override values  -> DO NOT copy into package defaults
```

Policy identifiers such as:

```text
login_protection
otp_protection
api_heavy_protection
```

are part of the imported package policy model and are not, by themselves, evidence of Host coupling.

---

## 4. Current persistence/backend boundary

The imported package runtime currently exposes storage contracts, including:

```text
RateLimitStoreInterface
CorrelationStoreInterface
CircuitBreakerStoreInterface
```

These contracts define the semantics required by the Rate Limiter core.

The current standalone package does **not** contain a concrete Redis adapter, SQL repository, Mongo adapter, or generic Cache implementation.

Therefore this audit makes **no backend selection**.

No backend-specific package, adapter, schema, extension, or Composer dependency is authorized by this audit merely because the former Host used one.

The rule is:

```text
Rate Limiter storage contracts
        -> remain package-owned

Concrete backend support
        -> separate implementation decision
        -> only when explicitly selected
        -> must satisfy the existing contracts
        -> must be documented, implemented, and verified before support is claimed
```

The Package Building Standard makes persistence rules conditional. SQL/PDO/schema requirements are not applicable unless this package actually owns SQL persistence or SQL database behavior.

No `maatify/persistence` dependency is currently justified by the imported Rate Limiter runtime.

---

## 5. Package bootstrap is incomplete

The raw import was intentionally only an implementation baseline, not a completed Composer package.

The current repository root still lacks required package infrastructure, including:

```text
README.md
CHANGELOG.md
RATE_LIMITER_PACKAGE_REFERENCE.md
composer.json
phpstan.neon
tests/
CI workflow
```

The selected package identity remains:

```text
Repository: php-rate-limiter
Composer:   maatify/php-rate-limiter
Namespace:  Maatify\RateLimiter\
PHP:        >= 8.4
```

`composer.lock` must remain uncommitted for this reusable Composer library.

Dependencies must be declared only when they are required by actual package code or by a mandatory Standard compliance change.

---

## 6. Proven documentation Host coupling

`src/README.md` still describes the code as part of the Admin Control Panel / monorepo and documents monorepo-style autoloading.

That is no longer correct for the standalone package.

The technical content should be preserved where still valid, but package documentation must be reorganized into the standalone repository structure.

Expected direction:

```text
src/README.md
        -> root README.md content

src/docs/*
        -> docs/*
```

Required cleanup:

- remove Admin Control Panel ownership language;
- remove monorepo installation instructions;
- document the actual Composer package installation once `composer.json` exists;
- retain valid security guarantees;
- retain valid policy/default behavior;
- do not introduce new backend claims.

This is proven Host decoupling / package presentation work only.

---

## 7. Exception architecture requires Standards compliance

The current package-owned exception is based directly on `RuntimeException`.

The adopted Package Building Standard requires package-defined exceptions to use the appropriate stable hierarchy from `maatify/exceptions` and requires a package-owned marker interface extending `Throwable`.

Required direction:

```text
RateLimiterExceptionInterface extends Throwable

RateLimiter package exceptions
    -> appropriate stable maatify/exceptions hierarchy
    -> implement RateLimiterExceptionInterface directly or indirectly
```

Existing semantic failure behavior must be preserved.

This does not authorize blind catch-all wrapping of infrastructure errors.

This compliance change creates a justified direct dependency on the minimum stable `maatify/exceptions` version that exposes the hierarchy actually used.

---

## 8. DTO layer requires Standards compliance

The adopted Package Building Standard requires true DTOs to be:

```text
final readonly
implements JsonSerializable
explicit jsonSerialize()
```

Current imported DTOs such as `RateLimitContextDTO` are ordinary classes with readonly promoted properties and do not yet satisfy that required DTO form.

For every true data snapshot/result DTO:

```text
existing fields          -> KEEP
existing meaning         -> KEEP
final readonly           -> APPLY
JsonSerializable         -> APPLY
explicit jsonSerialize() -> APPLY
```

This correction must not change Rate Limiter behavior or replace typed DTOs with untyped arrays.

Every object under the current DTO namespace must be classified by responsibility before mechanical conversion; only true data snapshots/results remain DTOs.

---

## 9. `RateLimitRequestDTO` is execution intent and must be reclassified

The current `RateLimitRequestDTO` carries execution intent through:

```text
isPreCheck
isFailure
isSuccess
```

and exposes intent constructors:

```text
checkOnly()
recordFailure()
recordSuccess()
```

The adopted Standard states that an object representing execution/action intent must not be named a DTO and that Commands are self-validating value objects.

The current public constructor also permits contradictory combinations of the three flags.

Required correction:

- preserve the three supported operations;
- represent the operation as an execution-intent contract, following the adopted naming/type rules;
- make contradictory intent states impossible;
- keep business behavior equivalent.

A likely resulting type is a `...Command`, but the implementation phase must apply the adopted naming rule against the actual final responsibility rather than renaming mechanically.

This is Standards compliance, not policy redesign.

---

## 10. Shared Clock integration is already compliant

The imported runtime already consumes:

```text
Maatify\SharedCommon\Contracts\ClockInterface
```

This must be preserved.

The standalone package therefore has a real direct dependency on the minimum stable `maatify/shared-common` version exposing the used Clock API.

Do not create a package-local Clock abstraction.

---

## 11. Proven defect: fallback UA double-normalization

The current Engine normalizes the User-Agent before invoking the local fallback limiter.

The local fallback limiter then normalizes the received value again using a routine that expects raw browser/OS patterns.

This can collapse already-normalized UA information and weaken the intended K2 differentiation during fallback behavior.

Classification:

```text
PROVEN DEFECT / CONTRACT BYPASS
```

Required correction:

- remove the double-normalization path;
- preserve the intended UA normalization semantics;
- add a regression test proving meaningful K2 differentiation;
- do not redesign `LocalFallbackLimiter` as part of this defect fix.

---

## 12. Local fallback counter cleanup requires proof before change

`LocalFallbackLimiter` periodically clears its static counter collection globally.

That deserves verification against the documented degraded-epoch/window guarantees, but it is not automatically classified as a defect.

Current classification:

```text
VERIFY WITH TEST — NO CHANGE AUTHORIZED YET
```

Required order:

1. characterize the current timing behavior;
2. compare it with the locked failure semantics;
3. modify it only if an observable contract violation is proven.

No speculative cleanup algorithm is authorized by this audit.

---

## 13. Failure semantics are locked behavior

The imported `FAILURE_SEMANTICS.md` explicitly defines storage failures, atomicity failures, and internal logic failures, and defines package behavior for:

```text
FAIL_CLOSED
FAIL_OPEN
DEGRADED_MODE
```

It also explicitly states that failure semantics are security-critical and must not be changed implicitly.

Therefore broad failure handling inside the current Engine must not be narrowed, rewritten, or "cleaned up" merely for stylistic reasons.

Any change to failure-mode behavior requires independent evidence, tests, and explicit security/versioning treatment.

Decision for the current extraction pass:

```text
PRESERVE
```

---

## 14. Host-specific code is evidence, not package scope

Host-specific implementations may be inspected only to answer questions such as:

- does the current package contract support replacement?
- is a behavior actually Host-specific?
- is there a real integration constraint that the package must preserve?

They are not automatically candidates for import.

In particular, this audit does not import or prescribe Host infrastructure simply because it was used by the Production-Validated Reference application.

The package scope is determined by its own Rate Limiter responsibilities and adopted Standards.

---

## 15. Characterization tests must precede behavior-affecting refactoring

Before changing runtime behavior, the package needs characterization coverage for the imported security model.

Coverage should be derived from actual package behavior and include, where applicable:

```text
policy/default values
K1-K5 key behavior
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
local fallback limits
retry-after
```

The goal for all behavior not classified as a proven defect is:

```text
same logical input sequence
        -> same observable Rate Limiter behavior
```

Production Host tests may be used as behavioral evidence, but HTTP/Auth/application-specific assertions should not be copied blindly into this standalone package.

The adopted Testing Standard's Consumer Verification Harness requirement remains applicable at package-readiness time.

---

## 16. Forbidden extraction shortcuts

Without new evidence and explicit justification, do not:

```text
- remove production presets;
- convert all thresholds into Host configuration;
- remove login/otp/api-heavy policy identities;
- invent interfaces for every concrete class;
- reduce the Engine to a generic counter utility;
- change failure semantics;
- remove local fallback behavior;
- change the K1-K5 model;
- change penalty/decay/budget rules merely for architectural aesthetics;
- replace this core with the old maatify/rate-limiter implementation;
- import Host-specific App* classes as package defaults;
- claim support for Redis, SQL, MongoDB, Cache, or any other backend without an explicit implementation decision and verification;
- add an infrastructure dependency simply because the Production Host used it.
```

---

## 17. Work units

### Work Unit 1 — Package Bootstrap

No runtime behavior change.

Create/complete only package-level infrastructure required by the adopted Standards:

```text
composer.json
README.md
CHANGELOG.md
RATE_LIMITER_PACKAGE_REFERENCE.md
phpstan.neon
test/bootstrap infrastructure
CI workflow
docs relocation
```

Composer dependencies must be based on actual code:

- `maatify/shared-common` because the imported runtime consumes `ClockInterface`;
- `maatify/exceptions` when the exception-compliance change is implemented;
- no persistence/cache/backend dependency without separate evidence and decision.

### Work Unit 2 — Characterization Tests

Freeze current non-defective Rate Limiter behavior before runtime compliance changes.

### Work Unit 3 — Runtime Standards Compliance

Under characterization coverage:

```text
DTO classification/compliance
RateLimitRequest execution-intent correction
maatify/exceptions integration
PHP 8.4 / PHPStan max compliance
proven fallback UA double-normalization fix
```

No policy redesign.

### Work Unit 4 — Storage Adapter Decision, only if needed

This is **not pre-decided** by this audit.

If the package needs one or more concrete storage adapters, each adapter/backend must be separately selected from an actual package requirement.

For every selected backend:

- implement the existing Rate Limiter storage contracts;
- preserve their atomicity/TTL/state semantics;
- declare only the dependencies actually needed;
- provide backend-appropriate real verification;
- document only support that actually exists.

If no concrete adapter is selected for a given release phase, no backend is invented merely to complete the architecture diagram.

### Work Unit 5 — Extension Review, only after characterization

Inspect existing extension seams only where a real consumer requirement demonstrates a limitation.

No new abstraction should be introduced without proven runtime replaceability need.

### Work Unit 6 — Consumer Verification / Release Readiness

Before the first externally published RC:

```text
Consumer Verification Harness
clean Composer installation
production PSR-4 autoloading
public documented workflow
required CI checks
PHPStan max
release-facing docs
```

Any persistence/backend verification in this stage applies only to backends actually claimed by the package.

---

## 18. Current decision summary

```text
Production behavior/defaults             -> PRESERVE
Existing Rate Limiter contracts          -> PRESERVE
ClockInterface integration               -> PRESERVE
Failure semantics                        -> PRESERVE
Host/monorepo documentation              -> REMOVE / REWRITE
Package root/bootstrap                    -> BUILD TO STANDARD
Package exception hierarchy              -> FIX TO STANDARD
True DTO class form                       -> FIX TO STANDARD
RateLimitRequestDTO responsibility/name  -> FIX TO STANDARD
Fallback UA double-normalization          -> FIX AS PROVEN DEFECT
Fallback GC behavior                      -> TEST BEFORE ANY CHANGE
Concrete persistence/backend choice       -> NOT DECIDED BY THIS AUDIT
Redis/Cache/SQL/Mongo dependency          -> NONE INFERRED
```

The extraction principle remains:

```text
Preserve what the Rate Limiter already owns.
Change only what the Standards, proven Host decoupling, or a proven defect require.
Do not create dependencies or architecture from scenarios that do not exist in the package.
```
