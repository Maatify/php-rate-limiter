# G4 v2 integrated re-assembly — generated summary

## Harness validity

| Check | Result |
| --- | --- |
| V0 | PASS: 34 rows equal the G3 v2 S24 archive |
| V1 | PASS: 74 Part R rows equal the G4 archive on the unaffected fixtures (HAR-B, HAR-N, CROSS, STEAL, CLEAR, E2, BOOT, BOOT-X, CGN-B) |
| IN | PASS: no input-assertion violation in any scenario |
| D-IN | PASS: STREAMS reverted 68/68 rejected, corrected 0/68 rejected; BRU24 reverted 1/1 rejected, corrected 0/1 rejected; HAR-B reverted 1/1 rejected, corrected 0/1 rejected |

## Part N — `FULL2`

Verdict (PLAN.md §7.3, pre+out Hosts): **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE (pre+out Hosts)**

Gate P (checkOnly-only Hosts, PLAN.md §7.4): **FAIL**

| Gate | Result | Detail | Detector | Evidence |
| --- | --- | --- | --- | --- |
| A1 | PASS | every benign stream ≤ S0 and ≤ S24 | — | pipeline, as planned |
| A2 | PASS | MIX legit 54 (S0 117) | — | pipeline, as planned |
| A3 | PASS | SP1n 4 (S12 12); SP3 8 (S12 24); SP6 24 (S12 72); ABA_A 8 (S12 24) | — | pipeline, as planned |
| B1 | PASS | admitted 51, recount 51, ceiling 52 | — | pipeline, as planned |
| B2 | PASS | δ 0: 60 ≤ 1860; δ 60: 0 ≤ 1800; δ 300: 0 ≤ 1560; δ 900: 0 ≤ 960; δ 1800: 0 ≤ 60; δ 3600: 0 ≤ 0 | — | pipeline, as planned |
| C1 | PASS | owner denials 0 | fired: R-NO-BIND owner denials 14 | pipeline, as planned |
| C2 | PASS | admitted 51, ceiling 52 | — | pipeline, as planned |
| C3 | PASS | victim P 0; reach 4 (R-NO-BIND 4) | fired: R-WEAKBIND victim P 6 | pipeline, as planned |
| C4v2 | PASS | thief P/window 2 ≤ 6, 24 h 9 ≤ 30, after the change 0; thief U after 27 ≤ 28; owner admitted after 0 s on P (≤ 60 s, P) | fired: N-NOPWB owner wait never | pipeline, as planned |
| C5 | PASS | BOOT refused 0 / 383; BOOT-X bound-instance refusals 0 | fired: R-NO-BIND refused 28 day-1 requests from bound instances | pipeline, as planned |
| C6 | PASS | day-3 share 9.6E-5; bound refusals with sprayer 0 | — | MODEL |
| E | PASS | identical: yes | fired: N-NORENEW differs: yes | pipeline, as planned |
| K5 | PASS | owner rows from 960 s identical between fail and ctl: yes | fired: N-NOK5SEP differs: yes | pipeline, as planned |
| P | FAIL | pre L1 72 ≤ 0, D2 61 ≤ 36 | — | pipeline, as planned |

## Part R — G4 with corrected input (`R-FULL`, G4 definitions)

Verdict (G4 PLAN.md §5): **NO-GO AFTER EXPERIMENT (A1, C4, E, P)**. G4 archive: NO-GO AFTER EXPERIMENT (A1, C4, E, P).

| Gate | Result | Detail | Detector | Evidence |
| --- | --- | --- | --- | --- |
| A1 | FAIL | L1 72 > S0 0 ({"cap:pproof":38,"cap:pproof_day":34}); ABA_B 94 > S24 72 ({"cap:pproof":94}); Y6b 10 > S24 0 ({"cap:pproof":10}) | — | pipeline, as planned |
| A2 | PASS | MIX legit 54 (S0 117) | — | pipeline, as planned |
| A3 | PASS | SP1n 4 (S12 12); SP3 8 (S12 24); SP6 24 (S12 72); ABA_A 8 (S12 24) | — | pipeline, as planned |
| B1 | PASS | admitted 51, recount 51, ceiling 52 | — | pipeline, as planned |
| B2 | PASS | δ 0: 60 ≤ 1860; δ 60: 0 ≤ 1800; δ 300: 0 ≤ 1560; δ 900: 0 ≤ 960; δ 1800: 0 ≤ 60; δ 3600: 0 ≤ 0 | — | pipeline, as planned |
| C1 | PASS | owner denials 0 | fired: R-NO-BIND owner denials 14 | pipeline, as planned |
| C2 | PASS | admitted 51, ceiling 52 | — | pipeline, as planned |
| C3 | PASS | victim P 0; reach 4 (R-NO-BIND 4) | fired: R-WEAKBIND victim P 6 | pipeline, as planned |
| C4 | FAIL | thief P/window 2, 24 h 9, after 0; owner wait never | — | pipeline, as planned |
| C5 | PASS | BOOT refused 0 / 383; BOOT-X bound-instance refusals 0 | fired: R-NO-BIND refused 28 day-1 requests from bound instances | pipeline, as planned |
| C6 | PASS | day-3 share 9.6E-5; bound refusals with sprayer 0 | — | MODEL |
| E | FAIL | identical: no | fired: R-NO-K5SEP differs: yes | pipeline, as planned |
| P | FAIL | pre L1 72 ≤ 0, D2 61 ≤ 36 | — | pipeline, as planned |

