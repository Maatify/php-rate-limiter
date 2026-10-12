# Generated summary (do not edit; produced by report.php from results/actual)

## Gate status per candidate

| Gate | A1 | PW | P2 | RL |
|---|---|---|---|---|
| A.1 rotation continuity (DECISION_MATRIX §5.0, KEY_STRATEGY: previous state must not become a current-only reset) | PASS | FAIL | FAIL | PASS |
| A.2 near-threshold WATCH keeps blocking "hover at N-1" (DECISION_MATRIX §5.0) | PASS | FAIL (owner) | FAIL (owner) | PASS |
| A.3 backend failures propagate (never ALLOW / silent skip) | PASS | PASS | PASS | PASS |
| A.4 bounded storage: declared caps, TTL on every key, no key explosion beyond linear | PASS | PASS | PASS | PASS |
| A.5 atomic evidence under concurrency (real Redis, 8 concurrent writers) | NOT_APPLICABLE | FAIL (owner) | FAIL (owner) | UNPROVEN |
| A.6 Public Command Lifecycle coverage kept (record-only, check-only, API_OVERUSE recordSuccess-only hosts) | FAIL | FAIL | FAIL | PASS |
| A.7 DEC-003 cycle tracking / active-block ordering / key derivation untouched | PASS | PASS | PASS | PASS |
| A.8 normative DECISION_MATRIX §5.3 text unchanged | FAIL (owner) | FAIL (owner) | FAIL (owner) | FAIL (owner) |
| B. security regression: evasion traffic that S0 stops but the variant lets through | FAIL (owner) | FAIL (owner) | FAIL (owner) | FAIL (owner) |
| C. fairness: legitimate users denied by correlation while the attacker pays only free failures / botnet traffic | FAIL (owner) | FAIL (owner) | FAIL (owner) | FAIL (owner) |
| D. residual: attack streams that fully reach verification under S0 AND the variant | PASS | PASS | PASS | PASS |

## Attack-vs-legitimate matrix (Login with account id, flow pre+out)

Cell = `legit denied/legit attempts (of which attributable to correlation)` · `attacker denied/attempts` · `attacker attempts that reached verification`.

