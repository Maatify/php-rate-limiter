# #160 البند 2 — تغطية DEC-029 لما لم يُرصد طبيعيًا — PLAN

> **الحالة:** PLAN، في commit وحده قبل أي اختبار. اختبارات وتوثيق فقط: **لا تغيير في `src/`**، ولا قياس على E-CI.
> **الأساس:** `main@eea9d1a`، الفرع `claude/dec029-coverage`.
> **المصدر:** تكليف الـ Lead «البند 2 — تغطية DEC-029 لما لم يُرصد طبيعيًا». قواعد القسم «أ» نفسها: Draft بالعربية، والدمج بيد الـ Lead، و`check-whitespace.sh` قبل كل push.
> **القاعدة الحاكمة:** الاختبارات الثلاثة توثّق عقدًا قائمًا، فالمتوقع أن تكون **خضراء على `main`**. إن احمرّ أيٌّ منها فهو **finding على DEC-029 كما نُفّذ**: أتوقف وأبلغ بلا إصلاح.

أرقام الأسطر من `src/Service/EvaluationPipeline.php` و`src/Config/LoginProtectionPolicy.php` عند `eea9d1a`.

## 1. الشكل المختلط وأي أمر ينتجه (قراءة)

### 1.1 أين يُبنى

* **الفرع العام** (`else`، L1956–L2015): لا API-heavy (L1888)، ولا `isTrustedAuthenticationPolicy` (L1927، L2596: جلسة موثوقة + `TRUSTED_AUTHENTICATION`).
* **المرشَّح واحد** (L2014) بـ `persistence` فيه حتى مدخلان:
  * **K4 (L1983–L1995):** حين `accountLevel > 0`. مستوى المدخل هو مستوى K4 الـ lifecycle إن كان ≥ 2 والمفتاح في `lifecycleEligibleKeys` (L1984–L1988)، ومدته `PenaltyLadder::getDuration` لمستواه.
  * **K5 (L1997–L2003):** حين `k5Level > 0`، بمستواه ومدته.
* **مستوى المرشَّح** `newMaxLevel`. و`retryAfter` هو الأكبر بين `retryAfterByKeyType` للمفاتيح التي قرارها قرار المرشَّح (L1960–L1970).

### 1.2 الأمر في `LoginProtectionPolicy`

**الأمر:** `recordFailure('login_protection')`. **الشروط:**
* بصمة جهاز موجودة؛
* `isDevicePreviouslyVerifiedForAccount = true`، والجلسة **غير** موثوقة. فالجهاز معروف للحساب (`isKnownForAccount`، L2581)، والطلب يسلك الفرع العام لا فرع المصادقة الموثوقة.

**ما يسجّله الفشل:**
* الفشل من جهاز معروف يسجّل K5 فقط: `k5_failure = 2` (L3038–L3040؛ السياسة L72).
* حين تُستنفد حصة الجهاز المعروف (`getKnownDeviceAccountAllowance() = 8`، السياسة L106، وتُحسب في L1659–L1675)، يُضاف `k4_failure = 3` (السياسة L70) في **الطلب نفسه**. فالطلب يكتب K4 وK5 معًا.
* K4 يُكتب بـ generation-bound mutation (L1731–L1745). يصير lifecycle-eligible عند المستوى ≥ 2 (L1757–L1761)، والعتبات K4 = 5/8/12 وK5 = 8/12/16 (السياسة L57–L58).

### 1.3 السيناريو الحتمي

* 12 فشلًا للحساب نفسه من الجهاز المعروف نفسه.
* `FixedClock` يتقدّم 301 ث بعد كل فشل، فينتهي كل حجب قبل الطلب التالي (أطول حجب في السيناريو L3 = 300 ث). بدون التقدّم يوقف الـ precheck التسجيل من الفشل السابع (حجب K5 فعّال).
* **قراءة على E-LOCAL بسكربت مؤقت، حتمية، لا رقم معدود:**
  * بعد الفشل 11: K4 = 9، أي L2؛
  * في الفشل **12**: K4 = 12، أي **L3 lifecycle-eligible**، وK5 = 14، أي **L2**.

  فالطلب 12 مرشَّح مختلط: K4 بالمستوى 3 وK5 بالمستوى 2، مستوى K5 دون مستوى K4. **هو الطلب المختبَر.**
* الحصة تُستنفد عند الفشل 9، فأول K4 = 3 عنده.

## 2. التنبؤات (مسجَّلة قبل كتابة الاختبارات)

* **الإعداد:** الطلبات 1–11 بلا تسليح على `ScriptedPublicationRateLimitStore` (يتصرف كـ `InMemoryRateLimitStore`).
* **الضابط المرجعي (control):** السيناريو نفسه على `InMemoryRateLimitStore` غير مُسيَّر، بالطلبات الاثني عشر. منه يؤخذ ما يُقارَن (الدرجات، وقرار الطلب 12).

### (i) b2 المختلط

