# #110 Account-Scoped Known-Device Relief (AKDR) — feasibility study

**Status: EVIDENCE ONLY — Draft PR for independent review.** Nothing here changes `src/`, `tests/`, `docs/`, any ADR or decision,
Composer, CI, the public API, Redis keys or any Store contract. Nothing here selects or approves a policy. Everything lives under
`investigations/account-scoped-known-device-relief-110/`. DEC-019 and DEC-021 stay ACTIVE and binding. DEC-020 is not approved.

* Parent: [#110](https://github.com/Maatify/php-rate-limiter/issues/110).
* Related: [#97](https://github.com/Maatify/php-rate-limiter/issues/97),
  [#100](https://github.com/Maatify/php-rate-limiter/issues/100),
  [#106](https://github.com/Maatify/php-rate-limiter/issues/106). None of them is closed or resolved by this study.
* Reference study: [PR #118](https://github.com/Maatify/php-rate-limiter/pull/118) (HMCI), which named this direction as untested.
* Baseline: `Maatify/php-rate-limiter` `main@ebeb0a32c8fd42e0d4eb4d2500f30ea14036ad92` (DEC-021 ACTIVE). `src/` is byte-identical to
  `a6c79f4`, so the pinned runtime hashes are the HMCI ones.

## Question

Can the existing proof that a device belongs to an account (`isDevicePreviouslyVerifiedForAccount`, or a trusted session bound to the
account) lift the collective Network Correlation penalty (K1 credential spray, K2 churn, LOW dilution) from the innocent client, while the
network and the account stay protected against an attacker who holds valid devices, sessions and accounts?

## Verdict

**NO-GO** for a candidate investigation of this direction under the current #110 constraints. The relief works for known users. It does not
work as a security design, for structural reasons that a patch cannot tune away. See [`REPORT.md`](REPORT.md) §1 and §3 for the scoped
statement, and §5 for what would have to change first.

## Read in this order

1. [`REPORT.md`](REPORT.md): answers to the ten questions, verdict, findings and Owner decisions.
2. [`CURRENT_STATE.md`](CURRENT_STATE.md): what "known device" means in the code today, where K1 and K2 are consumed and enforced, with
   file:line and tests.
3. [`ARCHITECTURE.md`](ARCHITECTURE.md): alternatives A, B, C1–C3, D1 and D2, the consumption / enforcement matrix, and how each is
   modelled.
4. [`SECURITY_ANALYSIS.md`](SECURITY_ANALYSIS.md): attacker classes, the multi-session gate, Host misuse, concurrency, IPv6, rotation,
   DEC-015, mixed version, storage and privacy.
5. [`COMPARATIVE_ANALYSIS.md`](COMPARATIVE_ANALYSIS.md): main versus S0 (prior), the variants versus main, and the earlier #110 studies.
6. [`VALIDATION_PLAN.md`](VALIDATION_PLAN.md): the preconditions and gates any later candidate would owe.
7. [`expected/summary.md`](expected/summary.md): every number, generated.

## Kinds of evidence (kept separate everywhere)

| Label | Meaning |
|---|---|
| `[CODE]` | read from `src/` at the baseline (file:line) |
| `[CONTRACT]` | canonical `docs/` or an ACTIVE decision |
| `[TEST]` | asserted by an existing test in `tests/` |
| `[MAIN]` | measured here on the **unpatched** runtime (`MAIN` variant): a fact about today's `main` |
| `[MODEL]` | measured here on a **relief model** (variants `A` … `D2`): the unpatched pipeline seen through injected store decorators. Not a candidate patch; see `ARCHITECTURE.md` §4 for what it does and does not reproduce |
| `[PRIOR]` | merged earlier evidence (study and file named). The #113 S0 numbers are read from `investigations/trusted-multi-session-s0/expected/` and are never re-run or mixed with main |
| `[ANALYTIC]` | reasoning from the above; not executed |

A `[MODEL]` result is not a security acceptance and not a measurement of a candidate runtime.

## What was run

* **Runtime:** the repository's unpatched `EvaluationPipeline`, `DeviceIdentityResolver` and `EphemeralBucket` (sha-256 pinned and asserted;
  any foreign candidate label or K1-harness self-test switch aborts with exit 4), the package's reference test stores, and a controlled
  clock.
* **Relief models:** two decorators injected into the unpatched pipeline act only on a request the variant relieves:
  * `AkdrRateStore` hides that request's own K1 and/or K2 block;
  * `AkdrCorrStore` keeps (or, for C3, drops) its observation in the shared spray / churn / LOW-dilution sets, and answers below the
    trigger.

  When the variant keeps "trigger" semantics, the collective block the pipeline would have issued is persisted through the same Store
  method and the pipeline's own cycle constants.
* **Reused unchanged:** the PR #108 K1 harness (`K1Store` attribution, `k1Ctx`, `k1Replay` as the equivalence reference) and the PR #113
  streams (`tmsStreams()`).
* **Scenarios:**
  * **V01:** known-device attackers and controls. 29 Login streams, 8 variants, flows `pre+out` / `pre` (plus `out` for MAIN and C1),
    and an OTP subset. 586 cells.
  * **O01:** shared NAT offices of 12 / 50 / 100 / 250 browsers, LOW or MED fingerprints, 0 / 50 / 90 / 100 % known, with no attacker, a
    credential-less sprayer (NC) or a credentialed known attacker (KC). One morning with retries. 384 cells.
  * **B01:** bootstrap. 100 browsers for 14 days from an empty store. The Host verifies a browser after an admitted successful login.
    Every browser goes through the pipeline every day, so nothing is idealized. 9 runs × 2 modes.
* **Not exercised:** Redis, concurrency, IPv6 macro scopes, secret rotation, DEC-015 fallback, mixed versions and custom policies. These
  are analysed in `SECURITY_ANALYSIS.md` and listed as gates in `VALIDATION_PLAN.md`.
* **Why this is not a repetition:**
  * #113 measured S0 only, and inferred (did not measure) what K2-advisory-for-trusted would do. Here that relief is measured on post-DEC-021
    main.
  * The relief is extended to `isDevicePreviouslyVerifiedForAccount`, which no earlier study touched.
  * The office, pool and bootstrap questions that HMCI left open (R2-03) are run on the real pipeline instead of an idealized model.

## Reproduce

```bash
composer install
bash investigations/account-scoped-known-device-relief-110/run.sh             # ~3 min; diffs against expected/
UPDATE=1 bash investigations/account-scoped-known-device-relief-110/run.sh    # regenerate expected/ (review the git diff)
```

`run.sh` fails closed:

* It refuses a baseline that is not an ancestor of HEAD, and any difference or uncommitted change under `src/`, `tests/`, `docs/` or the
  Composer files.
* Each scenario aborts with exit 4 if the loaded runtime is not the pinned unpatched baseline, or if a foreign label is set.
* It byte-diffs the three evidence files, `summary.md` and `observations.jsonl` against `expected/`.
* `report.php` exits **5** on invalid evidence:
  * missing, empty, malformed or mislabelled rows, or a wrong runtime hash or cycle constant;
  * unknown, duplicate or missing cells, rows or days;
  * fields outside the exact schema;
  * a decision character outside `A/S/H/-`, or a record step that does not follow exactly the admitted checks;
  * relief on MAIN, on a request that is not known for its own account, or a known request left unrelieved by an ungated variant;
  * any stored metric that differs from the value re-derived from the archived per-request traces and the deterministic schedule;
  * an office token stream that breaks the browser rules:
    * a retry only after a denial, ≥ 60 s later, at most 3;
    * a typo follow-up exactly 15 s later;
    * nothing after a success;
    * no browser stopping early;
  * a bootstrap known set that is not the carried establishment set;
  * a failed gate (G-EQ, G-SUPP, G-STREAM, G-INERT).
* `report.php` exits **6** when the evidence is valid but an observation is not reproduced.
* `selftest.php` generates 24 mutations; `run.sh` checks the exact exit code of each: 22 must exit 5, and 2 internally consistent masks
  (`masked_gain`, `masked_pool`) must exit 6. Three more checks show each scenario refuses a foreign label.

Integrity scope:

* The validator proves that every archived number is consistent with the archived per-request evidence.
* That each archived decision is what the pinned runtime and the stated model return is shown by re-running the scenarios: `run.sh`
  byte-diffs them against `expected/`.
* The per-request evidence is about 3.8 MB. `results/` is git-ignored scratch.

## Files

| Path | Content |
|---|---|
| `lib/akdr.php` | provenance pins; the relief variants; `AkdrRateStore` / `AkdrCorrStore` decorators; `AkdrLab`; replay with attribution; V streams; office / bootstrap engine |
| `scenarios/v01_known_attackers.php` | V01 matrix and the G-EQ / G-STREAM / G-SUPP gates |
| `scenarios/o01_shared_nat.php` | O01 office matrix |
| `scenarios/b01_bootstrap.php` | B01 14-day bootstrap |
| `report.php` | fail-closed validation, re-derivation, G-INERT, generated `summary.md` / `observations.jsonl` |
| `selftest.php` | negative-control mutation generator |
| `run.sh` | runner with baseline gate and self-tests |
| `expected/*.jsonl` | archived per-cell / per-day evidence with per-request decision strings or tokens |
| `expected/summary.md`, `expected/observations.jsonl` | generated tables and the observations M1–M3, R1–R16 |
