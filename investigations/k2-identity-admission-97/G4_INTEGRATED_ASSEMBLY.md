# G4 — Integrated assembly: identity, attribution, budgets and device binding (#97)

> **Status:** G4 INVESTIGATION EVIDENCE for architectural review. Not an ADR, not a policy, not a production value, not a
> statement that Runtime is ready, and not an Owner decision on `U_src`, T or K5 separation. No change to `src/`, `tests/`,
> `composer.json`, public contracts, presets, thresholds, ADRs, standards, or the G1 / G2 / G3 evidence. DEC-015, DEC-019 and
> DEC-021 stay ACTIVE.
> **Pre-registration:** [`g4/integrated/PLAN.md`](g4/integrated/PLAN.md) (plan v1), committed in `da859ef` **before** any G4 harness
> code or result. Gates, values and verdict rules were not changed afterwards. Development corrections made before the first
> archived run, interpretations of the plan text, and post-hoc diagnostics are listed in §6; none of them changes a gate.
> **Owner input:** K5 separation by binding approved **for this study only** (2026-10-18); T = 30 minutes stays the study value.
> **Evidence classes:** `MEASURED` (archived harness output), `DERIVED` (follows from stated premises), `POST-HOC` (added after the
> first results, not in any gate), `MODEL` (the CGN-B admission-layer simulation), `UNPROVEN`.
> **Input deviation F131-01 (found in review, 2026-10-18):** in the `STREAMS` fixture and in every `BRU24`-based fixture
> (`BRU24`, `BRU24-HV`, the attacker rows of `REC`) the instance id never reached the pipeline. The Host binding model received it
> and lane selection is as planned, but the pipeline received the original context. The archived results and the recorded verdict
> are kept unchanged as the result of **this exact harness**; the gates built on those fixtures are **not evidenced as planned**.
> Scope and the claims that remain usable are in §6.4. This is not a clean measurement of the full assembly.

## 1. Baseline and provenance

| Item | Value |
| --- | --- |
| Source baseline | `main@730e4e1ed731d3c1d01eec45aadd49466b2a19a5`; `src/`, `composer.json`, `composer.lock` byte-identical to the G1 baseline `4c6ca61` (checked by `run.sh`) |
| Builds | `CAND` and `NOESC` from the G3 builder (pipelines `1840d6e2…dfd9`, `2926e1c5…1db4`); `G4` = NOESC + [`g4-k5-separation.diff`](g4/integrated/patches/g4-k5-separation.diff) (patch `7af55e4a…b278b1`, pipeline `45d201d0…63fd`); `C5` = CAND + the same patch (pipeline `3111c315…6f79`). All pinned in [`build_g4.sh`](g4/integrated/build_g4.sh); the builder fails on any other difference |
| K5 separation patch | On the P lane only, the K5 key, the K5 micro-cap key, the device Anti-Equilibrium watcher and the DEC-021 known-device allowance use `k5p` / P-lane purposes, so P-lane device history is written only by P-lane outcomes |
| New code | [`lib/g4.php`](g4/integrated/lib/g4.php) (Host binding model `G4Host`, gate `G4Gate`, replay `g4Replay`), three scenarios, `report.php`, `run.sh`. G1 / G3 / G3 v2 libraries and the G3 builder are reused read-only |
| V0 | **PASS**: 34 rows of S24 on CAND through the G4 replay equal the G3 v2 archive field for field |
| Reproduction | `bash investigations/k2-identity-admission-97/g4/integrated/run.sh`; two consecutive runs byte-identical. Exit 3 = outputs identical, harness valid, a registered gate fails |

## 2. Verdict (`PLAN.md` §5)

**NO-GO AFTER EXPERIMENT — failed gates A1, C4, E, P** — the recorded result of the archived harness. Every detector fired (C1,
C3, C5, E). The last column marks the gates whose fixtures are affected by F131-01 (§6.4): their numbers are what this harness
measured, and they are **not evidenced as planned**, whether they passed or failed.

