# G1 targeted falsification — DA punishment ablation and T1 negative fixtures (pre-declared)

> **Status:** PRE-DECLARATION, committed **before** any result of this step. Responds to the Principal Architect's G1
> verification addendum review
> ([PR #126 comment 6228568914](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6228568914)):
> * F126-G1-R2-01: the optional, narrowly scoped DA ablation;
> * F126-G1-R2-02: T1 negative fixtures.
>
> Scope: R-1 / G1 isolated evidence only. No `src/`, DTO, preset, standard, ADR, policy, threshold or issue change; no G2.
> **Append-only:** `../PLAN.md`, `VERIFY_PLAN.md`, the original and addendum harnesses, and both archived evidence sets
> (`../expected/`, `expected/`) stay unchanged. The runners must still reproduce them byte for byte. New results go to
> `expected_da_t1/`.

## 1. DA ablation (F126-G1-R2-01)

### 1.1 Arms (same pipeline otherwise; all built on the trace build so every denial is attributed)

| Arm | Patch on top of `patches/g1-trace.diff` | Meaning |
| --- | --- | --- |
| **CAND** (trace) | none | the unchanged candidate, as archived (reference) |
| **CAND-DA-M** | in `checkDistributedAccountAttack()`, the persistence of the **other member K5 keys** of the device-window snapshot is removed. The current request's own K5, the level-4 K4 on the third occurrence and the HARD candidate are kept | the literal reading of the review: no member K5 persistence |
| **CAND-no-DA-punishment** (primary) | the distributed-account check still runs and still records its device window and occurrence snapshots (**observation kept**), but its candidate is discarded: no decision and no persistence on any K5 or K4 | the review's principle: no device is penalized on group association alone |

The flood rule (also a device-population rule) is **not** changed; its origins are reported.

### 1.2 Measurements (same parameters R, R-src4, R-src40; same adversarial strategies)

* **Matrix:** every stream × strategy × flow of M01 on each arm, with trace origins, C9-H event checks and both C1-H recounts. The CAND arm must reproduce the archived `dec` / `lanes` / `reach` strings.
* **Owner effect isolation:**
  * MX and its U-family decomposition, as in the addendum.
  * New fixture **MXE (proof expiry on the same device):** owner of `acct-mxe` on one device (`198.51.100.160`, Firefox 130, `sd-mxe-owner`). The owner holds a proof for 0–600 s (P). Proof expired for 600–1500 s, so the same device is untrusted with no proof (U). The owner re-proves for 1500–2400 s (P), with an attempt every 120 s throughout. In parallel, other unknown devices (rotating sources and UAs) fail on `acct-mxe` every 20 s. Control: the same owner sequence without other devices.
* **Device and lifecycle outcomes:** the original `../scenarios/m02_device.php` (DV-1 … DV-6) and `../scenarios/m04_lifecycle.php` (K5-1 … 3, DEC-021 coupling, DEC-007 on K4P, LT-1 S1–S6) are run unchanged on each arm and compared with the archived CAND rows.
* **Reported per arm:**
  * benign denials by origin (D2, MIX, all benign streams);
  * `reach_foreign`, `reach_proof`, `reach_own`, per-target reach and verifier calls for every attack stream, as deltas against CAND (S0 is shown for reference only, never as a target).

### 1.3 Engineering variant GO / NO-GO (fixed now)

A variant is **NO-GO** if any of these holds:

| ID | Condition |
| --- | --- |
| N1 | a hardened gate fails on its matrix (C1-H both recounts, C9-H, C10-H) |
| N2 | any benign denial has origin `distributed_account_attack` |
| N3 | in MX (every U-family subset) or MXE phase C, a proven owner's P decision differs from its no-other-devices control **and** the difference is attributed to a device-population origin (`distributed_account_attack` or `flood`) |
| N4 | any attack cell admits more than the admission caps (I1 / I3 event recount) |

Otherwise the variant is **GO as an engineering component**: the DA group punishment can be removed without breaking the measured invariants. Its reach and NAT deltas are reported as its security cost.

A variant GO is not an adoption GO. The package proof boundary (C8) and T1 (§2) are separate prerequisites, and the Lead's adoption NO-GO for the current candidate stands.

## 2. T1 negative fixtures (F126-G1-R2-02)

Run on the **original** stores (`../lib/admission.php`, unchanged: these fixtures are expected to expose the defect and are kept as failing fixtures). Also run on a separately named fixed store pair in `lib/` (`G1RefStoreV2`, `G1RedisStoreV2`): validate every key before the first write, refuse an existing permit id (`permit_exists`), and never write device sets on refusal.

| ID | Fixture | Required (fixed store) |
| --- | --- | --- |
| T1-N1 | the permit key exists with a wrong type (string) | refusal or error **before** any counter write; all counter values unchanged |
| T1-N2 | the permit id already exists in state `CLAIMED` (re-issue the same id) | refused; the claim state stays `CLAIMED`; a second `accepted` is impossible |
| T1-N3 | the permit id already exists in state `ISSUED` | refused; the record is unchanged |
| T1-N4 | admission refused by a counter while the P device sets hold expired members | the current and previous device sets are byte-identical before and after |
| T1-N5 | admitted with a previous generation present | the previous-generation device set is never written (read-only) |
| T1-N6 | regression | R-1 race (exact admission), R-2 device-set race, R-3 value-level all-or-nothing and the H-4 / R-8 claim series all hold on the fixed stores (Redis and reference) |

**T1 status rule:**
* The original T1 is reported **NOT RECOMMENDED**, with its failing fixtures kept.
* The fixed store is reported as "fixtures pass in isolation (single Redis), `UNPROVEN` for production".
* T1 stays optional. A T0-only architecture is evaluated without it.

## 3. Outputs

* `expected_da_t1/*.jsonl`, `gates_da_t1.json` and `summary_da_t1.md`, reproducible with
  `bash investigations/k2-identity-admission-97/g1/verify/run_da_t1.sh`.
* That runner first requires the original study and the addendum to compare clean.
* Report: `../../G1_DA_T1.md`, with the variant GO / NO-GO.