## Changes from the G4 archive (G4 arm → Part R arm; best response over strategies and id policies)

None.

### Row level (every Part R stream row, 684 rows, against its G4 archive row)

5 of 684 rows differ:

| G4 arm | Flow | Stream | Strategy | Ids | Metrics G4 → Part R | Denial causes, G4 archive | Denial causes, Part R |
| --- | --- | --- | --- | --- | --- | --- | --- |
| ESC | pre+out | Y6b | none | fresh | reachOwn 36 → 11, verifierCalls 56 → 31, attackReachMaxPerWindow 13 → 5, attackReachMaxSliding 13 → 5 | attack []; legit {"cap:pproof":10} | attack {"pen:K4":5,"pen:K4+K5":1,"pen:K5":14,"pen:unpersisted":4,"soft":1}; legit {"cap:pproof":10} |
| ESC | pre+out | X1 | none | fresh | decisions or causes only | attack {"soft":60}; legit [] | attack {"pen:K5":7,"pen:unpersisted":51,"soft":2}; legit [] |
| ESC | pre+out | X7 | none | fresh | reachOwn 81 → 4, verifierCalls 121 → 44, attackReachMaxPerWindow 120 → 43, attackReachMaxSliding 120 → 43 | attack []; legit [] | attack {"pen:K5":77}; legit [] |
| ESC | pre+out | BRU | none | fresh | reachForeign 3 → 2, verifierCalls 3 → 2 | attack {"pen:K4":2,"soft":97}; legit [] | attack {"pen:K4":57,"pen:K4+K5":1,"pen:K5":18,"pen:unpersisted":21,"soft":2}; legit [] |
| ESC | pre+out | BRD | none | fresh | decisions or causes only | attack {"pen:K4":2,"soft":97}; legit [] | attack {"pen:K4":58,"pen:K4+K5":1,"pen:K5":35,"soft":5}; legit [] |

## Streams, `pre+out` (legitimate denied / best-response attack reach, U + P)

| Stream | S0 | S24 | FULL2 | FULL2-40 | N-PG1 | N-NORENEW | N-NOPWB | N-NOK5SEP | P-NETF | R-FULL | R-FULL-40 | R-NO-BIND | R-NO-FCN | R-NO-TB | R-NO-K5SEP | R-ESC | R-WEAKBIND |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| L1 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 72 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 72 / 0 | 72 / 0 | 0 / 0 | 72 / 0 | 72 / 0 | 72 / 0 | 72 / 0 | 72 / 0 |
| ABA_B | 118 / 0 | 72 / 0 | 0 / 0 | 0 / 0 | 94 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 94 / 0 | 94 / 0 | 72 / 0 | 94 / 0 | 94 / 0 | 94 / 0 | 94 / 0 | 94 / 0 |
| D2 | 117 / 0 | 39 / 0 | 24 / 0 | 2 / 0 | 24 / 0 | 24 / 0 | 24 / 0 | 24 / 0 | 24 / 0 | 24 / 0 | 2 / 0 | 24 / 0 | 24 / 0 | 24 / 0 | 24 / 0 | 39 / 0 | 24 / 0 |
| N12 | 56 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| Nu4 | 47 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| Y6b | 28 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 10 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 10 / 0 | 10 / 0 | 0 / 0 | 10 / 0 | 10 / 0 | 10 / 0 | 10 / 0 | 10 / 0 |
| KD | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 |
| UAC | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 9 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 | 6 / 0 |
| MIX | 117 / 0 | 63 / 27 | 54 / 8 | 42 / 8 | 54 / 8 | 54 / 8 | 54 / 8 | 54 / 8 | 54 / 8 | 54 / 8 | 42 / 8 | 54 / 8 | 51 / 27 | 54 / 8 | 54 / 8 | 66 / 8 | 54 / 8 |
| OFF12 | 8 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| POI | 12 / 80 | 0 / 180 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 180 | 0 / 96 | 0 / 96 | 0 / 96 | 0 / 96 |
| L3 | 104 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| D1 | 596 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| SP6 | 0 / 20 | 0 / 144 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 144 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 24 |
| SP3 | 0 / 7 | 0 / 48 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 48 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 |
| SP1n | 0 / 4 | 0 / 24 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 24 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 |
| ABA_A | 0 / 3 | 0 / 48 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 48 | 0 / 8 | 0 / 8 | 0 / 8 | 0 / 8 |
| X2 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 |
| X1 | 0 / 2 | 0 / 2 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 17 | 0 / 4 | 0 / 2 | 0 / 4 |
| X7 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 |
| BRU | 0 / 3 | 0 / 3 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 80 | 0 / 5 | 0 / 3 | 0 / 5 |
| BRD | 0 / 3 | 0 / 3 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 80 | 0 / 5 | 0 / 3 | 0 / 5 |

