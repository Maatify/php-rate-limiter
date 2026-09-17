# Rate Limiter Standard Compliance & Extraction Audit

## Status

**Verdict:** `NEEDS CHANGES`

This audit is intentionally limited to:

1. the code currently imported into `Maatify/php-rate-limiter`;
2. the locally adopted Applicable Standards Set;
3. the Production-Validated Reference Module only where needed to establish original behavior, integration reality, or proven Host coupling.

It does **not** choose, invent, or require persistence backends, cache layers, Redis libraries, SQL implementations, framework integrations, new extension models, or other infrastructure that the current package does not itself own.

---

## 1. Non-negotiable extraction rule

```text
PRESERVE BY DEFAULT
```

A runtime change is authorized only when at least one of the following is proven:

```text
1. STANDARD COMPLIANCE
2. PROVEN HOST DECOUPLING
3. PROVEN DEFECT / CONTRACT BYPASS
```

Everything else is preserved.

A different design, cleaner abstraction, possible future backend, old package feature, Host implementation, or hypothetical consumer need is **not** sufficient justification for a runtime change.

### Scope Gate

Any proposed task that does not map directly to one of the three authorized reasons above is outside this extraction plan.

It must not be added to a Work Unit, dependency list, package contract, roadmap requirement, or release gate unless it is separately evidenced and explicitly approved.

This rule applies even when the proposed work appears technically useful.

---

## 2. Audit baseline

```text
Repository:         Maatify/php-rate-limiter
Integration branch: draft/first-release
Baseline commit:    8ef00c7fb2baf0a9bd88b277e69d1aa984150919
Audit draft:        draft/extraction-blueprint
```

The baseline contains the raw import of the Production-Validated Reference Module.

Production reference inspected only where comparison was necessary:

```text
Repository: Maatify/athar-platform
Commit:     6caf3634d5b00d8c3eff285ec36e554c2a9a4a8d
```

The locally adopted Applicable Standards Set remains authoritative, especially:

- `PACKAGE_BUILDING_STANDARD.md` v1.4.0
- `COMPOSER_PACKAGE_STANDARD.md` v2.0.0
- `TESTING_STANDARD.md` v1.1.0
- `CI_WORKFLOW_STANDARD.md` v1.1.0
- `LIBRARY_PRESENTATION_STANDARD.md` v1.0.1

### Owner Decision — Package License

Owner decision recorded on 2026-09-16:

```text
maatify/php-rate-limiter -> PROPRIETARY SOFTWARE
```

This establishes the initial proprietary licensing baseline before first release. It is an Owner-level legal/distribution decision and does **not** reopen Finding 1.

The package-facing licensing contract must remain synchronized:

```text
composer.json license -> proprietary
LICENSE               -> proprietary notice
README.md              -> Proprietary
```

Repository visibility does not grant open-source rights or permission to use, modify, or redistribute the software. Any such rights require separate written authorization or a written license agreement from Maatify.

---

## 3. Architectural conclusion

The imported Production-Validated Reference Module is the implementation baseline.

The extraction is not a redesign project.

Correct direction:

```text
Production Module
    -> preserve proven behavior
    -> remove proven Host coupling
    -> satisfy adopted package standards
    -> repair only proven defects / contract bypasses
    -> verify preserved behavior
    -> prepare release
```

Incorrect directions include:

```text
Production Module -> redesign from scratch
Production Module -> genericize proven domain behavior
Production Module -> invent infrastructure dependencies
Production Module -> import Host implementation because it exists
Production Module -> restore features from the old standalone package without separate evidence
```

---

## 4. Package-owned behavior and contracts to preserve

The following imported behavior is package-owned and must remain unless an adopted Standard directly requires a structural correction or a concrete defect is proven:

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
- local fallback behavior, except for individually proven defects;
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

The Production-Validated Reference demonstrates that package policies can already be replaced or extended without deleting the package defaults.

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

## 5. Current storage/backend boundary

The imported package runtime currently exposes storage contracts:

```text
RateLimitStoreInterface
CorrelationStoreInterface
CircuitBreakerStoreInterface
```

These contracts define Rate Limiter semantics.

The current standalone package does **not** contain a concrete Redis adapter, SQL repository, Mongo adapter, or generic Cache implementation.

Therefore:

```text
Rate Limiter storage contracts -> KEEP
Concrete backend selection      -> OUT OF CURRENT EXTRACTION SCOPE
Backend-specific dependency     -> NONE INFERRED
```

