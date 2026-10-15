# #110 Host-Managed Client Instance Identity (HMCI) — architecture study

**Status: EVIDENCE ONLY — Draft PR for independent review.** Nothing here changes `src/`, `tests/`, `docs/`, any ADR or
decision, Composer, CI, the public API, Redis keys or any Store contract. Nothing here selects or approves a policy.
Everything lives under `investigations/host-managed-client-identity-110/`. DEC-019 and DEC-021 stay ACTIVE and binding.
DEC-020 is not approved.

* Parent: [#110](https://github.com/Maatify/php-rate-limiter/issues/110).
* Related: [#97](https://github.com/Maatify/php-rate-limiter/issues/97),
  [#100](https://github.com/Maatify/php-rate-limiter/issues/100),
  [#106](https://github.com/Maatify/php-rate-limiter/issues/106). None of them is closed or resolved by this study.
* Baseline: `Maatify/php-rate-limiter` `main@a6c79f45e79171e26031fc720822428e09e6e36c` (merged #117, DEC-021 ACTIVE).

## Goal and scope

The Owner's idea: the Host issues a stable identifier for each browser (cookie) or app installation from the first
visit. The limiter could then tell independent browsers behind one NAT and UA apart, instead of punishing them
collectively. This study answers:

1. How can browser-context identity continue before and after login, separate from trust and from the account, with
   the smallest change for the consumer?
2. How can that identity reduce shared-NAT false positives without letting an attacker buy quota by rotating
   identifiers, or reach the verifier more than is acceptable?

In scope:

* code and contract analysis of the current inputs;
* a comparison of directions A, B, C and D;
* a proposed model;
* a threat model and validation plan;
* one isolated observation (H01) of how the **existing** inputs behave when a Host puts a browser id in them;
* one bootstrap model (H02): can an office actually reach the proposed standing, starting from a fresh store? Its NEW part
  runs the real runtime; its ESTABLISHED part is idealized and labelled as such.

Out of scope: building or measuring a candidate runtime, passkeys, new fingerprinting, and any decision.

## Read in this order

1. [`REPORT.md`](REPORT.md) — summary, results, scoped verdicts, findings, Owner decisions, and §6 reconciliation of the
   Lead review F118-01 … F118-05. Verdicts:
   * overall direction: `INCONCLUSIVE`;
   * proposal C: not acceptable as specified;
   * the A / B / `correlationId` mappings on the current runtime: NO-GO on the measured streams;
   * D as a class: not excluded.
2. [`CURRENT_STATE.md`](CURRENT_STATE.md) — fields, keys, limits and contract-versus-code differences, with file:line.
3. [`ARCHITECTURE.md`](ARCHITECTURE.md) — the four directions, the proposed model, lifecycles, Key & Consumption Matrix.
4. [`SECURITY_ANALYSIS.md`](SECURITY_ANALYSIS.md) — threat model, rotation, spray, NAT, trust, privacy, storage,
   tension T-CNET.
5. [`CONTRACT_FEASIBILITY.md`](CONTRACT_FEASIBILITY.md) — `CONTRACT_CHANGE_REQUIRED`; compatibility, Redis,
   concurrency, rollback.
6. [`VALIDATION_PLAN.md`](VALIDATION_PLAN.md) — scenarios V1–V17, gates and the missing evidence.
7. [`expected/summary.md`](expected/summary.md) — every H01 and H02 number, generated.

## Kinds of evidence (kept separate everywhere)

| Kind | Where |
|---|---|
| Analytic proof from code / contracts | `CURRENT_STATE.md` (`[CODE]`, `[CONTRACT]`, `[TEST]`), `ARCHITECTURE.md` / `SECURITY_ANALYSIS.md` (`[ANALYTIC]`) |
| Isolated reproducible experiment | H01 (`[H01]`): `scenarios/h01_input_modes.php`; H02 NEW-browser part (`[H02]`): `scenarios/h02_bootstrap.php`; `expected/` |
| Model with an idealized part | H02 ESTABLISHED browsers (`[H02 MODEL]`): assumed admitted and absent from the shared sets — an upper bound for the office, never a fairness result |
| Prior merged evidence | `[PRIOR]` with study and file reference |
| Not executed | the direction-C candidate and every gate in `VALIDATION_PLAN.md` |
| Approved Runtime | `main@a6c79f4` as is (S0 rules + DEC-021) — unchanged by this study |

A REPRODUCED H01 observation is a fact about the shipped runtime. It is not a security acceptance of any direction.

## H01 — what was run

* Runtime: the repository's **unpatched** `src/` at the baseline. The pinned sha-256 of `EvaluationPipeline`,
  `DeviceIdentityResolver` and `EphemeralBucket` is asserted, and a foreign candidate label is refused (exit 4).
* Harness: it reuses the PR #103 `Lab` unchanged (real `EvaluationPipeline`, real `DeviceIdentityResolver`, reference
  stores, controlled clock).
* Input modes:
  * **M0**: none.
  * **M1**: `clientFingerprint = ['bid' => id]`.
  * **M2**: `sessionDeviceId = id`, untrusted.
  * **M3**: `sessionDeviceId = id`, trusted before authentication. This is a contract violation, measured as a hazard.
  * **M4**: `correlationId = id`.
* Streams:
  * **OFF12 / OFF100**: benign offices behind one NAT with one Firefox major.
  * **SPR_S / SPR_R**: 200-account spray with a stable or a rotating id.
  * **BRU_S / BRU_R**: 100-attempt single-account brute force.
  * **UAS / UAC**: a known owner without and with a browser update.
* Why it is not a repetition: no earlier study measured a host-issued browser / installation id. The N-series of #108
  (stable fingerprints on one IP+UA) is cited, not re-run. The new hypotheses are:
  * each existing input used as a browser id, on post-DEC-021 `main`;
  * a NAT above the 50-device IP cap;
  * id rotation;
  * a UA change on a stable id.
* Not covered by H01 / H02: Redis, concurrency, mixed versions, IPv6, secret rotation, OTP. Those belong to a candidate
  study (`VALIDATION_PLAN.md`).

## H02 — what was run

* Setup: 100 browsers on one NAT and one Firefox major, 14 days, one shared store from empty. Each day there are arrivals
  in a shuffled order, ~10 % typos, and up to 3 retries after `max(retryAfter, 60 s)`.
* NEW browsers: real MAIN pipeline with M0 inputs, which are the inputs proposal C sends for them.
* Establishment rule (model): a MAIN-admitted, recorded successful login, under the per-network daily cap `E_net`.
* ESTABLISHED browsers: idealized.
* Variants:
  * MAIN: no establishment (the control).
  * BASE: uncapped.
  * CAP5: `E_net = 5`.
  * CLR10: 10 % cookie clearing per day.
  * FIX7: fixed 7-day standing.
  * ATK: BASE plus a credential-less sprayer on the NAT.
  * ATKM: MAIN plus the same sprayer (the control for ATK).

## Reproduce

```bash
composer install
bash investigations/host-managed-client-identity-110/run.sh             # ~20 s; diffs against expected/
UPDATE=1 bash investigations/host-managed-client-identity-110/run.sh    # regenerate expected/ (review the git diff)
```

`run.sh` fails closed:

* It refuses a baseline that is not an ancestor of HEAD.
* It refuses any difference or uncommitted change under `src/`, `tests/`, `docs/` or the Composer files.
* Both scenarios abort (exit 4) if the loaded runtime is not the pinned unpatched baseline, or if a foreign candidate label
  is set.
* `report.php` exits 5 on invalid evidence:
  * missing, empty, malformed or mislabelled evidence;
  * a wrong runtime hash;
  * unknown, duplicate or missing cells / rows;
  * fields outside the exact per-class schema;
  * characters outside the decision alphabet, or a record step not allowed after its check step;
  * a wrong confidence for the mode;
  * an H01 metric that differs from the value re-derived from the archived per-request traces and the deterministic
    schedule;
  * an H02 counter, `check` string or per-user transition that differs from the replay of the archived per-request event
    tokens (first login once per browser, retries only after a denial, establishment only after an admitted successful
    login, the `E_net` cap, the FIX7 expiry rule, the carried established set).
* `report.php` exits 6 when the evidence is valid but an observation is not reproduced.

Integrity scope:
* The validator proves that the archived numbers are consistent with the archived per-request evidence.
* That each archived decision is what the pinned runtime returns is shown by re-running both scenarios; `run.sh` diffs them
  byte-for-byte against `expected/`.
* CLR10's random cookie-clearing draw is checked for consistency (a subset of the established set), not recomputed.

`selftest.php` generates 24 mutations, and `run.sh` checks the exact expected exit code for each:
* 22 must be rejected with exit 5. Examples: a corrupt-but-length-correct trace, a duplicate cell, a mismatched reach, an
  H02 counter change that keeps every arithmetic relation, an attacker-reach shift, a tampered event token, a dropped retry.
* 2 must turn an observation NOT_REPRODUCED with exit 6: a masked rotation regression and a masked attack gain.

Two further checks show each scenario refuses a foreign candidate label. `results/` is git-ignored scratch; the committed
evidence is `expected/`.

## Files

| Path | Content |
|---|---|
| `lib/hmci.php` | provenance pins, input modes, context builder, block attribution, shared schedule / trace helpers and the H01 matrix definition |
| `scenarios/h01_input_modes.php` | the 40-cell matrix (8 streams × 5 modes) |
| `scenarios/h02_bootstrap.php` | the 7-variant × 14-day bootstrap model |
| `report.php` | fail-closed evidence validation (H01 trace-derived, H02 event-replay) and generated `summary.md` / `observations.jsonl` |
| `selftest.php` | negative-control mutation generator used by `run.sh` |
| `run.sh` | runner with baseline gate and self-tests |
| `expected/h01_input_modes.jsonl` | raw per-cell evidence with per-request `check` / `record` decision strings |
| `expected/h02_bootstrap.jsonl` | raw per-day H02 evidence: day counters, the `check` string, and the per-request event tokens and cleared / expired browsers they are re-derived from |
| `expected/summary.md`, `expected/observations.jsonl` | generated tables and the observations O1–O10, B1–B6 |