| Stream | class | S0 | A1 | PW | P2 | RL |
|---|---|---|---|---|---|---|
| L1 | benign | L 127/134 (c127) · A 0/0 · reach 0 | L 127/134 (c127) · A 0/0 · reach 0 | L 0/134 (c0) · A 0/0 · reach 0 | L 0/134 (c0) · A 0/0 · reach 0 | L 14/134 (c14) · A 0/0 · reach 0 |
| L1f | benign | L 127/134 (c42) · A 0/0 · reach 0 | L 127/134 (c42) · A 0/0 · reach 0 | L 90/134 (c0) · A 0/0 · reach 0 | L 90/134 (c0) · A 0/0 · reach 0 | L 59/134 (c7) · A 0/0 · reach 0 |
| L2 | benign | L 104/121 (c104) · A 0/0 · reach 0 | L 0/121 (c0) · A 0/0 · reach 0 | L 0/121 (c0) · A 0/0 · reach 0 | L 0/121 (c0) · A 0/0 · reach 0 | L 104/121 (c104) · A 0/0 · reach 0 |
| L3 | benign | L 104/121 (c104) · A 0/0 · reach 0 | L 0/121 (c0) · A 0/0 · reach 0 | L 0/121 (c0) · A 0/0 · reach 0 | L 0/121 (c0) · A 0/0 · reach 0 | L 104/121 (c104) · A 0/0 · reach 0 |
| L4 | benign | L 0/12 (c0) · A 0/0 · reach 0 | L 0/12 (c0) · A 0/0 · reach 0 | L 0/12 (c0) · A 0/0 · reach 0 | L 0/12 (c0) · A 0/0 · reach 0 | L 0/12 (c0) · A 0/0 · reach 0 |
| X1 | evasion | L 0/0 (c0) · A 60/61 · reach 2 | L 0/0 (c0) · A 60/61 · reach 2 | L 0/0 (c0) · A 60/61 · reach 2 | L 0/0 (c0) · A 60/61 · reach 2 | L 0/0 (c0) · A 60/61 · reach 2 |
| X2 | evasion | L 0/0 (c0) · A 116/121 · reach 6 | L 0/0 (c0) · A 0/121 · reach 121 | L 0/0 (c0) · A 0/121 · reach 121 | L 0/0 (c0) · A 115/121 · reach 7 | L 0/0 (c0) · A 116/121 · reach 6 |
| X2L | both | L 29/30 (c29) · A 117/121 · reach 5 | L 0/30 (c0) · A 0/121 · reach 121 | L 0/30 (c0) · A 0/121 · reach 121 | L 28/30 (c28) · A 115/121 · reach 7 | L 29/30 (c29) · A 117/121 · reach 5 |
| X3 | evasion | L 0/0 (c0) · A 120/121 · reach 2 | L 0/0 (c0) · A 120/121 · reach 2 | L 0/0 (c0) · A 120/121 · reach 2 | L 0/0 (c0) · A 120/121 · reach 2 | L 0/0 (c0) · A 120/121 · reach 2 |
| X4 | poisoning | L 79/81 (c79) · A 40/40 · reach 0 | L 79/81 (c79) · A 40/40 · reach 0 | L 0/81 (c0) · A 39/40 · reach 2 | L 0/81 (c0) · A 39/40 · reach 2 | L 77/81 (c77) · A 40/40 · reach 0 |
| X5 | evasion | L 0/0 (c0) · A 0/21 · reach 21 | L 0/0 (c0) · A 0/21 · reach 21 | L 0/0 (c0) · A 0/21 · reach 21 | L 0/0 (c0) · A 0/21 · reach 21 | L 0/0 (c0) · A 0/21 · reach 21 |
| X6 | poisoning | L 79/81 (c79) · A 40/40 · reach 0 | L 79/81 (c79) · A 40/40 · reach 0 | L 0/81 (c0) · A 39/40 · reach 2 | L 0/81 (c0) · A 39/40 · reach 2 | L 77/81 (c77) · A 40/40 · reach 0 |
| X7 | evasion | L 0/0 (c0) · A 116/121 · reach 6 | L 0/0 (c0) · A 0/121 · reach 121 | L 0/0 (c0) · A 0/121 · reach 121 | L 0/0 (c0) · A 0/121 · reach 121 | L 0/0 (c0) · A 116/121 · reach 6 |
| X8 | both | L 49/50 (c49) · A 148/151 · reach 4 | L 0/50 (c0) · A 119/151 · reach 32 | L 0/50 (c0) · A 119/151 · reach 32 | L 48/50 (c48) · A 146/151 · reach 6 | L 49/50 (c49) · A 148/151 · reach 4 |
| Y1 | evasion | L 0/0 (c0) · A 117/121 · reach 8 | L 0/0 (c0) · A 117/121 · reach 8 | L 0/0 (c0) · A 113/121 · reach 16 | L 0/0 (c0) · A 113/121 · reach 16 | L 0/0 (c0) · A 117/121 · reach 8 |
| Y2a | evasion | L 0/0 (c0) · A 4/8 · reach 6 | L 0/0 (c0) · A 4/8 · reach 6 | L 0/0 (c0) · A 0/8 · reach 8 | L 0/0 (c0) · A 0/8 · reach 8 | L 0/0 (c0) · A 4/8 · reach 6 |
| Y2b | evasion | L 0/0 (c0) · A 4/8 · reach 8 | L 0/0 (c0) · A 4/8 · reach 8 | L 0/0 (c0) · A 0/8 · reach 8 | L 0/0 (c0) · A 0/8 · reach 8 | L 0/0 (c0) · A 4/8 · reach 8 |
| Y2c | evasion | L 0/0 (c0) · A 4/20 · reach 18 | L 0/0 (c0) · A 0/20 · reach 20 | L 0/0 (c0) · A 0/20 · reach 20 | L 0/0 (c0) · A 0/20 · reach 20 | L 0/0 (c0) · A 4/20 · reach 18 |
| Y2c2 | evasion | L 0/0 (c0) · A 4/20 · reach 20 | L 0/0 (c0) · A 0/20 · reach 20 | L 0/0 (c0) · A 0/20 · reach 20 | L 0/0 (c0) · A 0/20 · reach 20 | L 0/0 (c0) · A 4/20 · reach 20 |
| Y4b | poisoning | L 9/30 (c0) · A 3/3 · reach 1 | L 9/30 (c0) · A 3/3 · reach 1 | L 9/30 (c0) · A 1/3 · reach 3 | L 9/30 (c0) · A 1/3 · reach 3 | L 1/30 (c0) · A 3/3 · reach 1 |
| Y4c | poisoning | L 28/30 (c5) · A 29/30 · reach 2 | L 28/30 (c5) · A 29/30 · reach 2 | L 23/30 (c0) · A 24/30 · reach 7 | L 23/30 (c0) · A 24/30 · reach 7 | L 26/30 (c3) · A 29/30 · reach 2 |
| Y4d | poisoning | L 9/30 (c0) · A 3/3 · reach 1 | L 9/30 (c0) · A 3/3 · reach 1 | L 9/30 (c0) · A 0/3 · reach 3 | L 9/30 (c0) · A 0/3 · reach 3 | L 1/30 (c0) · A 3/3 · reach 1 |
| Y4e | poisoning | L 79/81 (c6) · A 40/40 · reach 0 | L 79/81 (c6) · A 40/40 · reach 0 | L 73/81 (c0) · A 37/40 · reach 3 | L 73/81 (c0) · A 37/40 · reach 3 | L 77/81 (c7) · A 39/40 · reach 1 |
| Y4f | poisoning | L 79/81 (c6) · A 40/40 · reach 0 | L 79/81 (c6) · A 40/40 · reach 0 | L 73/81 (c0) · A 38/40 · reach 3 | L 73/81 (c0) · A 38/40 · reach 3 | L 77/81 (c7) · A 40/40 · reach 0 |
| Y6b | poisoning | L 28/30 (c9) · A 35/36 · reach 2 | L 28/30 (c9) · A 35/36 · reach 2 | L 19/30 (c0) · A 23/36 · reach 13 | L 19/30 (c0) · A 23/36 · reach 13 | L 5/30 (c2) · A 13/36 · reach 24 |
| Y6c | poisoning | L 28/30 (c9) · A 36/36 · reach 2 | L 28/30 (c9) · A 36/36 · reach 2 | L 19/30 (c0) · A 31/36 · reach 7 | L 19/30 (c0) · A 31/36 · reach 7 | L 5/30 (c2) · A 36/36 · reach 3 |
| Y7 | evasion | L 0/0 (c0) · A 239/241 · reach 3 | L 0/0 (c0) · A 239/241 · reach 3 | L 0/0 (c0) · A 237/241 · reach 7 | L 0/0 (c0) · A 237/241 · reach 7 | L 0/0 (c0) · A 237/241 · reach 5 |
| Y9a | evasion | L 0/0 (c0) · A 30/31 · reach 2 | L 0/0 (c0) · A 30/31 · reach 2 | L 0/0 (c0) · A 29/31 · reach 7 | L 0/0 (c0) · A 29/31 · reach 7 | L 0/0 (c0) · A 30/31 · reach 2 |
| Y9b | evasion | L 0/0 (c0) · A 10/12 · reach 3 | L 0/0 (c0) · A 10/12 · reach 3 | L 0/0 (c0) · A 0/12 · reach 12 | L 0/0 (c0) · A 0/12 · reach 12 | L 0/0 (c0) · A 10/12 · reach 3 |