| Gate | Result | `MEASURED` (`FULL`) | Input (F131-01) |
| --- | --- | --- | --- |
| V0 | PASS | 34 / 34 rows equal | as designed (no ids by definition) |
| **A1** benign ≤ S0 and ≤ S24 | **FAIL** | L1 72 (S0 0), ABA_B 94 (S24 72), Y6b 10 (S24 0); every one of them `cap:pproof` / `cap:pproof_day` | **affected** (`STREAMS`) |
| A2 MIX ≤ S0 | PASS | 54 (S0 117, S24 63) | **affected** (`STREAMS`) |
| A3 spray ≤ S12 | PASS | SP1n 4, SP3 8, SP6 24, ABA_A 8 (S12: 12, 24, 72, 24; S0: 4, 7, 20, 3) | **affected** (`STREAMS`) |
| B1 per-account bound | PASS | BRU24 51 admitted, recount 51, ceiling 52 | **affected** (`BRU24`) |
| B2 recovery | PASS | wait from arrival 60 s at δ = 0, 0 for every other δ | **affected** (attacker rows from `BRU24`) |
| C1 bound owner under harassment | PASS | HAR-B 0 owner denials; detector `NO-BIND` 14 | as planned |
| C2 Host-violation hole | PASS | BRU24-HV 51 admitted (G3 v2 `TBK30R-HV`: 5,760) | **affected** (`BRU24-HV`) |
| C3 cross-account | PASS | 0 P admissions on 200 victims, reach 4 (= `NO-BIND`); detector `WEAKBIND` 6 P admissions on victims | as planned |
| **C4** stolen token | **FAIL** | thief ≤ 2 P per window, 9 in 24 h, 0 after the epoch bump (all pass); **the owner's U login after the bump is never admitted** while the thief keeps failing | as planned |
| C5 NAT bootstrap | PASS | BOOT 0 / 383 refused; BOOT-X 0 refusals of bound instances; detector `NO-BIND` refuses 28 such requests | as planned |
| C6 CGNAT (`MODEL`) | PASS | N = 5,000, λ = 1: day-3 refusal share 0.0096 %; with the sprayer 0 refusals of bound clients | not applicable (no pipeline) |
| **E** E2 | **FAIL** | owner rows differ with and without the population; detector `NO-K5SEP` differs too | as planned |
| **P** checkOnly-only Hosts | **FAIL** | `pre`: L1 72 (≤ 0), D2 61 (≤ 36) | **affected** (`STREAMS`) |

## 3. Results (`MEASURED`, `pre+out`, legitimate denied / best-response attack reach)

> Every row of this table comes from `STREAMS` and is affected by F131-01 (§6.4): the pipeline did not receive the instance ids.

| Stream | S0 | S24 | **FULL** | FULL-40 | NO-BIND | NO-FCN | NO-TB | NO-K5SEP | ESC |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| L1 | 0 / 0 | 0 / 0 | **72** / 0 | 72 / 0 | 0 / 0 | 72 / 0 | 72 / 0 | 72 / 0 | 72 / 0 |
| ABA_B | 118 / 0 | 72 / 0 | **94** / 0 | 94 / 0 | 72 / 0 | 94 / 0 | 94 / 0 | 94 / 0 | 94 / 0 |
| D2 | 117 / 0 | 39 / 0 | **24** / 0 | 2 / 0 | 24 / 0 | 24 / 0 | 24 / 0 | 24 / 0 | 39 / 0 |
| Y6b | 28 / 0 | 0 / 0 | **10** / 0 | 10 / 0 | 0 / 0 | 10 / 0 | 10 / 0 | 10 / 0 | 10 / 0 |
| N12 / Nu4 / OFF12 / L3 / D1 | 56 / 47 / 8 / 104 / 596 | all 0 | **all 0** | all 0 | all 0 | all 0 | all 0 | all 0 | all 0 |
| KD / UAC | 9 / 6 | 9 / 6 | **9 / 6** | 9 / 6 | 9 / 9 | 9 / 6 | 9 / 6 | 9 / 6 | 9 / 6 |
| MIX (legit / attack) | 117 / 0 | 63 / 27 | **54 / 8** | 42 / 8 | 54 / 8 | 51 / 27 | 54 / 8 | 54 / 8 | 66 / 8 |
| SP6 / SP3 / SP1n / ABA_A | 20 / 7 / 4 / 3 | 144 / 48 / 24 / 48 | **24 / 8 / 4 / 8** | 24 / 8 / 4 / 8 | same | 144 / 48 / 24 / 48 | same | same | same |
| POI | 80 | 180 | **96** | 96 | 96 | 180 | 96 | 96 | 96 |
| X1 / BRU / BRD | 2 / 3 / 3 | 2 / 3 / 3 | **4 / 5 / 5** | 4 / 5 / 5 | 4 / 5 / 5 | 4 / 5 / 5 | 17 / 80 / 80 | 4 / 5 / 5 | 2 / 3 / 3 |
| X2 / X7 | 121 / 40 | same | same | same | same | same | same | same | same |

