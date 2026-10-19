# D8 option 8.2 — a cross-account failure capacity on the proven door: design on paper (#97, step 4)

> **Status:** DESIGN for a measurement. Not an ADR, not a policy, not a value, not an API shape, not a statement that Runtime is ready. Written **before** the G7 plan and before any G7 code or result.
> It makes the Owner's direction on decision 8 of DEC-025 (option 8.2, conditional on a measurement) testable. No change to `src/`, `tests/`, `composer.json`, presets, contracts, DEC-019 or DEC-021.
> **Question.** Can a narrow rule on the proven door (P) bound an attacker who holds valid sessions for many accounts behind one source, **without refusing the legitimate office** that has the same request shape?

## 1. What the problem is, and what it is not

`TMS` (an attacker with valid sessions for 12 accounts behind one NAT) and `N12T` (the trusted office behind the same NAT) are the **same requests in the same order**; only the outcome differs: failure for the attacker, success with typos
for the office (#113: 0 of 61 decisions differ on S0). Therefore **no rule that acts before the verifier on the request alone can tell them apart.** On `FULL2` the attacker reaches 61/61 (`TMS`) and 120/200 (`TMSd`) against 7/61 and
2/200 on S0 (G6 arm 2), bounded only by `P_acct` per account and window.

The only separating signal the library can hold without a new input is the **outcome**: an office's users mostly succeed; a multi-session attacker fails on many different accounts from one source.

## 2. The rule (one mechanism)

| Item | Definition |
| --- | --- |
| **Scope** | the **source** (IP or IPv4 /32 / IPv6 /64 prefix, as `U_src`) **on the proven door only** |
| **Membership** | an **account** is a member of the source's set for the window when an attempt from that source that was **admitted on P** for that account **failed** (a recorded failure outcome). A different account is a different member |
| **Success** | **not counted and gives no credit back.** It never adds a member and never removes one |
| **Effect** | when the set of the source already holds `F` accounts in the window, a P request for a **further** account is refused before the verifier with a capacity refusal (a `SOFT_BLOCK` capacity decision, cause `cap:`…); a request for an account already in the set is not refused by this rule |
| **Persistence** | none beyond the window: a per-window set, like `U_src`'s counter. **No escalation, no penalty score, no persisted block on the source** |
| **Value** | `F` is a study value taken from the archive (G6 plan §2) |

**Not part of it:** a count of sessions or credentials (the attacker's `TMS` holds one per account; a count of those equals the office's); any use of the User-Agent; any Host input; any new identifier.

## 3. Attribution (DEC-022 Decision 1)

The rule writes **no persisted penalty on a shared scope.** The source is a shared scope (an office, a CGNAT), so it may carry only "observation, or a per-window capacity bound that neither escalates nor persists". This rule is the second kind:
a per-window capacity bound. It is the P-door twin of the net failure cap `F` of the U door, which already has that shape (`cap:fail_src`, G3 v2).

| DEC-022 requirement | How the rule meets it |
| --- | --- |
| a persisted penalty only on Actor × Subject or on the Subject's door | none is persisted |
| a shared scope has only the roles "observation" or "per-window capacity that neither escalates nor persists" | capacity per window; the set is discarded with the window |
| failures attributed to U never reach P state or a P budget | U failures do not enter the P set; P failures do not enter the U set (two separate counters) |
| success never earns credit on a shared scope | success neither counts nor refunds |

## 4. Relation to the existing `cap:fail_src` on the U door

**A separate counter with the same shape, keyed by door.** The U counter counts accounts that failed through the U door; the proposed one counts accounts that failed through the P door. They are not summed, so a stranger's U failures never refuse a
proven user (door separation) and a proven user's failures never consume the strangers' budget. The U counter keeps its net form (a later success of the same account from the same source removes it); the P counter does **not** (success gives
no credit), as the assignment fixes. That difference is deliberate: a proof holder who succeeds once on account A must not erase the evidence of failures on B, C, D.

## 5. Where it acts, and where it does not

| Mode (DEC-023) | Behaviour |
| --- | --- |
| `pre+out` | acts: the outcome is recorded, the set fills, the refusal happens at `checkOnly` before the verifier (a reservation-class component under DEC-024's opt-in principle) |
| `pre` | **inert**, like the U failure cap: no outcome arrives, the set stays empty. Whatever protection a `pre` Host has on the proven door is `P_acct` / `P_proof` only |
| `out` | nothing is bounded before the verifier in any design |
| degraded mode (DEC-015) | absent, like every admission bound |

It is a new typed opt-in capability if it is ever built (DEC-008, DEC-024 C-c1). No shape is designed here.

## 6. What it can and cannot do (`DERIVED`; the measurement decides)

* **Against the attacker.** A source can have failures on at most `F` accounts per window; further accounts are refused. Failures on the `F` accounts it already holds continue up to `P_acct` / `P_proof`. So the aggregate reach of `TMS` / `TMSd` falls from
  "every account × `P_acct`" to "about `F` accounts × their caps per window". It will **not** reach S0's 7/61 and 2/200 unless `F` is small: S0's bound came from K2 churn on the first request.
* **Against the office.** The office is refused only when more than `F` **different** users fail within one window from the same source. With the office's typo rate this is the benign gate the Owner named; a large office, or a mass-reset event, can cross it. That
  is the cost to be measured, not assumed.
* **`TMX`** (one session against 12 accounts) has proof only for its own account; its foreign accounts are on the U door, so the P rule adds nothing there.
* **A Host that does not record outcomes** gets nothing from it (§5).

## 7. Open points, for the plan

1. The window: the archive's `W` = 600 s (the same as `U_src` and `P_acct`).
2. The threshold `F`: the archive's 4 (the U failure cap) as the primary arm and the archive's 8 as a neighbouring arm for reporting.
3. Which cells are gated and which are declared costs: fixed in `g7/cross_account_p/PLAN.md` before any code.