## Record-only Host (recordFailure/recordSuccess only) (shape=login, flow=out)

| Stream | class | S0 | A1 | PW | P2 | RL |
|---|---|---|---|---|---|---|
| L1 | benign | L 124/134 · A 0/0 · reach 0 | L 124/134 · A 0/0 · reach 0 | L 0/134 · A 0/0 · reach 0 | L 0/134 · A 0/0 · reach 0 | L 14/134 · A 0/0 · reach 0 |
| L1f | benign | L 125/134 · A 0/0 · reach 0 | L 125/134 · A 0/0 · reach 0 | L 56/134 · A 0/0 · reach 0 | L 56/134 · A 0/0 · reach 0 | L 64/134 · A 0/0 · reach 0 |
| L2 | benign | L 102/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 102/121 · A 0/0 · reach 0 |
| L3 | benign | L 102/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 102/121 · A 0/0 · reach 0 |
| L4 | benign | L 0/12 · A 0/0 · reach 0 | L 0/12 · A 0/0 · reach 0 | L 0/12 · A 0/0 · reach 0 | L 0/12 · A 0/0 · reach 0 | L 0/12 · A 0/0 · reach 0 |
| X1 | evasion | L 0/0 · A 60/61 · reach 61 | L 0/0 · A 60/61 · reach 61 | L 0/0 · A 60/61 · reach 61 | L 0/0 · A 60/61 · reach 61 | L 0/0 · A 60/61 · reach 61 |
| X2 | evasion | L 0/0 · A 115/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 115/121 · reach 121 | L 0/0 · A 115/121 · reach 121 |
| X2L | both | L 29/30 · A 116/121 · reach 121 | L 0/30 · A 0/121 · reach 121 | L 0/30 · A 0/121 · reach 121 | L 28/30 · A 115/121 · reach 121 | L 29/30 · A 116/121 · reach 121 |
| X3 | evasion | L 0/0 · A 120/121 · reach 121 | L 0/0 · A 120/121 · reach 121 | L 0/0 · A 120/121 · reach 121 | L 0/0 · A 120/121 · reach 121 | L 0/0 · A 120/121 · reach 121 |
| X4 | poisoning | L 78/81 · A 40/40 · reach 40 | L 78/81 · A 40/40 · reach 40 | L 12/81 · A 39/40 · reach 40 | L 12/81 · A 39/40 · reach 40 | L 32/81 · A 40/40 · reach 40 |
| X5 | evasion | L 0/0 · A 0/21 · reach 21 | L 0/0 · A 0/21 · reach 21 | L 0/0 · A 0/21 · reach 21 | L 0/0 · A 0/21 · reach 21 | L 0/0 · A 0/21 · reach 21 |
| X6 | poisoning | L 78/81 · A 40/40 · reach 40 | L 78/81 · A 40/40 · reach 40 | L 12/81 · A 39/40 · reach 40 | L 12/81 · A 39/40 · reach 40 | L 32/81 · A 40/40 · reach 40 |
| X7 | evasion | L 0/0 · A 115/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 115/121 · reach 121 |
| X8 | both | L 49/50 · A 146/151 · reach 151 | L 0/50 · A 0/151 · reach 151 | L 0/50 · A 0/151 · reach 151 | L 48/50 · A 145/151 · reach 151 | L 49/50 · A 146/151 · reach 151 |
| Y1 | evasion | L 0/0 · A 113/121 · reach 121 | L 0/0 · A 113/121 · reach 121 | L 0/0 · A 113/121 · reach 121 | L 0/0 · A 113/121 · reach 121 | L 0/0 · A 113/121 · reach 121 |
| Y2a | evasion | L 0/0 · A 2/8 · reach 8 | L 0/0 · A 2/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 2/8 · reach 8 |
| Y2b | evasion | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 |
| Y2c | evasion | L 0/0 · A 2/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 2/20 · reach 20 |
| Y2c2 | evasion | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 |
| Y4b | poisoning | L 9/30 · A 2/3 · reach 3 | L 9/30 · A 2/3 · reach 3 | L 1/30 · A 1/3 · reach 3 | L 1/30 · A 1/3 · reach 3 | L 1/30 · A 2/3 · reach 3 |
| Y4c | poisoning | L 28/30 · A 28/30 · reach 30 | L 28/30 · A 28/30 · reach 30 | L 23/30 · A 24/30 · reach 30 | L 23/30 · A 24/30 · reach 30 | L 26/30 · A 28/30 · reach 30 |
| Y4d | poisoning | L 9/30 · A 2/3 · reach 3 | L 9/30 · A 2/3 · reach 3 | L 0/30 · A 0/3 · reach 3 | L 0/30 · A 0/3 · reach 3 | L 1/30 · A 2/3 · reach 3 |
| Y4e | poisoning | L 79/81 · A 39/40 · reach 40 | L 79/81 · A 39/40 · reach 40 | L 71/81 · A 36/40 · reach 40 | L 71/81 · A 36/40 · reach 40 | L 77/81 · A 39/40 · reach 40 |
| Y4f | poisoning | L 78/81 · A 40/40 · reach 40 | L 78/81 · A 40/40 · reach 40 | L 36/81 · A 38/40 · reach 40 | L 36/81 · A 38/40 · reach 40 | L 55/81 · A 40/40 · reach 40 |
| Y6b | poisoning | L 28/30 · A 34/36 · reach 36 | L 28/30 · A 34/36 · reach 36 | L 0/30 · A 0/36 · reach 36 | L 0/30 · A 0/36 · reach 36 | L 5/30 · A 12/36 · reach 36 |
| Y6c | poisoning | L 27/30 · A 35/36 · reach 36 | L 27/30 · A 35/36 · reach 36 | L 6/30 · A 33/36 · reach 36 | L 6/30 · A 33/36 · reach 36 | L 5/30 · A 35/36 · reach 36 |
| Y7 | evasion | L 0/0 · A 238/241 · reach 241 | L 0/0 · A 238/241 · reach 241 | L 0/0 · A 237/241 · reach 241 | L 0/0 · A 237/241 · reach 241 | L 0/0 · A 36/241 · reach 241 |
| Y9a | evasion | L 0/0 · A 29/31 · reach 31 | L 0/0 · A 29/31 · reach 31 | L 0/0 · A 29/31 · reach 31 | L 0/0 · A 29/31 · reach 31 | L 0/0 · A 29/31 · reach 31 |
| Y9b | evasion | L 0/0 · A 9/12 · reach 12 | L 0/0 · A 9/12 · reach 12 | L 0/0 · A 0/12 · reach 12 | L 0/0 · A 0/12 · reach 12 | L 0/0 · A 9/12 · reach 12 |