## Streams, `pre` (checkOnly-only Hosts)

| Stream | S24 pre | R-FULL pre | R-FULL-40 pre | R-NO-BIND pre | R-NO-TB pre | FULL2 pre | FULL2-40 pre | N-PG1 pre | P-NETF pre |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| L1 | 0 / 0 | 72 / 0 | 72 / 0 | 122 / 0 | 72 / 0 | 72 / 0 | 72 / 0 | 72 / 0 | 0 / 0 |
| ABA_B | 72 / 0 | 94 / 0 | 94 / 0 | 112 / 0 | 94 / 0 | 94 / 0 | 94 / 0 | 94 / 0 | 0 / 0 |
| D2 | 36 / 0 | 61 / 0 | 61 / 0 | 61 / 0 | 24 / 0 | 61 / 0 | 61 / 0 | 61 / 0 | 61 / 0 |
| N12 | 0 / 0 | 0 / 0 | 0 / 0 | 1 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| Nu4 | 0 / 0 | 0 / 0 | 0 / 0 | 41 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| Y6b | 0 / 0 | 10 / 0 | 10 / 0 | 26 / 0 | 10 / 0 | 10 / 0 | 10 / 0 | 10 / 0 | 0 / 0 |
| KD | 0 / 0 | 4 / 0 | 4 / 0 | 8 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 0 / 0 |
| UAC | 0 / 0 | 4 / 0 | 4 / 0 | 8 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 0 / 0 |
| MIX | 63 / 27 | 61 / 27 | 61 / 55 | 61 / 27 | 51 / 27 | 61 / 27 | 61 / 55 | 61 / 27 | 61 / 27 |
| OFF12 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| POI | 0 / 180 | 0 / 180 | 0 / 180 | 11 / 180 | 0 / 180 | 0 / 180 | 0 / 180 | 0 / 180 | 0 / 180 |
| L3 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| D1 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| SP6 | 0 / 144 | 0 / 144 | 0 / 240 | 0 / 144 | 0 / 144 | 0 / 144 | 0 / 240 | 0 / 144 | 0 / 144 |
| SP3 | 0 / 48 | 0 / 48 | 0 / 80 | 0 / 48 | 0 / 48 | 0 / 48 | 0 / 80 | 0 / 48 | 0 / 48 |
| SP1n | 0 / 24 | 0 / 24 | 0 / 40 | 0 / 24 | 0 / 24 | 0 / 24 | 0 / 40 | 0 / 24 | 0 / 24 |
| ABA_A | 0 / 48 | 0 / 48 | 0 / 80 | 0 / 48 | 0 / 48 | 0 / 48 | 0 / 80 | 0 / 48 | 0 / 48 |
| X2 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 | 0 / 121 |
| X1 | 0 / 17 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 17 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 |
| X7 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 | 0 / 40 |
| BRU | 0 / 80 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 80 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 |
| BRD | 0 / 80 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 80 | 0 / 5 | 0 / 5 | 0 / 5 | 0 / 5 |

## Legitimate denial causes, `pre+out` (streams with denials)

| Stream | FULL2 | R-FULL |
| --- | --- | --- |
| L1 | 0 [] | 72 {"cap:pproof":38,"cap:pproof_day":34} |
| ABA_B | 0 [] | 94 {"cap:pproof":94} |
| D2 | 24 {"cap:fail_src":2,"cap:src":22} | 24 {"cap:fail_src":2,"cap:src":22} |
| Y6b | 0 [] | 10 {"cap:pproof":10} |
| KD | 9 {"pen:unpersisted":1,"soft":8} | 9 {"pen:unpersisted":1,"soft":8} |
| UAC | 6 {"cap:pproof":2,"soft":4} | 6 {"cap:pproof":2,"soft":4} |
| MIX | 54 {"cap:fail_src":42,"cap:src":12} | 54 {"cap:fail_src":42,"cap:src":12} |