Every arm, the `pre` table, every fixture, the E2 owner rows, BOOT per day, CGN-B and the necessity table are in
[`g4/integrated/expected/summary.md`](g4/integrated/expected/summary.md); gate details in
[`gates.json`](g4/integrated/expected/gates.json).

## 4. Findings

### 4.1 What binding delivers (`MEASURED`)

Binding is the component that does what the earlier studies could not:

* **The owner on a bound device is not refused because of strangers.** HAR (30 rotating sources failing every 10 s on the owner's
  account): 0 owner denials, against 14 without binding.
* **A Host's false "known" flag no longer selects the proven door.** The flag selects nothing; only a verified token opens P (lane
  selection is unaffected by F131-01). The BRU24-HV count of 51 against 5,760 for G3 v2 comes from an affected fixture (§6.4).
* **A token never works on another account.** CROSS: 0 P admissions on 200 victims; the weak boundary (no account check) admits 6.
* **Offices and CGNAT.** BOOT-X (40 browsers behind one IP, sprayer on the same IP for 8 h): 0 refusals of bound browsers, against
  28 refusals of the same requests without binding. CGN-B (`MODEL`, N = 5,000, λ = 1): day-3 refusal share 0.0096 % with binding,
  41.9 % without; during a full day of spraying at 1 / s, 0 refusals of bound clients (45.3 % of all requests refused, all of
  them from clients not yet bound).
* **checkOnly-only Hosts.** Issuance needs no outcome reporting, so binding exists in `pre` too. The `pre` counts (L1 122 → 72,
  Nu4 41 → 0, Y6b 26 → 10, UAC 8 → 4, FULL against NO-BIND) come from `STREAMS` and are affected by F131-01 (§6.4).
* **Stolen token: bounded and revocable.** The thief who copied the owner's id and token reached 9 P-lane attempts in 12 h (bounded
  by `N_P` and K4P penalties), and 0 after the epoch bump. The thief's P-lane failures cost the owner 2 of 6 P logins before the
  bump (`soft`, the shared-account P penalties the proposal already declares in §7 "Stolen cookie").

### 4.2 Failure 1 — P-lane budgets refuse heavy bound users (A1, P)

> F131-01: every count in this subsection comes from `STREAMS`. The mechanism (`P_proof` is keyed by the token id that the Host
> model issues, and successful P-lane logins are charged to it) does not read the context's `sessionDeviceId`; whether the counts
> change with the planned input is **not evidenced**.

The G1 P-lane budgets (`P_proof` 6 per window and 30 per day per proof reference) were sized for a **proof**: something an
attacker might hold and use to guess. Under binding, the token **is** the everyday credential of a legitimate device, so a
success-heavy device spends the budget on its own successful logins. Every A1 failure has that single cause:

| Stream | FULL denials | Cause | Same stream on U (NO-BIND) |
| --- | --- | --- | --- |
| L1 (134 logins from one device) | 72 | `cap:pproof` 38, `cap:pproof_day` 34 | 0 |
| ABA_B | 94 | `cap:pproof` 94 | 72 |
| Y6b | 10 | `cap:pproof` 10 | 0 |

The same cause gives L1 72 in `pre` (gate P). The other part of gate P is D2 61 in `pre`: the bucket cannot refund without
outcomes, so an office with typos loses requests; with the fixed window (`NO-TB`) D2 `pre` is 24.

`DERIVED` direction for a v2 (not tested): P-lane budgets that count failures net of successes (as the net failure cap does on
U), or a refund on success, so a bound device is limited in guessing and not in logging in.