No backend-specific package, adapter, schema, extension, or Composer dependency is authorized merely because a former Host used it or because a future consumer might need it.

If concrete backend support is considered later, that is a separate evidenced decision and is not a Work Unit in this audit.

The Package Building Standard makes persistence rules conditional. SQL/PDO/schema requirements do not apply unless this package actually owns SQL persistence or SQL database behavior.

No `maatify/persistence` dependency is justified by the imported runtime.

---

## 6. Package bootstrap is complete — STANDARD COMPLIANCE

The raw import was intentionally an implementation baseline. The Finding 1 remediation establishes the package foundation required by the adopted Standards.

The completed package-foundation scope is:

```text
README.md
CHANGELOG.md
RATE_LIMITER_PACKAGE_REFERENCE.md
composer.json
LICENSE
phpstan.neon
tests/
CI workflow
local parity scripts and documentation
```

The selected package identity remains:

```text
Repository: php-rate-limiter
Composer:   maatify/php-rate-limiter
Namespace:  Maatify\RateLimiter\
PHP:        ^8.4
License:    proprietary
```

`composer.lock` must remain uncommitted for this reusable Composer library.

Dependencies must be declared only when required by actual package code or a mandatory Standard compliance change.

This Work Unit must not alter runtime behavior.

---

## 7. Documentation contains proven Host coupling — HOST DECOUPLING

**Status:** RESOLVED / CLOSED

**Reason:** PROVEN HOST DECOUPLING

The Package Reference was updated to reflect the standalone package reality:
- `Modules/RateLimiter` ownership and location wording was removed.
- Monorepo autoload documentation was removed and Composer mapping reflects `src/`.
- Stale extraction-state wording was removed.
- Unsupported package-owned Infrastructure/driver claims were corrected.
- No runtime behavior changed.
- No backend or integration scope was added.

---

## 8. Exception architecture requires Standards compliance

Status: RESOLVED / CLOSED
Reason: STANDARD COMPLIANCE

* `RateLimiterExceptionInterface extends Throwable`;
* `RateLimiterException` implements the package marker;
* `RateLimiterException` extends the stable `InvalidArgumentMaatifyException` hierarchy;
* `maatify/exceptions:^1.0` is a direct dependency;
* existing throw sites/messages and engine failure behavior were preserved;
* no infrastructure catch-all wrapping was introduced.
---

## 9. DTO form requires Standards compliance

Status: RESOLVED / CLOSED
Reason: STANDARD COMPLIANCE

* all 18 classified true DTOs are `final readonly`;
* all implement `JsonSerializable`;
* all have explicit `jsonSerialize()`;
* fields, defaults, constants, methods and value semantics were preserved;
* `RateLimitRequestDTO` was deliberately excluded and remains open under Finding 10.
---

## 10. `RateLimitRequestDTO` is execution intent — STANDARD COMPLIANCE

The current object carries execution intent through:

```text
isPreCheck
isFailure
isSuccess
```

and exposes supported intent factories:

```text
checkOnly()
recordFailure()
recordSuccess()
```

The adopted Standard states that execution/action intent must not be represented as a DTO and that Command-style execution objects must validate their input contract.

The public constructor also permits contradictory flag combinations.

Required correction is intentionally minimal:

- preserve the three supported operations and their meaning;
- preserve cost and policy semantics;
- classify the object according to its actual responsibility under the adopted Standard;
- make invalid contradictory execution states impossible;
- update internal/public call sites consistently;
- do not split the workflow, invent additional operations, or redesign the Engine as part of this compliance change.

The exact replacement type/name must be selected from the adopted naming rules after inspecting actual usage; this audit does not invent an additional command model beyond what compliance requires.

---

## 11. Shared Clock integration is already compliant — PRESERVE

The imported runtime consumes:

```text
Maatify\SharedCommon\Contracts\ClockInterface
```

This must remain.

The standalone package therefore has a real direct dependency on the minimum stable `maatify/shared-common` version exposing the Clock API actually used.

Do not create a package-local Clock abstraction and do not change time behavior during extraction.

---

## 12. Fallback UA double-normalization — PROVEN DEFECT

The Engine first calls:

```text
DeviceIdentityResolver::normalizeUserAgent(raw UA)
```

That normalizer returns a reduced lowercase browser representation such as `chrome/123` when it recognizes the browser.

The Engine then passes that already-normalized value into `LocalFallbackLimiter::check()`.

`LocalFallbackLimiter` normalizes the value again using a different routine that expects raw browser/OS patterns such as `Chrome/123`, `Windows`, `Mac OS`, `Linux`, and similar markers.

