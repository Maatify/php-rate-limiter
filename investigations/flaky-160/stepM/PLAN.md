# #160 — الخطوة M: قياس قبل أي تغيير في `src/` (DEC-029، المسار B) — تسجيل مسبق

> **الحالة:** PRE-REGISTRATION. أدلة فقط: لا تغيير في `src/` ولا `tests/` ولا `composer.json` ولا `phpunit.xml` ولا `ci.yml`؛ `docs/decisions/` لا يُمسّ (مسودة تعديل DEC-029 تُكتب في REPORT فقط، M4). المراحل `phase2` و`phase2b` تُقرأ ولا تُحرَّر.
> **الرأس:** الفرع مقطوع من `main@b6f4e22` (DEC-029 PROPOSED، PR #166). `git diff 2cf8565 b6f4e22 -- src tests composer.json phpunit.xml` يمسّ ملفًا واحدًا هو `tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php` (إصلاح T-CLAIM، PR #164)؛ لذا V-HEAD هنا مثبَّت على `b6f4e22` (`V_HEAD_REF`) لا على `2cf8565`. `src/` متطابق البايتات مع `2cf8565`: hash المتجر `7b0eed6c…` (= `store.sha256` للـ W1) وhash `RateLimitConcurrencyException.php` هو `ef65c882…`.
> **التكليف:** الـ Lead، #160 «الخطوة M» (2026-10-24)، بعد قرار الـ Owner على F-DEC029-01 (الخيار ب: P1 لكتابات الـ scoring فقط، وحدة الـ allowance مستهلك محدود، L1741 يبقى ويُقاس، تعديل DEC-029 قبل التفعيل).
> **القواعد المحفوظة:** تعليم الـ PR Ready ودمجه بيد الـ Lead على كلمة الـ Owner لا بيد المنفذ؛ أي سؤال تعاقدي يُطرح LT قبل البناء ويتوقف الدفع حتى يُحكم فيه؛ الأرقام المعدودة من E-CI فقط (E-LOCAL smoke لا يُعدّ)؛ لا dispatch ولا إعادة dispatch بلا كلمة الـ Owner؛ لا push ولا CI آخر أثناء dispatch؛ `check-whitespace.sh` قبل كل push؛ الـ PR Draft بالعربية حتى مراجعة الـ Lead وقرار الـ Owner؛ مراجعتي الذاتية «self-review» لا «Lead review».

## 0. الأسئلة

1. **M1 — الجرد.** كل موضع `new RateLimitConcurrencyException` في `src/`: ما الذي يُلتزم قبله، نوعه (scoring أم استهلاك)، وميزانيته؛ وصف A-DET لكل موضع يثبّت ما يُلتزم قبل الرمي.
2. **M2 — خطوط الأساس على `main@b6f4e22`** قبل أي تغيير: توزيع المحاولات (M2a، من السجلات)، الاستثناءات والنشرات غير المطبَّقة في تدفقات حميدة غير مصطنعة عبر Integration + System (M2b، E-CI)، ومعدل A-NAT (M2c، بلا إعادة تشغيل).
3. **M3 — التنبؤات 1–5** للنموذج، تُكتب الآن وتُقاس في الخطوة S.
4. **M4 — مسودة تعديل DEC-029** (في REPORT فقط).

## 1. ما قُرئ من الكود (قراءة، لا قياس)

`EvaluationPipeline` عند `b6f4e22`:

| الموضع | الرسالة | الشرط |
| --- | --- | --- |
| L1741 | `K4 generation mutation conflict budget exhausted.` | حلقة `for ($attempt = 0; $attempt < 3; …)` L1725–L1740 على `CallerTimeStore::mutateGenerationBoundScore` للمفتاح `k4` ولسياسة `PostPunishmentReentryPolicyInterface`؛ تنتهي بلا `applied` فيبقى `$newScore === null` |
| L2168 | `K4 punishment publication conflict budget exhausted.` | حلقة L2133–L2161 على `blockWithPunishmentLifecycleTracking`؛ ثلاثة طرق خروج بلا تطبيق (r3: ثلاث محاولات؛ b1: `generation === null`؛ b2: المستوى المعاد حسابه < 2) |

ترتيب الكتابات داخل أمر واحد (`process` ← `processUpdates` L1638 ← `persistWinningCandidates` L2088): (1) allowance للجهاز المعروف عند L1664 (`incrementBudgetAcrossRotation`، شرط: فشل لجهاز معروف وسياسة allowance وحساب وk5-delta > 0 وk4-delta = 0)؛ (2) `last_missing_fp` عند L1700 إن كان البصمة غائبة؛ (3) حلقة `foreach ($keys …)` بالترتيب `k1, k2, k3, k4, k5` (`buildKeys` L2804): كل مفتاح بـ delta > 0 يُكتب في تكرار حلقته (K4 بحلقة mutation L1725–L1741 على سياسة الـ lifecycle، وغيره بـ `CallerTimeStore::increment`)، مع watch flags؛ (4) مكبح الميزانية وmicro-cap وbudget عند L1845–L1856؛ (5) `persistWinningCandidates` في النهاية (L2088 فصاعدًا)، وهو الذي ينشر حجب K4 عند L2168. `RateLimiterEngine::limit` L344 يلتقط الاستثناء ويحوّله إلى `HARD_BLOCK` مستوى 2 و`retryAfter 1` وcause `k4_concurrency_conflict`، فالـ Host عبر الـ Engine لا يرى الاستثناء بل هذا القرار.

**استنتاجان من القراءة يُسجَّلان قبل القياس:**
* عند L1741 يكون **مُلتزمًا** قبل الرمي: وحدة الـ allowance (إن انطبق شرط L1664) وكتابات scoring للمفاتيح المعالَجة قبل K4 (K1–K3، ولأمر الـ A-test يقع منها K1 فقط: `k1_spray = 5`، K2/K3 بدلتا 0 — `calculateDeltas` L2883–L2936). K5 يلي K4 في الحلقة فلا يُكتب عند L1741. وعليه جملة Context L47 في DEC-029 («K = 0: nothing committed for that request») **غير دقيقة**: K = 0 يعني لا K5، لا أنه لا شيء مُلتزم. الهوية `ΣK = ΣU − conflicts` في الـ 1,998 تشغيل الناجح تقتضي أن كل عامل تعارض-mutation أخذ وحدة allowance (يُؤكَّد في A-DET-1741 على الحالة الاصطناعية).
* L2168 يقع بعد الـ allowance وmutation K4 وK5 `+2` (A-DET 2b، 20/20).

## 2. M1 — الجرد وصفوف A-DET

**الطريقة:** `grep -rn "new RateLimitConcurrencyException" src` (يُسجَّل في REPORT مع ناتجه) + جدول لكل موضع (ما يُلتزم قبله، نوعه، ميزانيته، الاختبارات التي تثبّته). أي موضع ثالث في `src/` = finding. خارج `src/`: `tests/Fixtures/S0Baseline/S0EvaluationPipeline.php` نسخة مجمَّدة تحمل الموضعين (L1616، L2002)؛ تُذكر في الجدول ولا تُقاس ولا يمسّها أي تغيير src.

**A-DET-1741 (جديد، `harness/adet1741.php`):** نفس بناء `adet.php` (الـ fixture والـ policy والـ pipeline كالاختبار، المتجر هو صنف W1 المسجِّل، ومفتاح التسليح في `ArmedStore` فقط وبعد الـ pre-state)، المتغيرات: `1` (حقن: mutation K4 يجيب «غير مطبَّق» ثلاث مرات)، `0` (ضابط، بلا حقن). Pre-state: allowance 8 وK4 = 6 (كما A-DET).
* **قاعدة الإصابة للمتغير 1 (مسجَّلة):** استثناء رسالته `K4 generation mutation conflict budget exhausted.` و`getLine()` = سطر هذا الـ `throw` كما يجده مسح الملف؛ ثلاث مكالمات mutation كلها غير مطبَّقة، وصفر مكالمات نشر؛ وفي Redis بعد الاستثناء: allowance **+1**، وK1 **أعلى** مما كان، وK2 وK3 **كما كانا**، وK4 وK5 **كما كانا**، ولا حجب K4 ولا K5.
* **الضابط 0:** لا استثناء، `HARD_BLOCK`، mutation واحد، نشر مطبَّق، حجب K4 بمستوى ≥ 2.
* **تنبؤ P-M1-1:** المتغير 1: **20/20**؛ الضابط: **20/20**.
* **تنبؤ P-M1-2:** إعادة تشغيل `adet.php` (L2168) كما هو على `b6f4e22`: inject 20/20، control 20/20، b2 20/20 (كما `main@2cf8565`).
* **تنبؤ P-M1-3:** لا موضع ثالث في `src/` (grep: اثنان).
* تجربة غير صالحة (تُعدّ ولا تحسب إصابة ولا خطأ) إن لم يُبلَغ فرع الـ mutation.

## 3. M2 — خطوط الأساس

### 3.1 M2a — من سجلات 2b، بلا تشغيل (`harness/m2a.php`)

المطلوب في التكليف: توزيع محاولات النشر لكل عامل في الـ 2,000 تشغيل (1/2/3/فشل) وتوزيع محاولات mutation K4؛ تنبؤ مسجَّل من الـ Lead: **≥ 95 % من العمّال ينشرون من المحاولة الأولى**.

**قيد بيانات (finding F-M-01، يُعرض LT-M-01):** سجلات الـ 2,000 تشغيل لا تحتوي بيانات المحاولات للعمّال غير المتعارضين. `run.sh a_run` حذف مجلد أي تشغيل ناجح صالح وأبقى سطر الحكم فقط (`verdicts.jsonl`)، وسطر الحكم يحمل `pubs` لعمّال التعارض فقط. المحفوظ فعلًا: (أ) أسطر حكم الـ 2,000 (عدد عمّال التعارض بحسب الموضع وعدد محاولات النشر لعامل تعارض النشر)؛ (ب) ملفات المسجّل كاملة لتشغيلين فاشلين فقط (362، 756) = 64 عاملًا. فالتنبؤ بصيغته (نسبة العمّال الذين ينشرون من المحاولة الأولى على الـ 2,000) **لا يُحسب من السجلات**. المنفَّذ: (أ) و(ب) كما هما، وتُعرض النتيجة كوصف لا اختبار للتنبؤ، مع قيد أن (ب) عيّنة متحيزة بالبناء (تشغيلان كثيفا التعارض). الخيار (ج) — إعادة تشغيل A-NAT على E-CI بتعديل harness يحفظ ملخص كل عامل (بلا لمس `src/`) — يحتاج كلمة الـ Owner؛ لا أبدأه.

### 3.2 M2b — E-CI، مجموعة Integration + System كاملة مرة واحدة تحت مسجّل W2

**الأداة (W2، `harness/m2b_lib.php`، بوتستراب `--bootstrap` فقط):** أخف من W1 (لا تسجيل لكل مكالمة). تخدم صنفين من prepended autoloader بنص الملف على القرص مع تعديل ميكانيكي واحد لكل منهما، hash-pinned ومسجَّل كـ diff:
1. `RateLimitConcurrencyException`: `{}` ← constructor يستدعي `parent::__construct(...$args)` ثم `M2b::thrown()` الذي يسجل موضع `new` (ملف:سطر المستدعي) واسم الاختبار الجاري (أعمق frame اسم دالته `test…`). يرى كل رمي من أي متجر أو مستدعٍ، بما فيه ما يلتقطه الـ Engine.
2. `RedisFullCapabilityStore`: تعديل W1 ذاته (`final class … → class …Inner`، pin `store.sha256`) + صنف فرعي يتجاوز `mutateGenerationBoundScoreAt` و`blockWithPunishmentLifecycleTrackingAt` فقط: يستدعي `parent::` ويعدّ ويسجّل الاختبار عند «غير مطبَّق».
* **حدّ معلَن:** عدّ «النشرات غير المطبَّقة» على مستوى المتجر يخص متجر Redis فقط؛ متاجر الذاكرة في الاختبارات (`InMemoryRateLimitStore` يعيد غير مطبَّق طبيعيًا L198/L201/L220) لا تُعدّ على مستوى المتجر، ورميها يظهر في (1).
* **V-TRANSFORM وكواشف (`harness/m2b_check.php`، في `checks`):** V1 hash الملف = pin (وgit)؛ V2 النص المقيَّم = الأصل + `m2b-transform.diff` بـ `patch`؛ V3 المتجر المقيَّم = الأصل + `store-transform.diff`؛ V4 الصنف الفرعي = تجاوزان لكل منهما استدعاء `parent::` واحد؛ D1 رمي من موضع معروف ينسب إلى ملف:سطر والاختبار؛ D2 رمي بلا frame اختبار ينسب إلى `?`؛ D3 fork child يعدّ ما رماه هو فقط؛ D4 الصنف المخدوم يحفظ الرسالة ونوع الأب. أي فشل = المجموعة لا تُشغَّل.
* **التشغيل:** `vendor/bin/phpunit --bootstrap m2b_bootstrap.php -c phpunit.xml --testsuite Integration,System` مرة واحدة، Redis بحاوية جديدة من `docker/redis-integration/compose.yaml` كما في 2b، ملف `m2b-<pid>.json` لكل عملية (الأبناء المتفرعون يبدأون عدّادهم من الصفر). نتيجة PHPUnit (الحالة والملخص) تُحفظ؛ **أي اختبار فاشل يُدرج بالاسم** (احتمال تدخل الأداة)، والمجموعة الطبيعية خضراء على `main` (CI العادي).
* **الاختبارات المقصودة (سُمّيت الآن من قراءة `tests/` قبل أي تشغيل، وتُستثنى صراحةً من التنبؤ وتُعرض أرقامها منفصلة):**
  * `tests/System/PostPunishmentReentryLifecycleTest.php::testMutationAndPublicationConflictsRefreshAndRetryWithFreshState` (L351)، `::testThreeMutationConflictsReturnTransientHardWithoutCircuitFailure` (L411)، `::testThreePublicationConflictsReturnTransientHardWithoutCircuitFailure` (L441): تحقن عمدًا «غير مطبَّق» وتثبّت قرار الـ Engine عند الاستنفاد (`HARD_BLOCK` / `retryAfter 1` / `k4_concurrency_conflict`، وعدد مكالمات 3).
  * `tests/Integration/Redis/KnownDeviceAllowanceConcurrencyIntegrationTest.php` (كل دوالّه): 16 عاملًا متفرعًا.
  * `tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php::testWidenedHardBlockExpiryIsValidForCycleTrackingLifecycleAndPreviousReads` (فيه `pcntl_fork` L3697).
  * ما عدا هذه يُعدّ «تدفقًا حميدًا غير مصطنع». أي رقم غير صفري خارجها يُعرض `unexpected` باسم الاختبار ولا يُستثنى لاحقًا.
* **تنبؤ P-M2b-1 (من الـ Lead، مسجَّل):** **0** استثناء عند L1741 و**0** عند L2168 خارج الاختبارات المقصودة أعلاه. **تنبؤ P-M2b-2 (لي):** **0** نشرة غير مطبَّقة على مستوى متجر Redis خارج المقصودة. أي رقم غير صفري عند L1741 يعيد فتح سؤال النطاق بدليل (يُرفع للـ Lead).
* **التكلفة:** تُقدَّر وتُعرض على الـ PR قبل الـ dispatch (REPORT §6)؛ لا dispatch بلا كلمة الـ Owner.

### 3.3 M2c — A-NAT كما في 2b

بلا إعادة تشغيل. خط الأساس 2/2,000 (0.10 %، Wilson [0.027 %, 0.364 %])؛ يُسجَّل مع 4/1,000 (A-RATE، المرحلة 2) و1/5,000 (A-REC).

## 4. M3 — التنبؤات المسجَّلة للنموذج (تُقاس في الخطوة S على فرع `src/`؛ تُكتب الآن بصيغة قابلة للدحض)

1. **A-NAT بنفس الـ harness بعد التغيير:** 0 استثناء من L2168 في 2,000 تشغيل (كان 2)، و`k5 = 2 × (consumed − conflicts)` يصحّ في كل تشغيل بلا تخفيف. دُحض إن ظهر استثناء واحد من L2168 أو فشل واحد عند L136.
2. **محاولات النشر بعد التغيير:** الموضع يصل إلى «المحاولة الأخيرة» في ≤ ما كان يصل إلى الرمي (الأساس: عامل التعارض في الـ 2 = 2/2 وصل إلى الثالثة)، والعائد بقرار-من-الحالة بلا نشر يُعدّ ويُبلَّغ مع زمن ظهور الحجب لاحقًا (من أول ناشر ناجح). دُحض إن زاد عدد الوصول للمحاولة الأخيرة عن خط الأساس أو لم يظهر حجب بعد عائد قرار-بلا-نشر.
3. **مساواة القرارات:** على Integration + System، القرارات والحالة النهائية متطابقة مع التغيير وبدونه في كل تدفق أحادي العامل (V-CLOCK-2 موسَّعًا إلى V-DECISION). أي اختلاف finding. **ملاحظة مسبقة (لا تعديل):** `testThreePublicationConflictsReturnTransientHardWithoutCircuitFailure` يثبّت اليوم أن ثلاث إخفاقات نشر ← `HARD_BLOCK`/`retryAfter 1`/cause `k4_concurrency_conflict`/3 مكالمات؛ هو اختبار P2-by-design ويُتوقَّع أن يُغيَّر في الخطوة S (اختبارات حمراء أولًا) — وليس «اختلاف قرار» غير مقصود؛ يُسمّى صراحةً هناك.
4. **تكلفة المستخدم الحميد (درس 5-b2):** admission suites (`tests/System/Admission*`) والأرشيف لا تتغير فيها أي نتيجة؛ زمن `persistWinningCandidates` تحت 16 عاملًا لا يزيد عن الوسيط الحالي بأكثر من 1.25×.
5. **L1741:** يبقى يرمي كما هو (A-DET 20/20) ولا يصل إليه أي تدفق حميد (M2b = 0).

## 5. أسئلة للـ Lead (LT-M-xx) — تُحكم قبل أي دفع

* **LT-M-01 — M2a.** البيانات المطلوبة غير محفوظة (§3.1). أ) أقبل (أ)+(ب) كخط أساس وصفي ويُعاد صياغة التنبؤ إلى «نسبة العمّال الذين نشروا من المحاولة الأولى بين من بلغوا فرع النشر» ويُقاس في الخطوة S؛ ب) أو أصرّ على M2a على الـ 2,000 فيلزم إعادة A-NAT على E-CI بتعديل harness (لا src) وكلمة Owner وتكلفة (≈ ما قدّرته 2b: ≈ 1.4 runner-hour على 8 shards).
* **LT-M-02 — نطاق P1 وحدّه عند L1741.** قرار الـ Owner عدّد «كتابات الـ scoring فقط (mutation K4 وزيادة K5)». عند L1741 يُلتزم قبل الرمي allowance وK1–K3 (للأمر المقيس: K1 `+5`). هل (أ) L1741 خارج P1 لأن المُلتزم قبله ليس mutation K4 ولا K5 (يبقى يرمي، وContext L47 يُصحَّح فقط)، أم (ب) كتابة K1–K3 «scoring» فتدخل P1 فيقع L1741 تحت قاعدة «لا استثناء بعد كتابة مُلتزمة»؟ هذا حدّ النطاق وليس تفصيلًا: (ب) يوسّع الخطوة S إلى L1741 ويناقض بند «L1741 يبقى كما هو».
* **LT-M-03 — أداة M2b.** هل W2 (تحويل ميكانيكي لـ `RateLimitConcurrencyException` يضيف constructor يسجّل، + متجر مسجِّل لدالتين، كلاهما hash-pinned وبـ diff وV-TRANSFORM) مقبولة كأداة قياس للـ E-CI، علمًا بأن الصنف `final` في `src/` يبقى على القرص دون تغيير؟
* **LT-M-04 — تركيبة المجموعة.** `--testsuite Integration,System` يشمل مجموعة `archive` (تُشغَّل في CI منفصلة). هل تدخل في M2b أم تُستثنى؟ افتراضي: تدخل (التكليف: «Integration + System كاملة»).

