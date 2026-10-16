# #97 Host-managed client identity and UA cross-IP denial — study

**Status: EVIDENCE ONLY — Investigation only, no production runtime change.** Nothing here changes `src/`, `tests/`, `docs/`, any
ADR or decision, Composer, CI, the public API, Redis keys or any Store contract. DEC-019 and DEC-021 stay ACTIVE and binding.

* Issue: [#97](https://github.com/Maatify/php-rate-limiter/issues/97) (stays OPEN).
* Related / dependencies only: [#100](https://github.com/Maatify/php-rate-limiter/issues/100) (shared-K2 churn),
  [#106](https://github.com/Maatify/php-rate-limiter/issues/106) (K1 spray on shared IP),
  [#120](https://github.com/Maatify/php-rate-limiter/issues/120) (UA rotation / trusted multi-session).
* Baseline: `Maatify/php-rate-limiter` `main@9e95f2fcca689440e9b0afc50cd9ef4158c096b3`. The loaded `EvaluationPipeline`,
  `DeviceIdentityResolver`, `EphemeralBucket`, `LoginProtectionPolicy` and `ApiHeavyProtectionPolicy` are sha-256-pinned and must be
  the repository's own unpatched files.
* Prior evidence reused: the PR #103 Lab and streams, the PR #105 D1 stream (unchanged), the shapes of the
  `host-managed-client-identity-110` streams.

## Question

Can a Host-issued identifier for each app installation / browser — available before login, stable across UA and IP changes —
remove the UA-derived cross-IP false blocks of #97 with the **smallest change to the existing library**, without giving an
attacker more reach to the verifier? The study tests one minimal candidate (CIR: the id travels in the existing
`clientFingerprint`, and the user agent is left out of the fingerprint material when an id is present), against today's runtime,
the same id with today's resolver, and a "remove UA entirely" control.

## Result

**`NO-GO`** by the pre-registered rule. The #97 cohort denials disappear (D1 596/600 → 0/600), but two people behind one router go
from 0 to 127/134 denials through K2 churn (#100), and any constant or stolen id moves the request onto the weak MEDIUM
enforcement path (a naive replayer: X2 6 → 121/121, stolen id 4 → 32/180; against today's best attacker response the loss is
narrow — +15 victim guesses in checkOnly-only BRD). Details, answers to Q1–Q5 and Owner decisions:
[`REPORT.md`](REPORT.md).

## Read in this order

1. [`VALIDATION_PLAN.md`](VALIDATION_PLAN.md) — arms, strategies, streams, gates and verdict rule. **Committed alone, before any
   harness existed** (commit `4c2d857`), then only §6–§7 were filled in.
2. [`REPORT.md`](REPORT.md) — verdict, root cause, comparison, Q1–Q5, findings F97-01 … F97-06, recommendation, limits.
3. [`expected/summary.md`](expected/summary.md) — every number, generated; [`expected/gates.json`](expected/gates.json) — every gate
   check.

## Files

| Path | Content |
|---|---|
| `lib/ci97.php` | provenance pins; the candidate `Ci97ClientInstanceResolver`, the control `Ci97NoUaControlResolver`, the post-hoc `Ci97LowConfidenceProbeResolver`; churn-only counterfactual store; stream definitions; materialisation per arm and attacker strategy; replay and metric derivation |
| `scenarios/c01_matrix.php` | the pre-registered matrix: 905 cells (stream × shape × arm × strategy × flow) with a correlation-off counterfactual for every non-attack cell |
| `scenarios/c02_lifecycle.php` | secret rotation, mixed fleet, rollout switch, malformed ids, shared-empty-id D1, the official `RateLimiterBuilder` composition check, post-hoc IPv6 cell |
| `scenarios/c03_posthoc_probe.php` | post-hoc probe CIRLI over the whole matrix (diagnosis only) |
| `report.php` | fail-closed validator, mechanical gate evaluation, verdict, pinned observations O1–O16, generated summary |
| `selftest.php` | 20 negative-control mutations used by `run.sh` |
| `run.sh` | runner with baseline gate, byte-for-byte golden diff and self-tests |
| `expected/` | committed evidence (`c01_matrix.jsonl`, `c02_lifecycle.jsonl`, `c03_posthoc_probe.jsonl`), `summary.md`, `gates.json` |

Every candidate and control is a `DeviceIdentityResolverInterface` implementation inside this folder, injected the way a Host would
inject one (`RateLimiterBuilder::withDeviceIdentityResolver()`); `c02` proves that path gives the same decisions as the Lab.

## Reproduce

```bash
composer install
bash investigations/ua-client-identity-97/run.sh            # ~55 s; diffs against expected/
UPDATE=1 bash investigations/ua-client-identity-97/run.sh   # regenerate expected/ (review the git diff)
```

`run.sh` fails closed:

* it refuses a baseline that is not an ancestor of HEAD, and any difference or uncommitted change under `src/`, `tests/`, `docs/`
  or the Composer files;
* every scenario aborts (exit 4) if a loaded runtime file is not the pinned baseline, or if an earlier study's candidate selector
  (`WSB_CANDIDATE`, `OQC_ARM`, `GNH_ARM`, `OAAA_ARM`, `LAB_ROOT`) is set;
* the three evidence files, `summary.md` and `gates.json` must be byte-identical to `expected/`;
* `report.php` exits 5 on invalid evidence (missing / malformed / mislabelled files, wrong provenance, unknown / duplicate /
  missing cells or rows, fields outside the exact schema, characters outside the decision alphabet, steps not allowed by the flow,
  event kinds or actors that differ from the stream definition, metrics that differ from their re-derivation, a missing
  counterfactual, an attribution that does not cover the denied actors) and exit 6 when the evidence is consistent but a pinned
  observation is not reproduced;
* `selftest.php` produces 20 mutations; 16 must exit 5 and 4 must exit 6 — the latter are internally consistent tamperings that
  would mask the evasion result, the NAT regression, a baseline drift or a composition mismatch.

`results/` is git-ignored scratch.

## Not covered

OTP, real Redis and concurrency (no claim depends on atomicity; see REPORT §9), DEC-015 backend failure beyond a code reading,
and any custom policy other than `API_OVERUSE`.