The second pass therefore can discard browser/platform differentiation and collapse the fallback K2 input.

This is a code-path mismatch inside the imported package itself, not a hypothetical backend concern.

Required correction:

- eliminate the double-normalization mismatch using the smallest behavior-preserving fix;
- preserve the intended K2 fallback distinction;
- add a regression test for the exact defect;
- do not redesign `DeviceIdentityResolver` or `LocalFallbackLimiter` beyond what the defect requires.

---

## 13. Local fallback global GC — PROVEN CONTRACT MISMATCH

The locked `FAILURE_SEMANTICS.md` states that, while in `DEGRADED_MODE`, local in-memory counters must persist for the entire degraded epoch and must not reset within that epoch as a renewable clean slate.

`LocalFallbackLimiter` currently performs a global counter reset when its hourly GC threshold is crossed:

```text
self::$counters = []
```

That reset is independent of the active degraded epoch.

Therefore an active degraded epoch can cross the GC boundary and lose counters before the epoch ends.

This directly conflicts with the locked anti-reset guarantee.

Required correction:

- preserve the existing degraded caps and windows;
- remove only the contract-breaking reset behavior or replace it with the minimum cleanup mechanism that cannot clear still-valid degraded state;
- add regression coverage proving active degraded state is not reset by cleanup;
- do not redesign the fallback model.

The implementation shape is not dictated by this audit; the locked behavior is.

---

## 14. Failure semantics are locked — PRESERVE

The imported `FAILURE_SEMANTICS.md` explicitly defines storage failures, atomicity failures, internal logic failures, and the package behavior for:

```text
FAIL_CLOSED
FAIL_OPEN
DEGRADED_MODE
```

It states that these semantics are security-critical and must not be altered implicitly.

Therefore broad failure handling inside the current Engine must not be narrowed, rewritten, or cleaned up merely for style.

Any additional change to failure-mode behavior requires separate concrete evidence and explicit security/versioning treatment.

Current extraction decision:

```text
PRESERVE
```

---

## 15. Host-specific code is evidence, not package scope

Host-specific code may be inspected only to establish facts such as:

- whether the package already supports replacement;
- whether a behavior is genuinely Host-specific;
- whether extraction would break a real production integration;
- whether a claimed production behavior is actually exercised.

Host-specific classes are not candidates for import merely because they exist.

No Host adapter, policy override, pipeline override, cache implementation, backend implementation, or application infrastructure enters package scope without a separate package-owned requirement.

This audit creates no future extension-refactor Work Unit from Host code.

---

## 16. Characterization before behavior-affecting changes

Before changing runtime code under Standard compliance or proven defect repair, characterization/regression coverage must protect the existing supported behavior that surrounds the change.

Coverage must be based on actual imported behavior, not an imagined feature matrix.

Relevant existing capabilities include, where exercised by the package:

```text
policy/default values
K1-K5 behavior
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

The governing assertion for every non-defective behavior is:

```text
same logical input sequence
    -> same observable Rate Limiter behavior
