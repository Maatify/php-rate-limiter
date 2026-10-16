# Paper comparison of the 11 Shared NAT architecture proposals

**Type:** study artifact — paper analysis of design texts only. No runtime, API, Store Contract or DEC change; no ranking, no winner, no candidate selection, no merged design.
**Umbrella:** [#122](https://github.com/Maatify/php-rate-limiter/issues/122) (sub-issue of #110).
**Source snapshot (pinned):** every citation below refers to the files under `proposals/` at commit `54e119e10848006ad4037dc9200bf70579061ae4` (source reconciliation, §0.5). Line numbering is identical to the earlier snapshot `ecd0d96`. If any proposal file changes, this comparison must be reconciled against the new SHA-256 before any of its statements are reused.
**Status:** draft for engineering review. Nothing here is accepted until the Owner and the Lead review it.

| # | File | SHA-256 | Original verification (§0.5) |
|---|---|---|---|
| 01 | `proposals/01-kimi-3.md` | `e08f1342243b7bbaa1af6efc0fc71e9aba0c3333ee4cbcb2c660e63f9a10220d` | BYTE-VERIFIED |
| 02 | `proposals/02-gemini-argon.md` | `361a773618fe10e427376cdace96bae788417c6d6049586fda360e9289dd6944` | BYTE-VERIFIED |
| 03 | `proposals/03-gemini-3-1-pro.md` | `77a52e28c4b4208500bd8afbd3f1cc5686519fc1f9f1883ff4f935ff3c9809f6` | NOT VERIFIABLE (no original) |
| 04 | `proposals/04-deepseek.md` | `55c90cb72405f6b8d6bb04f9fa841aa6a0895359bd90b035f914c486a73f87fb` | PARTIAL (prefix [0, 1751) + length) |
| 05 | `proposals/05-grok.md` | `1b5fb2157b418a95072c8a2e5f2253f1b83a9a718674a71f3b7cc24911829f61` | BYTE-VERIFIED |
| 06 | `proposals/06-gpt-6-1-sol.md` | `08b5680b457fc088b21a98cfdac5a28b490ef8993896f2e55d936185f0a5c96d` | NOT VERIFIABLE (no original) |
| 07 | `proposals/07-gpt-6-astra.md` | `6699f88ba1c5546426a938ca480b998d77c407ae2284a4a6428ff289d32a8b02` | NOT VERIFIABLE (no original) |
| 08 | `proposals/08-gpt-6-luna.md` | `d442a23e05ff3090a6bcec6f27989aaacb7e7f67614f67ac3b7c3df6f9b06bfb` | NOT VERIFIABLE (no original) |
| 09 | `proposals/09-gpt-6-5-terra.md` | `9d626820e7fa476269e81073006e98f363963e4fb7989aba9b22f8c0b6f94db8` | NOT VERIFIABLE (no original) |
| 10 | `proposals/10-fable-5-1.md` | `67cd22828e0dae192e1222337d7d37323a015695d63bb489b153af15a0996bfb` | BYTE-VERIFIED |
| 11 | `proposals/11-opus-5-5.md` | `f69479abd38a6c597096e13d9cd9d84e029cab338a58f3486987769f05c0f605` | BYTE-VERIFIED |

---

## 0. Method

### 0.1 Two separate layers

- **Part A — Design Analysis (§1–§8).** Only what the eleven texts say, cited by file and line (`02:L353` = `proposals/02-gemini-argon.md`, line 353, at the pinned commit). No outside result is attributed to a design.
- **Part B — Security & Governance Constraints (§9).** The project's binding decisions and standards. They are listed separately and are **not** used to grade any cell in Part A. They become relevant only when someone later judges whether a design can be built.

### 0.2 Evidence codes

Each judgement carries one or more codes. None of them means "proven secure".

| Code | Meaning |
|---|---|
| `MECH` | The text describes a concrete mechanism (what is counted, where, when). |
| `CLAIM` | The author states a guarantee. Not verified here. |
| `COND[x]` | The guarantee holds only under the named assumption `x`. |
| `DERIV` | Follows (or fails) logically from the contract as written; the derivation is shown in the text of this document. |
| `EXP` | Can only be settled by an independent experiment. |
| `CONTRA` | A proven internal contradiction: two quoted passages of the same design that cannot both hold. Missing information is never `CONTRA`. |
| `UNSPEC` | The design does not specify this aspect. Behaviour is not guessed. |

A stated bound is reported with its **unit, window and assumptions**. Bounds from different designs are not compared numerically when their units differ (attempts vs pairs vs accounts vs risk points).

### 0.3 Bias control

The same evidence standard applies to all eleven designs. Model name, popularity, or the number of designs that agree with an idea is not used as evidence. Note for reviewers: designs 10 and 11 were produced by Claude models, and this comparison is written by a Claude model. Every judgement on 10 and 11 is cited like the others so that a reviewer can check it independently.

### 0.4 Provenance notes (not judgements)

- `10:L3`, `10:L17`, `10:L381`, `10:L399` contain Owner decisions (Q1) recorded inside the design text after it was written; `10:L3` also says "غير ملتزمة في Git", which is no longer true of this copy. `10` also cites prior internal studies (`10:L49–L51`: OAAA, HMCI, F‑AKDR‑01; AKDR at `10:L162`, `10:L338`). These references are part of the verbatim source, not inputs to this analysis.
- `08:L264` says the author used "ملاحظة سابقة تخص تقييم أدلة rate limiter" in addition to the challenge text.
- `11:L1` states the answer was written from the challenge text only.

### 0.5 Source reconciliation (F123-01)

| # | Original available? | Result at `54e119e` |
|---|---|---|
| 01 | yes, #110 record (SHA-256 recorded) | byte-identical after removing a trailing LF added at recording |
| 02 | yes, #110 record (13 segments, SHA-256 recorded) | byte-identical after removing the added LF |
| 05 | yes, #110 record (20 segments, SHA-256 recorded) | 21 Arabic diacritic pairs had been reordered (fatha/kasratan before shadda, Unicode canonical order) on the paste path; original order restored; byte-identical |
| 10 | yes, Owner file `NAT_ARCHITECTURE_STUDY_2026-10-16_AR.md` and #110 record | byte-identical, unchanged |
| 11 | yes, #110 record (SHA-256 recorded) | byte-identical after removing the added LF |
| 04 | partial: #110 held only part 1/10, chars [0, 1751) of 16,535, and marked the record INCOMPLETE | prefix matches; total length 16,535 matches; remainder unverifiable |
| 03, 08, 09 | no original outside the Owner's chat paste | added LF removed; the text contains no shadda + short-vowel sequences, so the reordering seen in 05 cannot have occurred |
| 06, 07 | no original outside the Owner's chat paste | added LF removed; the text contains 4 and 6 short-vowel + shadda sequences whose original order cannot be verified |

**Archive status (2026-10-16, after merge of #123):** the 85 #110 comments that held these verbatim records (79 source segments and 6 COMPLETE VERBATIM records for 01, 02, 04, 05, 10, 11) were deleted by the Owner after each one was verified byte-for-byte against the files on `main` (segments contained in the file; records carrying the file's SHA-256). The "#110 record" entries above therefore describe the reference used at reconciliation time, not comments that still exist. A full pre-deletion backup of all 139 #110 comments is held by the Owner outside the repository: `comments-20261016T140552Z.json`, SHA-256 `466b98a440b60387d3fb718c992ef5b7c192d5323edab13752f937405c98f948`.

The reordering in 05 does not change any word, line or citation used here. All Arabic quotes in this document were re-checked against the reconciled files (§0.6).

### 0.6 Scope of the reviews behind this document

- **First pass (`501a525`):** four LLM reviewers, one per group of designs, were asked to check (1) that each cited line contains the claimed text, (2) exact Arabic quotes, (3) fairness of the reading, and (4) to try to refute each `CONTRA` and `DERIV` item. They did not prove bounds formally and did not run experiments. One reviewer proposed a derivation (C‑14, "unbounded K4 inflation") that was wrong; it was adopted without an independent derivation.
- **Engineering review of `501a525` (Owner):** found F123-01 to F123-05. C‑01 and C‑13 were reclassified from `CONTRA` to `DERIV` because each admits a consistent reading; C‑14 was re-derived; the shared-refund effect was added (C‑15); C‑08 was tightened with the K4 cap.
- **Engineering review of `a00af51` (Owner):** found F123-06: the first version of C‑15 inferred a reach_foreign break from gross admissions. C‑15 was rewritten: refunds give neighbour benefit and gross admissions above the cap, but unrefunded admissions, and so failed foreign attempts, stay within the cap per fixed window.
- **Status of every `CONTRA`, `DERIV` and bound here:** paper findings for engineering review. A citation check does not validate a security conclusion; acceptance of any `CONTRA` or reach bound requires the engineering review, and claims marked `EXP` require a reproducible experiment.

---

## 1. Design cards

Each card answers the same fields. "State model" refers to how an identity or a (client, account) relation becomes more trusted.

### 01 — KIMI 3, "Anchored Fair Limiter" (AFL)

| Field | Content |
|---|---|
| Core idea | "Observe wide, enforce narrow, escalate before you block" (`01:L111`). Budgets B1 per account, B2 per principal, B3 network backstop, B4 per-account concurrency, executed in one Lua script (`01:L113–L150`). |
| Network counts | Breadth of accounts (HLL) + low success ratio; fixed strict budget in pre-check-only (`01:L166–L173`). B3 applies **only** to traffic without Instance Token or session (`01:L141`). |
| Identity | Host-signed Instance Token issued "عند أول زيارة" (`01:L225`); no issuance limit is specified. A new identifier gets "أرضية صفرية + ميزانية أول-اتصال صغيرة" (`01:L155`). |
| Separation vs budget | Identity is a budget holder: B2 per principal and a first-contact budget per new identifier (`01:L124`, `01:L155`). |
| State model | Trust edges (Instance↔Account) gain trust with age and successes; "≥ 7 أيام + ≥ 3 نجاحات لرفع الاحتكاك" (`01:L216`); trust does not transfer across accounts (`01:L154`). No NEW/SEEN/KNOWN states. |
| Success effect | No refund: "النجاح لا يسترجعها (لا success-farming)" (`01:L148`). |
| Decisions | allow → throttle → challenge → narrow-block (`01:L131–L132`). |
| Host tiers | Pre-check only = declared "degraded" (`01:L162`); + outcome = full; outcome-only rejected or locally limited (`01:L164`). |
| Atomicity claim | "سكربت Lua واحد ذرّي" (`01:L146`); Redis Cluster not discussed (`UNSPEC`). |
| Failure modes | Configurable fail-closed with small local bucket or fail-open with alert (`01:L177`); versioned keys; IPv6 /64 (`01:L178–L180`). |
| Strongest contribution | The punishment-scope rule (`01:L136–L142`) and an explicit list of self-rejection criteria R1–R4 (`01:L261–L265`). |
| Most dangerous assumption | That issuing an Instance Token costs the attacker more than rotating a cookie (`01:L59`), while no issuance limit is defined (`01:L225`); the author also states any budget given to a new user is given to every new attacker identity (`01:L67`). |
| Possible break | `DERIV`, `COND[cheap token issuance; first-contact budget per identifier]`: B3 skips any request that carries a token (`01:L141`); tokens are issued at first visit (`01:L225`); each new identifier gets a first-contact budget (`01:L155`). Under both conditions a token-rotating attacker stays outside B3 and reach_foreign grows with the number of tokens, limited by B1 per victim account. Neither condition is settled by the text. See C‑01. |

### 02 — Gemini Argon, "Sovereign Quota & Asymmetric Escalation Engine" (SQAEE)

| Field | Content |
|---|---|
| Core idea | Four enforcers in one Lua script: per-account shield, Verified-CIT fan-out shield, unverified network pool with sub-key dampening, and a cross-account unbound-failure circuit breaker (`02:L131–L192`). |
| Network counts | `IP_Unverified_Budget` for all unverified traffic (`02:L163–L166`) + `Cross-Account Unbound Failures` per IP (`02:L180–L182`). K2 and Fingerprint Churn removed (`02:L127`). |
| Identity | Library-generated HMAC token carrying bound subjects client-side in the recommended contract (`02:L397–L404`); Host-issued tokens are also allowed (`02:L49`, `02:L146`, `02:L258`); revocation state in Redis (`02:L405`). |
| Separation vs budget | Unverified IDs get no own budget beyond a sub-cap inside the pool (`02:L165–L170`). Verified CITs get their own bucket on bound accounts (`02:L237`). |
| State model | Unverified → Verified only after a signed successful authentication (`02:L125`); bound accounts list; fan-out allowance of one extra account per 24 h (`02:L150`); burned after two failures on unbound accounts (`02:L153`). |
| Success effect | **Refund** of "80% إلى 100%" of the pool debit for that reservation only, never above the original cap (`02:L211–L212`), returned to the shared `IP_Unverified_Budget` (C‑15). Failure counters are never reduced (`02:L126`). |
| Decisions | ALLOW / STEP_UP / BLOCK (`02:L49–L50`, `02:L379`). |
| Host tiers | Pre-check only: no refund, no automatic promotion unless the Host passes `is_known_device_for_account` (`02:L335–L338`). |
| Atomicity claim | One Lua script, in-flight counter TTL 5 s, one-time reservation id (`02:L195–L208`). Cluster `UNSPEC`. |
| Failure modes | APCu / local per-worker limiter with local HMAC verification during Redis outage (`02:L427–L429`); key-id rotation; schema-prefixed keys (`02:L421–L422`); IPv4 /32 with advisory /24, IPv6 /64 (`02:L416–L417`). |
| Strongest contribution | Exemption limited to the accounts a token is bound to, with a falsification test that requires `AVR(K=12, M=20) == AVR(K=0, M=1)` (`02:L460`). |
| Most dangerous assumption | That the fan-out allowance and the outcome-dependent breaker (Enforcer 4) are tuned so that minted-and-bound tokens give no extra victim attempts. |
| Possible break | `CLAIM` (author-stated, `02:L353`) / `COND[M=1]`: a fresh CIT that succeeds on one attacker-owned account becomes Verified, and while one extra account per 24 h is allowed (`02:L150`) each such CIT can make one victim attempt outside the pool before the exemption is withdrawn (`02:L151–L152`). The aggregate is then bounded by Enforcer 4, which counts failures and therefore needs outcomes; per victim, Enforcer 1 applies (`02:L140`). The author recommends `M=0` (`02:L470–L471`), which closes this path. See C‑06. Also `DERIV` C‑02 on the 2/200 figure. |

### 03 — Gemini 3.1 Pro, "Contextual Risk Enclaves & Challenge-Response Routing"

| Field | Content |
|---|---|
| Core idea | Three atomic counters: strict account bucket, strict CID bucket, dynamic IP risk pool; decision ALLOW / BLOCK / CHALLENGE_REQUIRED (`03:L52–L110`). |
| Network counts | A risk score: +1 per pre-check, −2 per reported success, +4 per reported failure, max e.g. 100 (`03:L81–L91`). |
| Identity | Host-generated CID, mandatory (`03:L62`, `03:L187`). No issuance limit. |
| Separation vs budget | CID has its own strict bucket (15 / 5 min) (`03:L75–L79`). |
| State model | Host `Trust Flag` per account ("is_trusted_device_for_this_account" or "passed_captcha") (`03:L64`). No library-side state transitions. |
| Success effect | Lowers the shared network risk by 2 (`03:L89`); passing a challenge also lowers IP risk (`03:L110`). |
| Decisions | ALLOW / BLOCK / CHALLENGE (`03:L53`, `03:L191`). |
| Host tiers | No outcomes: risk rises by 1 per pre-check with no decrement (`03:L170–L174`). |
| Atomicity claim | Lua script (`03:L66`, `03:L168`). Cluster `UNSPEC`. |
| Failure modes | Redis outage, rotation, mixed deploys, IPv6: `UNSPEC`. |
| Strongest contribution | Clear statement that trust must be relational to one account (`03:L15`, `03:L189`). |
| Most dangerous assumption | That successes reported on a shared network are evidence of benign traffic on that network. |
| Possible break | `DERIV`: own-account successes lower the shared IP risk. Whether the +1 adds to outcome weights is `UNSPEC` (C‑03); five (additive) or two (non-additive) own successes offset one foreign failure, so an attacker with valid accounts can keep the IP risk below the threshold indefinitely; reach_foreign is then limited only by the per-CID bucket (rotatable CID) and the per-victim account bucket. See C‑04, C‑05. |

### 04 — DeepSeek, "Hierarchical Fair-Share Admission Control with Client Instance Accounting and Graduated Challenges"

| Field | Content |
|---|---|
| Core idea | Every attempt consumes from Global + Network + Account + Client Instance + Account+Client (`04:L135–L142`); fair-share inside the network; graduated ALLOW / THROTTLE / CHALLENGE / DENY (`04:L144–L148`). |
| Network counts | Attempts against a network budget `B` distributed by fair-share across `C` active clients (`04:L161–L167`). |
| Identity | Host-created HMAC-signed CIT (`04:L150–L152`); "تدويره مسموح، لكنه يستهلك من حصة إصدار محدودة per Network، ويبدأ Probation" (`04:L159`). Missing CIT → low-trust key `IP+UA+session-less` (`04:L142`). |
| Separation vs budget | CIT is a sub-key; "وجود CIT لا يمنح حصة إضافية" (`04:L158`). |
| State model | Probation share for new clients (`04:L165`); no explicit NEW/SEEN/KNOWN. |
| Success effect | "Benign Credit" to account/client only; network/global spray counters untouched (`04:L170`). Credit adjusts Failure Debt only (`04:L133`) and is described as limited (`04:L215`); the amount is `UNSPEC`. |
| Decisions | DENY on Account or Global exhaustion; Network exhaustion → Constrained mode (`04:L110`); a client over its fair share is denied or challenged (`04:L166`, `04:L205`). |
| Host tiers | Pre-check only: stricter, no refund (`04:L171`); outcome-only: monitoring only (`04:L172`). |
| Atomicity claim | One Lua script (`04:L175`). Cluster mechanism `UNSPEC`; raised as an open question (`04:L346`). |
| Failure modes | Login/OTP fail-closed with local emergency bucket; documented as non-distributed (`04:L176–L179`). |
| Strongest contribution | "لا هوية بلا أب" — every new identity draws from a parent budget (`04:L129`), and issuance per network is limited (`04:L159`). |
| Most dangerous assumption | That fair-share over `C` active clients is meaningful when `C` itself can be raised by the attacker up to the issuance limit (`04:L167`). The author concedes the point and relies on the issuance limit (`04:L197`, `04:L244`), whose values are a Host-contract condition (`04:L358`). |
| Possible break | The author states service-wide blocking under Global DoS as a deliberate exception (`04:L131`). `DERIV`: an attacker able to exhaust Global (multi-network, which the design declares out of scope at `04:L67`, `04:L280`) triggers it for all users; whether challenged or denied attempts debit Global is `UNSPEC` (`04:L175`). See C‑09. |

### 05 — Grok, "Two lanes, non-multiplicable budget, narrowest punishment"

| Field | Content |
|---|---|
| Core idea | Bound lane (prior success or Host-confirmed session for this account) consumes only the account budget; unbound lane consumes account budget + spray budget `D` per prefix per day (`05:L69–L78`). |
| Network counts | Unbound attempts per prefix: short burst + daily cap `D` (`05:L85`). Not Browser Major, not fingerprint. |
| Identity | Host-planted instance id (`05:L78`, `05:L190`); minting is acknowledged as free ("توقيعه يمنع العبث بالصيغة لا يمنع زراعته عبر زيارة الموقع", `05:L190`) and neutralised by a per-instance sub-cap with a single overflow bucket after a bounded number of distinct keys (`05:L86`, `05:L127`). |
| Separation vs budget | Identity is separation/counting only; "وجوده لا يمنح حصة" (`05:L78`). |
| State model | Unbound → bound only via success or `session_for(account)` (`05:L73`, `05:L189`). |
| Success effect | No refund; reservation-with-refund explicitly rejected (`05:L121`). |
| Decisions | ALLOW / DENY / CHALLENGE (`05:L94–L111`). |
| Host tiers | Table `05:L113–L119`; outcome-only "المكتبة ترفض أن تسمّي هذا حماية دخول". |
| Atomicity claim | One Lua script (`05:L125`). Cluster `UNSPEC`. |
| Failure modes | Local per-worker cap `ceil(D / N)` with declared max N (`05:L131`); dual-read on secret rotation (`05:L129`); IPv4 /32, IPv6 /64 (`05:L133`). |
| Strongest contribution | A single unbound budget `D` that the author states cannot be multiplied by identifier rotation, with a concrete self-rejection list (`05:L223`). |
| Most dangerous assumption | That the Host only issues `session_for(account)` after it has itself authenticated that account (`05:L189`). |
| Possible break | `CLAIM` (acknowledged limit): an attacker with many prefixes gets `D` per prefix (`05:L159`); prefix length is an explicit product decision (`05:L133`, `05:L196`). `CLAIM·EXP`: adapting `D` from historic bound links is bounded by `D_max` (`05:L88`), whose value is `UNSPEC` (`05:L231`); the author states 12 attacker accounts do not move it "تحريكًا ذا شأن" (`05:L88`), which needs measurement. |

### 06 — GPT-6.1 Sol, "Bounded admission with target-bound proof and reserved shares"

| Field | Content |
|---|---|
| Core idea | Binding pre-verifier budgets for service, network, account, client, relation; split network budget into `B_U` (unproven) and `B_P` (proof) with `B_U + B_P = B_N`; no refund (`06:L274–L328`). |
| Network counts | Attempts, across all Browser Majors (`06:L279`). |
| Identity | Client Instance ID = continuity record; "يجوز للمعرف الجديد إنشاء سجل، ولا يجوز له إنشاء ميزانية أمنية إجمالية جديدة" (`06:L272`); free issuance acknowledged (`06:L270`) and state creation globally capped (`06:L439`, `06:L538`). |
| Separation vs budget | Separation only (`06:L257–L272`). |
| State model | No trust states; a Host-verified proof bound to target account, operation, freshness, level and anti-replay reference (`06:L330–L354`). |
| Success effect | No refund under any outcome (`06:L376–L389`). |
| Decisions | Admit / proof required / wait / store unavailable (`06:L626–L631`). |
| Host tiers | Table `06:L652–L661`; "inspect without debit" gives no concurrency guarantee. |
| Atomicity claim | Single Redis or one hash slot per tenant; cross-slot atomicity not claimed (`06:L390–L409`). |
| Failure modes | Ambiguous admission ⇒ no verifier call; no per-worker local fallback; Redis HA async replication acknowledged (`06:L677–L692`). |
| Strongest contribution | Honest accounting: `A_total ≤ min(B_G, B_N, ΣB_Ai)` across the attacker's 12 own accounts (`06:L520–L528`), refusal to assume any positive ΔA is acceptable (`06:L620–L622`). |
| Most dangerous assumption | That the Host can provide a pre-verifier proof for the target account. `DERIV`: without it either `B_P = 0` (the design reduces to its Alternative 1, `06:L159–L175`) or `B_P` stays reserved and idle, shrinking the share for new users (`06:L324`). The author names other routes: limited-issuance entitlement, waiting/interaction, or a measured trade-off (`06:L920`). |
| Possible break | `CLAIM` (stated by the author): an attacker drains `B_U` and new users without proof are delayed (`06:L506–L510`); an attacker holding proofs for many accounts can consume part or all of the reserved share (`06:L532`; also `06:L322`, `06:L552`). |

### 07 — GPT-6 Astra, "Proof-based admission for account and operation (lanes U/P)"

| Field | Content |
|---|---|
| Core idea | Unproven lane U and proven lane P, both debited atomically before the verifier; optional trusted accounting entity (`07:L156–L232`). |
| Network counts | Attempts on lane U per network and service (`07:L220`). |
| Identity | Client Instance ID optional; links context, gives no entitlement; the library may offer an issuing helper (`07:L182`, `07:L474`). |
| Separation vs budget | Separation only; a signed cookie "يثبت أن الخادم أصدرها، وليس أن الخادم أصدر واحدة فقط لكل شخص" (`07:L184`). |
| State model | Host proof for (account, operation, period) (`07:L188–L201`); "سجل «هذا الجهاز استُخدم سابقًا» وحده ليس إثبات حيازة حاليًا" (`07:L62`). |
| Success effect | No refund (`07:L293–L297`). |
| Decisions | Allow / wait / proof required / deny / protection unavailable (`07:L491`). |
| Host tiers | Table `07:L482–L487`. |
| Atomicity claim | One Redis or one hash slot per security scope (`07:L327–L329`); distinguishes issued permits from running verifier calls (`07:L335–L340`); δ between debit and execution enters the window proof (`07:L342`). |
| Failure modes | No new admission on Redis loss; per-worker fallback rejected (`07:L362–L370`); WAIT does not give strong consistency (`07:L374`). |
| Strongest contribution | Per-account P share that U traffic cannot consume (`07:L224–L228`), and an explicit table of P contribution vs number of attacker accounts (`07:L278–L287`). |
| Most dangerous assumption | That a usable proof exists for new browsers of existing accounts; for a fully anonymous visitor the author states none exists (`07:L70–L73`). |
| Possible break | `CLAIM` (stated): total attacker reach grows `m × p` with owned accounts up to `P_Σ` unless a trusted accounting entity exists (`07:L278–L289`, `07:L414–L418`). The author also states that separating lanes does not cancel the experiment-D effect: once legitimate traffic leaves U, the attacker may find more room in U under an unchanged cap (`07:L232`, `07:L441`), and no ΔA > 0 is assumed acceptable (`07:L455`). |

### 08 — GPT-6 Luna, "Atomic admission before the verifier with escalation to extra proof"

| Field | Content |
|---|---|
| Core idea | `beginAttempt` reserves atomically; budgets `B₀` (unproven), `A` (account), `B₁` (post-challenge), `C` (verifier capacity); network risk triggers CHALLENGE instead of block (`08:L58–L115`). |
| Network counts | Activity, target diversity and rate per IP; signals raise risk and request challenge (`08:L82`). |
| Identity | Optional host CID for separation; no new quota (`08:L117–L127`). |
| Separation vs budget | Separation only (`08:L127`). |
| State model | No trust states; known devices/sessions "لا تمحو عدادات الشبكة أو الحساب ولا تتجاوز متطلبات Challenge" (`08:L86`). |
| Success effect | No refund (`08:L115`). |
| Decisions | Allow / challenge / deny-or-delay (`08:L62–L75`). |
| Host tiers | `08:L177–L181`. |
| Atomicity claim | Atomic multi-key reservation (`08:L105`); cluster requires keys in one operation space (`08:L113`). |
| Failure modes | Fail-closed or documented emergency path not claimed as global limit (`08:L185`); HMAC rotation dual read/write (`08:L187`). |
| Strongest contribution | Does not grant any identity or session a bypass of shared counters, and states that `B₁` after a CAPTCHA is a cost, not a hard bound (`08:L101`). |
| Most dangerous assumption | Depends on an unapproved acceptance of challenge friction for all users of a pressured network, including known employees; the author states the friction cannot be avoided and leaves acceptability to the Owner (`08:L135`, `08:L142`, `08:L162`, `08:L249`). |
| Possible break | `CLAIM` (stated): an attacker able to pass challenges has no hard bound after `B₀` (`08:L139`, `08:L101`). |

### 09 — GPT-6.5 Terra, "Hierarchical Costed Admission Control"

| Field | Content |
|---|---|
| Core idea | Reserve capacity from Global, Network, Account, Account+Client, Client (sub-limit) and a shared New/Unattested pool; CONSTRAINED mode under network pressure (`09:L54–L74`). |
| Network counts | Attempts and distinct-account pressure; HLL may not drive a hard deny alone (`09:L114`). |
| Identity | CIT as sub-key only; issuance consumes an Issuance Budget or requires challenge (`09:L162`). |
| Separation vs budget | Sub-share under parent scopes, not an independent budget (`09:L62`, `09:L65`, `09:L111`, `09:L137`). |
| State model | New/unattested pool vs others; no explicit KNOWN state. |
| Success effect | Outcomes adjust local debt only; no network/global refund (`09:L90`). |
| Decisions | ALLOW / THROTTLE / CHALLENGE / DENY; challenge yields a ticket and a new reservation (`09:L67–L74`). |
| Host tiers | `settle()` optional, does not change pre-auth limits (`09:L160`). |
| Atomicity claim | Lua atomic only within a node/slot; requires a single primary, a transactional store, or conservative leases (`09:L94–L104`); rejects sequential multi-key reservation (`09:L104`). |
| Failure modes | Fail-closed or challenge-only for Login/OTP; local per-worker bucket not a distributed guarantee (`09:L167`). |
| Strongest contribution | Explicit statement of Redis Cluster limits and of the cost of sequential reservations (`09:L94–L104`). |
| Most dangerous assumption | That Issuance Budget + probation pool make CIT rotation unprofitable; the author notes many CITs may yield several sub-shares unless issuance and challenges are costly and limited (`09:L137`); values are `UNSPEC`. |
| Possible break | `UNSPEC`: the reach of an attacker with valid sessions is "جزء محدود" with no bound (`09:L115`). Also ambiguous: whether a success that lowers local account debt (`09:L90`, `09:L113`) changes the next pre-auth reservation, given "`settle()` ... لا يغيّر حدود pre-auth" (`09:L160`); not a contradiction. |

### 10 — Fable 5.1, "Minted identity and pair-level admission"

| Field | Content |
|---|---|
| Core idea | Library-minted Attempt Credential (AC); budgets A (pair), S (identity), K4 (account), I (minting per network), U (unknown pairs per network), E, D; network never blocks an existing clean identity (`10:L150–L192`). |
| Network counts | Minting `I` and acceptance of unknown pairs `U` (`10:L152`); U disabled in pre-check only "لأنه سيساوي عدّ المحاولات" (`10:L192`). |
| Identity | Library mints; minting requires an `I` token; else DENY_ISSUANCE or CHALLENGE (`10:L162`, `10:L201`). |
| Separation vs budget | Fresh identity gets its full individual budget immediately, never more (`10:L153`). |
| State model | Pair known only after `recordSuccess(admissionId)` (`10:L216`); standing CLEAN/SUSPECT/DEGRADED tightens only (`10:L217`). |
| Success effect | No reset of identity counters; optional (Owner Q9) return of one unit to the shared `net:unknown` of the current window (`10:L216`, C‑15). |
| Decisions | ALLOW / DENY / CHALLENGE (`10:L201–L205`, `10:L238`). |
| Host tiers | Tier 0 carry credential, Tier 1 outcomes with admissionId, Tier 2 challenge (`10:L305–L321`). |
| Atomicity claim | One Lua script; in Cluster a fixed-order per-scope script where units consumed before a later rejection "تبقى ... محسوبة على الطالب" (`10:L211`). |
| Failure modes | Signature verification stateless; minting impossible during outage; local per-ac limit in DEGRADED (`10:L332`); kid grace re-sign same id (`10:L336`). |
| Strongest contribution | Privilege on the pair, not the identity, with a falsification list that includes a neighbour test (`10:L357`) and a mutation test for atomicity (`10:L352`). |
| Most dangerous assumption | That limiting minting per network (`I`) is not itself an exhaustion surface. |
| Possible break | `DERIV` (Cluster only): units consumed on earlier scopes remain charged when a later scope rejects (`10:L211`); a request rejected at U has already consumed the victim's K4 unit, so rejections burn victim K4 without reaching the verifier, up to `min(K4 cap, (I+H) × A)` per victim per W (C‑08). Optional Q9 refund returns capacity to a shared pool (C‑15). Ambiguous U definition and non-tight bound (C‑13). |

### 11 — Opus 5.5, "Pairwise Admission with Novelty Budgets"

| Field | Content |
|---|---|
| Core idea | Network counts novelty: NEW (credential, account) pairs per window on tiered prefixes; pair states NEW / SEEN / KNOWN; reserved K4 lane for KNOWN pairs (`11:L127–L163`). |
| Network counts | `U` = accepted NEW pairs on IPv4 /32 and /24 and IPv6 /64, /56, /48 (`11:L163`); `V` = attempts on SEEN pairs, outcome tier only (`11:L160`). |
| Identity | Free, stateless minting; library default; host id allowed if unguessable (`11:L136–L140`). |
| Separation vs budget | "وجود Cookie يمنح عدادات خاصة وذاكرة، ولا يمنح أي ميزانية إضافية" (`11:L129`). |
| State model | NEW → SEEN at first accepted attempt; SEEN → KNOWN only on `recordSuccess(admissionId)`; SEEN gives no privilege (`11:L142–L150`). |
| Success effect | KNOWN only; no unit returned, no counter reset (`11:L196`). |
| Decisions | ALLOW / DENY / CHALLENGE (`11:L178–L181`, `11:L205–L210`). |
| Host tiers | `11:L299–L315`. |
| Atomicity claim | Single Redis: one Lua; Cluster: per-slot scripts with compensation (DECR) of earlier reservations on rejection (`11:L180`, `11:L189–L192`). |
| Failure modes | Existing FAIL_CLOSED/DEGRADED; kid with verify-only old keys and same-id re-sign (`11:L317–L327`). |
| Strongest contribution | Separates SEEN from KNOWN so that pre-check-only still bounds the number of **new** accounts touched (`11:L57–L64`, `11:L69–L79`), and compensates earlier reservations on rejection to avoid permanent K4 burn (`11:L180`, `11:L186`). |
| Most dangerous assumption | That free minting is safe because cost is charged at novelty (`11:L136–L138`). |
| Possible break | `CONTRA`: reach_foreign is stated `≤ U` but U counts only new pairs while each pair allows up to A admissions (C‑12). `DERIV`/`EXP` (Cluster only): compensated rejections are free; held K4 units never exceed the K4 cap, but sustained floods may over-reject the victim (starvation hypothesis, C‑14). `CLAIM` (`11:L57–L64`, `11:L277`): in pre-check only, retries on SEEN pairs have no cross-account cap, which follows from free minting plus no pre-check V (C‑11). `UNSPEC`: the collateral of /24 aggregation on unrelated /32 users is not discussed (only /48 is, `11:L290`). |

---

## 2. Axis matrix (11 axes × 11 designs)

Cells: codes + evidence. "—" in the Evidence column means the design does not address the axis.

### Axis 1 — Scope of punishment under network pressure

| # | Codes | Evidence | Note |
|---|---|---|---|
| 01 | MECH, CLAIM | `01:L138–L142` | Narrowest principal; network action only on token-less traffic. |
| 02 | MECH, CLAIM | `02:L167–L168`, `02:L183–L185` | Only unverified requests / unbound accounts affected. |
| 03 | MECH | `03:L100–L106` | Whole network enters CHALLENGE for non-flagged CIDs. |
| 04 | MECH | `04:L110`, `04:L166` | Network → constrained mode; DENY on Account or **Global** exhaustion; per-client share exhaustion → DENY or CHALLENGE. |
| 05 | MECH, CLAIM | `05:L106`, `05:L109` | Only unbound requests on the prefix. |
| 06 | MECH | `06:L41–L45`, `06:L304–L327` | Request without remaining share; not group accusation. |
| 07 | MECH | `07:L113`, `07:L303`, `07:L400` | Whole U lane of the network (all unproven users); P lane unaffected. |
| 08 | MECH | `08:L82`, `08:L142` | Network risk → challenge for all unproven, including known employees. |
| 09 | MECH | `09:L63`, `09:L67–L74` | New identities to probation pool; challenge for clients over sub-limit. |
| 10 | MECH, CLAIM | `10:L152`, `10:L280` | Only minting and unknown pairs. |
| 11 | MECH, CLAIM | `11:L128`, `11:L275` | Only NEW pairs. |

### Axis 2 — Sybil: does a new cookie / UA / Major create budget?

| # | Codes | Evidence | Note |
|---|---|---|---|
| 01 | DERIV, COND | `01:L141`, `01:L155`, `01:L225` | Token-holders bypass B3; multiplication holds if issuance is cheap and first-contact budget is per identifier (C‑01). |
| 02 | MECH, CLAIM | `02:L163–L170` | Rotation stays inside one pool. |
| 03 | MECH, DERIV | `03:L75–L79`, `03:L150–L154` | CID bucket rotatable; only risk pool limits. |
| 04 | MECH, COND[issuance limit] | `04:L158–L159` | Limit values `UNSPEC`. |
| 05 | MECH, CLAIM | `05:L86–L88` | Overflow bucket caps distinct keys. |
| 06 | MECH, CLAIM | `06:L272` | No new budget per identifier. |
| 07 | MECH, CLAIM | `07:L182–L184` | Same. |
| 08 | MECH, CLAIM | `08:L84`, `08:L127` | Same. |
| 09 | MECH, COND[issuance budget] | `09:L74–L88`, `09:L162` | Values `UNSPEC`. |
| 10 | MECH, CLAIM | `10:L152–L162` | Minting costs `I`. |
| 11 | MECH, CLAIM | `11:L136–L138`, `11:L252` | Minting free; novelty costs `U`. |

### Axis 3 — Cross-account privilege (success/session on A helping B)

| # | Codes | Evidence | Note |
|---|---|---|---|
| 01 | MECH, CLAIM | `01:L154` | Trust on the edge only; but token holders bypass B3 (see axis 2). |
| 02 | MECH, COND[M=0] | `02:L150–L155`, `02:L471–L472` | One extra account per 24 h unless M=0. |
| 03 | MECH, COND[Host flag honest] | `03:L104`, `03:L189` | Trust flag per account from Host. |
| 04 | MECH | `04:L133`, `04:L170` | Credit to account/client adjusts Failure Debt only; not network/shared counters. |
| 05 | MECH, COND[`session_for` honest] | `05:L71–L74`, `05:L189` | |
| 06 | MECH | `06:L334–L345` | Proof bound to target account and operation. |
| 07 | MECH | `07:L205–L210` | Four explicit restrictions on proof. |
| 08 | MECH | `08:L86` | Session for A does not ease B. |
| 09 | MECH | `09:L85–L88` | No exemption for prior success/session. |
| 10 | MECH, CLAIM | `10:L154`, `10:L262` | Pair-level; "صافي المكسب عبر الحسابات = 0 بالتصميم (يُقاس)". |
| 11 | MECH, CLAIM | `11:L130`, `11:L249` | Pair-level; gain = 0 "ويجب قياس ذلك". |

### Axis 4 — Success credit: double refund, washing, shared replenishment

Three different properties are separated here (F123-04). A refund bound to the request's own reservation prevents fake or repeated refunds; it does **not** stop the returned capacity from benefiting someone else, because it goes back to a shared pool. Column (c) is about who can use returned capacity, not about attacker reach: a refund limited to the request's own debit keeps unrefunded admissions — and therefore failed foreign attempts — within the pool cap per window (C‑15, F123-06).

| # | (a) Fake / double refund prevented | (b) Own success washes own failures | (c) Shared capacity replenished (benefits any requester, incl. an adjacent attacker) | Evidence |
|---|---|---|---|---|
| 01 | n/a (no refund) | no | no | `01:L148` |
| 02 | yes: one-time `Attempt_Reservation_ID` (`02:L202`, `02:L208`), spoofed-refund test (`02:L462`) | no: failure and fan-out counters never reduced (`02:L126`) | **yes**: 80–100% of the request's own debit returned to the shared `IP_Unverified_Budget` (`02:L211`, `02:L212`); usable by any unverified requester on the IP; gross admissions can exceed the cap, unrefunded admissions cannot (C‑15) | `02:L207–L213` |
| 03 | `UNSPEC` (no attempt reference) | **yes** at network level (C‑04) | **yes**: −2 on the shared IP risk per success and on challenge pass (`03:L89`, `03:L110`) | `03:L87–L91` |
| 04 | `UNSPEC` for the credit | no: credit adjusts Failure Debt only (`04:L133`) | no: network/global spray counters untouched (`04:L170`) | `04:L133`, `04:L170`, `04:L215` |
| 05 | n/a (refund rejected) | no | no | `05:L121` |
| 06 | n/a | no | no | `06:L376–L389` |
| 07 | n/a | no | no | `07:L293–L297` |
| 08 | n/a | no | no | `08:L115` |
| 09 | `UNSPEC` | local debt only (`09:L90`) | no | `09:L90` |
| 10 | yes when enabled: bound to `admissionId` (`10:L212`, `10:L216`) | no: identity counters not reset (`10:L216`) | **yes when Q9 is enabled**: one unit, only if this admission consumed it, returned to the shared `net:unknown` of the current window (`10:L216`); usable by others, but unrefunded admissions stay ≤ U (C‑15); the author's claim "لا يفيد مهاجمًا مجاورًا إلا بقدر ما استهلك هو نفسه" is consistent with this | `10:L216`, `10:L362` |
| 11 | n/a (no refund) | no | no | `11:L196` |

### Axis 5 — Hard limit before the verifier under concurrency

| # | Codes | Evidence | Note |
|---|---|---|---|
| 01 | MECH, CLAIM | `01:L144–L150` | One Lua script, B4 per account. Cluster `UNSPEC`. |
| 02 | MECH, CLAIM | `02:L195–L208`, `02:L321–L329` | Lua + in-flight TTL 5 s. Cluster `UNSPEC`. |
| 03 | MECH, DERIV | `03:L168`, `03:L81–L87` | Additive reading: a burst can pass up to the pool max (e.g. 100) before outcomes; non-additive: no risk-pool burst bound at all (C‑05). The "~25" figure (`03:L136`) assumes sequential outcomes. |
| 04 | MECH, CLAIM | `04:L174–L179` | Cluster mechanism `UNSPEC`; open question (`04:L346`). |
| 05 | MECH, CLAIM | `05:L125` | Cluster `UNSPEC`. |
| 06 | MECH, COND[single slot] | `06:L390–L409`, `06:L411–L428` | Includes δ between debit and verifier start. |
| 07 | MECH, COND[single slot] | `07:L307–L342` | Permits vs running calls; δ in window proof (`07:L340–L342`). |
| 08 | MECH, COND[single op space] | `08:L103–L113` | |
| 09 | MECH, COND[single primary or leases] | `09:L92–L104` | |
| 10 | MECH, COND / DERIV | `10:L203–L211` | Cluster: no over-admission claimed; over-rejection charged to requester; permanent victim K4 burn up to `min(K4 cap, (I+H)×A)` (C‑08). |
| 11 | MECH, COND / DERIV | `11:L171–L192` | Cluster: compensation on rejection; no over-admission claimed. Held K4 units ≤ K4 cap at any instant; rejected requests cost the attacker no budget, so sustained over-rejection of the victim is a hypothesis to test (C‑14). `EXP`. |

### Axis 6 — Behaviour per Host mode

| # | Pre-check only | + Outcome | Outcome only | + Challenge |
|---|---|---|---|---|
| 01 | "degraded", B1–B4 + fixed B3 (`01:L162`) | full (`01:L163`) | rejected / local cap (`01:L164`) | escalation callback optional (`01:L229`) |
| 02 | no refund, promotion only by Host flag (`02:L335–L338`) | refund + promotion (`02:L201–L217`) | `UNSPEC` | STEP_UP optional (`02:L49–L50`) |
| 03 | risk only grows (`03:L170–L174`) | risk ± (`03:L87–L91`) | `UNSPEC` | required for NAT relief (`03:L180`) |
| 04 | stricter, no refund (`04:L171`) | failure debt (`04:L170`) | monitoring only (`04:L172`) | challenge capability flag (`04:L85`, `04:L293`) |
| 05 | caps hold; no new bindings without Host session (`05:L117`) | backoff + bindings (`05:L118`) | no protection (`05:L119`) | `D_challenge` (`05:L90`) |
| 06 | admission debit (`06:L657`) | learning (`06:L658`) | observe only (`06:L659`) | proof lane (`06:L304–L327`) |
| 07 | (`07:L484`) | (`07:L485`) | (`07:L487`) | proof required → P lane (`07:L168`, `07:L398`, `07:L491`) |
| 08 | (`08:L180`) | (`08:L179`) | (`08:L181`) | `B₁` (`08:L96`) |
| 09 | pre-auth reservation, no refund (`09:L119`) | `settle()` local debt (`09:L160`) | `UNSPEC` | ticket + re-reserve (`09:L73`) |
| 10 | Tier 0, U disabled (`10:L192`, `10:L311`) | Tier 1 (`10:L313–L316`) | no guarantee (`10:L326`) | Tier 2 (`10:L318–L321`) |
| 11 | Tier 0, U still counts NEW (`11:L299–L303`, `11:L75`) | Tier 1 + V (`11:L305–L307`) | no guarantee (`11:L315`) | Tier 2 (`11:L309–L311`) |

### Axis 7 — Day-zero / new-user trade-off stated explicitly

| # | Codes | Evidence |
|---|---|---|
| 01 | CLAIM | First-contact floor 2–3 attempts before escalation (`01:L213`); trade-off stated qualitatively. |
| 02 | MECH, CLAIM | New users share the unverified pool; refund chain on success (`02:L238`); STEP_UP under attack (`02:L261`). |
| 03 | CLAIM | Challenge or sacrifice the new user (`03:L206–L207`). |
| 04 | CLAIM | Probation + challenge (`04:L209–L211`). |
| 05 | MECH, CLAIM | `min(100, D)` on day zero, rest challenge or next day (`05:L139`). |
| 06 | CLAIM | Not guaranteed (`06:L484–L486`). |
| 07 | CLAIM | Not guaranteed for an anonymous visitor (`07:L70–L73`). |
| 08 | CLAIM | Challenge or accept unproven attempts (`08:L137`). |
| 09 | CLAIM | Challenge under pressure (`09:L110`). |
| 10 | MECH, CLAIM | Linear trade-off theorem with minting rate `I` (`10:L59–L68`, `10:L273`). |
| 11 | MECH, CLAIM | Linear trade-off theorem with novelty `U` (`11:L44–L55`, `11:L268`). |

### Axis 8 — Closed-form bound on attacker reach

| # | Bound as stated | Unit / window | Grows with | Codes |
|---|---|---|---|---|
| 01 | none closed; per-victim B1 (`01:L197`) | attempts | number of tokens (axis 2) | UNSPEC, DERIV |
| 02 | "10–15 / 200" with zero fan-out (`02:L353`) | attempts on victims / window per IP | IPs, windows | CLAIM, DERIV (C‑02 on the 2/200 figure) |
| 03 | "~25" (`03:L136`) | attempts / IP | own successes (C‑04), concurrency | CLAIM, DERIV |
| 04 | `≤` network budget `B` per window (`04:L162`, `04:L231`); "لا يستطيع تحويل 12 نجاحًا إلى 200 محاولة" (`04:L232`) | attempts / window | networks, Global; values `UNSPEC` | CLAIM |
| 05 | `≤ D` unbound per prefix per day (`05:L86`, `05:L147`) | attempts | prefixes; `D` adaptive up to `D_max` | CLAIM, EXP |
| 06 | reach_own `≤ min(B_G, B_N, ΣB_Ai)` (`06:L520–L528`); reach_foreign `≤ min(B_G, B_U)` per network (`06:L321`, `06:L506`, `06:L514`) | attempts / period | reach_own with owned accounts up to `B_N`; reach_foreign does not grow with owned accounts | MECH, CLAIM |
| 07 | `A_U + A_P`, `A_P ≤ min(P_Σ, Σ P_a)` (`07:L246–L272`) | attempts / T | owned accounts (`m·p`) | MECH, CLAIM |
| 08 | `B₀` then cost-only (`08:L92–L101`) | attempts | challenge-solving capacity | CLAIM |
| 09 | none (`09:L115`, `09:L121`) | — | — | UNSPEC |
| 10 | `min(U, (I+H)×S)×A` per W; pre-check `(I+H)×S×A` (`10:L261`) | attempts; author's form is a non-tight upper bound under the admissions reading (C‑13) | `H` (stored identities, grows with windows) | CLAIM, DERIV (C‑13: non-tight bound, ambiguous U; C‑10: grows) |
| 11 | reach_foreign `≤ U` per W per level (`11:L248`, `11:L250`) | stated for admissions, holds only for new accounts (C‑12) | levels; `× A` per pair; retries on SEEN bounded per account only in pre-check (`11:L62`) | CLAIM, **CONTRA** (C‑12) |

Units are not comparable across rows: 05, 06, 07, 10 count attempts (10's bound is non-tight, C‑13); 11 states a bound in U whose unit conflicts with its own definition of reach_foreign (C‑12); 03's network counter is in risk points and the stated ~25 is attempts derived from them.

### Axis 9 — Operations (Redis outage, rotation, mixed deploy, rollback, IPv6)

| # | Outage | Rotation | Mixed deploy / rollback | IPv6 |
|---|---|---|---|---|
| 01 | local bucket or fail-open with alert (`01:L177`) | key ring (`01:L179`) | versioned keys (`01:L178`) | /64 (`01:L180`) |
| 02 | local APCu limiter (`02:L427–L429`) | `kid` v1/v2 (`02:L421`) | schema prefix (`02:L422`) | /64 or /48 (`02:L417`) |
| 03 | UNSPEC | UNSPEC | UNSPEC | UNSPEC |
| 04 | fail-closed + local bucket (`04:L176–L179`) | UNSPEC | question (`04:L349`) | /64 (`04:L137`); /64 vs /56 open (`04:L345`) |
| 05 | local `ceil(D/N)` (`05:L131`) | dual read (`05:L129`) | sum ≤ D test (`05:L129`, `05:L211`) | /64 (`05:L133`) |
| 06 | fail-stop, HA limits (`06:L677–L692`) | (`06:L703–L711`) | (`06:L694–L701`) | prefix policy (`06:L713–L719`) |
| 07 | fail-stop (`07:L362–L364`) | (`07:L380–L386`) | (`07:L368–L369`) | RFC 8981 note (`07:L378`) |
| 08 | fail-closed or non-global emergency (`08:L185`) | dual read/write (`08:L187`) | (`08:L186`) | prefix tied to load (`08:L172`) |
| 09 | fail-closed / challenge-only (`09:L167`) | `UNSPEC` mechanism; versioned HMAC naming only (`09:L161`, `09:L163`); tested (`09:L179`) | `UNSPEC` mechanism; tested (`09:L179`) | question (`09:L207`) |
| 10 | existing modes + local per-ac (`10:L332`) | kid grace (`10:L336`) | default-off flag (`10:L333–L334`) | /64 (`10:L335`) |
| 11 | existing modes (`11:L321`) | verify-only old keys (`11:L325`) | default-off (`11:L322–L323`) | tiered /64–/48 (`11:L324`) |

### Axis 10 — Integration cost / API change

| # | Required from Host | Evidence |
|---|---|---|
| 01 | Issue token, report outcome; escalation callback optional | `01:L224–L233` |
| 02 | Read/write `rl_cid` cookie; reportOutcome; handle STEP_UP; new request/decision classes | `02:L363–L401` |
| 03 | Generate CID; send context-bound trust flag; handle 3 decisions; outcomes recommended | `03:L184–L193` |
| 04 | check/report; issue or verify CIT; optional challenge callback | `04:L287–L303` |
| 05 | `admit` before verifier; normalized account; operation type; session or outcome; instance id | `05:L184–L198` |
| 06 | Admission that debits; proof verification by Host; idempotent outcome | `06:L637–L675` |
| 07 | Basic + NAT contract tiers | `07:L459–L494` |
| 08 | beginAttempt / completeAttempt; challenge handling | `08:L164–L181` |
| 09 | reserve / settle / issue / validate | `09:L143–L167` |
| 10 | Tier 0 credential carry; Tier 1 admissionId outcomes; Tier 2 attestation | `10:L303–L326` |
| 11 | Tier 0 credential carry; Tier 1 admissionId outcomes; Tier 2 attestation | `11:L297–L315` |

### Axis 11 — Self-rejection criteria

| # | Codes | Evidence |
|---|---|---|
| 01 | MECH | R1–R4 with numeric thresholds (1%, 5%) chosen by the author (`01:L261–L265`). |
| 02 | MECH | Four falsification tests (`02:L455–L464`). |
| 03 | — | No explicit self-rejection criteria; an implicit no-leakage condition in the race test (`03:L202`). |
| 04 | MECH | `04:L330–L337`. |
| 05 | MECH | `05:L223`. |
| 06 | MECH | `06:L862–L879`. |
| 07 | MECH | `07:L579–L594`. |
| 08 | MECH | `08:L230–L240`. |
| 09 | MECH | `09:L190–L199`. |
| 10 | MECH | `10:L355–L362`. |
| 11 | MECH | `11:L360–L365`. |

---

## 3. Adversarial scenario coverage

Codes as in §0.2. "Handled" means a mechanism is described; it does not mean the outcome is proven.

### S1 — Many legitimate users behind one IP, same Browser Major

| # | Mechanism and limit | Evidence |
|---|---|---|
| 01 | Per-principal budgets; B3 not triggered with high success ratio (needs outcomes) or "generous" budget in pre-check. `COND[outcomes]`. | `01:L193` |
| 02 | Known (Verified) users bypass the pool; new ones share it with refund. | `02:L233–L243` |
| 03 | Success keeps risk near zero. `COND[outcomes]`. | `03:L114–L116` |
| 04 | Fair-share; new users challenged when constrained. | `04:L192–L199` |
| 05 | Bound users outside `D`; day zero `min(100, D)`. | `05:L139` |
| 06 | Pass if shares and proofs allow; no zero-block promise. | `06:L451–L461` |
| 07 | P lane for proven; U for others. | `07:L396` |
| 08 | Challenge after `B₀`; attacker can cause friction. | `08:L135` |
| 09 | Network/Client/Account reservation; new may be challenged. | `09:L110` |
| 10 | Known pairs never touch U; day zero via `I` or challenge. | `10:L273` |
| 11 | SEEN/KNOWN pairs never touch U; day zero via `U` or challenge. | `11:L268` |

### S2 — Attacker inside the same network

| # | Mechanism and limit | Evidence |
|---|---|---|
| 01 | Escalation on the attacker principal; colleagues "محميون بالأرضية المضمونة (min-fair)". The floor mechanism is not defined in §4 (`01:L107–L186`); token-holders are outside shared network budgets anyway (`01:L140–L141`). `UNSPEC` for the floor (C‑07). | `01:L200` |
| 02 | Fills unverified pool; Verified colleagues unaffected; new colleagues STEP_UP. | `02:L306–L317` |
| 03 | Raises risk → whole office challenged; flagged devices pass. | `03:L156–L163` |
| 04 | Fair-share floor; issuance limit; new users challenged. | `04:L242–L246` |
| 05 | Exhausts `D`; bound colleagues unaffected. | `05:L153` |
| 06 | Cannot consume `B_P` without proofs; can drain `B_U`. | `06:L548–L558` |
| 07 | Pushes unproven colleagues to challenge. | `07:L403` |
| 08 | Colleagues may need extra proof. | `08:L142` |
| 09 | Fair-share reduces monopolization, not proof of distinct humans. | `09:L117` |
| 10 | Exhausts I/U; known pairs unaffected. | `10:L266`, `10:L280` |
| 11 | Exhausts U; SEEN/KNOWN unaffected. | `11:L259`, `11:L275` |

### S3 — Credential spray across many accounts

| # | Mechanism and limit | Evidence |
|---|---|---|
| 01 | B3 with breadth + failure ratio → challenge; per-victim B1. Token-holder bypass (C‑01). | `01:L197` |
| 02 | Sub-cap 3, pool 20, breaker 10–20. | `02:L275–L285` |
| 03 | Risk pool → challenge at ~25 (sequential). | `03:L132–L138` |
| 04 | Network sees distinct accounts → constrained; fair-share. | `04:L219–L225` |
| 05 | `≤ D` per prefix per day. | `05:L147` |
| 06 | `B_U` and higher caps. | `06:L500–L510` |
| 07 | `min(200, U_n, U_Σ)`. | `07:L400` |
| 08 | `B₀` then challenge. | `08:L139` |
| 09 | Only reserved capacity; HLL not a hard deny alone. | `09:L114` |
| 10 | `min(U,(I+H)×S)×A` per W. | `10:L277` |
| 11 | `≤ U` new accounts per W; up to A admissions per pair (C‑12). | `11:L272` |

### S4 — Attacker with valid accounts and trusted sessions

| # | reach_own | reach_foreign | Evidence |
|---|---|---|---|
| 01 | edges reduce friction on own accounts | B2 + B1 per victim; tokens multiply (C‑01) | `01:L198`, `01:L216` |
| 02 | bound, outside pool | pool + breaker; `COND[M=0]` | `02:L287–L299` |
| 03 | flagged ALLOW | risk / CID buckets; washing (C‑04) | `03:L140–L146` |
| 04 | counts against network | network budget; share per CIT | `04:L227–L233` |
| 05 | bound lane, no network cap | `≤ D` | `05:L149` |
| 06 | `≤ min(B_G, B_N, ΣB_Ai)`, can consume `B_P` | `≤ min(B_G, B_U)` per network | `06:L514–L532`, `06:L321` |
| 07 | counted (`P` lane) | U | `07:L401` |
| 08 | no bypass | network + account | `08:L140` |
| 09 | no bypass | "جزء محدود" `UNSPEC` | `09:L115` |
| 10 | no network cap (reach_own) | `min(U, 12×S)×A`, non-tight under the admissions reading (C‑13) | `10:L263`, `10:L278` |
| 11 | no network cap (reach_own) | `≤ U` stated; `U × A` or `U + V` admissions by the mechanism (C‑12) | `11:L250`, `11:L273` |

### S5 — Rotation of client identity, fingerprint, browser

| # | Mechanism and limit | Evidence |
|---|---|---|
| 01 | `DERIV`, `COND` (C‑01) | `01:L141`, `01:L199` |
| 02 | One pool, sub-cap bypassed, pool cap holds | `02:L301–L304` |
| 03 | CID bucket bypassed; risk pool fills | `03:L148–L154` |
| 04 | Issuance-limited probation | `04:L235–L240` |
| 05 | One bucket to `D`; Major not a key | `05:L151` |
| 06 | No parent budget change | `06:L536–L546` |
| 07 | Parents unchanged | `07:L402` |
| 08 | Budgets not reset | `08:L141` |
| 09 | New pool | `09:L116` |
| 10 | Each new ID costs `I` | `10:L279` |
| 11 | Each new pair costs `U`; minting free | `11:L251–L257`, `11:L274` |

### S6 — New user vs known user (bootstrap)

See Axis 7. Common finding across designs that state it (05, 06, 07, 10, 11, 02): on a network under active attack, a new browser cannot be distinguished from an attacker's fresh identity without an added cost or proof (`05:L35`, `05:L143`, `06:L93–L114`, `07:L38–L53`, `07:L66–L73`, `10:L59–L63`, `11:L44–L52`, `02:L23–L34`). 01, 03, 04, 08, 09 state the same limit in their feasibility sections (`01:L41–L50`, `03:L17–L30`, `04:L70–L80`, `08:L19–L27`, `09:L29–L34`).

### S7 — Attacker-controlled successes and their effect on others' budgets

| # | Effect on own budgets | Effect on others / shared | Evidence |
|---|---|---|---|
| 01 | edge trust on own accounts | none (no refund) | `01:L148`, `01:L154` |
| 02 | Verified binding + refund of own reservation | returned capacity goes to the shared pool and is usable by others, incl. an adjacent attacker; the attacker's unrefunded (foreign) admissions remain ≤ the pool cap per window (C‑15) | `02:L210–L213` |
| 03 | trust flag (Host) | **lowers shared network risk** (C‑04) | `03:L89` |
| 04 | benign credit on Failure Debt only (amount `UNSPEC`) | none on network/global | `04:L133`, `04:L170` |
| 05 | binding on own account | none | `05:L121` |
| 06 | none | none | `06:L376–L389` |
| 07 | none | none | `07:L296` |
| 08 | none | none | `08:L115` |
| 09 | local debt | none | `09:L90` |
| 10 | known pair | if Q9 is enabled, one unit returned to the shared `net:unknown`, usable by others; unrefunded admissions remain ≤ U per window (C‑15) | `10:L216` |
| 11 | KNOWN pair | none | `11:L196` |

### S8 — Concurrent requests before outcomes

See Axis 5. `DERIV` for 03 (burst up to pool max before outcomes).

### S9 — Redis outage, lost replies, mixed versions

See Axis 9. Lost replies are addressed explicitly only by 06 (`06:L422–L424`), 07 (`07:L317–L319`) and 08 (partial: attempt-id and `completeAttempt` idempotency, `08:L170`, `08:L174`; lost reply only as a test item, `08:L212`); others `UNSPEC`.

---

## 4. Internal contradictions and derived breaks (with evidence)

Only items with two quoted passages that cannot both hold are marked `CONTRA`. `DERIV` items show the derivation. Ambiguities are marked `UNSPEC`.

- **C‑01 (01, `DERIV`, `COND[cheap token issuance; first-contact budget per identifier]`).** `01:L141`: "الحماية الشبكية الواسعة (B3) تُفعَّل **فقط** لحركة غير مثبتة الهوية (LOW: لا Instance Token ولا جلسة)". Tokens are issued "عند أول زيارة" (`01:L225`) with no stated issuance limit, and "معرّف جديد = أرضية صفرية + ميزانية أول-اتصال صغيرة" (`01:L155`); the author also states "أي ميزانية نمنحها للمستخدم الجديد تُمنح لكل هوية جديدة يصنعها المهاجم" (`01:L67`). Derivation: if (a) a fresh token can be obtained at low cost per attempt and (b) the first-contact budget is held per identifier, a token-rotating attacker stays outside B3, and reach_foreign grows with the number of tokens × first-contact budget, limited by B1 per victim account and the per-principal escalation, which fires only "بعد استنفاد الأرضية مع فشل متكرر" (`01:L195`). This is not marked `CONTRA`: the rotation scenario (`01:L199`, "الإجمالي مقيد بـB3/budget الإجمالي للشبكة والحساب") has a consistent reading in which rotated identities are token-less LOW traffic, to which B3 does apply. Neither condition is settled by the text: the token issuance contract is `UNSPEC` (`01:L59`, `01:L225`), and whether the first-contact budget is per identifier or drawn from the shared LOW share is `UNSPEC` (`01:L119`, `01:L156`, `01:L195`; `01:L67` and `01:L213` lean towards per identifier).
- **C‑02 (02, `DERIV`).** `02:L353` gives "2 / 200" when every unbound attempt requires STEP_UP ("ومنعنا أي Fan-out بدون STEP_UP"), and "10–15" when the exemption is only limited to the bound account. The two conditions differ, so this is not a contradiction. But a token-less attacker already reaches the unverified pool: "بحد أقصى 10 إلى 20 محاولة من أصل 200" (`02:L283`) and "فقط سعة IP_Unverified_Budget (من 10 إلى 20 محاولة)" (`02:L304`). Attacker C can drop its tokens and use that pool, so 2/200 holds only if the pool itself also requires STEP_UP, which the text does not say.
- **C‑03 (03, `UNSPEC`).** `03:L87–L91` define +1 per pre-check, −2 per success, +4 per failure, without saying whether outcome weights add to or replace the +1. `03:L130` ("يزيد خطر IP بمقدار 8، ثم ينقص بمقدار 2") and `03:L136` ("~25", i.e. 25 × 4 = 100) both match the non-additive reading; `03:L172` attaches the bare +1 to the no-outcome mode. Under the additive reading, L130 would be +9 and L136 about 20.
- **C‑04 (03, `DERIV`).** Washing by own successes. Additive reading: own success nets −1, foreign failure +5 → five own successes offset one foreign failure. Non-additive reading: −2 and +4 → two own successes offset one. Passing a challenge also lowers IP risk (`03:L110`). Under both readings an attacker with valid accounts can keep the IP risk below the threshold; reach_foreign is then limited only by the rotatable CID bucket (`03:L75–L79`) and per-victim account buckets (`03:L69–L73`).
- **C‑05 (03, `DERIV`).** Burst before outcomes. Additive reading: only +1 per request accrues before outcomes, so a burst can pass up to the pool maximum (`03:L85`, e.g. 100), not "~25" (`03:L136`). Non-additive reading: for a Host that reports outcomes nothing accrues before they arrive, so the risk pool gives no burst bound at all; scenario 9 relies on "امتلاء الـ Buckets" (`03:L168`), i.e. the account bucket (10) and the rotatable CID bucket (15).
- **C‑06 (02, `CLAIM`, `COND[M=1]`).** The author states it: "إذا سمحنا لكل رمز موثوق بمحاولة حساب إضافي واحد قبل سحب الإعفاء، قد يمرر المهاجم محاولة واحدة لكل جلسة يملكها حتى يصطدم بـ Enforcer 4" (`02:L353`). Under the strict rule, unbound attempts by Verified CITs go to the pool (`02:L161`, `02:L293`); `02:L150` is the M=1 option of question Q1 (`02:L470`). The aggregate is bounded by Enforcer 4 (`02:L180–L182`, needs outcomes); per victim, Enforcer 1 applies (`02:L140`). Note: the symbol `M` is used both for fan-out (`02:L151`, `02:L184`) and for rotated fingerprints (`02:L443`); this is ambiguity, not a contradiction.
- **C‑07 (01, `UNSPEC` vs claim).** `01:L200` claims colleagues are protected by a guaranteed floor (min-fair). §4 (`01:L107–L186`) defines no floor; the only "أرضية" in §4 is the new-identifier floor (`01:L155`). Alternative 1 kept the floor "كمكوّن" (`01:L81`), and it reappears as a validation target (`01:L243`, `01:L256`, `01:L263`) without a definition. Part of the claim holds without a floor: token-holding colleagues are outside any shared network budget (`01:L140–L141`), and token-less ones get a per-principal challenge, not a block (`01:L173`).
- **C‑08 (10, `DERIV`, Redis Cluster only).** On a single Redis the script consumes nothing on rejection (`10:L204`). In Cluster mode each scope runs "INCR-ثم-قارن-ثم-DECR-عند-التجاوز" and units consumed on earlier scopes stay charged on a later rejection (`10:L211`), order identity → pair → account → network. A request rejected at U (and at I only under the `10:L203` reading; `10:L201` checks I before any account scope) has therefore already debited the victim's K4 permanently for the window. Bound: because K4 itself is INCR-and-compare, the burned units per victim per W cannot exceed the K4 cap; each burned unit also costs the attacker one unit of its own pair budget A on (identity, victim), so the burn is `≤ min(K4 cap, (I+H) × A)` per victim per W. Consequence: an attacker who can field about `K4 cap / A` identities can hold the victim's non-known path at K4 for the whole window without any verifier call; only the reserved lane for known pairs remains (`10:L222`). U exhaustion does not limit this.
- **C‑09 (04, `DERIV`, conditional).** `04:L110`: DENY on Global exhaustion; the author names this as a deliberate exception: "الحجب الجماعي يُستبدل بـChallenge/Throttle، إلا في حالات Global DoS القصوى" (`04:L131`). An attacker able to exhaust Global denies the operation to every user. This requires a multi-network attacker (declared out of scope, `04:L67`, `04:L280`, `04:L347`) or a Global budget not larger than a network budget (values `UNSPEC`, `04:L283`); whether challenged or denied attempts debit Global is `UNSPEC` (`04:L175`).
- **C‑10 (10, `CLAIM` that grows).** Pre-check-only bound `(I + H) × S × A` per W (`10:L261`), where `H` is identities held from earlier windows. `H` grows with stored inventory (≤ `I` × windows within the 30-day idle TTL, `10:L170`). `D` in the budget table is per identity/day and per account/day (`10:L190`) and does not cap `H`; a daily minting cap is mentioned in prose (`10:L88`, `10:L267`) but is absent from the budget table (`UNSPEC`).
- **C‑11 (11, `CLAIM`, framing).** Pre-check-only: retries on SEEN pairs are capped per account only (`11:L62`, `11:L277`). The author calls this an impossibility ("مهما كانت الخوارزمية", `11:L62`). 11 itself defines a per-network cap on SEEN retries (V) and limits it to the Outcome tier (`11:L160`, `11:L179`); a pre-check V is possible at the cost of hitting returning office traffic (`11:L59–L61`). SEEN is per (credential, subject) (`11:L142`) and S is per credential (`11:L176`); the aggregate is unbounded because minting is free (`11:L136`). This is a trade-off, not an impossibility.
- **C‑12 (11, `CONTRA`).** `11:L250`: "محاولاته على غير حساباته (reach_foreign) ≤ U في النافذة" (also `11:L273`, H3 at `11:L336`, rejection criterion 2 at `11:L362`). reach_foreign is defined as verifier admissions: "قبولات الـVerifier على حسابات لا يملك المهاجم بيانات دخولها" (`11:L349`). But only NEW pairs consume U ("U (only if state == NEW)", `11:L178`); scenario 5 allows "≤ A لكل زوج" (`11:L272`); SEEN retries consume V in the Outcome tier (`11:L179`) and only per-account limits in pre-check (`11:L62`). Foreign admissions per W can therefore reach `U × A` (or `U + V`), which exceeds U whenever A > 1, and the design itself requires "A ≥ 3" for the legitimate retry scenario (`11:L271`). Under its own criterion (`11:L362`) the design would reject itself. A consistent reading would need reach_foreign in `11:L250`/`11:L336`/`11:L362` to mean new accounts, which `11:L349` rules out by defining it as verifier admissions.
- **C‑13 (10, `DERIV`, ambiguous definition — not `CONTRA`).** `10:L175` describes U's key as "عدّاد الأزواج غير المعروفة المقبولة". This admits two readings: a count of distinct unknown pairs, or a count of admissions of unknown pairs. The admission script consumes U on every admission of a not-known pair ("net unknown (if !known)", `10:L203`), and the pre-check rationale "U يُعطَّل (لأنه سيساوي عدّ المحاولات)" (`10:L192`) holds under the admissions reading. Under that consistent reading, foreign admissions per W are bounded by `min(U, (I+H) × S × A)`. The author's bound `min(U, (I+H) × S) × A` (`10:L261`) equals `min(U·A, (I+H)·S·A)`, which is ≥ `min(U, (I+H)·S·A)`: it is a valid but non-tight upper bound. The two coincide when `(I+H)·S·A ≤ U`; otherwise the author's bound overstates reach by up to a factor A. Under the distinct-pairs reading the author's bound is the natural one, but the L192 rationale would not hold. No two passages are irreconcilable, so this is recorded as ambiguity plus a non-tight bound.
- **C‑14 (11, `DERIV` for the numeric bound; `EXP` for starvation; Redis Cluster only).** Reservation order is pair (A) → credential (S) → account (K4) → network (U), each step "atomic INCR-and-compare" (`11:L174–L178`), and a later rejection compensates (DECR) earlier reservations (`11:L180`). (1) **Numeric bound:** K4 is INCR-and-compare, so the number of K4 units held at any instant cannot exceed the K4 cap; there is no unbounded inflation. (2) **Hold time:** a unit taken by a request later rejected at U stays held from its K4 INCR until the compensating DECR, i.e. at least the U round trip plus the DECR round trip; if the Worker fails in between, it stays held "حتى انتهاء النافذة" (`11:L192`). Behaviour on a lost Redis reply is `UNSPEC`. (3) **Cost to the attacker:** compensation also releases the attacker's own A and S, and the pair is not marked SEEN because it was never admitted (`11:L181`); repeated U-rejected requests therefore cost no budget, only request rate. U must already be exhausted on the network, which the attacker can do with NEW pairs. (4) **Starvation hypothesis (not proven):** by occupancy (Little's law), if the attacker sustains a rate λ of such requests on one victim with mean hold time h, the mean number of held K4 units is about λ·h; when λ·h approaches the K4 cap, the victim's non-KNOWN requests are likely to see K4 full and be over-rejected. KNOWN pairs keep the reserved lane (`11:L177`); pre-check-only Hosts have no KNOWN pairs (`11:L277`). The test H10 (`11:L343`) checks only the value at rest. Whether sustained starvation occurs depends on λ, h and the K4 cap and needs a reproducible concurrency experiment. Comparison: 10 makes the burn permanent but budget-bounded (C‑08); 11 makes it temporary and budget-free.

- **C‑15 (02, 10 Q9; `DERIV`; corrected after F123-06).** Scope: the refund mechanism as written, in one fixed window, with time-based refill and successes on target accounts treated separately (below). A refund is bound to the request's own reservation (`02:L202`, `02:L208`, `02:L212`; `10:L216`: "إذا كان هذا القبول قد استهلكها") and returns at most what that request debited, into a shared pool (`IP_Unverified_Budget` per IP, `02:L211`; `net:unknown` per network window, `10:L216`).
  - **(a) Neighbour benefit — yes.** Capacity returned by one requester's success is usable by any other requester on the same IP or network, including an adjacent attacker.
  - **(b) Gross admissions above the cap — yes.** With pool cap `U`, the number of admissions funded in the window is `U` plus everything refunded; e.g. 100 refunded legitimate logins followed by 20 attacker attempts on a pool of 20.
  - **(c) reach_foreign above the cap — not shown.** Every admission debits one unit and only a successful admission can be refunded, so in a fixed window `unrefunded admissions ≤ U` (with a partial refund such as 80% in 02, the unrefunded amount is still bounded by U). Attacker attempts on accounts it does not own fail and are not refunded, so `reach_foreign ≤ U` in the window; refunds do not by themselves break the stated per-window bounds (`02:L283`, `02:L304`; 10 with Q9 on).
  - **What refunds do change.** Without refunds, legitimate traffic in the window would have consumed part of `U` and left the attacker less; with refunds the attacker can still take up to the full `U` after many legitimate logins. The worst-case bound is unchanged; the incidental reduction from legitimate consumption disappears. This is the same effect 07 describes for removing legitimate traffic from a shared lane (`07:L232`).
  - **Treated separately.** (i) Time-based refill: 02's pool is also described as a rate ("10 محاولات لكل 10 دقائق", `02:L66`), so reach over longer periods grows with the number of windows, independently of refunds. (ii) Successes on target accounts: an attacker attempt that succeeds on a victim account would be refunded, so foreign admissions could exceed U only by the number of successful foreign guesses, each of which is an account compromise and is counted as such, not as a refund effect. (iii) 03 is different: its −2 per success is not bounded by what the request debited (+1), so it creates net capacity (C‑04).

`CONTRA` found: C‑12 (11). C‑01 (01) and C‑13 (10) were reclassified after engineering review (F123-05, F123-02) because each admits a consistent reading; the derivations remain. No `CONTRA` was found in 01–10 at the pinned commit; 09 has one ambiguity noted in its card. This is a statement about this review, not a proof of consistency.

---

## 5. Substantive disagreements between designs

| ID | Question | Positions |
|---|---|---|
| X1 | Refund on success | Refund or credit: 02 (80–100% of the own reservation, returned to the shared pool, `02:L211`), 03 (network −2, `03:L89`), 04 (benign credit on account/client Failure Debt, `04:L133`, `04:L170`), 10 (optional one unit to the shared `net:unknown`, `10:L216`). Shared replenishment (Axis 4 column c): 02, 03, 10 (Q9). No refund: 01 (`01:L148`), 05 (`05:L121`), 06 (`06:L376`), 07 (`07:L296`), 08 (`08:L115`), 09 (`09:L90`), 11 (`11:L196`). |
| X2 | Cost of creating an identity | Budgeted minting: 10 (`I`, `10:L162`), 04 (`04:L159`), 09 (`09:L162`). Free minting by design: 11 (`11:L136–L138`, reason: minting budget is an exhaustion surface). Free issuance acknowledged and neutralised: 05 (overflow bucket, `05:L86`, `05:L190`), 06 (no budget per identity, state-creation cap, `06:L270–L272`, `06:L439`). Host issuance without a stated limit: 01 (`01:L225`), 03 (`03:L187`), 07, 08. Library-issued without issuance limit: 02 (`02:L397`). |
| X3 | Who issues the identity | Library: 10, 11 (default), 02 (recommended contract; Host also allowed, `02:L146`, `02:L258`). Host: 01, 03, 04, 05, 06, 07 (optional library helper, `07:L474`), 08, 09. 11 allows Host ids only if unguessable (`11:L139`). |
| X4 | What the network counts | 01: account breadth + failure ratio for token-less traffic, fixed attempt budget in pre-check-only (`01:L170–L171`). Attempts: 04, 06, 07, 08, 09. Risk score: 03. Unverified-pool attempts + unbound failures: 02. Unbound attempts per day: 05. Minting + unknown pairs: 10. New pairs (novelty): 11. |
| X5 | Is U usable without outcomes? | 10: no, "سيساوي عدّ المحاولات" (`10:L192`). 11: yes, because SEEN is set at admission (`11:L147`, `11:L181`). The disagreement is partly definitional: 10's U is consumed per not-known admission (`10:L203`), 11's U only on first sighting (`11:L178`); 10's own U definition is inconsistent (C‑13). |
| X6 | Known users under network pressure | Bypass/protected lane: 02, 05, 10, 11, 03 (Host flag), 06 and 07 (with proof). Subject to challenge: 08 (`08:L142`), 04 (`04:L196`). 09: new identities → probation pool / challenge, clients over their sub-limit → challenge or throttle; known clients within their sub-limit are not challenged (`09:L71–L74`). |
| X7 | Atomicity in Redis Cluster | Single slot / primary required: 06, 07, 08, 09. Sequential without compensation: 10 (`10:L211`). Sequential with compensation: 11 (`11:L180`). Raised as an open question: 04 (`04:L346`). Not discussed: 01, 02, 03, 05. 09 does not adopt sequential reservation as the basis for fair-share (`09:L104`). |
| X8 | Redis outage | Local per-worker limiter as fallback: 01, 02, 04, 05, 10 (DEGRADED per-ac). Rejected as not equivalent: 06 (`06:L682`), 07 (`07:L366`), 08 (`08:L185`), 09 (`09:L167`). 11: existing modes only. 03: `UNSPEC`. |
| X9 | IPv4 aggregation | /24 used: 01 (B3 per /24, `01:L215`), 11 (tiered, `11:L163`), 04 (/24 default for IPv4, ASN optional, `04:L137`). /32 with advisory /24: 02 (`02:L416`). /24 rejected: 05 (`05:L133`). 10: individual IPv4 (`10:L335`). |
| X10 | Effect of a passed challenge | One admission for one pair: 10 (`10:L238`), 11 (`11:L208`). Ticket then fresh reservation: 09 (`09:L73`). Ticket within `D_challenge`: 05 (`05:L90`). Lowers network risk: 03 (`03:L110`). 02 has a `challengePassed` input (`02:L374`) whose effect in the recommended design is `UNSPEC`. Per-attempt cost: 06 Alt 3 (`06:L195–L211`), 08 (`08:L101`). 07: proof moves the attempt to lane P (`07:L168`, `07:L398`); tickets are a rejected alternative (`07:L137–L150`, `07:L152`). |
| X11 | Outcome binding to the admission | Attempt id / admissionId / reservationId / permit: 01, 02, 04, 06, 07, 08, 09, 10, 11. `UNSPEC`: 03; 05 mentions SUCCESS/FAILURE without an attempt reference (`05:L189`). |

---

## 6. Families (derived from §2–§5, non-exclusive)

| Family | Members | Material differences inside the family |
|---|---|---|
| F1 — Privilege bound to a (client, account) relation | 02, 05, 10, 11; partially 01 (edges, friction only), 03 (Host flag) | 02 allows one extra account (C‑06); 05 accepts a Host `session_for`; 10 and 11 build it by default only from outcomes bound to an admission; 10 allows an Owner opt-in to the Host flag (`10:L338`). |
| F2 — Privilege from a Host proof for account + operation | 06, 07; overlap with 03 and 05 (Host assertions) | 07 reserves P per account against U; 06 reserves `B_P` per network. |
| F3 — Network counts novelty or unbound traffic, not raw attempts | 02, 05, 10, 11 | 10 charges minting (`I`), 11 charges first sighting of a pair (`U`), 05 charges unbound attempts (`D`), 02 charges unverified attempts. 10 and 11 conflict on X2 and X5. |
| F4 — Network counts attempts, breadth or risk with fair-share / probation | 01 (breadth + failure ratio), 03, 04, 06, 07, 08, 09 | 03 uses a risk score with success credit (C‑04); 04 blocks on Global; 06/07/08/09 refuse refunds and identity budgets. |
| F5 — Challenge as the main relief under pressure | 03, 08, 01, 04, 09 | 03 lowers network risk on challenge; 08 states post-challenge is cost-only. |
| Cross-cutting split — refund allowed vs forbidden | X1 | Mixing designs across this split is a security-relevant change, not a detail. |
| Cross-cutting split — identity cost | X2 | Free minting vs budgeted minting changes the exhaustion surface. |

---

## 7. Open questions (merged, context kept)

| Theme | Questions as asked by the designs |
|---|---|
| Challenge in scope | Is a third decision (CHALLENGE/STEP_UP) and a Host attestation in scope? (`01:L272`, `02:L474`, `03:L206`, `04:L343`, `05:L232`, `06:L885`, `07:L602`, `08:L247`, `09:L204`, `10:L383`, `11:L371`). 10 and 11 call it the only lever for day zero; 06 and 07 treat it as one option among proof types. |
| Numeric budgets | Values of the network/account/identity budgets per operation (`01:L273`, `02:L476`, `04:L283`, `05:L231`, `08:L246`, `10:L382`, `11:L372`). 01 proposes ranges (`01:L208–L216`); 02 (`02:L66`, `02:L165–L166`, `02:L231`, `02:L476`) and 03 (`03:L71`, `03:L77`, `03:L85`) give example values; 05 gives illustrative values only (`05:L137`); others propose no numbers. |
| Outcome reporting adoption | Will Hosts report outcomes, and bound to the admission? (`01:L271`, `02:L475`, `04:L342`, `05:L233`, `06:L897`). |
| Trust in Host assertions | `session_for(account)` (`05:L189`), `is_known_device_for_account` (`02:L337`, `10:L338`), `Trust Flag` (`03:L64`), Host proof (`06:L330`, `07:L188`). 10 and 11 default to not trusting the Host flag (`10:L338`, `11:L327`). |
| Identity issuance | Who issues, at what cost, legal status of the cookie (`04:L348`, `09:L203`, `09:L208`, `10:L384`, `10:L388`, `11:L377`). |
| Redis topology and failure contract | Single primary vs Cluster; outage policy per operation (`06:L895`, `07:L604–L605`, `08:L251–L252`, `09:L205–L206`). |
| IPv6 / CGNAT | Prefix length; scaling for CGNAT (`04:L345`, `05:L234`, `09:L207`, `10:L387`, `11:L375`). |
| Reach metric | reach_own vs reach_foreign vs total (`06:L883`, `07:L598`, `11:L373`); 10 records an Owner decision in its own text (`10:L381`). |
| Multi-IP / botnet scope | (`04:L347`, `09:L210`). |
| Account normalization and non-existent accounts | (`06:L643`, `06:L648`, `08:L250`). |
| Trusted accounting entity | (`06:L889`, `07:L599`). |
| Design-specific | `T_seen` (`11:L374`), lost-cookie return path (`11:L376`), Q9 refund (`10:L389`), legitimate multi-account `S` (`10:L386`), fan-out `M` (`02:L470`). |

---

## 8. Limits that cannot be settled on paper

| Claim type | Designs | What would settle it |
|---|---|---|
| Atomic bound under concurrency, including Cluster variants | all; especially 10 (C‑08), 11 (compensation), 02 (in-flight TTL) | Barrier-synchronised concurrency tests with mutation (remove atomicity → test must fail), on single Redis and on Cluster. |
| Zero Sybil gain from identity rotation | 01, 02, 03, 04, 05, 09, 10, 11 | Adaptive attacker simulation with 1 vs N rotated identities on fixed traces; issuance limits must first be specified for 04 and 09, and 01 must specify whether its first-contact budget is per identifier. |
| No cross-account gain from own successes | 02 (`COND[M]`), 05, 10, 11 | Paired runs: attacker with vs without own successes, measured on foreign accounts only. |
| Washing magnitude for 03 | 03 | Simulation of C‑04 with stated weights. |
| Fair-share floor under adversarial client counts | 01, 04, 09 | Floor mechanism must be specified (01) and issuance limits fixed (04, 09) before any test. |
| Day-zero curve vs network budget | 02, 05, 10, 11 | Multi-day office simulation with and without challenge. |
| Challenge economics | 03, 05, 08, 09, 10, 11 | Not testable inside the library; needs external data on challenge-solving cost. |
| Host assertion honesty | 02, 03, 05, 06, 07 | Contract/integration review; not observable by the library. |
| Redis failover loss and mixed deploys | 01, 02, 04, 05, 06, 07, 08, 09, 10, 11 | Fault injection: lost replies, failover with acknowledged-write loss, mixed versions, rollback, key rotation. |
| Growth of bounds over time | 10 (C‑10), 11 (C‑11) | 30-day inventory simulation. |
| Refund timing and shared replenishment | 02, 03, 10 (Q9) | Paired runs in a fixed window: check that unrefunded admissions and reach_foreign stay ≤ the pool cap with and without legitimate refunds; measure the availability effect separately; refunds arriving out of order or duplicated under concurrency; for 03, the net capacity created by success credits. |

---

## 9. Part B — Security & Governance Constraints (not used in Part A grading)

These bind any later step that turns a design into a candidate. They are listed, not applied, here.

- **AGENTS.md → Maatify standards** via Selective Pinned Adoption (`docs/php-engineering-standards/STANDARDS_MANIFEST.md`, Adoption Commit `ebcf55b0591db61d18a1407bc3effef0197b388f`). Applicable standards include Decision Governance, Documentation Lifecycle, AI Collaboration Workflow and Testing.
- **DEC-019 (ACTIVE)** — retain shipped S0 behaviour and public contracts; no automatic adoption of measured candidates; scoped future changes need explicit Owner decisions that quantify fairness and attacker attempts reaching verification, including `checkOnly`-only / record-only guarantees, IPv6 macro, rotation, trusted/non-trusted collateral; DEC-019 authorizes **no mandatory new Host callback or signal** (`docs/decisions/DEC-019_INTERIM_SECURITY_FIRST_CORRELATION_POSTURE.md`, Decision items 1–6). Every design here that requires a new Host contract (all eleven, §2 Axis 10) would need a new decision.
- **DEC-021 (ACTIVE)** — K5 thresholds and known-device verifier bound on official presets. Designs that replace K5 with a pair key (10, 11), remove K2 as a scope (02, 10, 11; 10 and 11 move churn into the per-identity record, `10:L247`, `10:L252`, `11:L229`, `11:L234`) or remove Fingerprint Churn (02) interact with this decision.
- **DEC-015 (ACTIVE)** — bounded process-local emergency state exists only for circuit survivability; `FAIL_CLOSED`, `FAIL_OPEN`, `DEGRADED_MODE` remain authoritative. Designs with a local per-worker limiter fallback (01, 02, 04, 05, 10) would need to be reconciled with this decision.
- **DEC-016 (ACTIVE)** — a Lua operation that receives caller-supplied time must use it, not `redis.call('TIME')`. Applies to any new Lua admission script.
- **DEC-007 (ACTIVE)** — `recordSuccess()` never resets generation-bound K4 evidence. Consistent with the no-washing designs; a refund design (X1) would have to be checked against it.
- **DEC-008 (ACTIVE)** — custom policies opt into capabilities; new admission capabilities would follow this model.
- **DEC-020** — not approved; no file exists.
- **Release gate** — no RC/Stable/tag/Packagist publication before all findings are closed; merging this document closes no finding.

---

## 10. What this document does not do

- It does not rank the designs, choose a winner, or select candidates for implementation.
- It does not combine parts of several designs into a new design; any combination needs its own security analysis.
- It does not treat a stated bound as a measured result.
- It does not settle any open question in §7; those belong to the Owner.
