# #110 trust family — the R23-01 counter-case measured on S0

**Status: EVIDENCE ONLY — Draft PR for independent review.** Nothing here changes `src/`, `tests/`, `docs/`, any ADR/Decision, Composer, CI or any
public / Redis / store contract, and nothing here selects, approves or rejects a design. Everything lives under `investigations/trusted-multi-session-s0/`
(outside the PHPUnit / PHPStan / php-cs-fixer scopes and not part of the package contract). DEC-019 stays ACTIVE and binding.

Related: [#110](https://github.com/Maatify/php-rate-limiter/issues/110) (the comparative study; this measures the counter-case raised in the
architectural review of plans 2 / 3 / 2+3), [#97](https://github.com/Maatify/php-rate-limiter/issues/97), [#100](https://github.com/Maatify/php-rate-limiter/issues/100)
and [#106](https://github.com/Maatify/php-rate-limiter/issues/106) (**not** closed or resolved here).

Baseline: `Maatify/php-rate-limiter` `origin/main@ce89cb26241a660d58e9d896ee2f9ae56f0239c8` (merged #112). The study reuses, unchanged, the PR #103 `Lab`
(REAL `EvaluationPipeline` + REAL `DeviceIdentityResolver` + reference stores + controlled clock) and the PR #108 K1 harness (per-request attribution,
the `N12T` / `N12M` / `N12` / `SP5` streams). **Result and numbers: [`REPORT.md`](REPORT.md); every table is generated in [`expected/summary.md`](expected/summary.md).**

## 1. Question

The trust-family plans of #110 (plan 2, plan 3 / PBEI, and the 2+3 composite) make the shared K2 correlation block **advisory for a request with a
trusted, account-bound session**. The architectural review (R23-01) objected: an attacker holding **valid trusted sessions for several accounts** behind
one shared IP + User-Agent would spread attempts across accounts, and no per-account guard (§5.2 or plan 2's) replaces the cross-account K2 bound.
The executor's first rebuttal ("such an attacker uses one stable fingerprint per account, so S0's K2 never sees churn") was refuted on the code:
`DeviceIdentityResolver::resolve()` hashes `v2|ua|clientFp|sessionDeviceId`, so distinct sessions are distinct fingerprints.

This study measures, **on S0 only and before any candidate is built**:

1. what bounds that attacker in the shipped runtime, in each Host flow, and which key does it (`TMS`, `TMSd`, `TMSs`, `TMR`);
2. whether S0 can tell that attacker apart from the benign trusted office `N12T` (#108) — request for request (`t02_trace.php`);
3. what remains when the cross-account control is absent: the Host-violating controls `TMX` / `TMXb` (one trusted session presented against all 12
   targets — the contract says this is *not* a trusted session for the other 11 accounts) and the untrusted control `TMU`;
4. the office and the attacker together behind one NAT (`TPAIRT`, `TPAIRM`), and the OTP shape.

**No candidate, no patch, no arm.** The study does not measure any trust-family design; it fixes the S0 numbers every such design must be compared with,
and documents the limits of that inference (REPORT §4).

## 2. Streams (`lib/tms.php::tmsStreams`)

All attacker streams sit on the `N12T` NAT address and browser (same K1 / K2 scopes as the office); every attacker attempt fails.

| Stream | Content |
|---|---|
| `N12T`, `N12M`, `N12`, `SP5` | #108 references, unchanged (trusted office 56/61; half-trusted; untrusted; one trusted session spraying 200 fresh accounts 200/200) |
| `TMS` | **R23-01 attacker at the office cadence**: 12 valid trusted sessions, one per target account, one request / 40 s on a rotating target, 40 min — `N12T` request for request with `ok` → `fail` |
| `TMSd` | the same attacker, dense: 200 attempts at 1 / 3 s cycling the 12 targets (the `SP1n` cadence) |
| `TMSs` | dense and sequential per target: 17 consecutive attempts on each target |
| `TMR` | `TMSd` with a new client fingerprint on every attempt (the session per target kept) |
| `TMU` | untrusted control: `TMSd` with the same 12 session identifiers but `isSessionTrusted = false` |
| `TMX` | Host-violating control: **one** trusted session (bound to `vic0`) presented against all 12 targets, 200 attempts |
| `TMXb` | `TMX` sequential and longer: 25 consecutive attempts on each of the 12 targets (300) |
| `TPAIRT`, `TPAIRM` | the trusted / half-trusted office plus the `TMSd` attacker (own accounts) behind the same NAT from t = 300 |

Shapes: Login with account (all streams, flows `pre+out` / `pre` = checkOnly only / `out` = record-only) and OTP (`N12T` and the attacker streams except `TMXb`).

## 3. What is covered

| File | Content |
|---|---|
| `scenarios/t01_matrix.php` | 13 streams × 3 flows on Login + 7 × 3 on OTP (60 cells); per cell: attacker reach, reach in the first 600 s, max per account, accounts verified, first reach / first deny time, blocks issued by key, denials by source (PR #108 attribution). S0 invariants (hard-fail): `N12T` 56/61 and `SP5` 200/200 reproduced; `TMS` is `N12T` request for request; every attacker stream has 12 targets on the NAT; **the R23-01 premise** — 12 accounts ↔ 12 distinct, stable trusted sessions ↔ 12 distinct resolver fingerprints (bijection, on the events and on the real `DeviceIdentityResolver`) in `TMS`/`TMSd`/`TMSs`/`TMR`, ONE session / ONE fingerprint in the `TMX`/`TMXb` controls, `TMU` untrusted. Plus the contract-compliant `vic0` slice of `TMXb` |
| `scenarios/t02_trace.php` | per-request traces (first 80 requests + a run-length encoding of the whole stream) for `N12T`, `TMS`, `TMSd`, `TMSs`, `TMR`, `TMX`, `TMU` in the three flows; request-for-request decision divergence `N12T` vs `TMS` per flow |
| `report.php` | fail-closed evidence gates G1–G4 (exit 5 on any missing / malformed evidence; never a PASS then), generated `summary.md` |

## 4. Reproduce

```bash
composer install
bash investigations/trusted-multi-session-s0/run.sh            # everything, diffed against expected/ (~5 s)
UPDATE=1 bash investigations/trusted-multi-session-s0/run.sh   # regenerate expected/ (review the git diff!)
```

The run **fails closed**: `run.sh` itself refuses a set candidate label (`WSB_CANDIDATE`, `OQC_ARM`) with exit 4 (it never unsets one silently); the
baseline gate refuses any change under `src/` or the Composer files; every scenario aborts with exit 4 unless the loaded pipeline is the repository's
unpatched baseline (sha-256 `4b259d33…f4fc`) and no candidate label is set; a violated S0 invariant exits the scenario with 1; `report.php` exits 5 and marks
every gate UNPROVEN on missing, malformed or mislabelled evidence, and exits 6 (gate FAIL recorded, summary still written) on any violated assertion.
Self-tests in `run.sh`: candidate labels refused by the scenarios and by the runner (exit 4); a mis-built stream (two targets sharing one session) is
detected by the binding assertion (exit 1); a flipped `ok:true → ok:false` makes `report.php` exit 6; eight fail-closed cases (deleted matrix / trace file,
deleted cell, removed field, deleted assertion row, forged pipeline hash, deleted divergence row, deleted trace) must all make `report.php` exit 5 with no
PASS gate. `results/` is git-ignored scratch; the committed evidence is `expected/`.

Reference stores and the controlled clock only: sequential decision parity of this pipeline with real Redis was proven in #108 / #111; concurrency is
out of this study's scope (it would have to be measured on any candidate, as #111 showed).

## 5. Limits

Deterministic synthetic streams; not production statistics. "PASS" in the runner means the evidence matches `expected/`, never that a design is
acceptable. The study measures S0 only; any statement about a trust-family candidate in `REPORT.md` is an inference from S0 controls and is labelled as
such, never a candidate measurement.