`arm(PHP_INT_MAX, AFTER_LOSS_LEVEL_DROP)` قبل الطلب 12: أول نشر K4 يخسر، والمتجر المُسيَّر يكتب K4 = 0 كاتبًا متزامنًا، فتعطي إعادة القراءة مستوى < 2، أي `PUBLICATION_DROPPED`.

* القرار `HARD_BLOCK` **بمستوى K5 = 2**.
* `retryAfter = 60`: مدة مدخل K5 المحتفَظ به (`PenaltyLadder::getDuration(2)`)، عبر `withoutDroppedK4Contribution` (L2242–L2268) ثم `aggregateCandidates`.
* لا استثناء، و`metadata->cause` = null (ليس تأجيلًا).
* حجب K5 منشور فعلًا: `checkBlock(k5)` بالمستوى 2.
* حجب K4 غير منشور بالمستوى 3: لا حجب K4 فعّال (`checkBlock(k4)` = null؛ حجب L2 من الطلب 11 انتهى).
* نداء نشر واحد.
* **«لا تغيير في K4/K5 المكتوبين»:**
  * درجة K5 = درجتها في الضابط؛
  * درجة K4 = 0، وهي قيمة الكاتب المتزامن المُسيَّر. الـ pipeline لا يكتب في K4 ولا K5 بعد الخسارة، ولا يتراجع عن شيء.

### (ii) الضابط بلا خسارة

الطلب 12 بلا تسليح:
* `HARD_BLOCK` **بمستوى K4 = 3** (الأعلى)، و`retryAfter` = قيمة الضابط المرجعي؛
* لا cause؛
* حجب K4 فعّال بالمستوى 3، وحجب K5 فعّال بالمستوى 2، أي نشر الاثنين؛
* الدرجات = الضابط.

### (iii) تأجيل مختلط (r3، بلا هبوط)

`arm(PHP_INT_MAX)` بوضع `AFTER_LOSS_KEEP` قبل الطلب 12:
* القرار المحسوب كما هو: `HARD_BLOCK`، المستوى 3، و`retryAfter` = الضابط؛
* `cause = k4_publication_deferred`، والسياق `k4_publication_deferred` / `k4`؛
* 4 نداءات نشر، و3 قراءات snapshot؛
* **K5 منشور:** حجب فعّال بالمستوى 2. هذا يثبت LT-S-06 على الشكل المختلط: الكتل الباقية تُنشر دائمًا؛
* K4 غير منشور: لا حجب K4 فعّال؛
* الدرجات = الضابط.

## 3. DEC-029

* جملة في **Consequences** تسمّي الاختبارات الثلاثة تغطيةً للشكل المختلط.
* وتثبيت أن المسار المؤجَّل الطبيعي بلا حالة (0/2,000 في الخطوة S)، وأن تغطيته `PublicationDeferralTest`.
* لا تغيير في Decision. الملف غير مثبَّت بايتيًا في `DecisionRecordsVerbatimTest` (DEC-019/021/026/027 فقط).

## 4. ما يُفهم من نص التكليف (تفسيرات مسجَّلة، الحكم للـ Lead إن رأى غيرها)

* **LT-cov-01:** «لا تغيير في K4/K5 المكتوبين» في b2 = لا كتابة من الـ pipeline بعد الخسارة. درجة K4 تصير 0 بفعل الكاتب المتزامن المُسيَّر نفسه (L54–L56 من المتجر المُسيَّر)، وهذا شرط b2.
* **LT-cov-02:** `retryAfter` في (ii) و(iii) يُقارَن بالضابط المرجعي ولا يُتنبَّأ به رقمًا، لأنه يتبع `retryAfterByKeyType` وحالة الإيقاف المؤقت للتآكل (L1790 وما بعدها). أما في b2 فيُتنبَّأ به رقمًا: 60.

لا سؤال يمنع البناء. أكتب الاختبارات بعد هذا الـ commit مباشرة، وأتوقف إن احمرّ شيء.

## 5. مسار AGENTS.md (Adoption Standard §10.1)

Normal Engineering Task: قُرئ `docs/php-engineering-standards/STANDARDS_MANIFEST.md`. الـ Scope `/`، والـ activations `composer-package` 4.0.0 و`repository-governance` 3.0.0.

| Standard | الإصدار | لماذا |
| --- | --- | --- |
| `TESTING_STANDARD.md` | 2.0.0 | §4.2 و§4.5: اختبارات حتمية لعقد قائم |
| `PHP_CODING_STYLE_STANDARD.md` | 1.0.1 | الاختبارات |
| `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | 3.1.0 | جملة DEC-029 |
| `DECISION_GOVERNANCE_STANDARD_AR.md` | 1.0.0 | تعديل Consequences لا Decision |
| `AI_COLLABORATION_WORKFLOW_AR.md` | 12.0.0 | |
| `GITHUB_PHASE_STACK_WORKFLOW_AR.md` | 4.0.0 | Draft، والدمج بيد الـ Lead |