## Fixtures (pipeline, as planned)

| Fixture | FULL2 | FULL2-40 | N-PG1 | N-NORENEW | N-NOPWB | N-NOK5SEP | P-NETF | R-FULL | R-FULL-40 | R-NO-BIND | R-NO-FCN | R-NO-TB | R-NO-K5SEP | R-ESC | R-WEAKBIND |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| HAR-B owner denied / attack reach | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 14 / 4 | 0 / 4 | 0 / 48 | 0 / 4 | 0 / 3 | 0 / 4 |
| HAR-N owner denied / attack reach | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 0 / 4 | 14 / 4 | 0 / 4 | 0 / 47 | 0 / 4 | 0 / 3 | 0 / 4 |
| BRU24 admitted (recount) | 51 (51) | 51 (51) | 51 (51) | 51 (51) | 51 (51) | 51 (51) | 51 (51) | 51 (51) | 51 (51) | 51 (51) | 51 (51) | 2304 (0) | 51 (51) | 33 (33) | 51 (51) |
| BRU24-HV admitted | 51 | 51 | 51 | 51 | 51 | 51 | 51 | 51 | 51 | 51 | 51 | 2304 | 51 | 37 | 51 |
| REC wait, δ = 0 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 1860 | 60 |
| REC wait, δ = 60 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1800 | 0 |
| REC wait, δ = 300 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1560 | 0 |
| REC wait, δ = 900 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 960 | 0 |
| REC wait, δ = 1800 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 60 | 0 |
| REC wait, δ = 3600 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| CROSS victim reach / P | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 24 / 0 | 4 / 0 | 4 / 0 | 4 / 0 | 6 / 6 |
| STEAL thief P max / window | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 0 | 2 | 2 | 2 | 2 | 2 |
| STEAL thief P before / after the change (revoke) | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 0 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 | 9 / 0 |
| STEAL thief U after | 27 | 27 | 27 | 27 | 27 | 27 | 27 | 27 | 27 | 24 | 27 | 1151 | 27 | 17 | 27 |
| STEAL owner denied before | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 5 | 2 | 2 | 2 | 2 | 2 |
| STEAL owner wait after (lane) | 0 (P) | 0 (P) | 0 (P) | 0 (P) | never (—) | 0 (P) | 0 (P) | never (U) | never (U) | never (U) | never (U) | 0 (U) | never (U) | never (U) | never (U) |
| CLEAR denied | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| E2 identical | yes | yes | no | no | yes | yes | yes | no | no | no | no | no | no | no | no |
| K5U identical from 960 s | yes | yes | yes | yes | yes | no | yes | — | — | — | — | — | — | — | — |

### E2 owner rows (decisions / lanes; population vs control)