## Check-only Host (checkOnly only) (shape=login, flow=pre)

| Stream | class | S0 | A1 | PW | P2 | RL |
|---|---|---|---|---|---|---|
| L1 | benign | L 124/134 · A 0/0 · reach 0 | L 124/134 · A 0/0 · reach 0 | L 0/134 · A 0/0 · reach 0 | L 0/134 · A 0/0 · reach 0 | L 14/134 · A 0/0 · reach 0 |
| L1f | benign | L 124/134 · A 0/0 · reach 0 | L 124/134 · A 0/0 · reach 0 | L 0/134 · A 0/0 · reach 0 | L 0/134 · A 0/0 · reach 0 | L 14/134 · A 0/0 · reach 0 |
| L2 | benign | L 102/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 102/121 · A 0/0 · reach 0 |
| L3 | benign | L 102/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 102/121 · A 0/0 · reach 0 |
| L4 | benign | L 0/12 · A 0/0 · reach 0 | L 0/12 · A 0/0 · reach 0 | L 0/12 · A 0/0 · reach 0 | L 0/12 · A 0/0 · reach 0 | L 0/12 · A 0/0 · reach 0 |
| X1 | evasion | L 0/0 · A 59/61 · reach 2 | L 0/0 · A 59/61 · reach 2 | L 0/0 · A 58/61 · reach 3 | L 0/0 · A 58/61 · reach 3 | L 0/0 · A 59/61 · reach 2 |
| X2 | evasion | L 0/0 · A 115/121 · reach 6 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 115/121 · reach 6 |
| X2L | both | L 29/30 · A 116/121 · reach 5 | L 0/30 · A 0/121 · reach 121 | L 0/30 · A 0/121 · reach 121 | L 0/30 · A 0/121 · reach 121 | L 29/30 · A 116/121 · reach 5 |
| X3 | evasion | L 0/0 · A 117/121 · reach 4 | L 0/0 · A 117/121 · reach 4 | L 0/0 · A 117/121 · reach 4 | L 0/0 · A 117/121 · reach 4 | L 0/0 · A 117/121 · reach 4 |
| X4 | poisoning | L 79/81 · A 40/40 · reach 0 | L 79/81 · A 40/40 · reach 0 | L 0/81 · A 37/40 · reach 3 | L 0/81 · A 37/40 · reach 3 | L 77/81 · A 40/40 · reach 0 |
| X5 | evasion | L 0/0 · A 0/21 · reach 21 | L 0/0 · A 0/21 · reach 21 | L 0/0 · A 0/21 · reach 21 | L 0/0 · A 0/21 · reach 21 | L 0/0 · A 0/21 · reach 21 |
| X6 | poisoning | L 79/81 · A 40/40 · reach 0 | L 79/81 · A 40/40 · reach 0 | L 0/81 · A 37/40 · reach 3 | L 0/81 · A 37/40 · reach 3 | L 77/81 · A 40/40 · reach 0 |
| X7 | evasion | L 0/0 · A 115/121 · reach 6 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 115/121 · reach 6 |
| X8 | both | L 49/50 · A 147/151 · reach 4 | L 0/50 · A 119/151 · reach 32 | L 0/50 · A 119/151 · reach 32 | L 0/50 · A 119/151 · reach 32 | L 49/50 · A 147/151 · reach 4 |
| Y1 | evasion | L 0/0 · A 113/121 · reach 8 | L 0/0 · A 113/121 · reach 8 | L 0/0 · A 105/121 · reach 16 | L 0/0 · A 105/121 · reach 16 | L 0/0 · A 113/121 · reach 8 |
| Y2a | evasion | L 0/0 · A 2/8 · reach 6 | L 0/0 · A 2/8 · reach 6 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 2/8 · reach 6 |
| Y2b | evasion | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 |
| Y2c | evasion | L 0/0 · A 2/20 · reach 18 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 2/20 · reach 18 |
| Y2c2 | evasion | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 | L 0/0 · A 0/20 · reach 20 |
| Y4b | poisoning | L 9/30 · A 2/3 · reach 1 | L 9/30 · A 2/3 · reach 1 | L 9/30 · A 0/3 · reach 3 | L 9/30 · A 0/3 · reach 3 | L 1/30 · A 2/3 · reach 1 |
| Y4c | poisoning | L 28/30 · A 28/30 · reach 2 | L 28/30 · A 28/30 · reach 2 | L 23/30 · A 23/30 · reach 7 | L 23/30 · A 23/30 · reach 7 | L 26/30 · A 28/30 · reach 2 |
| Y4d | poisoning | L 9/30 · A 2/3 · reach 1 | L 9/30 · A 2/3 · reach 1 | L 9/30 · A 0/3 · reach 3 | L 9/30 · A 0/3 · reach 3 | L 1/30 · A 2/3 · reach 1 |
| Y4e | poisoning | L 79/81 · A 39/40 · reach 1 | L 79/81 · A 39/40 · reach 1 | L 73/81 · A 37/40 · reach 3 | L 73/81 · A 37/40 · reach 3 | L 77/81 · A 39/40 · reach 1 |
| Y4f | poisoning | L 79/81 · A 40/40 · reach 0 | L 79/81 · A 40/40 · reach 0 | L 73/81 · A 37/40 · reach 3 | L 73/81 · A 37/40 · reach 3 | L 77/81 · A 40/40 · reach 0 |
| Y6b | poisoning | L 28/30 · A 34/36 · reach 2 | L 28/30 · A 34/36 · reach 2 | L 19/30 · A 23/36 · reach 13 | L 19/30 · A 23/36 · reach 13 | L 5/30 · A 12/36 · reach 24 |
| Y6c | poisoning | L 28/30 · A 34/36 · reach 2 | L 28/30 · A 34/36 · reach 2 | L 19/30 · A 23/36 · reach 13 | L 19/30 · A 23/36 · reach 13 | L 5/30 · A 12/36 · reach 24 |
| Y7 | evasion | L 0/0 · A 238/241 · reach 3 | L 0/0 · A 238/241 · reach 3 | L 0/0 · A 234/241 · reach 7 | L 0/0 · A 234/241 · reach 7 | L 0/0 · A 236/241 · reach 5 |
| Y9a | evasion | L 0/0 · A 29/31 · reach 2 | L 0/0 · A 29/31 · reach 2 | L 0/0 · A 0/31 · reach 31 | L 0/0 · A 0/31 · reach 31 | L 0/0 · A 29/31 · reach 2 |
| Y9b | evasion | L 0/0 · A 9/12 · reach 3 | L 0/0 · A 9/12 · reach 3 | L 0/0 · A 0/12 · reach 12 | L 0/0 · A 0/12 · reach 12 | L 0/0 · A 9/12 · reach 3 |

