# #110 Guarded Network Headroom — S0 vs TH5 vs GNH5 (evidence report)

Level of every number: **S0** = the unpatched runtime (`S0_RUNTIME_OBSERVED`, `EvaluationPipeline` sha-256 `4b259d33…f4fc`, baseline
`1ad9af2`); **TH5 / GNH5** = the isolated patched candidate (`CANDIDATE_EXPERIMENTAL_RUNTIME`, `patches/gnh-candidates.patch`
sha-256 `c01611bc…`, patched pipeline `d7413b9d…`). Every number is generated in [`expected/summary.md`](expected/summary.md) by `report.php` from
deterministic synthetic streams on the real pipeline (reference stores + controlled clock; official Redis store for G05/G06); none is a
production statistic. Counts are requests. Flows: `pre+out` = `checkOnly` then the outcome if allowed; `pre` = `checkOnly` only; `out` =
record-only. The design and its feasibility verdict are in [`ARCHITECTURE.md`](ARCHITECTURE.md). **Nothing here is a decision**: DEC-019 stays
ACTIVE, ×5 and B are experimental parameters, the Owner decides under Decision Governance.

## 0. The question and the measured answer

> Can the network budget be widened to ×5 with a real guard that prevents unbounded or uncontrolled excess, reduce the false blocks of #97 /
> #100 / #106 in a valuable way, at an operational and integration cost suitable for a general PHP library?

| Part of the question | TH5 (thresholds ×5 only) | GNH5 (×5 + guard) |
|---|---|---|
| Guard exists and holds while the backend is healthy (per scope, per window, under concurrency, rotation) | **No** — the zone between the S0 and the x5 trigger is free: 65/65 known-subject requests, 16/16 simultaneous (G1a FAIL) | **Yes** — exactly B per scope per fixed window, ≤ the remaining budget under simultaneous requests, carried across rotation (G1a PASS, normal mode only). During a budget outage the DEC-015 degraded path ALLOWS without the budget (measured 3 ALLOWs; G4f) |
| No full collapse vs S0 | **No** — 36 attack cells go to full reach, e.g. TMSd 2→200/200, TMSs 18→204/204, SP4 78→576/576, ABA_A (no account / API) 3→120/120 (G1b FAIL) | **No** — 15 cells, all where S0 was already weak or the stream is tiny: SP2 60→72/72, SP4u 480→576/576, Y2a 6→8/8, Y2c 18→20/20, Y4b / Y4d 1→3/3, D6 2→3/3, Y9b (`pre`) 3→12/12 (G1b FAIL) |
| Extra attacker reach | 71 cells above 5×S0, 12 cells where S0 admitted none; largest +590 (XN1n `pre`), +198 inside one 600 s window (TMSd) | 43 cells above 5×S0, 12 cells where S0 admitted none; largest +432 (SP4 `pre`), +48 inside one 600 s window (Y1 `pre`) (G2) |
| False blocks reduced (task fairness cells, Login `pre+out`, Σ legit denied) | 1732 → 847 | 1732 → 1279 |
| Never more legitimate denials than S0 | yes, 0 of 165 cells (G3a PASS) | yes, 0 of 165 cells (G3a PASS) |
| Contract / integration cost | none at the API; **not rollback-safe** (S0 code errors on its state) | none at the API (G4g PASS); rollback raises no exception, but S0 state is not preserved (G4h FAIL: S0 keys diverge, XN1 1/200 decision differs after rollback, mixed D2 29→23); two contract blockers recorded (attempt identity, cross-key reservation) |

