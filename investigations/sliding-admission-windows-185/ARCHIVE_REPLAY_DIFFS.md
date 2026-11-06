# Archive Replay Diffs: Tumbling vs Sliding Admission Windows (#185, DEC-033)

## 1. Overview and Scope

This document details all differences between historical tumbling window baselines and the sliding admission window replays introduced by **DEC-033**.

All golden files and expected replay outputs have been regenerated and verified against the true sliding window implementation (`RedisAdmissionOperations` sliding sorted sets and `PreSettlementReferenceStore` in-memory sliding sorted sets):
- `tests/System/AdmissionArchiveCgnModelTest.php`
- `tests/System/AdmissionArchiveFixturesTest.php`
- `tests/System/AdmissionArchiveG7Test.php`
- `tests/System/AdmissionArchiveStreamsTest.php`
- `tests/System/AdmissionBuilderRerunTest.php`
- `tests/System/AdmissionEngineSourceCapacityTest.php`
- `tests/System/AdmissionIpv6SourceEquivalenceTest.php`
- `tests/System/AdmissionNm10BindingTest.php`

All 8 archive test suites pass with 0 failures, proving 100% equivalence between the sliding Redis store and the sliding reference model.

---

## 2. Root Cause Analysis of Diffs

All observed diffs stem from three invariant physical properties of the sliding window mechanism:

### 2.1 Elimination of Window Boundary Tumbling Resets
- **Tumbling behavior:** Fixed epoch buckets (`w<intdiv(t, W)>` with $W = 600\text{ s}$ or $86400\text{ s}$) reset instantly at multiples of $W$. An attacker or traffic burst straddling boundary $t = 600$ could issue up to $2 \times \text{capacity}$ within an interval of length $< W$ (e.g., 4 failures at $t = 590$ and 4 failures at $t = 601$).
- **Sliding behavior:** Elements are retained in a Redis sorted set with member expiry score $t_{\text{event}} + \text{ttl}$. Counting queries prune expired elements ($t_{\text{expiry}} \le t_{\text{now}}$) and count active entries. Across *any* continuous time window of length $W$, the count is strictly bounded by the configured capacity.

### 2.2 Active Sliding Retention of Failure Sets
- **Tumbling behavior:** Cross-account failure sets (`ufail`, `pfail`) emptied at bucket rollover $t = 600$.
- **Sliding behavior:** Failures recorded near the end of a nominal 600s bucket (e.g. $t = 590$) remain active until $t = 1190$. This prevents attackers from resetting failure thresholds at boundary boundaries, keeping malicious sources suppressed.

### 2.3 Elimination of Split Window/Day Causes
- Windowed proof scopes are strictly evaluated against the sliding window ($600\text{ s}$) and day ($86400\text{ s}$) with member IDs, eliminating bucket-boundary artifacts where daily and windowed counters would misalign during tumble rollovers.

---

## 3. Inventory of Archive and Golden Changes

### 3.1 G4 Integrated V2: CGN Model (`v2_cgn.G4.jsonl`)
- **Rows updated:** 8 `FULL2` arm rows across various device/IP permutations in `investigations/k2-identity-admission-97/g4/integrated_v2/expected/v2_cgn.G4.jsonl`.
- **Cause:** Recomputed with sliding window counts matching `CgnModel::run` sliding semantics.

### 3.2 G7 Cross-Account Fixtures (`g7_fixtures.G5V2.jsonl`)
- **`BOOT-X` Fixture (lines 11, 32, 53):**
  - Refusals: increased from `68/96` to `76/102` (+8 refusals).
  - `noBindRefusedWhereFullBound`: increased from `18` to `26` (+8).
  - *Root Cause:* Boundary probe attempts at $t = 600$ that were previously admitted due to the tumbling bucket reset are now caught by the active sliding failure set.
- **`TOKEN` Fixture (lines 16, 37, 58):**
  - Attacker reach: dropped from `45/44/45` to `30/30/30` across 60s, 300s, and 600s intervals.
  - *Root Cause:* Token burst across any 600s interval is now strictly capped by proof capacity ($U_{\text{proof}} = 30$), eliminating the cross-boundary double-spend that allowed up to 45 permits under tumbling windows.
- **`np` Fixtures (lines 19, 40, 61):**
  - `pAdmitted` updated to `880`.

### 3.3 G7 Replay Streams (`g7_streams.G5V2.jsonl`, `g5v2_streams.G5V2.jsonl`, `v2_streams.G4.jsonl`)
- **`D-PXALL` Stream:**
  - Archived reach: `N12` = 32 (was 36), `N12T` = 40.
- **`MIX pre+out` Stream:**
  - `legitDenied`: decreased from 54 to 40 (`cap:fail_src` dropped from 42 to 28).
  - `attackDenied`: increased from 192 to 196 (+4 denials).
  - `attackReachMaxSliding`: reduced from 8 to 4 (strictly bounded by capacity 4).
  - *Root Cause:* Under sliding windows, the attacker cannot burst at the boundary. The persistent sliding failure set reliably suppresses the attacker, eliminating false resets and drastically reducing spurious collateral refusals for benign users sharing the subnet.
- **`MIX pre` Stream:**
  - `attackReachMaxSliding`: dropped from 27 to 7.
  - Verifier calls: dropped from 87 to 67 (-20 calls).
- **`L1 pre` Stream:**
  - Denials unified under `cap:proof` (72 denials) without tumbling window split artifacts (`cap:pproof: 38`, `cap:pproof_day: 34`).
- **`Y6b pre` Stream:**
  - `legitDenied`: 11 (was 10), attributed cleanly to `cap:proof: 11`.
- **`TPAIRT` Co-located Office Stream (G7 PX4):**
  - Attacker reach under cap: dropped from 72 to 40.
  - Co-located office cost: changed from 21 to 26 / 61.
  - *Root Cause:* Under tumbling windows, the attacker failed on lane P up to 4 times per 600s bucket; at boundary $t = 600$, the bucket reset, giving the benign office an opening. Under sliding windows, attacker failures recorded at $t = 590$ remain active until $t = 1190$. Legitimate office requests arriving in that overlapping window are refused (`cap:pfail_src`).
- **`TMS` / `TMSd` (G7 PX4):**
  - `TMS` reach: 21 (pre-registered bound: $\le 29$).
  - `TMSd` reach: 40 (pre-registered bound: $\le 40$).

---

## 4. Verification and Parity

All raw replay outputs were captured in `investigations/sliding-admission-windows-185/raw_archive_diffs.json`. Every divergence between tumbling and sliding windows has been reconciled, verified against the mathematical specifications of DEC-033, and confirmed by passing test suites.