## API_OVERUSE Host (recordSuccess only, no account) (shape=api, flow=out)

| Stream | class | S0 | A1 | PW | P2 | RL |
|---|---|---|---|---|---|---|
| L1 | benign | L 124/134 · A 0/0 · reach 0 | L 124/134 · A 0/0 · reach 0 | L 15/134 · A 0/0 · reach 0 | L 15/134 · A 0/0 · reach 0 | L 22/134 · A 0/0 · reach 0 |
| L2 | benign | L 102/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 102/121 · A 0/0 · reach 0 |
| X1 | evasion | L 0/0 · A 59/61 · reach 61 | L 0/0 · A 59/61 · reach 61 | L 0/0 · A 0/61 · reach 61 | L 0/0 · A 0/61 · reach 61 | L 0/0 · A 59/61 · reach 61 |
| X2 | evasion | L 0/0 · A 115/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 115/121 · reach 121 |
| X3 | evasion | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 | L 0/0 · A 0/121 · reach 121 |
| X4 | poisoning | L 79/81 · A 39/40 · reach 40 | L 79/81 · A 39/40 · reach 40 | L 1/81 · A 1/40 · reach 40 | L 1/81 · A 1/40 · reach 40 | L 77/81 · A 39/40 · reach 40 |
| X6 | poisoning | L 79/81 · A 39/40 · reach 40 | L 79/81 · A 39/40 · reach 40 | L 1/81 · A 1/40 · reach 40 | L 1/81 · A 1/40 · reach 40 | L 77/81 · A 39/40 · reach 40 |
| X8 | both | L 49/50 · A 146/151 · reach 151 | L 0/50 · A 0/151 · reach 151 | L 0/50 · A 0/151 · reach 151 | L 0/50 · A 0/151 · reach 151 | L 49/50 · A 146/151 · reach 151 |
| Y2a | evasion | L 0/0 · A 2/8 · reach 8 | L 0/0 · A 2/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 0/8 · reach 8 | L 0/0 · A 2/8 · reach 8 |
| Y4d | poisoning | L 9/30 · A 2/3 · reach 3 | L 9/30 · A 2/3 · reach 3 | L 0/30 · A 0/3 · reach 3 | L 0/30 · A 0/3 · reach 3 | L 1/30 · A 2/3 · reach 3 |
| Y4e | poisoning | L 79/81 · A 39/40 · reach 40 | L 79/81 · A 39/40 · reach 40 | L 1/81 · A 1/40 · reach 40 | L 1/81 · A 1/40 · reach 40 | L 77/81 · A 39/40 · reach 40 |
| Y4f | poisoning | L 79/81 · A 39/40 · reach 40 | L 79/81 · A 39/40 · reach 40 | L 1/81 · A 1/40 · reach 40 | L 1/81 · A 1/40 · reach 40 | L 77/81 · A 39/40 · reach 40 |