| Arm | Population: decisions | lanes | causes | Control: decisions | lanes | causes |
| --- | --- | --- | --- | --- | --- | --- |
| FULL2 | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:unpersisted":2,"soft":10} | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:unpersisted":2,"soft":10} |
| FULL2-40 | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:unpersisted":2,"soft":10} | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:unpersisted":2,"soft":10} |
| N-PG1 | `AAAAAASSSSAAASSSSSSSSSSSSSSSSSSSSSSSSS` | `PPPPPPPPPPPPPUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":25,"cap:pproof":4} | `AAAAAASSSSAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPUPPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":4,"pen:unpersisted":2,"soft":10} |
| N-NORENEW | `AAAAAAAAAASSSSSSSSSSSSSSSSSSSSSSSSSSSS` | `PPPPPPPPPPUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":28} | `AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPUUUUPPPPPPPPPPPPPPPPPPPPPPPP` | [] |
| N-NOPWB | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:unpersisted":2,"soft":10} | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:unpersisted":2,"soft":10} |
| N-NOK5SEP | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:K5":2,"soft":10} | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:K5":2,"soft":10} |
| P-NETF | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:unpersisted":2,"soft":10} | `AAAAAAAAAAAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:unpersisted":2,"soft":10} |
| R-FULL | `AAAAAASSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS` | `PPPPPPPPPPUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":28,"cap:pproof":4} | `AAAAAASSSSAAAAAAAAAAAAAASSSSAAAAAASSSS` | `PPPPPPPPPPUUUUPPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":12} |
| R-FULL-40 | `AAAAAASSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS` | `PPPPPPPPPPUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":28,"cap:pproof":4} | `AAAAAASSSSAAAAAAAAAAAAAASSSSAAAAAASSSS` | `PPPPPPPPPPUUUUPPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":12} |
| R-NO-BIND | `ASSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS` | `UUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":37} | `AAAAAAAAAAAAASSSSSSSSSSSSSSSSSSSAAAAAS` | `UUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":20} |
| R-NO-FCN | `AAAAAASSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS` | `PPPPPPPPPPUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":28,"cap:pproof":4} | `AAAAAASSSSAAAAAAAAAAAAAASSSSAAAAAASSSS` | `PPPPPPPPPPUUUUPPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":12} |
| R-NO-TB | `AAAAAASSSSAASSSSSSAAAAAAAASSAAAAAASSSS` | `PPPPPPPPPPUUUUUUUUUUPPPPPPPPPPPPPPPPPP` | {"cap:acct":6,"cap:pproof":10} | `AAAAAASSSSAAAAAAAAAAAAAASSSSAAAAAASSSS` | `PPPPPPPPPPUUUUPPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":12} |
| R-NO-K5SEP | `AAAAAASSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS` | `PPPPPPPPPPUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":28,"cap:pproof":4} | `AAAAAASSSSAAAAAAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPUUUUPPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":4,"pen:K5":2,"soft":10} |
| R-ESC | `AAAAAASSSSHHHHHHHHSHHHHHHHHHHHHHHHHHHH` | `PPPPPPPPPPUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:pproof":4,"pen:K4":18,"pen:K4+K5":1,"pen:K5":8,"soft":1} | `AAAAAASSSSAAAAAAAAAAAAAASSSSAAAAAASSSS` | `PPPPPPPPPPUUUUPPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":12} |
| R-WEAKBIND | `AAAAAASSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS` | `PPPPPPPPPPUUUUUUUUUUUUUUUUUUUUUUUUUUUU` | {"cap:acct_tb":28,"cap:pproof":4} | `AAAAAASSSSAAAAAAAAAAAAAASSSSAAAAAASSSS` | `PPPPPPPPPPUUUUPPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":12} |

### K5U owner rows from 960 s (decisions / lanes; `fail` vs `ctl`)

| Arm | fail: decisions | lanes | causes (all rows) | ctl: decisions | lanes | causes (all rows) |
| --- | --- | --- | --- | --- | --- | --- |
| FULL2 | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] |
| FULL2-40 | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] |
| N-PG1 | `AAAAAAAAAASSSSAAAAAASSSS` | `PPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":12} | `AAAAAAAAAASSSSAAAAAASSSS` | `PPPPPPPPPPPPPPPPPPPPPPPP` | {"cap:pproof":12} |
| N-NORENEW | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] |
| N-NOPWB | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] |
| N-NOK5SEP | `AAAASSSSSAAAAAHSSSSAAAHS` | `PPPPPPPPPPPPPPPPPPPPPPPP` | {"pen:K5":2,"soft":10} | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] |
| P-NETF | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] | `AAAAAAAAAAAAAAAAAAAAAAAA` | `PPPPPPPPPPPPPPPPPPPPPPPP` | [] |

### BOOT / BOOT-X (legitimate attempts / refused per day)

| Fixture | Pair | Arm | Day 1 | Day 2 | Day 3 | Bound-instance refusals | NO-BIND refusals where bound (day 1) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| BOOT | FULL2+R-NO-BIND | FULL2 | 125 / 0 | 127 / 0 | 131 / 0 | 0 | 0 |
| BOOT | FULL2+R-NO-BIND | NO-BIND | 125 / 0 | 127 / 0 | 131 / 0 | 0 | 0 |
| BOOT | R-FULL+R-NO-BIND | FULL | 125 / 0 | 127 / 0 | 131 / 0 | 0 | 0 |
| BOOT | R-FULL+R-NO-BIND | NO-BIND | 125 / 0 | 127 / 0 | 131 / 0 | 0 | 0 |
| BOOT-X | FULL2+R-NO-BIND | FULL2 | 125 / 68 | 127 / 0 | 131 / 0 | 0 | 28 |
| BOOT-X | FULL2+R-NO-BIND | NO-BIND | 125 / 96 | 127 / 0 | 131 / 0 | 0 | 28 |
| BOOT-X | R-FULL+R-NO-BIND | FULL | 125 / 68 | 127 / 0 | 131 / 0 | 0 | 28 |
| BOOT-X | R-FULL+R-NO-BIND | NO-BIND | 125 / 96 | 127 / 0 | 131 / 0 | 0 | 28 |

## CGN-B (`MODEL`, 4 replications; per day: attempts / refused (share); bound attempts / bound refused)

