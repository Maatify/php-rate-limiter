# #173 — تذبذب CVH-2 في Consumer Verification Harness عند حدّ نافذة 600 ثانية — PLAN (S0)

> **الحالة:** PLAN وحده، ثم **توقف حتى أحكام الـ Lead** على §6. **harness فقط:** لا تغيير في `src/` ولا `tests/`.
> **الأساس:** `main@5f49813`، الفرع `claude/cvh2-window-173`. قواعد القسم «أ»:
> - Draft بالعربية، والدمج بيد الـ Lead؛
> - `check-whitespace.sh` قبل كل push؛
> - أي فشل CI يُبلَّغ ثم أنتظر كلمة الـ Owner قبل أي إعادة.
>
> **المصدر:** تكليف الـ Lead «#173». الظاهرة مرصودة في PR #172، run 40932111864، المحاولة 1:
> - التشغيلان النظيفان المتوازيان فشلا معًا بـ `CVH-2: the 25th admission was not a level-1 SOFT_BLOCK.`؛
> - بدآ عند 18:34:49Z، وطُبع فشلهما عند 18:40:01Z، و18:40:00 حدّ 600 ث.

كل ما هنا **قراءة للكود عند `5f49813`، لا قياس**، إلا ما يُذكر مصدره.

## 1. السبب (قراءة)

* **نافذة السعة:** `AdmissionRuntime` يحسب نافذة سعة المصدر `'w' . intdiv($t, windowSeconds)` (`src/Service/AdmissionRuntime.php` L254)، و`windowSeconds = 600` (`src/DTO/AdmissionBudgetsDTO.php` L75). `$t` هو ساعة الـ runtime.
* **ساعة الـ runtime في الـ harness:** `consumer-verification/verify.php` يبني runtime الـ admission (`$admissionBuild`، L1108–L1112) **بلا `withClock`**، فهو على `SystemClock` الافتراضي.
  * إن عبرت ساعة الحائط حدّ 600 ث أثناء الإجازات الأربع والعشرين في CVH-2 (L1150–L1156)، انقسم العدّ على نافذتين ولم يُرفض الخامس والعشرون.
* **ليس عيبًا في الـ runtime:** النافذة الثابتة عقد موثَّق (DEC-025/028). المشكلة افتراض في الـ harness.

### 1.1 لماذا الـ admission على ساعة النظام اليوم (تاريخ مسجَّل، يحتاج حكمًا: LT-173-01)

* **التعليق في L1106–L1107:** «The admission compositions use the builder's default system clock … the bounded correlation snapshots carry a Redis-time expiry (DEC-016), which a frozen caller clock falls behind as soon as real time passes.»
* **أصله:** 5-c، commit `1e951ee` بتاريخ 2026-10-21، `investigations/k2-identity-admission-97/5c/PLAN.md` L420–L425 (تعديل بعد الاختبارات، مقاس محليًا).
  * بساعة `FixedClock` متجمدة، كان كل `checkOnly` يصل إلى bounded snapshot يرمي «invalid snapshot»، لأن الانتهاء من Redis `TIME`.
  * والـ preset العادي `login_protection` رمى أيضًا. سُجّل N-5c-01، أي **#153**.
* **بعده:** #153 المرحلة 2 (`c8b8fd7`، 2026-10-22، #158) نقلت كل سكربت في المتجر إلى ساعة المتصل:
  * واجهات `CallerTimeBoundedCorrelation*`؛
  * وملاحظة DEC-016 بتاريخ 2026-10-21: «the built-in `RedisFullCapabilityStore` reads no Redis `TIME` in any script»؛
  * و`BoundedCorrelationResultValidator::snapshot()` (L38–L59) يقارن `expiresAt` بـ `$now` الممرَّر.
* **فالسبب المسجَّل في L1106 قد يكون تقادم.** هذا **غير مقاس** بعد #158، وهو التنبؤ (د) في §4. الإصلاح المقترح (أ) يعتمد عليه، ولا يُبنى قبل أن يتحقق في S1.