## OTP policy, pre+out (shape=otp, flow=pre+out)

| Stream | class | S0 | A1 | PW | P2 | RL |
|---|---|---|---|---|---|---|
| L1 | benign | L 127/134 · A 0/0 · reach 0 | L 127/134 · A 0/0 · reach 0 | L 0/134 · A 0/0 · reach 0 | L 0/134 · A 0/0 · reach 0 | L 14/134 · A 0/0 · reach 0 |
| L2 | benign | L 104/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 0/121 · A 0/0 · reach 0 | L 104/121 · A 0/0 · reach 0 |
| X1 | evasion | L 0/0 · A 61/61 · reach 1 | L 0/0 · A 61/61 · reach 1 | L 0/0 · A 61/61 · reach 1 | L 0/0 · A 61/61 · reach 1 | L 0/0 · A 61/61 · reach 1 |
| X2 | evasion | L 0/0 · A 121/121 · reach 6 | L 0/0 · A 121/121 · reach 121 | L 0/0 · A 121/121 · reach 121 | L 0/0 · A 121/121 · reach 7 | L 0/0 · A 121/121 · reach 6 |
| X3 | evasion | L 0/0 · A 121/121 · reach 1 | L 0/0 · A 121/121 · reach 1 | L 0/0 · A 121/121 · reach 1 | L 0/0 · A 121/121 · reach 1 | L 0/0 · A 121/121 · reach 1 |
| X4 | poisoning | L 79/81 · A 40/40 · reach 0 | L 79/81 · A 40/40 · reach 0 | L 0/81 · A 40/40 · reach 1 | L 0/81 · A 40/40 · reach 1 | L 77/81 · A 40/40 · reach 0 |
| X6 | poisoning | L 79/81 · A 40/40 · reach 0 | L 79/81 · A 40/40 · reach 0 | L 0/81 · A 40/40 · reach 1 | L 0/81 · A 40/40 · reach 1 | L 77/81 · A 40/40 · reach 0 |
| X8 | both | L 49/50 · A 151/151 · reach 4 | L 3/50 · A 151/151 · reach 32 | L 3/50 · A 151/151 · reach 32 | L 48/50 · A 151/151 · reach 6 | L 49/50 · A 151/151 · reach 4 |
| Y2a | evasion | L 0/0 · A 8/8 · reach 6 | L 0/0 · A 8/8 · reach 6 | L 0/0 · A 8/8 · reach 8 | L 0/0 · A 8/8 · reach 8 | L 0/0 · A 8/8 · reach 6 |
| Y4d | poisoning | L 9/30 · A 3/3 · reach 1 | L 9/30 · A 3/3 · reach 1 | L 9/30 · A 0/3 · reach 3 | L 9/30 · A 0/3 · reach 3 | L 1/30 · A 3/3 · reach 1 |
| Y4e | poisoning | L 79/81 · A 40/40 · reach 0 | L 79/81 · A 40/40 · reach 0 | L 73/81 · A 40/40 · reach 3 | L 73/81 · A 40/40 · reach 3 | L 77/81 · A 40/40 · reach 1 |
| Y4f | poisoning | L 79/81 · A 40/40 · reach 0 | L 79/81 · A 40/40 · reach 0 | L 73/81 · A 40/40 · reach 2 | L 73/81 · A 40/40 · reach 2 | L 77/81 · A 40/40 · reach 0 |

## Exact boundaries and recovery (controlled clock; A02)