### 4.3 Failure 2 — an owner who falls back to the strangers' door is starved while the attack continues (C4, E)

When a bound owner loses P (token expired, epoch bumped after a password change, new device, cleared cookies) the next login is U,
and the per-account bucket is shared with whoever is attacking the account. Under a continuous rotating attacker the bucket refills
one token per T and the attacker, who asks every 15 s, takes it:

* **STEAL:** after the password change the thief keeps failing every 15 s from rotating IPs. The owner, retrying every 60 s, is
  **never** admitted in the remaining 12 h: 719 owner attempts, every one refused `cap:acct_tb`, while the thief is admitted 27
  times (the bucket bound). With the fixed window
  (`NO-TB`) the owner is admitted at once, but that arm loses the account bound (BRU24 2,304).
* **E2:** the 600 s token expires; with the population failing on the account, the owner's U logins are refused (`acct_tb` 28) and
  the owner is never rebound; without the population the owner rebinds at 900 s and stays on P.
* **REC passes** only because its attack **stops**; recovery within T is a property of the end of an attack, not of an ongoing one.

`POST-HOC`: moving the owner 5 s after a thief slot does not change the result, because the deterministic replay phase-locks the
refill to the thief's 15 s grid; with random arrivals the owner would win a refill now and then, at odds near the ratio of the
two request rates. The structural point stands: **the strangers' door gives an owner no priority over an attacker on the same
account**, and binding only helps while the owner holds a valid token.

`DERIVED` directions (not tested): the Host issues the new binding as part of the password-change / recovery flow (a proven event)
instead of through a U login, and a longer, sliding token lifetime reduces how often an owner falls back to U. Either needs its own
pre-registered measurement.

### 4.4 E2 and K5 separation

* In every arm with binding the owner's first P phase (0–540 s) is identical with and without the population: **the population never changes
  an owner decision while the owner is on P.**
* The E2 divergence starts at token expiry and is the U-lane starvation of §4.3, not device history.
* `POST-HOC`: in the control run (no population) `NO-K5SEP` refuses the owner on P with `pen:K5` (2) and `soft` (10), besides
  `cap:pproof` (4), because the owner's own U failures at 630–870 s are written to the K5 key the P lane reads; with K5 separation
  the same rows show only `cap:pproof` (12). K5
  separation therefore removes the carry-over from U history into P, but the registered measurement (E2 identical, binary) fails
  in both arms for the §4.3 reason and cannot show it. Under the necessity rule K5 separation is **"not shown necessary"**.

### 4.5 Necessity (Q3, registered rule)

> F131-01: the rows built on `STREAMS` or `BRU24` (A-gates, BRU24, BRU24-HV, REC, `pre`) are affected; HAR-B, BOOT-X, CGN-B, CROSS,
> STEAL and E2 are not.

| Removed | Registered expectation | Worse than FULL (`MEASURED`) | Result |
| --- | --- | --- | --- |
| Binding (`NO-BIND`) | C1, C5, C6 | HAR-B owner 14; BOOT-X bound-instance refusals 28; CGN-B day 3 8,713 refusals (FULL 2), with sprayer day 2 21,066 (9,587); UAC 9; `pre` L1 122 | shown |
| Net failure cap (`NO-FCN`) | A3 | SP6 144, SP3 48, SP1n 24, ABA_A 48 (FULL 24 / 8 / 4 / 8); POI 180; CROSS reach 24 | shown |
| Bucket (`NO-TB`) | B1, `pre` BRU | BRU24 and BRU24-HV 2,304 (FULL 51); `pre` BRU 80 (5); X1 17 | shown |
| K5 separation (`NO-K5SEP`) | E | none of the registered measurements (§4.4) | not shown necessary |
| No escalation on U (`ESC` restores it) | B2 | REC wait 1,860 s at δ = 0 (FULL 60); D2 39 (24); MIX 66 (54) | shown |

Removing binding **improves** L1, ABA_B and Y6b (back on U), which is §4.2 seen from the other side.

### 4.6 Q4 — `U_src` 24 or 40 (reported, no winner declared)