| Arm | N | λ | Sprayer | Day 1 | Day 2 | Day 3 | Bound at end |
| --- | --- | --- | --- | --- | --- | --- | --- |
| R-FULL | 500 | 1 | no | 2149 / 0 (0); 811 / 0 | 2126 / 0 (0); 1628 / 0 | 2009 / 0 (0); 1857 / 0 | 1895 |
| R-FULL | 500 | 1 | yes | 2149 / 0 (0); 811 / 0 | 2126 / 765 (0.359831); 1358 / 0 | 2009 / 0 (0); 1536 / 0 | 1735 |
| R-FULL | 500 | 4 | no | 8326 / 3 (0.00036); 6255 / 0 | 8241 / 0 (0); 8210 / 0 | 8473 / 0 (0); 8473 / 0 | 2000 |
| R-FULL | 500 | 4 | yes | 8326 / 3 (0.00036); 6255 / 0 | 8241 / 139 (0.016867); 8101 / 0 | 8473 / 0 (0); 8446 / 0 | 1999 |
| R-FULL | 5000 | 1 | no | 21144 / 3996 (0.18899); 5879 / 0 | 21166 / 254 (0.012); 15067 / 0 | 20804 / 2 (9.6E-5); 18404 / 0 | 18663 |
| R-FULL | 5000 | 1 | yes | 21144 / 3996 (0.18899); 5879 / 0 | 21166 / 9587 (0.452943); 11537 / 0 | 20804 / 257 (0.012353); 14651 / 0 | 16440 |
| R-FULL | 5000 | 4 | no | 84034 / 47729 (0.567972); 24209 / 0 | 84308 / 5990 (0.071049); 69881 / 0 | 83955 / 0 (0); 83495 / 0 | 19995 |
| R-FULL | 5000 | 4 | yes | 84034 / 47729 (0.567972); 24209 / 0 | 84308 / 35364 (0.419462); 48752 / 0 | 83955 / 5793 (0.069001); 69907 / 0 | 19548 |
| R-FULL-40 | 500 | 1 | no | 2149 / 0 (0); 811 / 0 | 2126 / 0 (0); 1628 / 0 | 2009 / 0 (0); 1857 / 0 | 1895 |
| R-FULL-40 | 500 | 1 | yes | 2149 / 0 (0); 811 / 0 | 2126 / 765 (0.359831); 1358 / 0 | 2009 / 0 (0); 1536 / 0 | 1735 |
| R-FULL-40 | 500 | 4 | no | 8326 / 0 (0); 6258 / 0 | 8241 / 0 (0); 8210 / 0 | 8473 / 0 (0); 8473 / 0 | 2000 |
| R-FULL-40 | 500 | 4 | yes | 8326 / 0 (0); 6258 / 0 | 8241 / 139 (0.016867); 8101 / 0 | 8473 / 0 (0); 8446 / 0 | 1999 |
| R-FULL-40 | 5000 | 1 | no | 21144 / 987 (0.04668); 7370 / 0 | 21166 / 3 (0.000142); 16084 / 0 | 20804 / 0 (0); 18860 / 0 | 18920 |
| R-FULL-40 | 5000 | 1 | yes | 21144 / 987 (0.04668); 7370 / 0 | 21166 / 8123 (0.383776); 13008 / 0 | 20804 / 0 (0); 15719 / 0 | 17091 |
| R-FULL-40 | 5000 | 4 | no | 84034 / 25924 (0.308494); 39445 / 0 | 84308 / 0 (0); 82003 / 0 | 83955 / 0 (0); 83907 / 0 | 20000 |
| R-FULL-40 | 5000 | 4 | yes | 84034 / 25924 (0.308494); 39445 / 0 | 84308 / 9153 (0.108566); 75107 / 0 | 83955 / 0 (0); 81686 / 0 | 19968 |
| R-NO-BIND | 500 | 1 | no | 2149 / 0 (0); 0 / 0 | 2126 / 0 (0); 0 / 0 | 2009 / 0 (0); 0 / 0 | 0 |
| R-NO-BIND | 500 | 1 | yes | 2149 / 0 (0); 0 / 0 | 2126 / 2114 (0.994356); 0 / 0 | 2009 / 0 (0); 0 / 0 | 0 |
| R-NO-BIND | 500 | 4 | no | 8326 / 488 (0.058612); 0 / 0 | 8241 / 513 (0.06225); 0 / 0 | 8473 / 545 (0.064322); 0 / 0 | 0 |
| R-NO-BIND | 500 | 4 | yes | 8326 / 488 (0.058612); 0 / 0 | 8241 / 8187 (0.993447); 0 / 0 | 8473 / 545 (0.064322); 0 / 0 | 0 |
| R-NO-BIND | 5000 | 1 | no | 21144 / 9050 (0.428017); 0 / 0 | 21166 / 9089 (0.429415); 0 / 0 | 20804 / 8713 (0.418814); 0 / 0 | 0 |
| R-NO-BIND | 5000 | 1 | yes | 21144 / 9050 (0.428017); 0 / 0 | 21166 / 21066 (0.995275); 0 / 0 | 20804 / 8713 (0.418814); 0 / 0 | 0 |
| R-NO-BIND | 5000 | 4 | no | 84034 / 71937 (0.856046); 0 / 0 | 84308 / 72211 (0.856514); 0 / 0 | 83955 / 71857 (0.855899); 0 / 0 | 0 |
| R-NO-BIND | 5000 | 4 | yes | 84034 / 71937 (0.856046); 0 / 0 | 84308 / 83886 (0.994995); 0 / 0 | 83955 / 71857 (0.855899); 0 / 0 | 0 |
| FULL2 | 500 | 1 | no | 2149 / 0 (0); 811 / 0 | 2126 / 0 (0); 1628 / 0 | 2009 / 0 (0); 1857 / 0 | 1895 |
| FULL2 | 500 | 1 | yes | 2149 / 0 (0); 811 / 0 | 2126 / 765 (0.359831); 1358 / 0 | 2009 / 0 (0); 1536 / 0 | 1735 |
| FULL2 | 500 | 4 | no | 8326 / 3 (0.00036); 6255 / 0 | 8241 / 0 (0); 8210 / 0 | 8473 / 0 (0); 8473 / 0 | 2000 |
| FULL2 | 500 | 4 | yes | 8326 / 3 (0.00036); 6255 / 0 | 8241 / 139 (0.016867); 8101 / 0 | 8473 / 0 (0); 8446 / 0 | 1999 |
| FULL2 | 5000 | 1 | no | 21144 / 3996 (0.18899); 5879 / 0 | 21166 / 254 (0.012); 15067 / 0 | 20804 / 2 (9.6E-5); 18404 / 0 | 18663 |
| FULL2 | 5000 | 1 | yes | 21144 / 3996 (0.18899); 5879 / 0 | 21166 / 9587 (0.452943); 11537 / 0 | 20804 / 257 (0.012353); 14651 / 0 | 16440 |
| FULL2 | 5000 | 4 | no | 84034 / 47729 (0.567972); 24209 / 0 | 84308 / 5990 (0.071049); 69881 / 0 | 83955 / 0 (0); 83495 / 0 | 19995 |
| FULL2 | 5000 | 4 | yes | 84034 / 47729 (0.567972); 24209 / 0 | 84308 / 35364 (0.419462); 48752 / 0 | 83955 / 5793 (0.069001); 69907 / 0 | 19548 |
| FULL2-40 | 500 | 1 | no | 2149 / 0 (0); 811 / 0 | 2126 / 0 (0); 1628 / 0 | 2009 / 0 (0); 1857 / 0 | 1895 |
| FULL2-40 | 500 | 1 | yes | 2149 / 0 (0); 811 / 0 | 2126 / 765 (0.359831); 1358 / 0 | 2009 / 0 (0); 1536 / 0 | 1735 |
| FULL2-40 | 500 | 4 | no | 8326 / 0 (0); 6258 / 0 | 8241 / 0 (0); 8210 / 0 | 8473 / 0 (0); 8473 / 0 | 2000 |
| FULL2-40 | 500 | 4 | yes | 8326 / 0 (0); 6258 / 0 | 8241 / 139 (0.016867); 8101 / 0 | 8473 / 0 (0); 8446 / 0 | 1999 |
| FULL2-40 | 5000 | 1 | no | 21144 / 987 (0.04668); 7370 / 0 | 21166 / 3 (0.000142); 16084 / 0 | 20804 / 0 (0); 18860 / 0 | 18920 |
| FULL2-40 | 5000 | 1 | yes | 21144 / 987 (0.04668); 7370 / 0 | 21166 / 8123 (0.383776); 13008 / 0 | 20804 / 0 (0); 15719 / 0 | 17091 |
| FULL2-40 | 5000 | 4 | no | 84034 / 25924 (0.308494); 39445 / 0 | 84308 / 0 (0); 82003 / 0 | 83955 / 0 (0); 83907 / 0 | 20000 |
| FULL2-40 | 5000 | 4 | yes | 84034 / 25924 (0.308494); 39445 / 0 | 84308 / 9153 (0.108566); 75107 / 0 | 83955 / 0 (0); 81686 / 0 | 19968 |