| Probe | S0 | A1 | PW | P2 | RL |
|---|---|---|---|---|---|
| B1 lone legitimate client after a 3-member trigger: first allowed second | 601 | 601 | 62 | 62 | 121 |
| B5 one seed burst then silence: legit denials / last denial second | 120/300 · last 600 | 120/300 · last 600 | 12/300 · last 60 | 12/300 · last 60 | 24/300 · last 120 |
| B2 third member at 599 s, failing / succeeding (record-only): blocked? | fail=B ok=B | fail=B ok=B | fail=B ok=a | fail=B ok=a | fail=B ok=B |
| B2 third member at 600 s (set expired), failing / succeeding: blocked? | fail=a ok=a | fail=a ok=a | fail=a ok=a | fail=a ok=a | fail=a ok=a |
| B3 WATCH: second pair ends at 1800 s / 1801 s (record-only): blocked? | 1800=B 1801=a | 1800=B 1801=a | 1800=a 1801=a | 1800=a 1801=a | 1800=B 1801=a |
| B4 persistent attacker (1 req/s): runs / block issuances | a0-0,B1-130 · 3 | a0-0,B1-130 · 3 | a0-1,B2-130 · 3 | a0-1,B2-130 · 3 | a0-0,B1-130 · 3 |

## Rotation, ephemeral overflow, backend failure (A03)

| Probe | S0 | A1 | PW | P2 | RL |
|---|---|---|---|---|---|
| R1 pair split across an outer-key rotation: third member blocked? | yes | yes | **NO** | **NO** | yes |
| R2 block issued before rotation still enforced after | yes | yes | yes | yes | yes |
| F1a/F1b backend failure propagates (churn-set write / witness write) | BackendFailureException / n/a | BackendFailureException / n/a | BackendFailureException / BackendFailureException | BackendFailureException / BackendFailureException | BackendFailureException / BackendFailureException |

## Storage and state growth (A04 in-memory, A05 real Redis)

| Probe | S0 | A1 | PW | P2 | RL |
|---|---|---|---|---|---|
| A04 S3: 3000 unique anchors, correlation ON, nobody denied: entries (sets+flags) per request | 0.7 | 0.7 | 1.94 | 2.6 | 1.54 |
| A04 S3: bounded-set classes (ttl/cap: calls) | 900/50:3000 600/3:3000 600/6:3000 | 900/50:3000 600/3:3000 600/6:3000 | 900/50:3000 600/3:9000 600/6:3000 | 900/50:3000 600/3:9000 660/6:3000 600/6:3000 | 900/50:3000 600/3:3000 600/6:3000 |
| A04 after 2000 s idle: entries left | 0 | 0 | 0 | 0 | 0 |
| A05 R3 real Redis: keys created per request, rounded (400 rotating anchors) | 7 | 7 | 19 | 19 | 15 |
| A05 R4 real Redis: 8 concurrent failures of ONE fingerprint (20 rounds) vs the distinct-evidence tally (correct = 1) | n/a | n/a | inflated · tally reached the evidence threshold (3) with ONE fingerprint: yes | inflated · tally reached the evidence threshold (3) with ONE fingerprint: yes | n/a |

## User-visible legitimate denials under paid poisoning, all causes (C2)

**A1**

| Stream/flow | S0 | A1 | with correlation disabled |
|---|---|---|---|
| X6/pre+out | 79/81 | 79/81 | 0 |
| X6/out | 78/81 | 78/81 | 0 |
| Y4c/pre+out | 28/30 | 28/30 | 23 |
| Y4c/out | 28/30 | 28/30 | 0 |
| Y4e/pre+out | 79/81 | 79/81 | 73 |
| Y4e/out | 79/81 | 79/81 | 0 |
| Y4f/pre+out | 79/81 | 79/81 | 73 |
| Y4f/out | 78/81 | 78/81 | 0 |
| Y6c/pre+out | 28/30 | 28/30 | 19 |
| Y6c/out | 27/30 | 27/30 | 0 |

**PW**

| Stream/flow | S0 | PW | with correlation disabled |
|---|---|---|---|
| X6/pre+out | 79/81 | 0/81 | 0 |
| X6/out | 78/81 | 12/81 | 0 |
| Y4c/pre+out | 28/30 | 23/30 | 23 |
| Y4c/out | 28/30 | 23/30 | 0 |
| Y4e/pre+out | 79/81 | 73/81 | 73 |
| Y4e/out | 79/81 | 71/81 | 0 |
| Y4f/pre+out | 79/81 | 73/81 | 73 |
| Y4f/out | 78/81 | 36/81 | 0 |
| Y6c/pre+out | 28/30 | 19/30 | 19 |
| Y6c/out | 27/30 | 6/30 | 0 |

**P2**

| Stream/flow | S0 | P2 | with correlation disabled |
|---|---|---|---|
| X6/pre+out | 79/81 | 0/81 | 0 |
| X6/out | 78/81 | 12/81 | 0 |
| Y4c/pre+out | 28/30 | 23/30 | 23 |
| Y4c/out | 28/30 | 23/30 | 0 |
| Y4e/pre+out | 79/81 | 73/81 | 73 |
| Y4e/out | 79/81 | 71/81 | 0 |
| Y4f/pre+out | 79/81 | 73/81 | 73 |
| Y4f/out | 78/81 | 36/81 | 0 |
| Y6c/pre+out | 28/30 | 19/30 | 19 |
| Y6c/out | 27/30 | 6/30 | 0 |

**RL**

