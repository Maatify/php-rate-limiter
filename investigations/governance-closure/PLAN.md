# #181 — بند الإغلاق 5: تنظيف الحوكمة قبل الإصدار — PLAN (S0)

> **الحالة:** PLAN (S0، `5326c70`)؛ أحكام الـ Lead على §6 مسجَّلة في §10، وS1 مأذون.
> **الأساس:** `main@0193b7905653140a42371c06a8443f870e8d371b`، بعد دمج #179. الفرع `claude/governance-closure-181-s0`.
> **المصدر:** وصف #181 وأول تعليق فيه (تكليف S0–S2، 2026-11-02T11:00:44Z).
> **القواعد:**
> * Draft بالعربية، والدمج والإغلاق بيد الـ Lead، و`check-whitespace.sh` قبل كل push.
> * أي فشل CI يُبلَّغ، ثم كلمة الـ Owner قبل أي إعادة.
> * لا تغيير في `src/` أو `tests/` أو أي DEC ACTIVE، ولا تعديل في التقارير الأصلية؛ ملاحق مؤرَّخة فقط.
> * لا رقم بلا مصدره: كل رقم هنا قُرئ من GitHub أو git أو ملف مُلتزَم، والأمر أو الموضع مذكور معه. التنبؤات في §3 و§4 مسجَّلة **قبل** أي تشغيل.

## 1. الجرد المستقل (2026-11-02)

### 1.1 الأوامر

* `gh issue list --state open --limit 100 --json number,title,updatedAt,comments`
* لكل issue: `gh issue view <n> --json number,title,createdAt,updatedAt,author,body,comments,labels`، وآخر تعليق `comments[-1]`.
* لـ #98: `gh api repos/Maatify/php-rate-limiter/issues/98/timeline --paginate` (أحداث الإغلاق وإعادة الفتح).

### 1.2 المفتوح على GitHub

`gh issue list --state open` أعاد **10** issues: #181 نفسه، والتسع أدناه.

| # | الموضوع | أُنشئ | التعليقات | آخر تعليق | في جدول الـ Lead |
| --- | --- | --- | --- | --- | --- |
| #97 | UA-only LOW-confidence وcross-IP HARD_BLOCK للدخول الشرعي | 2026-10-11 | 34 | [2026-10-16T20:48:30Z](https://github.com/Maatify/php-rate-limiter/issues/97#issuecomment-6224411578): Draft PR #126 | **لا** |
| #98 | تمديد Retry-After لـ API_OVERUSE على K2 مشترك | 2026-10-11 | 2 | [2026-10-13T08:43:20Z](https://github.com/Maatify/php-rate-limiter/issues/98#issuecomment-6141763100): إعادة الفتح | نعم |
| #100 | churn correlation وHARD_BLOCK متكرر لعملاء غير مرتبطين خلف IP + UA واحد | 2026-10-12 | 40 | [2026-10-14T06:56:23Z](https://github.com/Maatify/php-rate-limiter/issues/100#issuecomment-6162741957): مقارنة OAAA بعد #116 | **لا** |
| #106 | K1 credential-spray يحجب مستخدمين شرعيين خلف NAT | 2026-10-12 | 14 | [2026-10-14T06:56:27Z](https://github.com/Maatify/php-rate-limiter/issues/106#issuecomment-6162742773): مقارنة OAAA بعد #116 | **لا** |
| #110 | إطار A–H لخطط NAT | 2026-10-13 | 54 | [2026-10-16T08:40:14Z](https://github.com/Maatify/php-rate-limiter/issues/110#issuecomment-6211589576): ربط F-AKDR-01 بـ #120 | نعم |
| #120 | F-AKDR-01: حدود K2 للجلسات الموثوقة تتقسم بـ Browser Major | 2026-10-16 | 0 | — | نعم |
| #121 | تضاؤل الدرجات يُسقط الجزء الكسري عند كل كتابة | 2026-10-16 | 0 | — | نعم |
| #122 | مظلة أرشيف 11 تصميمًا + `COMPARISON.md` | 2026-10-16 | 0 | — | نعم |
| #180 | rc.3 المنشور قبل إصلاح الساعة (#158) | 2026-11-01 | 0 | — | نعم |

**finding S0** (ليس issue): `investigations/trusted-multi-session-s0/REPORT.md` L74–L75، §3.3: الشريحة الملتزمة بالعقد من `TMXb`، أي جلسة موثوقة واحدة على حسابها `vic0`، «25/25 reached, 0 denied, over 72 s». الرقم في `expected/summary.md` §6b. السطر يطابق مرجع الـ Lead (L75).

### 1.3 الفروق مع جدول الـ Lead

* **D-181-01: ثلاث issues مفتوحة ليست في الجدول، هي #97 و#100 و#106.**
  * آخر تعليق في كلٍّ منها ينص على بقائها مفتوحة.
  * #97 ينتظر الخطوات الخمس، وقد أُغلقت (§2.1).
  * #100 و#106 يبقيهما الـ Lead مفتوحتين تحت DEC-019، و«لا إصلاح Runtime».
  * هدف #181 «كل issue مفتوحة»، فالحكم في LT-181-06.
* **D-181-02: سجل الحسم المفتوح في نص #110 فيه findings معلَّقة ليست في الجدول.**
  * **F-HMCI-01..04:** «OPEN FOR EXPLICIT DISPOSITION»، ومصدرها `investigations/host-managed-client-identity-110/REPORT.md` L111–L114.
  * **Q-P1..Q-P5:** «OPEN DESIGN / OWNER QUESTIONS»، ومصدرها `investigations/account-scoped-known-device-relief-110/REPORT.md` L116–L124.
  * الحكم في LT-181-06.
* **D-181-03: نصوص #110 و#120 تقول «DEC-019 / DEC-021 ACTIVE»، وهذا لم يعد صحيحًا على `main`.**
  * `docs/decisions/DECISIONS_INDEX.md` يجعلهما **SUPERSEDED**: DEC-021 بـ DEC-026، وDEC-019 بـ DEC-027، كلاهما في PR #150 بتاريخ 2026-10-20 (DEC-021 §Status L12).
  * نص المصالحة يصحّح ذلك (§5). وتعديل نص الـ issue نفسه سؤال LT-181-09.