> F131-01: the stream rows of this comparison are affected; the CGN-B rows are not.

With binding and the net cap, 40 lowers D2 from 24 to 2 and MIX from 54 to 42 in `pre+out`, and CGN-B bootstrap refusals (day 1,
N = 5,000, λ = 1: 3,996 → 987; λ = 4: 47,729 → 25,924). Single-IP spray in `pre+out` is the same (the failure cap binds first). In
`pre`, where the cap is inert, 40 lets spray through at 40 per window: SP6 240 against 144. The choice is a trade between
checkOnly-only Hosts and office / CGNAT bootstrap; it stays an Owner security decision.

### 4.7 What binding does not fix (`MEASURED`, residual)

* **Newcomers next to a co-located sprayer.** BOOT-X day 1: 68 of 125 requests refused, all from browsers not yet bound
  (`cap:fail_src`). CGN-B with the sprayer: 45 % of day-2 requests refused, again almost all unbound. A new browser cannot be told
  apart from the sprayer on the same IP (§2.9); binding only protects after the first success.
* **MIX** (54) remains the co-located residual, below S0 and S24.
* **X1 / BRU / BRD** reach 4 / 5 / 5 against 2 / 3 / 3 on S0: the bucket's initial C = 4, as in G3 v2.

## 5. What this means for the proposal (`DERIVED`, not decisions)

> Read with F131-01 (§6.4): the binding claims rest on the unaffected fixtures (HAR-B, CROSS, STEAL, BOOT / BOOT-X, CGN-B, E2);
> the budget claims rest on affected ones.

| Proposal item | Effect of G4 |
| --- | --- |
| §7 trust from success | The binding behaves as the proposal claims for owner protection, NAT, CGNAT, Host violations, cross-account use and stolen tokens. It is not adoptable with the G1 P-lane budgets: those must stop charging successful logins of bound devices (§4.2) |
| §6.2 per-account bucket | Bounds the attacker exactly as measured before, but an owner who falls back to U during a continuing attack is starved (§4.3). The fall-back path (rebinding after password change, token lifetime) needs a design and a measurement |
| §15 decision 3, K5 separation | Its measured effect is the removal of U-history carry-over into the owner's P decisions (§4.4, post-hoc); the registered gate could not show it. It stays an Owner decision |
| §6.3 `U_src` | §4.6 |

A v2 would need a new pre-registered plan; this report does not open one.

## 6. Plan conformance

### 6.1 Development corrections before the first archived run

The harness was run while it was being written; the archived results come from the code in this commit. Corrections made before
the archive:

1. **Provisioning time.** Token-provisioning events first used the stream-relative time while the Host compared it with the
   cell's absolute clock, so every provisioned token was already expired (KD and UAC stayed on U). Events now receive the absolute
   time (`g4Replay`).
2. **STEAL.** A first draft let the owner log in after the bump with a new instance id; the plan says the owner logs in again
   from the bound device, which the archived code does.
3. **E2 and BOOT-X.** First drafts did not follow the plan's shapes (population window, sprayer day); the archived code follows
   `PLAN.md` §2 as interpreted in §6.2.

### 6.2 Interpretations of the plan text

| Plan text | Implemented as |
| --- | --- |
| BOOT-X "sprayer on day 1"; C5 detector "day-1 request" | the first of the three days (09:00–17:00), consistent with CGN-B "day 2" / "day 3" counting from 1 |
| E2 "U attempts at 630–870 s with 3 failures" | three failed U attempts at 630, 750 and 870 s |
| E "owner P-lane decisions identical" | decisions and lanes of every owner row identical (the lanes differ as soon as the owner cannot rebind) |
| C4 "thief verifier calls ≤ 6 per window and ≤ 30 per day (`P_proof`)" | the thief's P-lane admissions with the stolen token; the thief's U attempts after the bump (27, bucket-bounded) are reported |
| A3 "best-response reach" | foreign U reach plus P reach, over the G1 strategies and both id policies |
| Necessity expectation "`NO-TB` → the `pre` BRU reach" while §2 lists `pre` for three arms | `pre` also runs for `NO-TB`, so the registered expectation can be evaluated; no gate uses it |