## 6. انحرافات معلَنة (قبل أي شيء معدود)

* **ترتيب البناء:** بُني الـ harness (`adet1741.php`، W2، `m2a.php`) وجُرِّب على E-LOCAL **قبل** commit هذه الـ PLAN (القاعدة المحفوظة: PLAN قبل الـ harness). لم يُعدّ أي رقم من E-LOCAL ولم يُستعمل لضبط التنبؤات سوى ما يلي، وهو مسجَّل بحرفه: (أ) في أول smoke لـ `adet1741.php` كان التسليح قبل الـ pre-state فاستهلك الحقنُ مكالمة الـ pre-state (0/5)؛ صُحّح ترتيب التسليح فقط. (ب) في قاعدة الإصابة كتبتُ أولًا «K1–K3 أعلى»؛ كانت خطأ قراءة، وقرأتُ `calculateDeltas` (§1 أعلاه) فاستُبدلت بـ«K1 أعلى وK2/K3 كما كانا»، ثم أُعيد الـ smoke (20/20 للمتغيّرين). القاعدة المسجَّلة في §2 مشتقة من القراءة، والـ smoke لم يغيّر غيرها.
* لا commit للـ harness قبل هذه الـ PLAN: الـ PLAN أولًا بمفردها، ثم الـ harness، ثم REPORT.

## 7. المخرجات والترتيب

1. هذه الـ PLAN (commit مستقل). 2. `harness/` بنسخ موثّقة بـ provenance (`PROVENANCE.txt` بـ SHA-256 لكل نسخة عن `phase2b/harness` عند `b6f4e22`، و`PROVENANCE-run.sh.diff` لتعديلات `run.sh`) والكواشف. 3. `REPORT.md` بما يمكن من غير dispatch: الجرد (M1 قراءة)، M2a، M2c، التنبؤات 1–5 مكتوبة، مسودة تعديل DEC-029 (M4)، وبيان ما لم يُقس. 4. بعد حكم LT-M وكلمة الـ Owner: workflow مؤقت (`git revert 8f3d29c` ثم تكييف: `contents: read`، بلا secrets، حارس push على الفرع، `env.sh`، وحدات `checks | adet | suite`) ثم dispatch واحد ثم أرقام E-CI في REPORT و`logs/` بـ SHA256SUMS؛ يُحذف الـ workflow قبل Ready في commit مستقل. 5. PR Draft بالعربية.