* **D-181-04 (#121): سطور الجدول.**
  * الـ Lead ذكر `DECISION_MATRIX.md` §7 L779–L780، وهي فعلًا سطرا Device وIP. سطر Account في L778.
  * السطر المقابل في `docs/POLICIES.md` هو L405 («IP score: −1 every 3 minutes»).
* **D-181-05 (#180): عدد الـ commits.**
  * نص #180 يقول «67 commit» بتاريخ 2026-11-01.
  * `git rev-list --count v1.0.0-rc.3..0193b79` يعطي **68**؛ الفرق دمج #179.
  * `git tag --contains c8b8fd7` فارغ، فلا وسم يحوي إصلاح #158.
* **D-181-06 (#122): جدول الفهرس في نص #122 بلا عمود «Original verification».**
  * `README.md` L12–L24 و`COMPARISON.md` فيهما العمود.
  * البصمات والحالات ستُقارن في M-122.
* **D-181-07 (finding S0): خط الأساس.**
  * S0 قيس على `ce89cb2`، **قبل** DEC-021.
  * AKDR قاس `TMXb` على `ebeb0a3`، **بعد** DEC-021: «TMXb 300 → 48» في `pre+out` (`account-scoped-known-device-relief-110/COMPARATIVE_ANALYSIS.md` L19–L24).
  * لم يُصالَح ذلك في S0 REPORT؛ يُصالَح في M-S0.
* **مطابق بلا فرق:**
  * #110: 54 تعليقًا، آخرها في 2026-10-16.
  * #98: أُعيد فتحه في 2026-10-13T08:42:34Z (timeline).
  * #120: TMSd 2/200 مقابل TMUA 36/200 على `e916383`.
  * #180: rc.3 بتاريخ 2026-10-07، والإصلاح على `main` منذ `c8b8fd7`.

## 2. ما تغيّر على `main` منذ آخر حالة مسجَّلة (قراءة، لا قياس)

### 2.1 القرارات

من `docs/decisions/DECISIONS_INDEX.md` عند `0193b79`:

* **DEC-022..DEC-029 ACTIVE.**
* **DEC-019 → DEC-027:** الوضع الأمني نفسه للسياسات **غير** المعتمِدة للـ admission.
* **DEC-021 → DEC-026:** حدّ الـ verifier للجهاز المعروف، منقول حرفيًا لكل سياسة، مع أربعة فروق للمعتمِدة.
* **5-c:** commit `1e951ee` (#150) فعّل الـ admission.
* **الـ admission opt-in:**
  * `src/Config/AdmissionReservationPolicyInterface.php` L15: «`LoginProtectionAdmissionPolicy` is the official policy that implements it; no default preset does».
  * فـ `LoginProtectionPolicy` و`OtpProtectionPolicy` و`ApiHeavyProtectionPolicy` على مسار DEC-027.

### 2.2 الكود الذي تمسّه القياسات

* **`src/Service/DecayCalculator.php` (#121):** لم يتغير منذ `5e40583` (#36). `git log --oneline -- src/Service/DecayCalculator.php` يعطي commit واحدًا.
  * `calculateDecay()` L45–L51: `floor(max(0, (now − lastUpdate) − paused) / interval)`.
* **كتابة الدرجة (#121، #98):**
  * `EvaluationPipeline.php` L1729–L1760: `netChange = (decayed + delta) − base`، ثم `CallerTimeStore::increment`.
  * يُعاد ضبط `updatedAt = now` عند كل كتابة في الـ Lua في `RedisFullCapabilityStore.php` L162، وفي `InMemoryRateLimitStore.php` L75 (store المرجع).
  * المسار نفسه كما وصفه #121 على rc.3، مع تغيير واحد: الوقت صار وقت المتصل (DEC-016، `c8b8fd7`).
* **الـ harnesses القديمة (#120، S0):**
  * الـ scenarios تبني `EvaluationPipeline` مباشرة (`account-scoped-known-device-relief-110/lib/akdr.php` L294–L305).
  * الـ constructor لم يتغير (`git show ebeb0a3:src/Service/EvaluationPipeline.php` L98–L108 مقابل `main` L103–L113).
  * الـ pipeline يمرّ الآن عبر `CallerTimeStore::*` و`checkBlockAt` وما يشبهها، والـ stores المرجعية تحيلها إلى الطرق القديمة التي تتجاوزها decorators الدراسة (`InMemoryRateLimitStore.php` L543–L546، `StatefulInMemoryCorrelationStore.php` L594–L632). فالـ decorators تبقى في المسار، بالقراءة لا بالتشغيل.
  * `git diff --shortstat ebeb0a3 0193b79 -- src` يعطي 56 ملفًا، و`ce89cb2..0193b79` يعطي 59.

### 2.3 بوابات الأدوات القديمة (تمنع تشغيلها كما هي على `main`)

| الأداة | البوابة | الموضع |
| --- | --- | --- |
| AKDR `run.sh` | `git diff --name-only ebeb0a3 -- src tests docs composer.json composer.lock` غير فارغ، فـ exit 4 | `run.sh` L24، L36–L38 |
| AKDR scenarios | sha256 لـ `EvaluationPipeline` و`DeviceIdentityResolver` و`EphemeralBucket` مقابل ثوابت | `lib/akdr.php` L46–L50، L93–L99 |
| S0 `run.sh` | `git diff --name-only ce89cb2 -- src composer.json composer.lock` غير فارغ، فـ exit 4 | `run.sh` L25، L45–L47 |
| S0 scenarios | sha256 لـ `EvaluationPipeline` مقابل `4b259d33…` | `lib/tms.php` L16–L17، L39–L42 |
| S0 self-test `forged_pipeline_sha256` | يستبدل البصمة القديمة نصًّا | `run.sh` L178 |

## 3. القياسات المقترحة (S1، بعد الأحكام فقط)

**قواعد مشتركة:**
* كل قياس على `main@0193b79`، أو على SHA يحدده الحكم، مسجَّلًا.
* يُحفظ الناتج الخام، والأمر، ورمز الخروج، و`php -v` و`composer show` المختصرة، وsha256 كل ملف ناتج، تحت `investigations/governance-closure/results/<M-id>/`.
* الشكل في LT-181-08.

### M-120 — سيناريو AKDR على `main` (#120)

* **الأداة:** `investigations/account-scoped-known-device-relief-110/run.sh` كاملًا: V01 وO01 وB01، و`report.php`، و24 self-test، و3 فحوص للـ label.
* **الأمر كما هو:** `bash investigations/account-scoped-known-device-relief-110/run.sh`.
* **التشغيل على `main` يحتاج قرارًا (LT-181-01)** لأن البوابة في §2.3 ترفضه. الخيار الذي أقترحه:
  * worktree مؤقت عند `0193b79` خارج المستودع المُلتزَم؛
  * فيه patch يمسّ **ثوابت الـ provenance وحدها**: `run.sh` L24، و`lib/akdr.php` L46 وL48–L50 إلى `0193b79` وبصمات الملفات الثلاثة عليه؛
  * لا سطر آخر، والـ patch يُحفظ كما هو في `results/M-120/provenance.patch`؛
  * والمنهج والـ streams والـ expected/ كما هي.
* **إن بقي الادعاء:** TMUA أعلى من TMSd في `pre+out`.
* **إن زال:** TMUA ≈ TMSd.
* **التنبؤ المسجَّل:**
  1. **كما هو، بلا patch:** exit 4 عند `baseline_identical`، قبل أي scenario.
  2. **بالـ patch:**
     * **(أ)** يخرج `run.sh` بـ 1؛ والفرق مع `expected/` محصور في صفوف الـ provenance، صف واحد في كل من `v01`/`o01`/`b01` وسطر واحد في `summary.md` (`grep -c` للبصمات: 1 لكلٍّ).
     * **(ب)** بعد حذف صفوف الـ provenance: V01 وO01 وB01 مطابقة **بايتًا ببايت** لـ `expected/`.
     * **(ج)** MAIN: TMSd **2/200** وTMUA **36/200** في `pre+out`، وفي `pre` كذلك، وأقصى حساب 3.
     * **(د)** `report.php`: كل الملاحظات REPRODUCED.
     * **(هـ)** الـ self-tests الـ 27 تمر.
  * **السبب:**
    * `LoginProtectionPolicy` لا يعتمد الـ admission؛
    * DEC-027 يحمل DEC-019 حرفيًا، وDEC-026 يحمل DEC-021 حرفيًا لغير المعتمِدة؛
    * والتغييرات منذ `ebeb0a3` على هذا المسار سباكة وقت المتصل (#153) ودورة K4 (#160، #174)، ولا يُتوقع أن تغيّر قرارًا في streams حتمية بساعة مضبوطة.
  * **ما يكذّب التنبؤ:** أي صف قرار مختلف. يُسجَّل finding بلا ترقيع، ثم يُفحص سببه.
* **المنهج لا يقيس سياسة الـ admission** (`LoginProtectionAdmissionPolicy` عبر `RateLimiterEngine`)، لأن الـ harness يستدعي الـ pipeline مباشرة. إضافة ذلك ذراع جديد، لا «السيناريو نفسه»: LT-181-03.

### M-S0 — `trusted-multi-session-s0/run.sh` كاملًا (finding S0)

* **الأداة:** `run.sh` كاملًا: t01 وt02، و`report.php` والبوابات، والـ self-tests (4 للـ label، وكشف الربط المكسور، و`violated_assertion`، و8 fail-closed).
* **التشغيل على `main`:** البوابة نفسها (§2.3)، فالخيار نفسه في LT-181-01. الـ patch يمسّ `run.sh` L25 وL178، و`lib/tms.php` L16–L17.
* **إن بقي الادعاء:** شريحة `vic0` 25/25 بلا رفض.
* **إن زال:** رفض داخل الشريحة.
* **التنبؤ المسجَّل:**
  1. **كما هو:** exit 4 عند `baseline_src_identical`.
  2. **بالـ patch:**
     * **(أ)** يخرج `run.sh` بـ 1، لأن `expected/` من قبل DEC-021.
     * **(ب)** صفوف t01 المختلفة هي Login `pre+out` لـ TMSs وTMX وTMXb، بالأرقام التي قاسها AKDR [MAIN] على `ebeb0a3`: **TMSs 18 → 5، وTMX 200 → 60، وTMXb 300 → 48** (`COMPARATIVE_ANALYSIS.md` L19–L22). يُضاف إليها صف الـ provenance، وصفوف OTP `pre+out` للثلاثة (اتجاه النقص متوقع، و**الرقم غير متنبَّأ به**).
     * **(ج)** صفوف `pre` و`out` كلها، وN12T 56/61، وTMSd 2/200، وSP5 200/200: بلا تغيير.
     * **(د)** t02: صفوف TMSs وTMX ضمن streams الأثر (`lib/tms.php` L62) تتغير في `pre+out` فقط.
     * **(هـ)** التأكيدات الـ 11 في t01 صحيحة، فـ `report.php` بلا exit 6 والبوابات PASS. `gates.jsonl` قد يختلف نصًّا حيث يذكر أرقامًا.
     * **(و)** الـ self-tests تمر.
  * **شريحة `vic0` (§6b):** **reached ≤ 5 من 25**، ورفض واحد على الأقل داخل الـ 72 ث.
    * المصدر: حدّ DEC-021 «about 5 Login verifier reaches per account per 600 s» (`COMPARATIVE_ANALYSIS.md` L24)، وKS1 MAIN 5/200 في `pre+out` (`expected/summary.md` L54).
    * الرقم الدقيق غير متنبَّأ به.
  * **في `pre` (check-only):** يبقى بلا حدّ، لأن DEC-021 لا يتعلم من check-only (`COMPARATIVE_ANALYSIS.md` L27–L28).
* **حالة الـ finding المتوقعة:** «مقيَّد في `pre+out` منذ DEC-021، وقائم في `pre`» للسياسة غير المعتمِدة. والـ admission (DEC-023/024) هو ما يخص `pre` للمعتمِدة، وهو خارج هذا المنهج (LT-181-03).

### M-121 — اختبار أحمر صغير للتضاؤل (#121)

* **المكان:** `investigations/governance-closure/decay-121/`، **لا** `tests/`. `phpunit.xml` في الجذر يجمع `tests/Unit` و`tests/Integration` و`tests/System` فقط (L13–L19)، فلا يدخل CI.
* **الشكل المقترح (LT-181-04):**
  * ملف PHPUnit واحد بـ `phpunit.xml` خاص في المجلد، أو سكربت PHP يخرج بغير صفر عند الفشل؛
  * عبر الـ API العامة: `RateLimiterBuilder` + `withClock()` بساعة مضبوطة؛
  * سياسة `ApiHeavyProtectionPolicy` فرعية بعتبات لا يُحجب عندها؛
  * قراءة الدرجة بـ `buildOperationalReader()->readScorePolicy(...)`، كما في نص #121.
* **الحالتان (من نص #121)، K1 بفاصل IP 180 ث:**
  * **التحكم:** ستة `recordSuccess` عند t = 0، ثم قراءة عند t = 500.
  * **المختبَرة:** ستة عند t = 0، 100، …، 500، ثم قراءة عند t = 500.
* **التأكيد «الأحمر» (العقد كما وُثِّق):** الدرجة الفعلية عند t = 500 = 6 − floor(500 / 180) = **4** في الحالتين.
* **إن بقي الادعاء:** التحكم = 4 (أخضر)، والمختبَرة = **6** (أحمر).
* **إن زال:** المختبَرة = 4.
* **التنبؤ المسجَّل:** **أحمر**: التحكم 4 والمختبَرة 6، على الـ store المرجعي.
  * **السبب:** `DecayCalculator` لم يتغير منذ #36، و`updatedAt = now` عند كل كتابة في الـ store المرجعي (L75) والـ Lua (L162).
  * **ذراع Redis حقيقي** (إن حُكم به): التنبؤ نفسه. Redis 7.0.15 من `docker compose` المستودع، و`FLUSHALL` قبل كل تشغيل، والساعة ساعة المتصل (DEC-016).
* **ما بعده (ليس هنا):** الخياران في #121، تصحيح الوثائق أو تغيير السلوك (البند 6).

### M-98 — السيناريو نفسه (#98)

* **المصدر:** نص #98. لا سكربت مُلتزَم له، فالسيناريو يُبنى من النص، ومعاملاته تُسجَّل قبل التشغيل (LT-181-05):
  * سياسة `ApiHeavyProtectionPolicy` فرعية بعتبة K2 منخفضة، والمقترح 3، وK1/K3 بقيمها؛
  * IP وUA ثابتان؛
  * `recordSuccess(policyName)` متتالية في ثانية واحدة من ساعة مضبوطة؛
  * تسجيل `decision` و`retryAfter` لكل طلب.
* **المكان:** `investigations/governance-closure/retry-after-98/`.
* **إن بقي الادعاء:** كل طلب مرفوض يزيد `retryAfter` بفاصل التضاؤل.
* **إن زال:** `retryAfter` ثابت أو محدود.
* **التنبؤ المسجَّل:**
  * أول `SOFT_BLOCK` بـ `retryAfter` = **180**، ثم **360**، ثم **540**، بلا حدّ أعلى.
  * **السبب:**
    * K2 في `ApiHeavyProtectionPolicy` بـ L1 فقط (L67)، وفاصل نطاق IP 180 ث؛
    * الوصول يضيف 1 حتى مع `SOFT_BLOCK` (رد الـ Lead في #98)؛
    * ولا admission لهذه السياسة (DEC-027).
  * التنبؤ لا يعتمد على قيمة العتبة ما دامت الطلبات في الثانية نفسها.
  * **ذراع Redis حقيقي** (إن حُكم به): القيم نفسها بالساعة المضبوطة.

### M-122 — فهرس التصاميم مقابل الملفات (#122)

* **الأمر:** `shasum -a 256` وعدد الأسطر والأحرف وبايتات UTF-8 لكل ملف من `investigations/nat-architecture-proposals-110/proposals/*.md`.
* **يُقارن مقابل:** `README.md` L14–L24، وجدول `COMPARISON.md`، وجدول نص #122.
* **التنبؤ المسجَّل:** 11/11 مطابقة في البصمة والأسطر والأحرف، والحالة `UNREVIEWED / VERBATIM` في المصادر الثلاثة.
  * **السبب:** آخر commit مسّ المجلد هو `9e95f2f` (#124)، و`git log 4fde508..0193b79 -- …/proposals` فارغ.
  * والـ COMPARISON «draft for engineering review» بلا مراجعة مسجَّلة بعده.

### M-180 — الفجوة بين الوسوم و`main` (#180)

* **الأوامر:**
  * `git tag --contains c8b8fd7`؛
  * `git tag --contains eea9d1a` (#171)؛
  * `git rev-list --count v1.0.0-rc.3..<main>`؛
  * و`gh release list`.
* **التنبؤ المسجَّل:** لا وسم يحوي أيًّا منهما. هذا قيس بالقراءة في §1.3 لـ `c8b8fd7`؛ يُعاد في S1 بالسجل.
* **لا قياس سلوك:** إعادة إنتاج #180 على rc.3 خارج النطاق، لأن الـ issue تتبُّع لا عيب على `main`.

### M-110 — مصالحة وثائقية (#110، ومعه #97/#100/#106 إن حُكم بها)

* **لا أداة تُشغَّل.** جدول من المصادر المُلتزَمة:
  * سجل الخطط في نص #110 مقابل حالة كل خطة؛
  * DEC-022..029 من `DECISIONS_INDEX.md`؛
  * خطوات #97 الخمس من `investigations/k2-identity-admission-97/`؛
  * والـ findings المعلَّقة (D-181-02).
* كل صف بمصدره (ملف:سطر أو رابط تعليق).

## 4. التنبؤات مجمَّعة (قبل أي تشغيل)

| القياس | التنبؤ | يكذّبه |
| --- | --- | --- |
| M-120 كما هو | exit 4، `baseline_identical` | أي تشغيل لـ scenario |
| M-120 بالـ patch | الفرق صفوف provenance فقط؛ TMSd 2/200، TMUA 36/200 (`pre+out` و`pre`) | أي صف قرار مختلف |
| M-S0 كما هو | exit 4، `baseline_src_identical` | أي تشغيل لـ scenario |
| M-S0 بالـ patch | Login `pre+out`: TMSs 5، TMX 60، TMXb 48؛ `pre`/`out` بلا تغيير؛ `vic0` ≤ 5/25 | TMXb ≠ 48، أو `vic0` > 5، أو تغيّر صف `pre`/`out` |
| M-121 | أحمر: التحكم 4، المختبَرة 6 | المختبَرة = 4 |
| M-98 | 180 ← 360 ← 540، بلا حدّ | أي قيمة أخرى، أو ثبات |
| M-122 | 11/11 مطابقة | أي بصمة مختلفة |
| M-180 | لا وسم يحوي `c8b8fd7` ولا `eea9d1a` | وسم يحويهما |

## 5. نصوص المصالحة المقترحة (تُكمَل بنتائج S1، ويُنشرها الـ Lead)

كل نص بالبنية نفسها: **ما كانت، وما تغيّر، وما قيس، والخيارات.** القوسان «[S1]» مكان النتيجة المقاسة، ولا يُنشر نص قبل ملئها.

**#120 — F-AKDR-01**
> **ما كانت:** على `e916383`، تدوير 20 Browser Major مع 12 جلسة موثوقة مربوطة بحساباتها رفع وصول الـ verifier من 2/200 (TMSd) إلى 36/200 (TMUA). التصنيف لم يُحسم.
> **ما تغيّر:** DEC-019 وDEC-021 صارا SUPERSEDED بـ DEC-027 وDEC-026 (#150)، والـ admission صار متاحًا كسياسة معتمِدة (`LoginProtectionAdmissionPolicy`). السياسة الافتراضية `LoginProtectionPolicy` بقيت على مسار DEC-027.
> **ما قيس:** [S1: M-120 على `main@…`: TMSd …/200، TMUA …/200، والمطابقة مع `expected/`].
> **الخيارات:** (أ) مقايضة مقصودة مقبولة بحدود مذكورة للسياسة غير المعتمِدة، والمعتمِدة تقاس منفصلة (LT-181-03)؛ (ب) عيب يُحال إلى البند 6؛ (ج) دليل ناقص بتحقيق محدَّد. القرار للـ Owner.

**finding S0 — 25/25 لجلسة موثوقة واحدة على حسابها**
> **ما كانت:** على `ce89cb2` (قبل DEC-021)، 25/25 بلا رفض في 72 ث.
> **ما تغيّر:** DEC-021، ثم DEC-026، أضافا حدّ K5 للجهاز المعروف (Login K5 8/12/16، `k5_failure` = 2). AKDR قاس TMXb `pre+out` 300 → 48 على `ebeb0a3`.
> **ما قيس:** [S1: M-S0 على `main@…`: `vic0` …/25 في `pre+out`، و… في `pre`].
> **الخيارات:** إغلاق الـ finding لـ `pre+out` بالدليل، وإبقاء `pre` مسجَّلًا خاصية معروفة لـ check-only في غير المعتمِدة (أو إحالته إلى البند 6). يُضاف ملحق مؤرَّخ إلى S0 REPORT (LT-181-07).

**#121 — التضاؤل**
> **ما كانت:** سؤال عقدي: الوثائق تعد بمعدّل (−1 كل 3/5/10 د)، والتنفيذ يُسقط الجزء الكسري عند كل كتابة.
> **ما تغيّر:** لا شيء في `DecayCalculator` منذ #36. الكتابة انتقلت إلى وقت المتصل (DEC-016) دون تغيير `updatedAt = now`.
> **ما قيس:** [S1: M-121: التحكم …، المختبَرة …].
> **الخيارات:** (أ) تصحيح الوثائق إلى «−1 لكل فجوة خمول بطول الفاصل» (docs، بلا سلوك)؛ (ب) تغيير السلوك (`updatedAt += n × interval`)، فيُحال إلى البند 6 مع أسئلة #121 عن الـ pause وL2+.

**#98 — Retry-After على K2 مشترك**
> **ما كانت:** 180 → 360 → 540 بكل طلب مرفوض، وجواب الـ Lead (2026-10-13) أن هذا العقد الحالي بلا حدّ أعلى، ثم أُعيد الفتح لأن الشرح لا يحسم خطر الحرمان المشترك.
> **ما تغيّر:** `ApiHeavyProtectionPolicy` لا تعتمد الـ admission، فهي على DEC-027. لا تغيير على مسار الدرجة.
> **ما قيس:** [S1: M-98: …].
> **الخيارات:** (أ) إبقاء العقد مع توثيق صريح لغياب الحدّ وإرشاد المستهلك (docs)؛ (ب) حدّ أو تخطٍّ مقيَّد، وهو تغيير سياسة أمنية، فيُحال إلى البند 6. يبقى «آخر أولوية» كما في نص #110 ما لم يحكم الـ Owner بغيره.

**#122 — مظلة التصاميم**
> **ما كانت:** 11 تصميمًا محفوظة حرفيًا، و`COMPARISON.md` مسودة للمراجعة.
> **ما تغيّر:** المسار المعتمد لـ #97 صار الـ admission (DEC-022..028)، فالتصاميم الـ 11 أرشيف مقارنة لا مرشحات.
> **ما قيس:** [S1: M-122: …/11 مطابقة].
> **الخيارات:** (أ) إغلاق كأرشيف مكتمل بالدليل؛ (ب) إبقاء مفتوح إن أراد الـ Owner مراجعة `COMPARISON.md` قبل الإصدار.

**#180 — rc.3 قبل إصلاح الساعة**
> **ما كانت:** المستهلك على rc.3 يصيبه «invalid snapshot»، والإصلاح على `main` منذ `c8b8fd7`.
> **ما تغيّر:** #171 وغيره دخلا `main`؛ والفجوة 68 commit عند `0193b79`.
> **ما قيس:** [S1: M-180].
> **الخيار:** يبقى مفتوحًا حتى أول وسم يحوي `c8b8fd7` و`eea9d1a`، ويُذكر في CHANGELOG/ملاحظات الإصدار، ثم يُغلق بذلك الوسم. بوابة الإصدار في #110 (قرار الـ Owner 2026-10-15) تسبق الوسم.

**#110 — إطار A–H**
> **ما كانت:** إطار مراجعة موحّد لخطط NAT، وسجل خطط، وسجل حسم مفتوح، وبوابة إصدار ملزمة.
> **ما تغيّر:** الخطوات الخمس لـ #97 أُغلقت بالـ admission (DEC-022..028)، وDEC-029، وإصلاحات التذبذب (#158 … #175)، ودليل الاستقرار (#176).
> **ما قيس:** [S1: M-110، جدول لكل خطة وfinding بحالته على `main@…`].
> **الخيارات:** (أ) تعليق ختامي، والإغلاق بعد حسم #120 وF-HMCI وQ-P (البند 6)؛ (ب) الإبقاء مظلةً حتى الإصدار.

**#97 / #100 / #106 (إن حكم LT-181-06 بإدخالها)**
> **#97:** الخطوات الخمس منفّذة، والسؤال الأصلي (LOW UA-only وcross-IP HARD_BLOCK) مجاب للسياسة المعتمِدة. الخيار: إغلاق بالدليل، أو إبقاء لغير المعتمِدة.
> **#100 و#106:** للسياسات غير المعتمِدة، الوضع DEC-027، أي DEC-019 حرفيًا. الضرر الجماعي قائم بالتصميم هناك. الخيار: إبقاء بسبب مسجَّل، أو إحالة إلى البند 6.

## 6. أسئلة للـ Lead قبل البناء (LT-181-xx)

* **LT-181-01: تشغيل الأدوات المثبَّتة على `main` (M-120، M-S0).**
  * الأداتان ترفضان `main` بالتصميم (§2.3). الخيارات:
    * **(1) worktree مؤقت + patch للـ provenance وحده** (مقترح). الـ patch يُحفظ، وكل سطر غير الـ provenance ممنوع، والأصل في المستودع لا يُمس.
    * **(2) تشغيلهما كما هما:** النتيجة exit 4 (تنبؤ §4)، وتُسجَّل finding «الأداة لا تقيس `main`»، فلا قياس للادعاء.
    * **(3) نسخ مُلتزَمة تحت `governance-closure/replay/`:** تحتاج تعديل مسارات `dirname(__DIR__, 3)` (`lib/tms.php` L30، و`require` في `lib/akdr.php` L24)، أي أكثر من الـ provenance.
* **LT-181-02: معيار «المطابقة» في M-120/M-S0.**
  * أقترح: صفوف القرار مطابقة بايتًا ببايت بعد حذف صفوف الـ provenance فقط.
  * ولـ S0 مقارنة إضافية بأرقام AKDR [MAIN] حيث تتقاطع (TMSs/TMX/TMXb/TMSd/N12T)، لأن `expected/` الخاص به من قبل DEC-021.
* **LT-181-03: ذراع الـ admission لـ #120 وS0.**
  * هل يُقاس `LoginProtectionAdmissionPolicy` عبر `RateLimiterEngine` على streams TMSd/TMUA/TMXb؟ هذا بناء جديد، لا «السيناريو نفسه».
  * اقتراحي: خارج #181، ويُسجَّل خيارًا للبند 6.
* **LT-181-04: شكل اختبار #121.**
  * PHPUnit بـ `phpunit.xml` خاص في `decay-121/`، أم سكربت PHP؟
  * store مرجعي بساعة مضبوطة فقط، أم معه Redis حقيقي؟
  * المفتاح K1 (IP، 180 ث) كما في نص #121؟
* **LT-181-05: معاملات #98.**
  * عتبة K2 = 3، والساعة المضبوطة، وعدد الطلبات بعد أول حجب 3 (كجدول #98)، أم أكثر لإظهار غياب الحدّ (مثلًا 10)؟ وذراع Redis؟
* **LT-181-06: نطاق الجرد.**
  * هل تدخل #97 و#100 و#106 (D-181-01) وF-HMCI-01..04 وQ-P1..Q-P5 (D-181-02) في #181 كصفوف مصالحة وثائقية بلا قياس، أم تبقى للبند 6؟
  * اقتراحي: صفوف وثائقية هنا، والحسم في البند 6.
* **LT-181-07: مكان الملاحق والـ provenance.**
  * مجلدا AKDR وS0 لا يثبّتهما أي اختبار. `grep -rn` في `tests/` و`scripts/` و`.github/` لم يجد إشارة إليهما.
  * `investigations/k2-identity-admission-97/` مثبَّت بمانيفست `tests/Support/Admission/Archive/ArchiveData.php` و`DecisionRecordsVerbatimTest`.
  * المقترح: ملحق مؤرَّخ `ADDENDUM_2026-11-<dd>_181.md` في مجلد الدراسة لا يمسّ `expected/` ولا REPORT، ونسخة النتائج في `governance-closure/results/`.
  * هل تُحتسب الملاحق جزءًا من التقارير المحمية؟ ولا ملحق داخل `k2-identity-admission-97/` بلا حكم.
* **LT-181-08: شكل ملف النتائج.**
  * مقترح:
    * `results/<M-id>/` فيه `command.txt` و`stdout.txt` و`exit.txt` و`versions.txt`، والناتج الخام، و`provenance.patch` حيث ينطبق؛
    * و`results/results.jsonl` بسطر لكل ادعاء: `{id, sha, command, exit, predicted, observed, matches_prediction}`؛
    * و`SHA256SUMS` مثل #176.
* **LT-181-09: تصحيح النصوص القديمة.**
  * D-181-03 («DEC-019/021 ACTIVE» في #110 و#120): يكفي التصحيح في تعليق المصالحة، أم يُقترح تعديل نص الـ issue أيضًا؟ التعديل بيد الـ Lead.
* **LT-181-10: ترتيب S1.**
  * مقترح: M-122 وM-180 (قراءة)، ثم M-121 وM-98 (صغيران)، ثم M-120 وM-S0. كل قياس commit وحده بنتيجته.
  * وأي نتيجة تخالف التنبؤ توقف التسلسل وتُبلَّغ قبل التالي.

## 7. ما لا يثبته القياس (للـ REPORT، مسجَّل مسبقًا)

* **M-120 وM-S0:**
  * streams حتمية، وstores مرجعية، وساعة مضبوطة، كالدراستين الأصليتين.
  * لا Redis ولا تزامن ولا IPv6 ولا سياسة الـ admission (LT-181-03).
  * المطابقة تثبت أن قرارات `main` في هذه الـ streams هي قرارات خط الأساس، لا أن الأمن كافٍ.
* **M-121 وM-98:** يثبتان سلوك `main` في سيناريو محدد، لا العقد المقصود. العقد قرار للـ Owner.
* **M-122 وM-180:** قراءة مطابقة، لا مراجعة لمحتوى التصاميم ولا للمستهلكين.

## 8. الترتيب

1. **S0:** هذا الـ PLAN، commit واحد على Draft PR، ثم توقف حتى الأحكام.
2. **S1:** القياسات بترتيب LT-181-10، كلٌّ بنتيجته وملحقه.
3. **S2:** `REPORT.md` بجدول واحد: issue/finding، وما قيس، والنتيجة، والتوصية، ونص المصالحة النهائي.

## 9. مسار AGENTS.md (Adoption Standard §10.1)

Normal Engineering Task: قُرئ `docs/php-engineering-standards/STANDARDS_MANIFEST.md`. الـ Scope `/`، والـ activations `composer-package` و`repository-governance`.

| Standard | الإصدار | لماذا |
| --- | --- | --- |
| `DECISION_GOVERNANCE_STANDARD_AR.md` | 1.0.0 | حالات DEC-019/021/026/027 (D-181-03)، ولا تغيير في DEC ACTIVE |
| `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | 3.1.0 | ملاحق مؤرَّخة بدل تعديل التقارير الأصلية |
| `TESTING_STANDARD.md` | 2.0.0 | اختبار #121 الأحمر خارج `tests/` |
| `AI_COLLABORATION_WORKFLOW_AR.md` | 12.0.0 | |
| `GITHUB_PHASE_STACK_WORKFLOW_AR.md` | 4.0.0 | Draft، والدمج والإغلاق بيد الـ Lead |

## 10. أحكام الـ Lead على LT-181-01..10 (2026-11-02)

**المصدر:** [تعليق الـ Lead على #182، 2026-11-02T17:18:06Z](https://github.com/Maatify/php-rate-limiter/pull/182#issuecomment-6583524660) على PLAN `5326c70`. الفروق D-181-01..07 مقبولة. #97/#100/#106 تُركت عمدًا من جدول الـ Lead لأنها قرار البند 6، وصفوف F-HMCI-01..04 وQ-P1..Q-P5 سهو أقرّ به الـ Lead.

| السؤال | الحكم |
| --- | --- |
| LT-181-01 | **(1)** worktree مؤقت + patch للـ provenance وحده، **بعد** تشغيل كما هو للسجل (تنبؤ: exit 4). الـ patch يمسّ فقط: AKDR `run.sh` L24 و`lib/akdr.php` L46 وL48–L50؛ S0 `run.sh` L25 وL178 و`lib/tms.php` L16–L17، والثوابت الجديدة تُحسب على الـ SHA المقيس. `results/<M-id>/provenance.patch` يحفظه، و`results/<M-id>/patch-scope.txt` يسجّل `git diff --stat` للـ worktree دليلًا على أن لا شيء آخر تغيّر. (2) وحده و(3) مرفوضان. |
| LT-181-02 | **مقبول:** صفوف القرار مطابقة بايتًا ببايت بعد حذف صفوف الـ provenance وحدها؛ ولـ S0 مقارنة إضافية مقابل AKDR [MAIN] حيث تتقاطع الـ streams (TMSs/TMX/TMXb/TMSd/N12T)، لأن `expected/` من قبل DEC-021. |
| LT-181-03 | **ذراع الـ admission خارج #181**، ويُسجَّل في الـ REPORT خيارًا للبند 6 (بناء جديد، لا «السيناريو نفسه»). |
| LT-181-04 | **#121:** سكربت PHP (exit غير صفر عند الفشل) عبر الـ API العامة بـ `withClock()`، **بذراعين**: الـ store المرجعي وRedis حقيقي (كتابة الـ Lua في `RedisFullCapabilityStore.php` L162 هي مسار الإنتاج)، و`FLUSHALL` قبل كل تشغيل؛ K1 IP 180 ث كنص #121؛ التحكم والمختبَرة كما في §3. |
| LT-181-05 | **#98:** عتبة K2 = 3، ساعة ثابتة، IP/UA واحد، **10 طلبات بعد أول حجب**، بالذراعين كـ LT-181-04؛ يُسجَّل `decision` و`retryAfter` لكل طلب. |
| LT-181-06 | **صفوف وثائقية هنا، والقرار في البند 6:** #97 و#100 و#106 وF-HMCI-01..04 وQ-P1..Q-P5، لكلٍّ صف (الحالة على `main`، والمصدر ملف:سطر أو رابط تعليق، والخيارات) بلا قياس. الإغلاق أو الإبقاء قرار الـ Owner في البند 6. |
| LT-181-07 | **الملاحق:** `ADDENDUM_2026-11-<dd>_181.md` داخل مجلدي AKDR وS0، لا مساس بـ `expected/` ولا `REPORT.md`؛ النتائج منسوخة تحت `governance-closure/results/`. **لا ملحق داخل `k2-identity-admission-97/`.** الملاحق ليست جزءًا من التقارير المحمية بالـ provenance. |
| LT-181-08 | **مقبول كما اقتُرح:** `command.txt` و`stdout.txt` و`exit.txt` و`versions.txt` والناتج الخام و`provenance.patch`، و`results.jsonl` بسطر لكل ادعاء بـ `predicted`/`observed`/`matches_prediction`، و`SHA256SUMS`. |
| LT-181-09 | **الاثنان:** التصحيح في تعليق المصالحة، والـ Lead يُلحق قسم «Correction» مؤرَّخًا بنصَّي #110 و#120 (بلا إعادة كتابة الأصل) من نص يصوغه المنفّذ في الـ REPORT. |
| LT-181-10 | **مقبول:** M-122 وM-180 → M-121 وM-98 → M-120 وM-S0؛ commit لكل قياس بنتيجته؛ أي نتيجة تخالف تنبؤها توقف التسلسل وتُبلَّغ قبل التالي. |

**الترتيب المحكوم:** هذا الـ commit → S1 بالترتيب أعلاه → S2 `REPORT.md` بجدول واحد (issue/finding، ما قيس، النتيجة، التوصية، نص المصالحة النهائي) مع صفوف وثائقية لـ #97/#100/#106 وF-HMCI-01..04 وQ-P1..Q-P5. `check-whitespace.sh` قبل كل push؛ أي فشل CI يُبلَّغ ثم كلمة الـ Owner قبل أي إعادة. Draft؛ الـ Lead يراجع، والـ Owner يقرر، والـ Lead يدمج وينشر المصالحات.

## 11. سجل S1 (ملحق مؤرَّخ؛ لا يغيّر §1–§10)

### 11.1 M-122 — 2026-11-02، على شجرة مطابقة لـ `main@0193b79` في `src`/`tests`/`composer.*` (`results/M-122/versions.txt`)

* **الأمر:** `php investigations/governance-closure/scripts/m122.php results/M-122/issue-122-body.md` (نص #122 محفوظ كما جُلب، `updatedAt` 2026-10-16T13:59:42Z)، exit 0.
* **المقيس:** الملفات الـ 11: البصمة مطابقة في المصادر الثلاثة (README 11 صفًا، COMPARISON 11، #122 11)؛ الأسطر والأحرف مطابقة في README و#122، وبايتات UTF-8 في README؛ الحالة `UNREVIEWED / VERBATIM` في README و#122 لكل الـ 11. `git log 4fde508..HEAD -- proposals` فارغ.
* **self-test:** `m122-selftest.sh` يرفض بصمة مغيَّرة وصفًّا ناقصًا (exit 1 لكلٍّ)، فالمدخلات تصل المقارنة.
* **F-181-01 — نتيجتان تخالفان نص التنبؤ (§3 M-122):** التنبؤ قال «الأسطر والأحرف … والحالة في المصادر الثلاثة»، و`COMPARISON.md` L8–L20 لا يحمل إلا الملف والبصمة والتحقق الأصلي؛ لا أعمدة أسطر ولا أحرف ولا حالة لكل ملف، وحالته هو «draft for engineering review» (L6). **البيانات لا تخالف شيئًا**؛ الخطأ في صياغة التنبؤ، إذ نسب إلى COMPARISON حقولًا ليست فيه. سُجِّل `matches_prediction: false` للادعاءين في `results.jsonl` كما هما، و**توقف التسلسل** وفق LT-181-10 قبل M-180، حتى حكم الـ Lead.
* **حكم الـ Lead على F-181-01** ([2026-11-02T19:21:01Z](https://github.com/Maatify/php-rate-limiter/pull/182#issuecomment-6586254819)): خطأ صياغة تنبؤ لا finding بيانات.
  1. الصفان يبقيان كما سُجِّلا، والتنبؤ لا يُعاد صوغه بعد الحدث؛ أُضيف لكلٍّ `"cause": "prediction_wording"` (القيمة الأخرى `data` للاختلاف الحقيقي)، وجملة في صف M-122 بالـ REPORT: التنبؤ نسب إلى `COMPARISON.md` حقولًا ليست فيه.
  2. **LT-181-10 موضَّحًا لبقية S1:** التسلسل يتوقف عند اختلاف `cause` فيه `data` وحده؛ اختلاف `prediction_wording` يُسجَّل بالطريقة نفسها ويستمر التسلسل، ويُذكر في التقرير التالي للـ Lead. `scripts/claim.php` يشترط السبب لكل `false`.
  3. مصالحة #122: الأرشيف سليم (11/11 حرفيًا، بلا تغيير منذ `4fde508`)؛ الإغلاق أو الإبقاء يُحسم مع #110 في البند 6.

### 11.2 M-180 — 2026-11-02، على `main@0193b79`

* **الأوامر:** `git fetch --tags origin`، ثم `git tag --contains` و`git merge-base --is-ancestor … origin/main` لكل من `c8b8fd7` (#158) و`eea9d1a` (#171)، و`git rev-list --count v1.0.0-rc.3..origin/main`، و`gh release list` (كلها exit 0، في `results/M-180/`).
* **المقيس:** لا وسم يحوي أيًّا منهما، وكلاهما على `main`. الوسوم: `v1.0.0-rc.1..3` و`archive/5b3b-pre-refound-aad21af`. الفجوة **68** commit، وآخر إصدار على GitHub هو rc.3 (pre-release، 2026-10-07). #180 مفتوح بلا تعليقات.
* **مطابق للتنبؤ** في الادعاءات الثلاثة.

### 11.3 M-121 — 2026-11-02، على شجرة مطابقة لـ `main@0193b79` (`results/M-121/versions.txt`)

* **الأداة:** `decay-121/m121.php` عبر `RateLimiterBuilder::fromFullCapabilityStore(...)->withClock()->withPolicy()` و`buildOperationalReader()->readScorePolicy()`، و`lib/arms.php` للذراعين. السياسة `new ApiHeavyProtectionPolicy(['k1' => 1000, 'k2' => 1000, 'k3' => 1000])` (الـ constructor العام يقبل العتبات، فلا حاجة إلى صنف فرعي)، وIP وUA ثابتان بلا بصمة عميل لأن K1 نطاق IP. الساعة `FixedClock` عند t0 = 2026-01-01T00:00:00Z.
* **الأوامر:** `php …/m121.php memory` و`bash scripts/ci/run-with-redis-service.sh php …/m121.php redis` (Redis 7.0.15 من `docker/redis-integration/compose.yaml`، حاوية جديدة لكل تشغيل و`FLUSHALL` مع `DBSIZE = 0` قبل كل حالة). سبق التسجيلَ تشغيلُ تطوير واحد لذراع الذاكرة بالنتيجة نفسها.
* **المقيس (الذراعان متطابقان):**

  | الحالة | K1 المخزَّن | `updatedAt` | K1 الفعلي عند t0 + 500 | العقد الموثَّق |
  | --- | ---: | ---: | ---: | ---: |
  | التحكم (6 عند t0) | 6 | t0 | **4** | 4 |
  | المختبَرة (كل 100 ث) | 6 | t0 + 500 | **6** | 4 |

  exit 1 (أحمر) في الذراعين، وكل طلب ALLOW/NORMAL بلا إشارات فشل، وفي Redis 9 مفاتيح بعد كل حالة. K2 (نطاق IP أيضًا) بالقيم نفسها.
* **مطابق للتنبؤ** (`cause` لا ينطبق).
* **ملاحظة تسجيل:** `scripts/check-whitespace.sh` يفحص الملفات المتتبَّعة وحدها، فشُغِّل قبل `git add` ولم يرَ مسافات ذيلية في أسطر تقدّم docker compose داخل `redis.stdout.txt` (10 أسطر). من الآن `record.sh` يحذف المسافات الذيلية من الناتج المسجَّل، وطُبِّق الحذف نفسه على هذا الملف؛ أسطر JSON لم تتغير (`diff` لأسطر `{` فارغ)، والفحص يُشغَّل بعد `git add`.

### 11.4 M-98 — 2026-11-02، على شجرة مطابقة لـ `main@0193b79` (`results/M-98/versions.txt`)

* **الأداة:** `retry-after-98/m98.php` بالبناء نفسه لـ M-121؛ السياسة `new ApiHeavyProtectionPolicy(['k2' => 3])` (K1 600 وK3 300 كما تُشحن)، وIP وUA ثابتان، و`recordSuccess` متتالية عند t0 نفسها حتى أول قرار غير ALLOW، ثم **10** بعده (LT-181-05).
* **الأوامر:** `php …/m98.php memory` و`bash scripts/ci/run-with-redis-service.sh php …/m98.php redis` (Redis 7.0.15، `FLUSHALL` قبل التشغيل)، exit 0 في الذراعين.
* **المقيس (الذراعان متطابقان):** الطلبان 1–2 ALLOW، وأول حجب عند الطلب 3: `SOFT_BLOCK` L1 بـ `retryAfter` **180**، ثم الطلبات 4–13 كلها `SOFT_BLOCK` L1 بـ **360، 540، 720، 900، 1080، 1260، 1440، 1620، 1800، 1980**: زيادة 180 ث لكل طلب مرفوض بلا سقف. K2 المخزَّن 13، أي عدد الطلبات المرسلة كلها، فالمرفوض يُحتسب. NORMAL بلا إشارات فشل، و12 مفتاحًا في Redis.
* **مطابق للتنبؤ.**

### 11.5 M-120 — 2026-11-02، على `main@0193b79` (worktree مؤقت بـ patch للـ provenance وحده)

* **كما هو:** `bash investigations/account-scoped-known-device-relief-110/run.sh` على الفرع → exit 4، `baseline_identical`. مطابق.
* **بالـ patch (LT-181-01):** worktree منفصل عند `0193b79` في الـ scratchpad، و`composer install` من `composer.lock` نفسه. الـ patch: `run.sh` L24 و`lib/akdr.php` L46 وL48–L50 فقط، والبصمات الثلاث محسوبة على تلك الشجرة؛ ملفان و5 أسطر (`results/M-120/provenance.patch`، `patch-scope.txt`). الزمن 3:19 دقيقة. `.gitignore` في الجذر L20 يتجاهل `*.patch`، فأُضيف `provenance.patch` بـ `git add -f` دون تعديل `.gitignore`. وفي الـ patch سطر سياق فارغ (L8) بمسافة واحدة يرفضها `check-whitespace.sh`، فحُذفت؛ `git apply --check` على الـ worktree بعد إرجاعه يقبله، وناتج تطبيقه مطابق بايتًا لـ `git diff` الأصلي.
* **المقيس:** exit 1 بسبب صفوف الـ provenance وحدها؛ `report.php` PASS وكل الملاحظات REPRODUCED؛ 27/27 self-test؛ `observations.jsonl` مطابق. `scripts/compare-provenance.php` (LT-181-02): صفوف القرار مطابقة بايتًا ببايت (V01 617، O01 384، B01 252، observations 19)، وصفوف الـ provenance متساوية بعد حذف حقول الـ SHA، و`summary.md` يختلف في السطر 5 وحده. self-test المقارنة يرفض صفًّا مبدَّلًا وحقل provenance غير الـ SHA، ويقبل تغيير الـ SHA وحده.
* **MAIN:** TMSd **2/200** وTMUA **36/200** في `pre+out` و`pre`، وTMUA بحدّ 3 لكل حساب على 12 حسابًا.
* **مطابق للتنبؤ** في الادعاءات الستة. الملحق المؤرَّخ: `investigations/account-scoped-known-device-relief-110/ADDENDUM_2026-11-02_181.md`.

### 11.6 M-S0 — 2026-11-02، على `main@0193b79` (worktree مؤقت بـ patch للـ provenance وحده)

* **كما هو:** `bash investigations/trusted-multi-session-s0/run.sh` → exit 4، `baseline_src_identical`. مطابق.
* **بالـ patch:** worktree منفصل عند `0193b79` و`composer install`؛ `run.sh` L25 وL178 و`lib/tms.php` L16–L17 فقط، ملفان و4 أسطر (`results/M-S0/provenance.patch`، `patch-scope.txt`). أربعة أسطر سياق فارغة بمسافة واحدة حُذفت مسافتها كما في M-120؛ `git apply --check` في worktree ثالث مؤقت يقبل الـ patch وناتجه مطابق بايتًا لـ diff الـ worktree. الزمن 8 ث.
* **المقيس:** exit 1؛ `gates.jsonl` مطابق، والتأكيدات 11/11 صحيحة، وself-test 14/14. `scripts/s0-rows.php` يسرد الصفوف المختلفة وحقولها:
  * Login `pre+out`: TMSs **5**/204، TMX **60**/200، TMXb **48**/300. مطابق.
  * OTP `pre+out`: TMSs 18 → 3، TMX 200 → 36. لا stream OTP لـ TMXb في الأداة: `prediction_wording`.
  * كل صفوف `pre` بلا تغيير، وN12T 56/61 وTMSd 2/200 وSP5 200/200 بلا تغيير. مطابق.
  * شريحة `vic0`: 4 وصلت و22 رُفضت من 25، وأول رفض عند 9 ث. مطابق (≤ 5). المجموع 26 لـ 25 طلبًا كما تعدّها الأداة، ولم يُفحص هنا.
  * مقابل AKDR [MAIN] (`scripts/s0-vs-akdr.php`): 15/15 خلية متفقة. أول تشغيل للسكربت قرأ أعمدة «S0 [PRIOR]» (الفهرس 2/4/6 بدل 3/5/7) فأعطى 12/15؛ صُحِّح الفهرس قبل أي استنتاج، والتشغيل المسجَّل هو المصحَّح، وما رفضه الأول يبيّن أن المقارنة تصل مدخلاتها.
* **F-181-02 — اختلاف سببه البيانات (`cause: data`)، فيتوقف التسلسل قبل S2:**
  * التنبؤ (§3 M-S0 (ج) و(د)، و§4 «يكذّبه: تغيّر صف `pre`/`out`»): كل صفوف `out` بلا تغيير، وصفوف t02 تتغير في `pre+out` فقط.
  * المقيس: **8 صفوف `out` في t01** (Login TMX وTMXb وTMSs؛ OTP TMS وTMSd وTMSs وTMR وTMX) و**صفّان `out` في t02** (TMSs وTMX) تغيّرت. **الوصول (reach) فيها كلها بلا تغيير** (TMX `out` 200/200، TMXb 300/300، مطابق لـ AKDR main out)، والتغير في حقول الرفض والإصدار: Login TMX `out` رُفض 0 → 164 مع 36 إصدار K5، وTMXb 0 → 264 مع 12، وOTP TMX 24 → 188 مع 36 K5 و24 K4.
  * التنبؤ افترض أن DEC-021 لا يظهر في `out` لأن الوصول فيه لا يتغير؛ والبيانات تُظهر أن الـ limiter يصدر الآن حجوزات K5 ورفضًا مسجَّلًا في تدفق record-only دون أن يمسّ الوصول. لا تفسير أبعد من ذلك هنا.
* الملحق المؤرَّخ: `investigations/trusted-multi-session-s0/ADDENDUM_2026-11-02_181.md`.