### 6.3 Post-hoc material (excluded from every gate and verdict)

The owner offset of +5 s in STEAL (§4.3), the decomposition of E2 (§4.4), and the cause breakdowns of the STEAL owner denials
before and after the bump (§4.1, §4.3; from a diagnostic replay of the archived fixture, not archived).

### 6.4 Input deviation F131-01 (independent review, 2026-10-18)

**Defect.** `g4FromStream()` (`lib/g4.php`) and `g4Bru()` (`scenarios/g4_fixtures.php`) build each row as
`$r + ['ctx' => g4Ctx(...), 'inst' => ..., 'holder' => ...]`. The source row `$r` already has a `ctx` key, and PHP's array union
keeps the left operand's key, so the replacement context carrying the instance id in `sessionDeviceId` is silently dropped. The
new keys `inst` and `holder` are added. Checked against the archived code: 12,512 of 12,512 `STREAMS` rows (every stream, strategy
and id policy) and 5,760 of 5,760 `BRU24` rows reach the pipeline without the declared instance id.

**Why it matters.** `DeviceIdentityResolver::resolve()` puts `sessionDeviceId` into the fingerprint hash even when it is not
trusted (`v2|ua|clientFp|sessionDeviceId`). That hash is the K5 material and the P-lane device member counted by `N_P`. In the
affected fixtures the pipeline therefore saw the stream's original device identity (account + UA in most rows), not the planned
one: the `fresh` / `const` id policies changed only the Host model's jar and lane selection, not K5 or `N_P`. Different results
between the two policies are not evidence that the pipeline received them.

| Fixture | Built by | Pipeline input | Gates |
| --- | --- | --- | --- |
| `STREAMS` (all arms, `pre+out` and `pre`) | `g4FromStream()` | **affected** | A1, A2, A3, P; Q4 stream rows; necessity rows from streams |
| `BRU24`, `BRU24-HV`; `REC` attacker rows | `g4Bru()` | **affected** | B1, C2, B2; necessity BRU rows |
| `REC` owner rows | `g4Row1()` | as planned | (B2 depends on the affected attacker rows) |
| `HAR-B`, `HAR-N`, `CROSS`, `STEAL`, `CLEAR`, `E2`, `BOOT`, `BOOT-X` | `g4Row1()` | as planned | C1, C3, C4, C5, E |
| `CGN-B` | admission model, no pipeline | not applicable | C6 |
| V0 | `g4FromStream(..., withIds: false)` | as designed: no ids by definition, so V0 cannot detect this defect | V0 |

**What stays usable.** The results of the unaffected fixtures: owner protection under harassment (C1), no cross-account use (C3),
the stolen-token bound and revocation, and the owner's starvation on U after revocation (C4), NAT bootstrap (C5), CGNAT (C6), and
E2 (E). Lane selection by the Host model in every fixture.

**What is not evidenced as planned.** Every number from `STREAMS` and from the `BRU24`-based fixtures, pass or fail: A1, A2, A3,
B1, B2, C2, P, the stream tables of §3 and `expected/summary.md`, Q4's stream rows, and the necessity rows built on them. No
claim is made that they would be numerically unchanged with the planned input.

**Disposition.** The plan, harness, `expected/` and verdict are left exactly as archived; nothing was regenerated. A corrected
measurement needs Owner authorisation and a new pre-registered plan, and must add an input-shape assertion (for every non-V0 row
that declares an instance id, the DTO's `sessionDeviceId` equals it) with a detector that fails when `ctx` silently reverts to the
original. The other array unions in the G4 code (`G4Gate::admit()`, `g4Row()`, the scenario emitters) add keys the left
operand does not have, so they are not affected.

## 7. Limits

Reference stores and a controlled clock; no Redis, concurrency or failover; synthetic streams and a CGN model; study-code tokens
and a Host model, not real cookies; the UA-free K5 material of the proposal is not implemented (the instance id is carried in
`sessionDeviceId`, untrusted); the stolen-token thief rotates its UA, so a thief keeping the owner's UA (bounded by `P_proof`
instead of `N_P`) was not measured; the proof-acquisition paths of G2 are not combined; multi-IP spray and `U_glob` are out of
scope. Test values only.