## Part N necessity (against FULL2)

| Arm | Registered expectation | Worse | Better | Necessity |
| --- | --- | --- | --- | --- |
| N-PG1 | A1 | legit L1 72 (FULL2 0); legit ABA_B 94 (FULL2 0); legit Y6b 10 (FULL2 0); E2 differs 1 (FULL2 0) | — | shown |
| N-NORENEW | E | E2 differs 1 (FULL2 0) | — | shown |
| N-NOPWB | C4v2 | STEAL owner wait never (FULL2 0) | — | shown |
| N-NOK5SEP | K5 | K5U differs 1 (FULL2 0) | — | shown |

## Part R necessity (against R-FULL, G4 expectations)

| Arm | Registered expectation | Worse | Better | Necessity |
| --- | --- | --- | --- | --- |
| R-NO-BIND | C1, C5, C6 | legit UAC 9 (R-FULL 6); HAR-B owner denials 14 (R-FULL 0); pre legit L1 122 (R-FULL 72); BOOT-X refusals 96 (R-FULL 68); BOOT-X refusals of bound instances 28 (R-FULL 0); CGN 5000/1 day-3 refused 8713 (R-FULL 2); CGN 5000/1 + sprayer, day-2 refused 21066 (R-FULL 9587) | legit L1 0 (R-FULL 72); legit ABA_B 72 (R-FULL 94); legit Y6b 0 (R-FULL 10); STEAL thief P / window 0 (R-FULL 2) | shown |
| R-NO-FCN | A3 | reach SP1n 24 (R-FULL 4); reach SP3 48 (R-FULL 8); reach SP6 144 (R-FULL 24); reach ABA_A 48 (R-FULL 8); CROSS victim reach 24 (R-FULL 4) | legit MIX 51 (R-FULL 54) | shown |
| R-NO-TB | B1, pre BRU reach | BRU24 admitted 2304 (R-FULL 51); BRU24-HV admitted 2304 (R-FULL 51); pre reach BRU 80 (R-FULL 5) | STEAL owner wait 0 (R-FULL never); pre legit D2 24 (R-FULL 61) | shown |
| R-NO-K5SEP | E | — | — | not shown necessary |
| R-ESC | B2 | legit D2 39 (R-FULL 24); legit MIX 66 (R-FULL 54); REC wait δ0 1860 (R-FULL 60); REC wait δ60 1800 (R-FULL 0); REC wait δ300 1560 (R-FULL 0); REC wait δ900 960 (R-FULL 0); REC wait δ1800 60 (R-FULL 0) | BRU24 admitted 33 (R-FULL 51); BRU24-HV admitted 37 (R-FULL 51) | shown |

