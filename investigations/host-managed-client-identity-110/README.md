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
* one isolated observation (H01) of how the **existing** inputs behave when a Host puts a browser id in them.

Out of scope: building or measuring a candidate runtime, passkeys, new fingerprinting, and any decision.

## Read in this order

1. [`REPORT.md`](REPORT.md) — summary, results, verdict (`INCONCLUSIVE` for direction C; NO-GO for A, B, `correlationId`
   reuse and D), findings, Owner decisions.
2. [`CURRENT_STATE.md`](CURRENT_STATE.md) — fields, keys, limits and contract-versus-code differences, with file:line.
3. [`ARCHITECTURE.md`](ARCHITECTURE.md) — the four directions, the proposed model, lifecycles, Key & Consumption Matrix.
4. [`SECURITY_ANALYSIS.md`](SECURITY_ANALYSIS.md) — threat model, rotation, spray, NAT, trust, privacy, storage,
   tension T-CNET.
5. [`CONTRACT_FEASIBILITY.md`](CONTRACT_FEASIBILITY.md) — `CONTRACT_CHANGE_REQUIRED`; compatibility, Redis,
   concurrency, rollback.
6. [`VALIDATION_PLAN.md`](VALIDATION_PLAN.md) — scenarios V1–V17, gates and the missing evidence.
7. [`expected/summary.md`](expected/summary.md) — every H01 number, generated.

## Kinds of evidence (kept separate everywhere)

| Kind | Where |
|---|---|
| Analytic proof from code / contracts | `CURRENT_STATE.md` (`[CODE]`, `[CONTRACT]`, `[TEST]`), `ARCHITECTURE.md` / `SECURITY_ANALYSIS.md` (`[ANALYTIC]`) |
| Isolated reproducible experiment | H01 (`[H01]`): `scenarios/h01_input_modes.php`, `expected/` |
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
* Not covered by H01: Redis, concurrency, mixed versions, IPv6, secret rotation, OTP. Those belong to a candidate
  study (`VALIDATION_PLAN.md`).

## Reproduce

```bash
composer install
bash investigations/host-managed-client-identity-110/run.sh             # ~2 s; diffs against expected/
UPDATE=1 bash investigations/host-managed-client-identity-110/run.sh    # regenerate expected/ (review the git diff)
```

`run.sh` fails closed:

* It refuses a baseline that is not an ancestor of HEAD.
* It refuses any difference or uncommitted change under `src/`, `tests/`, `docs/` or the Composer files.
* The scenario aborts (exit 4) if the loaded runtime is not the pinned unpatched baseline, or if a foreign candidate
  label is set.
* `report.php` exits 5 on missing, empty, malformed or mislabelled evidence, a wrong runtime hash, a missing cell, or a
  per-request string that does not match its attempt count.

There are seven negative self-tests: empty file, missing cell, patched label, wrong hash, bad request string, malformed
JSON, and a foreign candidate label. `results/` is git-ignored scratch; the committed evidence is `expected/`.

## Files

| Path | Content |
|---|---|
| `lib/hmci.php` | provenance pins, input modes, context builder, block attribution |
| `scenarios/h01_input_modes.php` | the 40-cell matrix (8 streams × 5 modes) |
| `report.php` | evidence validation and generated `summary.md` / `observations.jsonl` |
| `run.sh` | runner with baseline gate and self-tests |
| `expected/h01_input_modes.jsonl` | raw per-cell evidence with per-request `check` / `record` decision strings |
| `expected/summary.md`, `expected/observations.jsonl` | generated tables and the observations O1–O10 |