**Answer: NO-GO for ×5 network headroom as tested, with a guard that is buildable and measurably bounded.** The guard turns "unbounded inside
the window" (TH5) into "≤ B per scope per window" (GNH5), and that bound holds exactly under concurrency. But a per-scope bound of this size
is not a small one: it multiplies by the number of scopes an attacker controls, it forgets an N−1 hover after 600 s where S0 remembers it for
1800 s, and it still lets 43 cells exceed 5×S0 and 12 cells where S0 admitted nobody. The fairness value is concentrated in the K1-isolated
offices (#106: Nu4 / Nu6 / Nu12 47→0) and is small where the issues are hardest (#100 ABA_B 118→106; #97 D1 596→584). Whether *any* increase over
S0 is acceptable is the Owner's decision (DEC-019 §5, §6 below); on the per-cell "no increase over S0" criterion of the #100 Lead review, both
candidate arms fail.

## 1. Gates

| Gate | TH5 | GNH5 |
|---|---|---|
| E0 baseline reproduction (S0: D2 117/115, SP1n/SP3/SP6 4/7/20, L1 127, D1 596, X1 2, X2 6, X7 6, Y9b 3, ABA_A 3, ABA_B 118, TMSd 2, TMX 200) | PASS (S0) | PASS (S0) |
| G1a Bounded Security — per-scope admission bound, from measured admissions, healthy backend only | **FAIL** (B1 65/65, B3 41/41, sliding 67, after rotation 40/40, C1 16/16, C2 8/8) | **PASS** |
| G1b Bounded Security — no full collapse vs S0 | **FAIL** (36 cells) | **FAIL** (15 cells) |
| G2 Security trade-off (5×S0 indicative, S0=0, full reach, absolute / per window) | OWNER_DECISION_REQUIRED | OWNER_DECISION_REQUIRED |
| G3a Fairness non-regression (165 cells with legitimate traffic) | PASS (140 improved) | PASS (139 improved) |
| G3b Fairness benefit + one-party NAT exhaustion | OWNER_DECISION_REQUIRED | OWNER_DECISION_REQUIRED |
| G4a Real Redis parity, zone admissions on the adapter, TTL, budget flags | PASS | PASS |
| G4b Concurrency on real Redis at the budget boundary | PASS (measurement valid: TH5 admits the whole burst) | PASS |
| G4c Rotation (3 modes × 7 streams), budget across rotation, IPv6 macro | **FAIL** (XN1 outer-only 46→69; macro H2 25 / H2b 31 > 24) | **FAIL** (XN1 22→26 under outer rotation, +4 > +3 tolerance) |
| G4d Rollback mid-window and mixed version with the REAL S0 code — **no exception only** | **FAIL** (reference store and Redis: S0 code throws on TH5 state) | PASS |
| G4h S0 parity after rollback (F115-01): S0-key state, churn → dilution, decisions | **FAIL** (state diverges; S0 throws) | **FAIL** (S0 keys differ in every tested history; dilution set of the churn-admitted device 0 on S0 vs 1 on GNH5; XN1 1 of 200 decisions differ after rollback; mixed D2 29→23) |
| G4e Storage keys / TTL bounds / set size | PASS | PASS |
| G4f Budget backend failure through the real engine; DEC-003 cycles — PASS = behaves as DEC-015 documents, **not** the B-bound | PASS (no budget op exists) | PASS (`FAIL_CLOSED` t=4–5, then **ALLOW/DEGRADED_MODE t=6–8**, then the fallback limit) |
| G4g Patch contract surface (no public method, interface or new store call) | PASS | PASS |

A FAIL is a **finding** (the evidence is valid). OWNER_DECISION_REQUIRED means the gate measures a trade-off no engineering gate can accept.

## 2. Protection — attacker attempts that reached verification (Login with account, `pre+out`)

| Stream | What it is | S0 | 5×S0 | TH5 | GNH5 | max per 600 s S0 / TH5 / GNH5 |
|---|---|---:|---:|---:|---:|---|
| ABA_A | 2 alternating fingerprints, new victim each, 1/10 s (#100 Lead control) | 3/120 | 15 | 47 | 15 | 2 / 24 / 8 |
| SP1n | spray, 1 IP, no fingerprint, 200 victims | 4/200 | 20 | 24 | 24 | 4 / 24 / 24 |
| SP1f | spray, fresh fingerprint per request | 2/200 | 10 | 14 | 8 | 2 / 14 / 8 |
| SP3 | stuffing, 1000 accounts at 1/s | 7/1000 | 35 | 47 | 47 | 4 / 24 / 24 |
| SP6 | 1 h sustained spray | 20/1200 | 100 | 140 | 140 | 4 / 24 / 24 |
| SP2 | N−1 hover 4 victims / 601 s, 3 h | 60/72 | 300 | **72/72** | **72/72** | 4 / 4 / 4 |
| SP4 | 8 IPs, 4 victims / IP / 601 s | 78/576 | 390 | **576/576** | 294 | 5 / 32 / 17 |
| SP4u | SP4, own UA per IP | 480/576 | — | **576/576** | **576/576** | 32 / 32 / 32 |
| SP4b | 3 victims / IP / cycle | 432/432 | — | 432 | 432 | 24 / 24 / 24 |
| SP5 | trusted device sprays (K1 advisory) | 200/200 | — | 200 | 200 | — |
| SP5c | trusted, fresh fingerprint per request | 2/200 | 10 | 14 | 8 | 2 / 14 / 8 |
| K1R4 | 4 victims repeated 10 min | 4/200 | 20 | 8 | 8 | 4 / 8 / 8 |
| X1 | 1 IP, fresh fingerprint, one victim | 2/61 | 10 | 2 | 2 | 2 / 2 / 2 |
| X2 | new IP per request, LOW | 6/121 | 30 | 30 | 18 | 5 / 29 / 17 |
| X7 | X2 + fabricated own-account successes | 6/121 | 30 | 30 | 18 | 5 / 29 / 17 |
| Y1 | 4-IP pool, fresh fingerprint and account | 8/121 | 40 | 56 | 32 | 8 / 56 / 32 |
| Y2a | 2 fingerprints / 601 s hover | 6/8 | 30 | **8/8** | **8/8** | 2 / 2 / 2 |
| Y7 | 3-fingerprint pool cycler | 3/241 | 15 | 47 | 15 | 2 / 24 / 8 |
| Y9a | IPv6 same /64, rotating interface id | 2/31 | 10 | 14 | 8 | 2 / 14 / 8 |
| Y9b | IPv6 variant | 3/12 | 15 | **12/12** | 9 | 3 / 12 / 9 |
| D5 | #105 attacker | 3/122 | 15 | 17 | 8 | 3 / 17 / 8 |
| D7 | #105 long attacker | 4/1801 | 20 | 40 | 22 | 2 / 14 / 8 |
| TMS | 12 valid trusted sessions, N12T cadence | 7/61 | 35 | **61/61** | 31 | 2 / 15 / 8 |
| TMSd | TMS dense, 1/3 s | 2/200 | 10 | **200/200** | 8 | 2 / 200 / 8 |
| TMSs | TMS sequential per target | 18/204 | 90 | **204/204** | 25 | 18 / 200 / 24 |
| XN1 | x5 N−1 gaming: 13 fingerprints × 23 victims cycled | 4/600 | 20 | 46 | 22 | 2 / 46 / 8 |
| XN1n | XN1 without fingerprint (K1 only) | 10/600 | 50 | 69 | 61 | 4 / 46 / 24 |
| XN1k | XN1 on K2 only (3 victims) | 4/600 | 20 | 6 | 9 | 2 / 6 / 6 |
| MIX | D2 office + spray attacker behind the same NAT | 0/200 | 0 | 20 | 6 | — |
| MIXN | isolated untrusted office + spray attacker | 2/200 | 10 | 37 | 35 | — |
| MIXT | trusted office + untrusted attacker | 3/200 | 15 | 35 | 16 | — |
| BURN | Nu12 office + neighbour spending the K1 headroom | 6/106 | 30 | 15 | 15 | — |

**Bold** = every attempt reaches verification. TH5 vs GNH5 differ most where the x5 trigger is never reached inside the window — fingerprint /
account cycling below the new N−1 (TMSd, TMSs, XN1, Y7, ABA_A) and the trusted multi-session attacker of #113, whose only cross-account
control is K2 churn: at cap 15 the 12 sessions never fire it on TH5; on GNH5 the budget bounds it (8/200). Where the x5 trigger *is* reached
(fresh victims: SP1n, SP3, SP6), the budget B = 20 = 25 − 5 adds nothing over TH5. Where S0 was already weak (SP2, SP4u), neither arm keeps
any of its remaining protection: the 600 s budget window forgets the hover that S0's 1800 s N−1 WATCH remembers.

Other flows and shapes (full rows in `expected/summary.md` §2, §4):

* **checkOnly-only Hosts (`pre`)** are hit harder on GNH5 than `pre+out` (spray is spent once per attempt in both, but `pre+out` failures also
  feed K4 / K5): XN1n 10→70, SP4 78→510, TMS 6→54, BURN 6→57, Y1 8→56.
* **Record-only (`out`)**: every attempt is verified before the library sees it on every arm (SP1n 200/200, ABA_A 120/120); the guard only
  bounds the ALLOW decisions returned.
* **Login without account id**: ABA_A TH5 **120/120**, GNH5 15 (S0 3). **OTP**: TMSd TH5 200/200, GNH5 8; ABA_A 3→47 / 15.
* **API_OVERUSE / DEC-008 custom API policy** (the patched rules are shared by every policy): Y7 `api` TH5 **123/241** (= the #107 PSC
  no-correlation control), GNH5 15; ABA_A `api` TH5 **120/120**, GNH5 15; XN1 `api` 4→128 / 22. The API cells are not protected separately.

## 3. Fairness — legitimate requests denied

| Stream | Issue | S0 `pre+out` | TH5 | GNH5 | GNH5 `out` / `pre` |
|---|---|---:|---:|---:|---|
| D1 | #97 LOW UA cohort, 600 users | 596/600 | 572 | 584 | 571 / 571 |
| L1 | #97 2 clients on a NAT | 127/134 | 0 | 85 | 44 / 44 |
| L1f | L1 with typos | 127/134 | 90 | 85 | 56 / 44 |
| ABA_B | #100 two colleagues alternating | 118/120 | 0 | 106 | 93 / 93 |
| D2 | #100/#106 NAT office | 117/121 | 69 | 93 | 68 / 67 |
| GAP | #100 WATCH gap | 50/52 | 0 | 38 | 25 / 25 |
| N3 / N6 / N12 | #106 office, shared UA | 56/61 | 0 / 5 / 0 | 32 | 7 / 7 |
| N12T / N12V | trusted / previously verified office | 56/61 | 0 | 32 | 7 / 7 |
| Nu4 / Nu6 / Nu12 | #106 K1-isolated office | 47/61 | 0 | **0** | 0 / 0 |
| OTPB | OTP office | 58/61 | 11 | 40 | 28 / 21 |
| MIX | office + attacker on the NAT | 117/121 | 78 | 99 | 78 / 79 |
| MIXN | isolated office + attacker | 49/61 | 22 | 22 | 0 / 22 |
| MIXT | trusted office + attacker | 58/61 | 0 | 37 | 26 / 23 |
| BURN | Nu12 office + budget-spending neighbour | 53/61 | 0 | 24 | 0 / 24 |

* **No regression**: on both arms no cell with legitimate traffic (165 cells, every shape and flow) is denied more than on S0 (G3a).
* **GNH5's benefit is capped by B and halved by `pre+out`**: the office cells N3…N12 are 32/61 in `pre+out` but 7/61 in `pre` or `out`,
  because churn / dilution spend the budget on both commands of an attempt (ARCHITECTURE §3, B.3).
* **One party can take the whole benefit away** (G02 B5): a neighbour with three of its own devices logging in successfully spends the K2
  budget in seconds; its colleague is then denied 15/15 on GNH5 exactly as on S0 (TH5: 0/15). BURN: the office goes 0 (Nu12) → 24/61 on GNH5.
* #97 (D1) is essentially unchanged by any ×5 arm: 30 IP scopes per UA cohort per 600 s is still far below a 600-user cohort.

## 4. The guard itself (sequential, concurrency, rotation, failure, rollback)

| Probe | S0 | TH5 | GNH5 |
|---|---|---|---|
| B1 K1 zone: 5 subjects then a known subject repeated (65 checkOnly) | 4 allowed, denied at t=4 | 65/65 | 24 (B=20 exactly), denied at t=24 |
| B2 two budget windows back to back (72 checkOnly) | 7 | 72 | 47; 20 + 20 per window; ≤ 39 in a sliding 600 s |
| B3/B4 K2 zone (41 attempts): `pre` / `pre+out` / `out` | 2 / 1 / 2 | 41 / 41 / 41 | 14 / 7 / 14 (B=12 commands each) |
| B6 renewal after exhaustion (probes 73…601 s) | re-issued like #100 | never denied | re-issued exactly like S0; passes at 600 s |
| G06 C1 16 simultaneous, 6 of B left (3 rounds) | 0 / 0 / 0 | 16 / 16 / 16 | **6 / 6 / 6**, window total 20 |
| G06 C2 8 simultaneous, 4 of B left | 0 / 0 / 0 | 8 / 8 / 8 | **4 / 4 / 4**, window total 12 |
| G06 C3 32 simultaneous distinct subjects, fresh scope | exactly 4 | ≤ 24 | ≤ 24, ≤ B headroom |
| G06 C4 both budgets in zone (K2 5 left, K1 15 left) | 0 | 8 | **5**, and ≥ 1 K1 slot spent by a request K2 refused (no cross-key reservation) |
| G03 R3 spent budget across outer rotation | 0 after | 40/40 after | exactly 10 after (10 spent before) |
| G04 F1 budget op fails (real engine) | unaffected | unaffected | `FAIL_CLOSED` L2/600 at t=4–5; after the breaker opens **ALLOW/DEGRADED_MODE at t=6, 7, 8** (the budget is not consulted), then the DEC-015 local fallback limit denies t=9–11 |
| G04 R1 / G05 P3 rollback mid-window to the REAL S0 code | — | **S0 throws** (`bounded distinct state exceeds its configured capacity` / Redis `malformed bounded state`) | no exception (that is all it proves) |
| G04 R2 / G05 P4 mixed version (alternating per request) | — | **S0 throws** on D2 | no exception; vs S0-only: SP1n reach 4 → 5, D2 legit denied 29 → 23 |
| G04 R3 S0-family keys after the same history, key for key | — | differ (S0 sets above cap) | **differ** in all 4 histories; churn-admitted device 3 reaches the original dilution set (S0 0, GNH5 1 member) |
| G04 R4 REAL S0 decisions after the arm's history vs after an S0-only history | — | S0 throws | identical on probe, ABA_A, D2; XN1 **1 of 200** differs |

The rollback hazard is a property of changing a bounded-set cap **in place**: any future cap change on these keys must use a new key
namespace (as GNH5 does) or a migration, otherwise every S0 node errors on every scope above its cap for up to 600 s.

## 5. Operational cost (measured)

* **Storage**: GNH5 keeps the S0 keys plus one x5 set per S0 rule set and one budget flag (TTL 600 s) per in-zone rule scope (G04 S1: 2 budget
  flags, 8 x5 sets for two in-zone scopes). Peak correlation keys / members: D2 28/48 (S0) → 50/114 (TH5) → 55/99 (GNH5); D1 3002/3006 →
  3002/3030 → 4205/4236; SP6 41/44 → 98/121 → 103/128. Largest set 25 members (≤ 30). Every short-TTL key is gone 1801 s after a stream; no new
  long-TTL key (G04 S2). Redis: every key has a TTL (G05 P2, G06 T1).
* **Store operations**: in the zone GNH5 adds one bounded-set operation per rule evaluation and one WATCH increment per headroom evaluation;
  outside the zone one extra bounded-set operation. Per-request latency was **UNMEASURED**.
* **Integration**: no public API, DTO, Builder, Policy or Store-interface change; custom stores that honour the existing atomic contracts need
  nothing new (argued from the contract; only the reference and official Redis stores were run).

## 6. Decisions only the Owner can take before any Runtime work

1. **Is any increase over S0 attacker reach acceptable at all** (DEC-019 §5), and if so the absolute cap per window and per concurrent burst,
   the rule for cells where S0 admits none (12 cells here), and for cells where S0 is already weak (SP2, SP4u)?
2. **Budget unit**: commands (today, fail-closed, halves `pre+out` benefit) or logical attempts — the latter needs an attempt identity in
   `RateLimitCommand` or a Host signal (**contract change**).
3. **Budget window vs N−1 memory**: a 600 s budget forgets what S0's 1800 s WATCH remembers; choose ≥ 1800 s or carry the WATCH into the
   budget (changes the fairness benefit — unmeasured).
4. **Per-scope vs per-attacker bound**: botnets / IP pools multiply B (SP4, Y1); a bound above K1/K2 is not modelled by the package.
5. **Cross-key reservation**: accept the measured fail-closed over-spend, or add an atomic multi-key Store capability (**contract change**,
   cost for custom stores).
6. **API_OVERUSE / custom policies**: the rules are shared; separate parameters per capability (DEC-008) were not designed here.
7. **Value of ×5 and of B** (20 / 12 / 24 here, experimental) — B = x5 cap − S0 cap gives nothing over TH5 on fresh-victim sprays.
8. **Any cap change on bounded sets must be rollback-safe** (new namespace or migration) — measured TH5 hazard.
9. Whether to act on the incidental S0 observation in §8 (no Issue opened).

## 6b. Corrections after the Lead review (F115-01, F115-02)

* **F115-01** — the earlier claim that GNH5 writes the S0 keys "exactly as S0 would" was wrong. G04 R1/R2 / G05 P3/P4 prove only that the S0
  code raises no exception on GNH5 state. The new G04 R3/R4 measure the state and the decisions: the S0-family keys diverge in every tested
  history, including the churn → dilution path the Lead identified, and the REAL S0 code then decides 1 of 200 XN1 requests differently;
  a mixed fleet denies D2 less (23 vs 29) and lets SP1n reach once more (5 vs 4). Gate G4h records this as FAIL; G4d now says "no exception".
* **F115-02** — G1a is a normal-mode property only. G04 F1 (unchanged numbers) shows `ALLOW/DEGRADED_MODE` at t=6–8 while the budget is not
  consulted: during an outage the bound is the existing DEC-015 fallback, not B. G1a no longer uses F1 and says so; G4f PASS means "as
  DEC-015 documents", not "B retained". A B-bound during outages (global, parallel or per node) is UNPROVEN. DEC-015 is not changed or
  questioned here.

## 7. Measured vs not measured

Measured (this directory, both stores where stated): every task §6 protection and fairness cell (none dropped; the list is enforced by
`lib/cells.php` and `report.php`), Login / OTP / Login-without-account / `API_OVERUSE` / DEC-008 custom API, the three flows,
trusted / untrusted / previously verified (N12T, N12, N12V), IPv6 /64 → /48 → /40 macro scopes, three rotation modes, the budget across
rotation, rollback and mixed version with the real S0 code (reference store and Redis), DEC-003 cycle log, 60 s / 600 s boundaries and
renewal, backend failure through the real engine, real-Redis parity and concurrency, storage and TTL bounds.

**UNMEASURED** (never estimated):

* the B-bound during a backend outage (any form; the DEC-015 degraded path decides, measured on one sequence only);
* S0 state / decision parity after rollback for any history other than the four measured in G04 R3/R4;
* an explicit ±1 s probe at the 1800 s WATCH boundary on the x5 WATCH (the 1800 s carry-over is observed only through S0 B2 and TTL bounds);
* concurrency of `pre+out` bursts (failure phase) on GNH5 — only `checkOnly` bursts were run (OQC's two-phase barrier was not reused);
* a custom **authentication** policy (DEC-008) — only the custom API policy was run;
* custom Store implementations other than the reference store and the official Redis adapter;
* the engine path for the matrix (the matrix calls the real `EvaluationPipeline`; the engine is exercised in G04 F1 only);
* per-request latency and Redis round trips; production traffic.

## 8. Incidental S0 observation (out of scope, recorded only — no Issue opened)

G03 R4: with an outer-key-only rotation (a `previousKeySecret` configured without a `previousFingerprintSecret`; the two are independent
public settings), S0 **hard-blocks a single device with one stable fingerprint** from its 2nd request after the rotation (K2 churn counts the
device's previous-generation and current-generation member as two; N−1 WATCH). Same with no client fingerprint (LOW). TH5 / GNH5 do not deny
it (GNH5 admits it out of the churn budget). The fingerprint-bridged mode (`previousFingerprintSecret` set) does not show it. Not investigated
further; the Owner may decide whether it deserves its own study.

## 9. Relation to the earlier studies

| Study | What is reused here | What is not |
|---|---|---|
| #103 / #104 / #105 (Lab, wsb streams, D streams, Redis safety) | the harness and streams, unchanged | — |
| #107 PSC / EQL / EQV | the cap-sweep lesson: cardinality alone gives up Y7 / X7 — reproduced on TH5 (`api` Y7 123/241) | no candidate code reused |
| #108 K1/NAT | K1Store attribution, K1 streams; "K1 off" control logic | K1 is not disabled here |
| #111 OQC / OQCW / OQCE | streams ABA_A / ABA_B / K1R4 / SP5c / GAP / OTPB; the concurrency lesson (measure every candidate under real simultaneity) | outcome-based evaluation is not used: the budget is spent at pre-check, so a concurrent burst cannot leak past it (G06) |
| #113 trusted multi-session | TMS / TMSd / TMSs / TMR / TMX / TMU / TPAIR streams; the K2 cross-account bound | trust is not used as an exemption; TH5 shows what happens when K2 churn stops firing (TMSd 200/200) |
| #114 (per-account trusted failures) | not treated here (out of scope); K5 / budget are not assumed to protect | — |
