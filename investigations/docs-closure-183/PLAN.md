# #183 — بند الإغلاق 6: PR وثائقي واحد (D-DOCS) — PLAN (S0)

> **الحالة:** PLAN (S0)، ثم S1 بعد أحكام LT-183-01..09 (2026-11-03). ما كُتب في §5.
> **الأساس:** `main@5dff37c45a83a7cccd1811142ca3e9a3215380c9`، بعد دمج #182. الفرع `claude/docs-closure-183-s0`.
> **المصدر:** [وصف #183](https://github.com/Maatify/php-rate-limiter/issues/183)، و[اعتماد الـ Owner](https://github.com/Maatify/php-rate-limiter/issues/183#issuecomment-6591675251) (2026-11-03، «اعتمد»: التوصيات كلها كما هي)، و[تعليق التكليف](https://github.com/Maatify/php-rate-limiter/issues/183#issuecomment-6591687770).
> **الدليل:** `investigations/governance-closure/REPORT.md` §1 (#181). كل رقم في النصوص المقترحة يُنقل من صفه.
> **القواعد:**
> * لا `src` ولا `tests` ولا أي DEC ACTIVE. يُتحقق بـ `git diff --stat main -- src tests docs/decisions` فارغًا، ويُسجَّل في وصف الـ PR.
> * commit لكل بند من 1–7 في S1. `check-whitespace.sh` بعد `git add` وقبل كل push.
> * أي فشل CI يُبلَّغ، ثم كلمة الـ Owner قبل أي إعادة. Draft؛ الدمج بيد الـ Lead.
> * الأسطر أدناه قُرئت على `main@5dff37c45a83a7cccd1811142ca3e9a3215380c9`. النصوص المقترحة بالإنجليزية لأنها لغة الوثائق.

## 1. ماذا يُكتب أين

**عمود «الرقم»** يشير إلى صف REPORT #181 الذي يُنقل منه الرقم، أو إلى ملف:سطر في الكود حيث النص يصف آلية لا رقمًا مقيسًا.

### البند 1 — عقد التضاؤل الفعلي (D6-1، #121)

**التحقق بـ `git grep`:** `git grep -n -E '(−|-)1 every' -- docs README.md` يعطي أربعة أسطر فقط: `docs/DECISION_MATRIX.md` L778–L780 و`docs/POLICIES.md` L405. لا ذكر آخر لـ «−1 every».

**الآلية في الكود** (لا رقم):
* `DecayCalculator.php` L45–L51: `floor((now − updatedAt − paused) / interval)`.
* كل كتابة تضبط `updatedAt = now`: Lua في `RedisFullCapabilityStore.php` L162 (`HSET … 'updatedAt', now`)، والـ store المرجعي `tests/Support/RateLimiter/InMemoryRateLimitStore.php` L75.

| # | الموضع | النص الحالي | النص المقترح | الرقم |
| --- | --- | --- | --- | --- |
| 1.1 | `docs/DECISION_MATRIX.md` L776–L780 (جدول §7) | `\| Scope \| Base Decay \|` ثم `−1 every 10 minutes` / `−1 every 5 minutes` / `−1 every 3 minutes` | العمود: `Base Decay (whole intervals since the last write)`، والخلايا: `−1 per full 10 minutes` / `−1 per full 5 minutes` / `−1 per full 3 minutes` | — |
| 1.2 | `docs/DECISION_MATRIX.md` بعد L780 (فقرة جديدة قبل §7.1) | — | **How decay is applied (intended behavior, #121, #183).** Decay counts whole intervals since the score's last write: every write to a score (`updatedAt`) restarts its interval, and any remainder is dropped. Decay therefore happens during idle periods; steady traffic on a key at a spacing shorter than its interval does not decay it. Example (K1, IP, 180-second interval): six writes at t = 0 leave an effective score of 4 at t = 500; six writes at t = 0, 100, …, 500 leave 6. A decay at a true rate (`updatedAt += n × interval`) is a post-1.0 roadmap candidate with its own decision record. | REPORT صف 3 (التحكم 4، المختبَرة 6) |
| 1.3 | `docs/POLICIES.md` L405 (API Heavy، Decay Rules) | `* IP score: −1 every 3 minutes` | `* IP score: −1 per full 3 minutes since the score's last write (intended behavior; see DECISION_MATRIX.md §7)` | — |

### البند 2 — Retry-After على K2 (D6-2، #98)

`docs/FAILURE_SEMANTICS.md` بلا قسم مناسب: §4.3 (L379–L408) لأوضاع فشل الـ store وحواجز FAIL_OPEN، لا لقرار الدرجة. فأقترح بدلًا منه POLICIES API Heavy (LT-183-03).

| # | الموضع | النص الحالي | النص المقترح | الرقم |
| --- | --- | --- | --- | --- |
| 2.1 | `docs/guides/USAGE_GUIDE.md` §Retry-After Semantics، بعد L210 (بند جديد) | البنود الأربعة L207–L210 | `- Refused requests still count on K2 (API Heavy). In the API Heavy policy (<code>API_OVERUSE</code>), a request refused by a score-derived <code>SOFT_BLOCK</code> is still scored on K2, so the score-derived <code>retryAfter</code> grows with each refused attempt and has no upper bound while attempts continue (measured with a K2 threshold of 3: 180 seconds at the first refusal, then +180 per refused request, 1,980 seconds at the eleventh). This is intended behavior (#98, #183): counting refused attempts prevents probing. Hosts and clients must honor <code>retryAfter</code> and not retry before it. The same scoring path for the other score keys and policies was not measured in #181. For policies that do not opt into admission, shared-key exposure behind NAT is the DEC-027 posture.` | REPORT صف 4 |
| 2.2 | `docs/POLICIES.md` API Heavy، بعد L410 (سطر جديد في Decay Rules) | L407–L410: فقرة L2+ والـ pause | `Each refused request is still counted on K2, so a score-derived Retry-After extends by one decay interval (180 seconds) per refused request with no cap while requests continue (#98, #183; measured on K2 only; USAGE_GUIDE.md Retry-After Semantics).` | REPORT صف 4 |

### البند 3 — قسم «حدود نموذج التهديد» (D6-3، D6-4)

| # | الموضع | النص الحالي | النص المقترح | الرقم |
| --- | --- | --- | --- | --- |
| 3.1 | `README.md` §Security and Trust Boundaries، بعد L197 (فقرة جديدة قبل «See …» L199) | — | `**Threat-model limits.** Policies that do not opt into the admission reservation keep the DEC-027 posture: clients that share an IP prefix and user agent behind NAT can be blocked together, and trusted sessions on several accounts are bounded per account but not across accounts. The admission policy (DEC-022..DEC-028) is the supported alternative. The limits, with their measured numbers, are in [Decision Matrix §10.1](docs/DECISION_MATRIX.md#101-threat-model-limits-non-guarantees).` | — |
| 3.2 | `docs/DECISION_MATRIX.md` قسم جديد §10.1 بعد L996 (آخر بنود §10) وقبل `---` L998 | — | النص في §1.1 أدناه. | REPORT صف 1 (TMSd 2/200، TMUA 36/200)؛ «3 لكل حساب على 12» من `results/M-120/tmsd-tmua.stdout.txt` L50 (PLAN #181 §11.5)؛ DEC-022 L196 (TMSd 40/200)؛ REPORT صفّا 9–10 |

#### 1.1 النص المقترح لـ §10.1

> ### 10.1 Threat-Model Limits (Non-Guarantees)
>
> These limits are intended behavior approved by the Owner (#183, 2026-11-03) on the evidence of #181. They name what the decision model does not protect.
>
> 1. **Collective harm behind shared NAT (policies that do not opt into admission).** K1 and K2 are shared-scope keys (IP prefix; IP prefix + normalized UA). Unrelated clients behind one NAT share them, so one client's failures, churn or credential spray can block the others (#100, #106). This is the DEC-027 posture (DEC-019 carried verbatim). The supported alternative is the admission reservation (DEC-022..DEC-028), where K1 credential spray is not evaluated and is replaced by the source capacity `U_src` and the net failure cap `F` (DEC-022).
> 2. **Trusted sessions on several accounts (policies that do not opt into admission).** The known-device bound (DEC-026) holds per account: in the measured stream, at most 3 verifier reaches per account in `pre+out`. Across accounts it is weaker by design: an attacker holding correctly bound trusted sessions on 12 accounts and rotating 20 browser majors reached the verifier 36 times in 200 attempts (TMUA), against 2 in 200 with one browser major (TMSd) (#120, measured on `main` in #181). For policies that opt into admission, the declared cost is TMSd 40 in 200 (DEC-022).
> 3. **What is not protected.** The library bounds attempts; it does not authenticate. No limit here lets a request pass verification on an account without that account's proof: every reach counted above is a verifier attempt that the Host still verifies.

### البند 4 — F-HMCI-02: `DECISION_MATRIX.md` L696

**الصياغة من الكود** (`src/Service/EvaluationPipeline.php`):
* L1234–L1241: المفتاح `keysV2['k2']`، أي IP prefix + normalized UA، والعضو `fingerprintHash`.
* L1394–L1408 (`observeChurn`): `addBoundedCorrelation($observation, 600, 3)`، ثم `count >= 3` يطلق؛ وعند `count === 2` يطلق إذا بلغ عدّاد مراقبة بعمر 1,800 ث القيمة 2.
* L1267–L1282: القرار `HARD_BLOCK` L2 على K2، بمدة 60 ث.

| # | الموضع | النص الحالي | النص المقترح | الرقم |
| --- | --- | --- | --- | --- |
| 4.1 | `docs/DECISION_MATRIX.md` L696 | `\| Rapid fingerprint changes \| \`≥ 3 changes within 10 minutes\` \| HARD_BLOCK (IP + UA) \|` | `\| Rapid fingerprint changes \| \`≥ 3 distinct DeviceFP on one K2 (IP prefix + normalized UA) within 600 s, or 2 distinct observed twice within 1,800 s\` \| HARD_BLOCK L2 (K2: IP + UA) \|` | الكود أعلاه |

### البند 5 — F-HMCI-01/03/04: `docs/guides/USAGE_GUIDE.md`

| # | الموضع | النص الحالي | النص المقترح | الرقم |
| --- | --- | --- | --- | --- |
| 5.1 | `docs/guides/USAGE_GUIDE.md` §Inputs and Outputs، بعد L193 (قسم فرعي جديد `### Identity inputs` قبل «`RateLimitCommand` expresses intent» L195) | L193 يذكر الحقول في جملة واحدة بلا إرشاد | النص في §1.2 أدناه. | الكود: `DeviceIdentityResolver.php` L34، L40؛ `EvaluationPipeline.php` L1072، L1550؛ `KEY_STRATEGY.md` L1025 |

#### 1.2 النص المقترح لـ `### Identity inputs`

> ### Identity inputs
>
> These fields apply to every policy. The admission section below adds `clientInstanceId` and `admissionCredential` for policies that opt in.
>
> * **`clientFingerprint`**: Host-normalized, bucketed, low-entropy fields that stay stable for one client. They enter the DeviceFP. Distinct DeviceFP values on one IP prefix + user agent count toward the churn rule (Decision Matrix §5.3), so values that vary per request make one client look like many. Do not pass per-request or high-entropy values.
> * **`sessionDeviceId`**: pass it only after authentication, bound to the authenticated account, and set `isSessionTrusted` only when the Host has verified that binding (Device Fingerprint §3.3). The default resolver folds a non-empty value into the DeviceFP without checking trust, so a value taken from an unauthenticated request lets the caller choose its DeviceFP.
> * **`correlationId`**: the stable, opaque subject of an attempt, typically the submitted login identifier. Credential-spray correlation uses `correlationId ?? accountId` (Key Strategy §6.1). Supply it whether or not the account exists, and keep it stable for the same submitted identifier.
> * **Missing DeviceFP.** The default `DeviceIdentityResolver` always produces a fingerprint, so the Decision Matrix rules for a missing DeviceFP (§2.2, §2.2.1, §2.4.1.2, §3.1, §3.1.1) apply only with a custom resolver that can return none.

### البند 6 — ROADMAP: ثلاثة بنود

**الموضع:** `docs/ROADMAP.md` قسم جديد `## Post-RC Security and Contract Candidates` بعد L164 (آخر بند في «Post-RC Backend and Verification Candidates»)، وقبل `## Rejected for Package Direction` L166. القالب L44–L52: Status، وPotential value، وWhy deferred، وBefore implementation (LT-183-06).

| # | العنوان | Status | Potential value | Why deferred | Before implementation | الرقم |
| --- | --- | --- | --- | --- | --- | --- |
| 6.1 | `### Rate-Based Score Decay` | POST-RC-CANDIDATE | Decay at the documented rate under steady traffic (`updatedAt += n × interval`), so a score falls one step per interval even while writes continue. | The current whole-interval behavior is the approved contract (#121, #183) and is stricter; changing it is a security-relevant store change in Lua and every adapter. | A dedicated decision record; red tests; a user-cost measurement before the store change; the stability gate and the S0/AKDR replays on the new head. | REPORT صف 3 |
| 6.2 | `### Admission-Path Measurement of Multi-Account Trusted Sessions` | POST-RC-CANDIDATE | Measure TMUA (20 browser majors over 12 account-bound trusted sessions) on the admission policy, where only TMSd is declared (40 in 200). | A new harness through `RateLimiterEngine`; the non-opt-in numbers are the approved limit (#120, #183). | Pre-registered predictions; the same streams as AKDR; `pre+out` and `pre`. | REPORT صف 1، §3؛ DEC-022 L196 |
| 6.3 | `### New / Unknown Users and Day 0` | POST-RC-CANDIDATE | A remedy for users with no known device, who gain nothing from the known-device bound (Q-P4). | No decision text addresses it; the admission layer and DEC-026 cover known or proven clients. | An Owner question set and a study with its own threat model. | REPORT صف 18 |

### البند 7 — CHANGELOG

| # | الموضع | النص الحالي | النص المقترح | الرقم |
| --- | --- | --- | --- | --- |
| 7.1 | `CHANGELOG.md` بعد L8 `## [Unreleased]`، قبل L10 | أول قسم الآن `### Fixed (#160, F-172-02, …)` | قسم `### Documentation (#183)` بسطر لكل بند 1–6، مثل: `- Decay contract: whole intervals since the last write; steady traffic faster than the interval does not decay (#121, #183; DECISION_MATRIX.md §7, POLICIES.md).` وبالمثل لـ Retry-After (#98)، و«Threat-model limits» (#120، #97/#100/#106)، وL696 (F-HMCI-02)، و«Identity inputs» (F-HMCI-01/03/04)، والبنود الثلاثة في ROADMAP. `No src, tests or decision record changes.` | — |

## 2. أسئلة للـ Lead قبل الكتابة (LT-183-xx)

* **LT-183-01: مكان هذا الـ PLAN.** المقترح `investigations/docs-closure-183/PLAN.md`، يُدمج مع الـ PR سجلًّا للتغييرات. البديل حذفه قبل الدمج.
* **LT-183-02: موضع القسم الكامل لحدود نموذج التهديد.**
  * **اقتراح أولي من الـ Lead (2026-11-03، ليس حكمًا):** `DECISION_MATRIX.md` §10.1، مع إحالة قصيرة من README «Security and Trust Boundaries» (3.1 و3.2 كما هما).
  * **المقترح:** `DECISION_MATRIX.md` §10.1 بعد Guarantees. البنود الثلاثة حدود لنموذج القرار (K1/K2، DEC-026، DEC-027)، وتجاور «Guarantees» يجعلها مقروءة كاستثناءات صريحة منها.
  * **البديل:** `FAILURE_SEMANTICS.md` §9 «Explicit Non-Guarantees». ذلك المستند يخص أوضاع فشل البنية (FAIL_CLOSED/OPEN، الـ circuit breaker)، فيختلط فيه الضرر بالتصميم مع الفشل.
* **LT-183-03: موضع Retry-After بدل `FAILURE_SEMANTICS.md`.** **اقتراح أولي من الـ Lead (ليس حكمًا):** USAGE_GUIDE عند وصف `retryAfter`/`SOFT_BLOCK`، وPOLICIES عند API Heavy، أي 2.1 و2.2. لا قسم فيه لقرار الدرجة (§4.3 لحواجز FAIL_OPEN). المقترح USAGE_GUIDE (2.1) وPOLICIES API Heavy (2.2). وهل تُضاف جملة في `DECISION_MATRIX.md` §7.2 (L788–L807)؟ لا أقترحها لأن §7.2 يعرّف الحساب، وهو صحيح كما هو.
* **LT-183-04: نطاق «كل طلب مرفوض يُحتسب».**
  * **اقتراح أولي من الـ Lead (ليس حكمًا):** يُكتب على ما قيس، أي K2 في `API_OVERUSE`، مع ذكر أن المسار نفسه للدرجات الأخرى لم يُقس هنا. صياغتا 2.1 و2.2 أعلاه عُدِّلتا على ذلك.
  * M-98 قاسه على K2 في `ApiHeavyProtectionPolicy` بـ `recordSuccess` وحده.
  * السؤال الباقي: هل تكفي جملة «not measured» في 2.1، أم تُحذف الإشارة إلى الدرجات الأخرى كليًا؟ لم أقس Login وOTP هنا.
* **LT-183-05: جدول §7 (1.1).** تعديل الخلايا والعنوان مع الفقرة 1.2، أم الفقرة وحدها وترك الجدول كما هو؟ المقترح الاثنان، لأن «−1 every 10 minutes» وحده هو الوعد الذي خالفه القياس.
* **LT-183-06: مكان بنود ROADMAP.** قسم جديد «Post-RC Security and Contract Candidates» (المقترح)، أم تحت «Post-RC Capability Candidates» القائم؟ والتكليف قال «تحت Post-RC …» بالقالب الموجود.
* **LT-183-07: سطر `DEVICE_FINGERPRINT.md` L131 «Improves differentiation under NAT/VPN».** F-HMCI-02 يذكر أن هذا الوعد ينقلب churn على IP + UA واحد. البند 4 في التكليف يخص L696 وحده. هل يُضاف إلى L131 تحفّظ يحيل إلى §5.3 وإلى «Identity inputs»، أم يبقى خارج هذا الـ PR؟
* **LT-183-08: F-HMCI-01 في `DECISION_MATRIX.md` نفسه.** التكليف يضعه في USAGE_GUIDE (5.1). هل تُضاف أيضًا جملة واحدة في §2.2 من `DECISION_MATRIX.md` («reachable only with a custom resolver»)، حيث تُعرَّف القاعدة؟
* **LT-183-09: سطر «سلوك مقصود معتمد».** المقترح ذكر «(intended behavior, #121, #183)» داخل النص نفسه، كما في 1.2 و2.1 و§10.1، بلا رابط تعليق في الوثائق المنشورة. البديل رابط تعليق اعتماد الـ Owner.

## 3. ما لا يفعله هذا الـ PR

* لا يغيّر سلوكًا ولا اختبارًا ولا DEC.
* لا يغلق issues ولا ينشر مصالحات (بيد الـ Lead).
* لا يقيس شيئًا جديدًا: كل رقم من REPORT #181 بصفه.
* لا يمسّ `investigations/` عدا هذا الـ PLAN.

## 4. مسار AGENTS.md (Adoption Standard §10.1)

Normal Engineering Task (documentation). قُرئ `docs/php-engineering-standards/STANDARDS_MANIFEST.md`.

| Standard | الإصدار | لماذا |
| --- | --- | --- |
| `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | 3.1.0 | تعديل وثائق قانونية وCHANGELOG وROADMAP |
| `DECISION_GOVERNANCE_STANDARD_AR.md` | 1.0.0 | الإحالة إلى DEC-022..028 بلا تعديل أي DEC ACTIVE؛ بنود ROADMAP تشترط DEC مستقبليًا |
| `AI_COLLABORATION_WORKFLOW_AR.md` | 12.0.0 | |
| `GITHUB_PHASE_STACK_WORKFLOW_AR.md` | 4.0.0 | Draft، والدمج بيد الـ Lead |

## 5. ما كُتب (S1)

**الأحكام:** [تعليق الـ Lead 6594026198](https://github.com/Maatify/php-rate-limiter/pull/184#issuecomment-6594026198) (2026-11-03) على LT-183-01..09 وصياغة 4.1. كُتب كل بند في commit واحد بنصوص §1 مع تعديلات الحكم، ودُفع كل commit وحده بعد انتهاء CI الرأس السابق. `check-whitespace.sh` نجح بعد `git add` في كل commit.

| البند | commit | ما كُتب (الموضع على الرأس `0f14809`) | CI |
| --- | --- | --- | --- |
| 1 | `ef1e77d` | `DECISION_MATRIX.md` §7: العمود والخلايا (1.1) L779–L784 والفقرة (1.2) L785–L791؛ `POLICIES.md` L405–L406 (1.3). `git grep -n -E '(−\|-)1 every' -- docs README.md` بعده فارغ (exit 1). | 41655920371، 16/16 |
| 2 | `7988eea` | `USAGE_GUIDE.md` L221، فقرة بعد بنود Retry-After الأربعة (2.1)؛ `POLICIES.md` API Heavy L413–L416 (2.2). جملة «not measured» بالصيغة المحكومة في الموضعين. §7.2 بلا تغيير (LT-183-03، 04). | 41658318331، 16/16 |
| 3 | `6727678` | `DECISION_MATRIX.md` §10.1 L1009–L1030 بعد Guarantees وقبل `---` التذييل، ونقطة 2 بالصيغة المحكومة؛ `README.md` فقرة «Threat-model limits» قبل «See …» (LT-183-02). `check-readme-examples.sh` exit 0 محليًّا. | 41660815891، 16/16 |
| 4 | `5e44a22` | `DECISION_MATRIX.md` §5.3 صف «Rapid fingerprint changes» بالصياغة المحكومة؛ `DEVICE_FINGERPRINT.md` §3.2 Properties L131–L133 (LT-183-07). | 41663200882، 16/16 |
| 5 | `c7e20ad` | `USAGE_GUIDE.md` `### Identity inputs` L203–L210 (5.1)؛ `DECISION_MATRIX.md` §2.2 L152–L153 جملة بعد الجدول (LT-183-08). | 41665203415، 16/16 |
| 6 | `76cf9d6` | `ROADMAP.md` `## Post-RC Security and Contract Candidates` بعد «Post-RC Backend and Verification Candidates» بالبنود 6.1–6.3 (LT-183-06). | 41667482484، 16/16 |
| 7 | `0f14809` | `CHANGELOG.md` `### Documentation (#183, governance closure item 6)` تحت `[Unreleased]` بسطر لكل بند 1–6. | 41669384473، 16/16 |

**ما اختلف في الشكل عن §1، مع السبب (النص نفسه لم يتغيّر):**
* **2.1** فقرة بعد قائمة Retry-After لا بند خامس فيها: القائمة تبدأ بـ «has four distinct contracts»، وبند خامس يناقضها. أُسقطت شرطة البند وحدها.
* **5.1** القسم قبل `### Retry-After Semantics` لا بعد L193: هناك كان سيضمّ فقرتَي `RateLimitCommand` و`RateLimitResultDTO` تحت «Identity inputs». يبقى داخل §Inputs and Outputs.
* **4.1** خلية القرار نص عادي («HARD_BLOCK L2 on K2 (IP + UA), 60 s») كبقية خلايا القرار في الجدول، والشرط بين backticks كبقية الشروط. أُعيدت محاذاة أعمدة الجدول.
* **ROADMAP** «Last reviewed» من 2026-09-27 إلى 2026-11-03، لأن الملف رُوجع في هذا الـ Work Unit (Maintenance Rule).

**سطر النسخة (حكم LT-183-02):** `DECISION_MATRIX.md` بلا سطر نسخة. `grep -n -i -E 'version|revision' docs/DECISION_MATRIX.md` يعطي على الرأس L20 (UA major version) وL595 (purpose/version) وتذييل L1035 فقط. وكذلك `DEVICE_FINGERPRINT.md`، و§11 فيه لنسخ خوارزمية الـ fingerprint. فلا رفع؛ سطر CHANGELOG يقول ذلك ويُعدّ سجلّ الـ changelog الذي يطلبه التذييلان.

**النطاق:** `git diff --stat origin/main -- src tests docs/decisions` فارغ، و`origin/main` = `5dff37c`. الفرع المحلي `main` في هذا الـ checkout قديم (`1cc715e`) ولم أحدّثه، فشُغّل الأمر على `origin/main`. `git diff --stat origin/main` يعطي ثمانية ملفات: الستة في `docs/` و`README.md` و`CHANGELOG.md`، وهذا الـ PLAN.

**ملاحظة خارج النطاق، لم تُكتب:** `DEVICE_FINGERPRINT.md` §7.1 L525 يقول «Rapid fingerprint changes under same IP + UA» بلا رقم، فلا يناقض §5.3 بعد البند 4.