## 2. جرد كل فحص في `verify.php` يعتمد على الزمن

* «جدار» = نافذة ثابتة محاذاة لساعة الحائط (`intdiv($t, W)`).
* «epoch» = نافذة تبدأ عند أول زيادة، فلا يقطعها حدّ حائطي.

| # | الفحص (أسطر `verify.php`) | الساعة اليوم | الاعتماد الزمني | التصنيف |
| --- | --- | --- | --- | --- |
| T1 | `$clock = new FixedClock(...)` L406 | — | **غير مستعمل** (لا مرجع إلى `$clock` بعده) | متغير ميت، يُزال أو يُترك (LT-173-05) |
| T2 | `$limiter` الرئيسي L408–L410: login/OTP progression، persistence، spray (L692–L706)، trusted session | `SystemClock` | درجات K تضمحل بزمن حقيقي. spray: مجموعة bounded بـ TTL نسبي 600 ث (`observeCredentialSpray`، `EvaluationPipeline` L1599 وما بعدها)، لا نافذة جدار | ساعة حقيقية؛ لا حدّ يقطعها داخل تشغيل بالثواني |
| T3 | إعادة الدخول بعد العقوبة: `sleep($sharedWaitSeconds)` L651–L658، و`$checkPublicReentry` بـ `sleep(2)` L428–L441؛ المخصَّصة L516 وL591 وL647 | `SystemClock` | **يحتاج مرور زمن حقيقي**: الحجب الفيزيائي (TTL في Redis) ينتهي بالزمن الحقيقي، والفحص يثبت ذلك عبر الـ API العامة | يبقى كما هو (توجيه الـ Lead) |
| T4 | التدوير L726–L753، وهجرة الـ budget L766–L785 | `SystemClock` | budget epoch من أول زيادة (`epochStart`) | epoch: آمن |
| T5 | الـ throttle البسيط 60 ث L409 وL790–L799، والموزون L811–L820، وتدويره L836–L851، وفشله L874 | `SystemClock` | `FixedWindowSimpleRateLimiter::consume` عبر `incrementAcrossRotation` (budget epoch). `resetAt` من `epochStart`. الاستهلاكات الثلاثة في ميلي ثوانٍ | epoch: آمن ما دام التسلسل < 60 ث |
| T6 | fallback caps L954–L1001 | `FixedClock` بـ `setNow` | جدار (`floor(now / window)`) | **أُصلح بالنمط نفسه في `a46701b` (#128، F127-04)**، سابقة مباشرة |
| T7 | الـ circuit L1025–L1038، وext-redis L1063–L1082 | `FixedClock` بـ `setNow` | فترات الـ circuit | محكوم بالفعل |
| T8 | **CVH-2** L1146–L1161 | `SystemClock` | **جدار 600 ث**: 24 + 1 في نافذة `source_capacity` واحدة | **العيب**؛ يصلح بساعة ثابتة |
| T9 | **CVH-3** L1163–L1173: credential من المصدر المستنفَد، والضابط المعبوث يتوقع `source_capacity` | `SystemClock`؛ `$mint` يستعمل `time()` لـ `iat`/`exp` | **جدار 600 ث**: الضابط يفترض أن المصدر ما زال مستنفَدًا في النافذة نفسها | المشكلة نفسها؛ يصلح بالساعة نفسها، و`$mint` من الساعة نفسها (LT-173-03) |
| T10 | **CVH-4** L1175–L1182: الـ credential الملغى يتوقع `source_capacity` | `SystemClock` | **جدار 600 ث** (المصدر نفسه) | كذلك |
| T11 | **CVH-5** L1184–L1197: permit يُطالب به مرة | `SystemClock` | مهلة الـ permit نسبية (`permitDeadlineSeconds ≤ windowSeconds`، `AdmissionBudgetsDTO` L60) | لا حدّ جدار؛ يصلح بالساعة نفسها بلا أثر |
| T12 | **CVH-6** L1199–L1217: `source_failure_cap` عند الخامس، و24 لمصدر يُسقط النتائج | `SystemClock` | **جدار 600 ث** (`w`)، و**جدار اليوم** (`'d' . intdiv($t, 86400)`، L276 وL311) لحدود اليوم: حدّ منتصف الليل UTC يقسمه أيضًا | المشكلة نفسها، وحدّ اليوم أندر |
| T13 | **CVH-1** L1121–L1143: المتغير بسياج مغلق يقرّر كـ `login_protection`، والضابط بسياج مفتوح يغيّر القرارات | `SystemClock` | تشغيلان متتاليان على runtimes مستقلة، والمقارنة تساوٍ نصّي لـ 40 قرارًا. الضابط `!==` | لا نافذة admission في المغلق. المفتوح يمسّ `source_capacity` لأربعة مصادر، فقد يتأثر الضابط بحدّ جدار نادرًا؛ لم يُرصد |

**الخلاصة:**
* الفحوص المعرَّضة لحدّ جدار هي **CVH-2 وCVH-3 وCVH-4 وCVH-6** (وCVH-1 المفتوح احتمالًا). كلها على runtime الـ admission نفسه بـ `SystemClock`.
* ما يحتاج زمنًا حقيقيًا بحق هو **T3** (إعادة الدخول بـ `sleep`) وحده. وT2 وT4 وT5 لا يقطعها حدّ داخل تشغيل بالثواني.

## 3. الأشكال (بلا اختيار قبل الأحكام)

| | (أ) runtime admission على `FixedClock` بـ `setNow` صريح (توجيه الـ Lead) | (ب) حارس انتظار قبل كتلة الـ admission | (ج) محاذاة بدء التشغيل بعيدًا عن الحدّ | (د) ساعة حقيقية بإزاحة ثابتة |
| --- | --- | --- | --- | --- |
| الآلية | `$admissionBuild` يأخذ `->withClock($admissionClock)` لكتلة CVH-2…CVH-6 (وCVH-1 إن حُكم). الزمن لا يتقدم إلا بـ `setNow`، ولا فحص الآن يحتاج تقدّمًا | قبل CVH-2: إن بقي في النافذة الحالية أقل من حدّ `B` ثانية، `sleep` حتى بداية النافذة التالية + 1 ث | `sleep` حتى بُعد معيَّن عن الحدّ قبل التشغيل كله | `now = time() + Δ` يضع الكتلة في أول النافذة |
| يزيل السبب؟ | **نعم**: العدّ كله في لحظة واحدة، مستقل عن لحظة البدء | **نعم بشرط**: مدة الكتلة < `B` (يُقاس) | **لا**: يضيّق النافذة ولا يزيلها (مرفوض في التكليف) | لا يزيل ما لم تُحسب Δ؛ ويضع انحرافًا ثابتًا عن TTL Redis الفيزيائي |
| الشرط المسبق | **التنبؤ (د):** لا «invalid snapshot» بساعة متجمدة بعد #158 (§1.1) | لا شيء | — | — |
| الأثر على «كما يفعل Host إنتاجي» (5-c) | الـ admission لم يعد على ساعة النظام في الـ harness، كباقي الفحوص ذات النوافذ (T6، T7) | يبقى على ساعة النظام | — | — |
| الكلفة | تغيير `$admissionBuild` وتعليق L1106؛ لا انتظار | ≤ `B` ث أحيانًا في كل تشغيل | — | — |
| حدّ اليوم (CVH-6) | يزول | يحتاج حارسًا ثانيًا عند منتصف الليل UTC | — | — |

**التوصية بلا اختيار:**
* (أ) يزيل السبب بلا شرط زمني، **إن** تحقق (د).
* إن لم يتحقق (د)، أي ما زالت الساعة المتجمدة ترمي، فـ (ب) هو البديل الذي يبقي الساعة الحقيقية.
* (ج) و(د) مرفوضان.

## 4. التنبؤات المسجَّلة

* **(أ) إعادة إنتاج حتمية للظاهرة:**
  * تسلسل CVH-2 نفسه على runtime admission بـ `FixedClock`، تبدأ عند `boundary − 1 s` (boundary مضاعف لـ 600)، وتتقدم ثانيتين بعد الإجازة الثانية عشرة؛
  * النتيجة: الخامس والعشرون **يُقبل** (ALLOW)، **20/20** تكرارًا، كل تكرار بـ namespace/env مستقل في Redis.
* **(ب) بعد الإصلاح:** CVH-2 بالساعة الثابتة **بلا تقدّم** عند أي موضع للحدّ:
  * المواضع: `boundary − 1 s`، و`boundary`، و`boundary + 1 s`، و`boundary + 599 s`، وموضع عشوائي؛
  * النتيجة: الخامس والعشرون **يُرفض** `SOFT_BLOCK` مستوى 1 بـ `source_capacity` / `source`، **20/20** لكل موضع.
  * **فهمي للنص:** «بنفس الساعة» تعني الساعة الثابتة نفسها، لا التقدّم عبر الحدّ؛ فالعبور الصريح يعيد الظاهرة بحكم العقد (LT-173-02).
* **(ج)** `scripts/ci/run-consumer-verification.sh` كاملًا ينجح على E-LOCAL ثم على E-CI (الـ Consumer Verification Harness في CI العادي 16/16)، والتشغيلان المتوازيان معًا.
* **(د)، شرط (أ):** runtime الـ admission على `FixedClock` متجمدة، مع مرور زمن حقيقي أثناء الكتلة (ثوانٍ):
  * لا يرمي «invalid snapshot» ولا أي استثناء في CVH-1…CVH-6، و`verify.php` كاملًا `PASS`، لأن انتهاء الـ bounded snapshots صار من ساعة المتصل بعد #158 (§1.1).
  * **إن لم يتحقق، فهو finding**: أتوقف وأبلغ قبل S2.
* `check-consumer-verification-boundary.sh` يبقى ناجحًا: لا استيراد من `tests/`، و`ConsumerVerification\FixedClock` من `consumer-verification/src/` نفسه.

## 5. المصفوفة

* `run-consumer-verification.sh` يشغّل `run_clean_consumer_verification` **مرتين متوازيتين** (L84–L92)، ولكلٍّ مشروع consumer وRedis خاصّان.
* اليوم يبدآن في اللحظة نفسها تقريبًا، فيعبران الحدّ نفسه معًا، ولذلك فشلا معًا في #172.
* بعد (أ) تصير كتلة الـ admission في كل منهما مستقلة عن لحظة البدء، فلا ارتباط بين التشغيلين ولا بحدّ الحائط.

## 6. أسئلة للـ Lead قبل البناء (LT-173-xx)

* **LT-173-01 — الشرط المسبق.** التعليق L1106–L1107 يسجّل أن الساعة المتجمدة كانت ترمي «invalid snapshot» (5-c، مقاس). وبعد #158 يُتوقع ألا ترمي.
  * **المقترح:** S1 يتحقق من (د) أولًا. إن تحقق: (أ)، وتصحيح التعليق L1106–L1107 ليقول لماذا لم يعد صحيحًا. وإن لم يتحقق: توقف، ثم (ب).
  * مقبول؟
* **LT-173-02 — قراءة التنبؤ (ب).** «بنفس الساعة» = الساعة الثابتة **بلا تقدّم** عند كل موضع للحدّ، لا تقدّم ثانيتين عبره؟
* **LT-173-03 — نطاق (أ).**
  * **المقترح:** ساعة admission واحدة لكتلة CVH-2…CVH-6 كلها، لأنها تشترك في المصدر المستنفَد والنوافذ نفسها.
  * و`$mint` يأخذ `iat`/`exp` من الساعة نفسها بدل `time()`، فلا يتعارض `iat` مع `now` إن ابتعدت الساعة الثابتة عن الحائط.
  * وCVH-1 هل يبقى على ساعة النظام (مقارنته بين runtimes مستقلة) أم ينتقل؟ المقترح: ينتقل أيضًا، فتصير الكتلة كلها مستقلة عن الحائط.
* **LT-173-04 — مكان إعادة الإنتاج (أ) وS1.**
  * **المقترح:** سكربت `consumer-verification/reproduce-cvh2-window.php` يعمل داخل مشروع الـ consumer المثبَّت، بالـ autoload نفسه، عبر وضع اختياري في `scripts/ci/run-single-consumer-verification.sh`، لا في `tests/`.
  * يُشغَّل محليًا في S1 (أحمر = الظاهرة تُعاد 20/20) وفي S3. ولا يُضاف إلى CI العادي إلا إن حُكم بذلك.
  * أم `scripts/ci/`؟
* **LT-173-05 — `$clock` الميت في L406.** يُزال أم يُستعمل لـ (أ)؟ المقترح: ساعة admission مسمّاة مستقلة، وإزالة الميت لأنه لا مرجع له.
* **LT-173-06 — CHANGELOG.** سابقة `a46701b` (#128، fallback caps بالنمط نفسه) **لم تُسجَّل** في CHANGELOG (harness فقط)، و`1e951ee` سُجِّل لأنه غيّر `src/`.
  * **المقترح:** لا مدخل CHANGELOG لهذا الـ PR، اتباعًا للنمط. أم مدخل `### Fixed (test harness)`؟

## 7. ترتيب الـ commits بعد الأحكام

1. الأحكام في §9 من هذا الملف (commit وحده).
2. **S1:** سكربت إعادة الإنتاج أحمر (الظاهرة 20/20)، والتحقق من (د).
3. **S2:** الإصلاح في `verify.php`.
4. **S3:** (ب) و(ج) محليًا، ثم CI 16/16.
5. **S4:** CHANGELOG حسب LT-173-06، والـ Standards في وصف الـ PR.

## 8. مسار AGENTS.md (Adoption Standard §10.1)

Normal Engineering Task: قُرئ `docs/php-engineering-standards/STANDARDS_MANIFEST.md`. الـ Scope `/`، والـ activations `composer-package` 4.0.0 و`repository-governance` 3.0.0.

| Standard | الإصدار | لماذا |
| --- | --- | --- |
| `CI_WORKFLOW_STANDARD.md` | 5.0.0 | بوابة Consumer Verification في CI، وتشغيلان متوازيان |
| `TESTING_STANDARD.md` | 2.0.0 | §4.7 (Host CI Enforcement of Required Behavioral Evidence: بوابة الـ Consumer Verification دليل سلوكي في CI)، وإعادة إنتاج حمراء قبل الإصلاح |
| `PHP_CODING_STYLE_STANDARD.md` | 1.0.1 | `verify.php` والسكربت |
| `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | 3.1.0 | تصحيح التعليق L1106، وCHANGELOG حسب LT-173-06 |
| `AI_COLLABORATION_WORKFLOW_AR.md` | 12.0.0 | |
| `GITHUB_PHASE_STACK_WORKFLOW_AR.md` | 4.0.0 | Draft، والدمج بيد الـ Lead |
| `COMPOSER_PACKAGE_STANDARD.md` | 5.0.0 | حدود الـ consumer (`check-consumer-verification-boundary.sh`) |

## 9. أحكام الـ Lead (PR #175، على LT-173-01..06)

**قبول كما اقتُرح.** تحقق الـ Lead بنفسه من النقطة المفتاحية: سكربت الـ bounded snapshot يحكم الانتهاء بساعة المتصل منذ #158، فتعليق 5-c في L1106–L1107 لم يعد يصف المتجر.

* **01 مقبول:**
  * S1 يتحقق من (د) أولًا: `verify.php` وكتلة الـ admission على ساعة ثابتة، فلا `invalid snapshot` ولا أي استثناء في CVH-1…CVH-6، والملف كله ينجح.
  * إن تحقق: الشكل (أ)، مع **استبدال** تعليق L1106–L1107 بالسبب الحالي (الانتهاء بساعة المتصل منذ #158، والساعة الثابتة تجعل نوافذ 600 ث مستقلة عن الحائط).
  * إن لم يتحقق: توقف وإبلاغ (finding على عقد #158)، ثم (ب).
* **02 نعم:** «بنفس الساعة» في (ب) = الساعة الثابتة **بلا تقدّم** عند كل موضع حدّ مسجَّل. الخطوة الصريحة ثانيتين عبر الحدّ تعيد الظاهرة بحكم العقد، وهي إعادة الإنتاج (أ)، لا فشل لـ (ب).
* **03 مقبول كاملًا:**
  * ساعة admission واحدة مسمّاة لكتلة CVH-1…CVH-6 كلها؛
  * `$mint` يأخذ `iat`/`exp` منها بدل `time()`؛
  * CVH-1 ينتقل أيضًا.
  * **قاعدة إضافية:** الساعة تتقدم فقط حيث يحتاج فحصٌ زمنًا، صراحةً بـ `setNow`، وكل خطوة بتعليق سببها. لا `sleep` في هذه الكتلة.
* **04 مقبول:**
  * `consumer-verification/reproduce-cvh2-window.php` داخل مشروع الـ consumer المثبَّت، عبر وضع اختياري في `run-single-consumer-verification.sh`؛
  * يُشغَّل في S1 (أحمر: الظاهرة 20/20) وفي S3 (أخضر عند المواضع الخمسة)؛
  * **لا** يُضاف إلى CI العادي، و`check-consumer-verification-boundary.sh` يبقى أخضر.
* **05:** يُحذف `$clock` الميت في L406، وتُستعمل ساعة admission مسمّاة.
* **06:** لا مدخل CHANGELOG (harness فقط، سابقة `a46701b`)، مع ذكر السابقة والسبب في وصف الـ PR.

**الترتيب:**
1. S1: إعادة الإنتاج الأحمر، وفحص (د).
2. S2: `verify.php` فقط.
3. S3: إعادة الإنتاج أخضر عند كل المواضع، والـ harness مرتين متوازيتين محليًا كما في CI.
4. وصف الـ PR بالـ Standards.

## 10. النتائج (E-LOCAL، مقاسة)

| التنبؤ | المرصود | يتحقق |
| --- | --- | --- |
| **(أ)** إعادة الإنتاج: `CVH2_REPRO_MODE=crossing`، البدء عند `boundary − 1 s` (boundary 1793311800)، وتقدّم 2 ث بعد الإجازة الثانية عشرة | الخامس والعشرون **مقبول** ALLOW **20/20**، وكل تسلسل على نافذتين | نعم (S1، `b92a0b4`) |
| **(ب)** `CVH2_REPRO_MODE=positions`، الساعة ثابتة بلا تقدّم | رفض SOFT_BLOCK مستوى 1 `source_capacity` / `source` **20/20 لكل موضع**: `boundary − 1`، و`boundary`، و`boundary + 1`، و`boundary + 599`، و`boundary + 579` (عشوائي)؛ 100/100 على نافذة واحدة | نعم |
| **(د)** `verify.php` بكتلة الـ admission على `$admissionClock` الثابتة | لا `invalid snapshot` ولا استثناء؛ CVH-1 متطابق (المتغير بسياج مغلق = الـ preset) | نعم |
| **(ج)** `scripts/ci/run-consumer-verification.sh` كاملًا، تشغيلان متوازيان كما في CI | run #1 وrun #2: `verification: PASS` و`cleanup: PASS` (22:02:29Z–22:07:44Z، 2026-10-29) | نعم محليًا؛ E-CI بعد الـ push |

* `check-consumer-verification-boundary.sh` أخضر بعد S1 وبعد S2.
* سكربت إعادة الإنتاج لا يختبر `verify.php` نفسه، فهو يبني runtime خاصًّا به:
  * (أ) يثبت الآلية: العبور يقسم العدّ؛
  * (ب) يثبت أن الساعة الثابتة تزيلها عند أي موضع، وهو الشكل الذي طُبِّق في S2؛
  * (ج) يثبت الإصلاح في `verify.php` نفسه.