## Q4: FULL2 (U_src 24) against FULL2-40, reported without a winner (rows that differ)

| Stream | Flow | FULL2 | FULL2-40 |
| --- | --- | --- | --- |
| D2 | pre+out | 24 / 0 | 2 / 0 |
| MIX | pre+out | 54 / 8 | 42 / 8 |
| MIX | pre | 61 / 27 | 61 / 55 |
| SP6 | pre | 0 / 144 | 0 / 240 |
| SP3 | pre | 0 / 48 | 0 / 80 |
| SP1n | pre | 0 / 24 | 0 / 40 |
| ABA_A | pre | 0 / 48 | 0 / 80 |
| CGN-B 5000/1/- (`MODEL`) | — | day refusals 3996, 254, 2 | day refusals 987, 3, 0 |
| CGN-B 5000/1/X (`MODEL`) | — | day refusals 3996, 9587, 257 | day refusals 987, 8123, 0 |
| CGN-B 5000/4/- (`MODEL`) | — | day refusals 47729, 5990, 0 | day refusals 25924, 0, 0 |
| CGN-B 500/4/- (`MODEL`) | — | day refusals 3, 0, 0 | day refusals 0, 0, 0 |

## P-NETF (reported only, PLAN.md §2.2)

| Gate (FULL2 definition) | P-NETF |
| --- | --- |
| A1 | pass: every benign stream ≤ S0 and ≤ S24 |
| A2 | pass: MIX legit 54 (S0 117) |
| A3 | pass: SP1n 4 (S12 12); SP3 8 (S12 24); SP6 24 (S12 72); ABA_A 8 (S12 24) |
| B1 | pass: admitted 51, recount 51, ceiling 52 |
| B2 | pass: δ 0: 60 ≤ 1860; δ 60: 0 ≤ 1800; δ 300: 0 ≤ 1560; δ 900: 0 ≤ 960; δ 1800: 0 ≤ 60; δ 3600: 0 ≤ 0 |
| C2 | pass: admitted 51, ceiling 52 |
| P | fail: pre L1 0 ≤ 0, D2 61 ≤ 36 |