| Stream/flow | S0 | RL | with correlation disabled |
|---|---|---|---|
| X6/pre+out | 79/81 | 77/81 | 0 |
| X6/out | 78/81 | 32/81 | 0 |
| Y4c/pre+out | 28/30 | 26/30 | 23 |
| Y4c/out | 28/30 | 26/30 | 0 |
| Y4e/pre+out | 79/81 | 77/81 | 73 |
| Y4e/out | 79/81 | 77/81 | 0 |
| Y4f/pre+out | 79/81 | 77/81 | 73 |
| Y4f/out | 78/81 | 55/81 | 0 |
| Y6c/pre+out | 28/30 | 5/30 | 19 |
| Y6c/out | 27/30 | 5/30 | 0 |


## Offline prediction (PR #103 model) vs experimental runtime — login pre+out

The model has no K4/K1-spray/score protections and (for A1/PW/P2) assumes the M2 distinct-arrival WATCH, which needs a store-contract signal; the runtime candidates do not. Differences are therefore expected and are reported, not hidden. A1 here is the runtime control "LOW exemption only" (no M2).

**A1**: 7/14 identical.

| Stream | model | runtime | same |
|---|---|---|---|
| L1 | 8/134 0/0 reach0 | 127/134 0/0 reach0 | **NO** |
| L1f | 8/134 0/0 reach0 | 127/134 0/0 reach0 | **NO** |
| L2 | 0/121 0/0 reach0 | 0/121 0/0 reach0 | yes |
| L3 | 0/121 0/0 reach0 | 0/121 0/0 reach0 | yes |
| L4 | 0/12 0/0 reach0 | 0/12 0/0 reach0 | yes |
| X1 | 0/0 59/61 reach2 | 0/0 60/61 reach2 | **NO** |
| X2 | 0/0 0/121 reach121 | 0/0 0/121 reach121 | yes |
| X3 | 0/0 0/121 reach121 | 0/0 120/121 reach2 | **NO** |
| X4 | 78/81 39/40 reach1 | 79/81 40/40 reach0 | **NO** |
| X2L | 0/30 0/121 reach121 | 0/30 0/121 reach121 | yes |
| X5 | 0/0 0/21 reach21 | 0/0 0/21 reach21 | yes |
| X6 | 78/81 39/40 reach1 | 79/81 40/40 reach0 | **NO** |
| X7 | 0/0 0/121 reach121 | 0/0 0/121 reach121 | yes |
| X8 | 0/50 0/151 reach151 | 0/50 119/151 reach32 | **NO** |

**PW**: 8/14 identical.

| Stream | model | runtime | same |
|---|---|---|---|
| L1 | 0/134 0/0 reach0 | 0/134 0/0 reach0 | yes |
| L1f | 0/134 0/0 reach0 | 90/134 0/0 reach0 | **NO** |
| L2 | 0/121 0/0 reach0 | 0/121 0/0 reach0 | yes |
| L3 | 0/121 0/0 reach0 | 0/121 0/0 reach0 | yes |
| L4 | 0/12 0/0 reach0 | 0/12 0/0 reach0 | yes |
| X1 | 0/0 59/61 reach12 | 0/0 60/61 reach2 | **NO** |
| X2 | 0/0 0/121 reach121 | 0/0 0/121 reach121 | yes |
| X3 | 0/0 0/121 reach121 | 0/0 120/121 reach2 | **NO** |
| X4 | 73/81 37/40 reach21 | 0/81 39/40 reach2 | **NO** |
| X2L | 0/30 0/121 reach121 | 0/30 0/121 reach121 | yes |
| X5 | 0/0 0/21 reach21 | 0/0 0/21 reach21 | yes |
| X6 | 73/81 37/40 reach21 | 0/81 39/40 reach2 | **NO** |
| X7 | 0/0 0/121 reach121 | 0/0 0/121 reach121 | yes |
| X8 | 0/50 0/151 reach151 | 0/50 119/151 reach32 | **NO** |

**P2**: 8/14 identical.

| Stream | model | runtime | same |
|---|---|---|---|
| L1 | 0/134 0/0 reach0 | 0/134 0/0 reach0 | yes |
| L1f | 0/134 0/0 reach0 | 90/134 0/0 reach0 | **NO** |
| L2 | 0/121 0/0 reach0 | 0/121 0/0 reach0 | yes |
| L3 | 0/121 0/0 reach0 | 0/121 0/0 reach0 | yes |
| L4 | 0/12 0/0 reach0 | 0/12 0/0 reach0 | yes |
| X1 | 0/0 59/61 reach12 | 0/0 60/61 reach2 | **NO** |
| X2 | 0/0 115/121 reach7 | 0/0 115/121 reach7 | yes |
| X3 | 0/0 0/121 reach121 | 0/0 120/121 reach2 | **NO** |
| X4 | 73/81 37/40 reach21 | 0/81 39/40 reach2 | **NO** |
| X2L | 28/30 115/121 reach7 | 28/30 115/121 reach7 | yes |
| X5 | 0/0 0/21 reach21 | 0/0 0/21 reach21 | yes |
| X6 | 73/81 37/40 reach21 | 0/81 39/40 reach2 | **NO** |
| X7 | 0/0 0/121 reach121 | 0/0 0/121 reach121 | yes |
| X8 | 48/50 145/151 reach7 | 48/50 146/151 reach6 | **NO** |