```

Production Host tests may be used only as evidence of existing behavior. Host HTTP/Auth/application orchestration must not be imported into package tests unless it is itself part of the package contract, which is not currently established.

The Consumer Verification Harness required by the adopted Testing Standard remains a package-readiness requirement.

---

## 17. Forbidden extraction expansion

Without new evidence and a separate explicit decision, do not:

```text
- remove or weaken production presets;
- convert package defaults into Host configuration merely for flexibility;
- remove login/otp/api-heavy policy identities;
- invent interfaces for concrete classes without a real runtime replaceability need;
- reduce the Engine to a generic counter utility;
- change failure semantics;
- remove local fallback behavior;
- change the K1-K5 model;
- change penalty/decay/budget rules for architectural aesthetics;
- replace this core with the old maatify/rate-limiter implementation;
- port features from the old standalone package during this extraction audit;
- import Host-specific App* classes as package defaults;
- import Host infrastructure because the Host uses it;
- choose Redis, SQL, MongoDB, Cache, or another backend;
- add persistence/backend/cache dependencies;
- add backend adapters;
- create a Redis or Cache package as part of this project;
- create a new extension architecture from hypothetical consumer needs;
- add a Work Unit for a capability that is not required by current code or an adopted Standard;
- convert a possible future improvement into a release blocker.
```

---

## 18. Authorized Work Units only

### Work Unit 1 — Package Bootstrap

**Status:** RESOLVED / CLOSED

**Reason:** STANDARD COMPLIANCE.

No runtime behavior change was made.

The following infrastructure corrections have been established according to the adopted Standards:

- `composer.json` corrected for canonical package metadata, support URLs, `^8.4`, runtime extensions, stable dependency policy, scripts, and Composer configuration.
- `LICENSE` was established during package bootstrap as the initial proprietary licensing baseline without reopening Finding 1.
- `README.md` corrected to show the pre-release repository-access state, Maatify presentation identity, and the actual public constructors/API.
- `CHANGELOG.md` kept factual with no synthetic release comparison.
- `RATE_LIMITER_PACKAGE_REFERENCE.md` moved to the repository root as the single canonical Package Reference.
- `phpstan.neon` configured at `level: max` with zero suppressions.
- `phpunit.xml` and `tests/Unit/PackageStructureTest.php` created for initial test infrastructure.
- `CI workflow` (`.github/workflows/ci.yml`) corrected for immutable actions, timeouts, concurrency, latest and lowest dependency modes, PHP 8.4/8.5 tests, platform checks, PHPStan max, audit, workflow lint, syntax, examples, whitespace, and a stable final gate.
- Repository-owned local parity scripts and `CONTRIBUTING.md` document the same applicable checks.
- Existing documentation trailing whitespace was removed mechanically so the required whitespace gate can verify the repository.

**Verification:**
- Latest dependency resolution, strict Composer validation, strict PSR autoload, platform checks, PHPStan max, PHPUnit, and Composer audit passed on PHP 8.5.9.
- Lowest-supported dependency resolution passed with PHPStan max, PHPUnit, strict PSR autoload, and platform checks.
- PHP syntax, README example syntax, workflow lint, and whitespace verification passed.
- PHP 8.4 is covered by the CI matrix; no PHP 8.4 binary is installed in the local environment.
- GitHub Actions run `35127586714` passed all required jobs, including PHP 8.4 and PHP 8.5 tests and the stable Final Gate, for commit `682ea37b90b4b0b1dde4b8a02198c4427af73d98`.

Dependencies are limited to actual requirements:

- `maatify/shared-common` because runtime code already consumes `ClockInterface`;
- nothing else unless separately proven and approved.

### Work Unit 2 — Characterization / Regression Protection

**Reason:** stability protection for the imported production behavior.

Protect the actual existing behavior needed before Work Unit 3 changes runtime structure or fixes proven defects.

Do not use this Work Unit to invent new features or backend support.

### Work Unit 3 — Runtime Compliance + Proven Defects

**Reasons:** STANDARD COMPLIANCE and PROVEN DEFECTS only.

Authorized items:

```text
true DTO classification/form compliance
RateLimitRequest execution-intent compliance
maatify/exceptions integration
PHP 8.4 / PHPStan max compliance
fallback UA double-normalization defect
fallback degraded-state GC contract mismatch
```

No other runtime redesign is authorized by this audit.

### Work Unit 4 — Consumer Verification / Release Readiness

**Reason:** adopted Testing / Composer / CI / Presentation Standards.

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

No persistence/backend verification applies unless backend support has been separately added through a later explicitly approved scope decision.

---

## 19. Decision summary

```text
Production behavior/defaults             -> PRESERVE
Existing Rate Limiter contracts          -> PRESERVE
Storage contracts                        -> PRESERVE
Concrete backend/adapters                -> OUT OF CURRENT SCOPE
ClockInterface integration               -> PRESERVE
Failure semantics                        -> PRESERVE
Package license                          -> PROPRIETARY (OWNER DECISION)
Host/monorepo documentation              -> REMOVE / REWRITE TO PACKAGE CONTEXT
Package root/bootstrap                    -> BUILD TO STANDARD
Package exception hierarchy              -> FIX TO STANDARD
True DTO class form                       -> FIX TO STANDARD
RateLimitRequestDTO responsibility/type  -> MINIMUM STANDARD FIX
Fallback UA double-normalization          -> FIX PROVEN DEFECT
Fallback global GC reset                  -> FIX PROVEN CONTRACT MISMATCH
New extension architecture               -> OUT OF CURRENT SCOPE
Old standalone package feature import     -> OUT OF CURRENT SCOPE
Redis/Cache/SQL/Mongo dependency          -> NONE INFERRED
```

The extraction principle is final for this audit:

```text
Preserve what the Rate Limiter already owns.
Change only what the adopted Standards, proven Host decoupling, or a proven defect require.
Do not create dependencies, backends, abstractions, Work Units, or release blockers from scenarios that do not exist in the package.
```
