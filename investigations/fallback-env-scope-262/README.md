# #262 — مشاركة حصة الـfallback المحلي بين البيئات داخل عملية PHP واحدة: دراسة تحقق وتصنيف ومقترح

**دراسة فقط.** لا يتغير شيء تحت `src/` ولا العقود ولا الافتراضيات ولا الـpin. الاختبار والدعم والمشغّل هنا وصفٌ للسلوك الحالي، لا إثبات أنه مقصود. أي تغيير في العقد ينتظر قرار الـOwner. #262 تبقى مفتوحة.

## 1. الهوية

| البند | القيمة |
|---|---|
| الريبو / remote | `Maatify/php-rate-limiter` — `origin = https://github.com/Maatify/php-rate-limiter.git` |
| الأساس | `main@d7d0ad9bd6f022ddbd8577f392e780bfb7a59c5a` (دمج #271)، الشجرة `44d4a527ad6432c85c1bddee8f3e227735b5d9ef`؛ الـindex وشجرة العمل نظيفان عند البدء، و`origin/main` لم يتحرك. CI على الأساس 17/17 |
| SHA المصدر الذي شُغّلت عليه الأدلة | `e1e0d931650ad3586ab0e81f33801fdad08670df` (الشجرة `0babe3650ac0f10325da2bd84dc5c76238eebdbf`): الأساس + الاختبار والدعم والمشغّل. **لا فرق تحت `src/` عن الأساس.** بعده تُضاف الأدلة والوثيقة فقط، وتصحيح شكلي: حذف المسافات الزائدة من أسطر `raw/*/run-meta.txt` و`raw/mutation-*.log` (فحص المسافات في CI)، وإزالة المسافة الأخيرة في سطر `command=` من `run.sh` — لا أثر على المحتوى المقيس |
| المعايير | Selective Pinned Adoption على `ebcf55b0591db61d18a1407bc3effef0197b388f` دون تحديث. مسار §10.1: `AGENTS.md` ثم `STANDARDS_MANIFEST.md`، والنطاق `investigations/` والاختبار؛ المجموعة المحلية فقط، دون اتصال بـupstream. Governance Helper داخل Docker على clone نظيف عند `dc510e67` (الأساس + الاختبار الأول؛ لا تغيير في حوكمة بعده): `mechanical_status = VALID`، `errors = []`، `explicit_exceptions_overrides = []` ([`raw/governance-helper.json`](raw/governance-helper.json)). (التشغيل داخل شجرة العمل المتفرعة نفسها يفشل بـ`ambiguous-manifest` لوجود نسخ أخرى تحت `.claude/worktrees`، فشُغّل على clone خارجها.) |
| Decision Preflight | **DEC-011** (اسم الـpolicy namespace الـfallback؛ القواعد typed)، **DEC-015** (4096 موضوعًا لكل population، overflow محافظ، تنظيف الانتهاء، تصنيف فشل التخزين: `BackendFailureException` فقط يدخل fallback)، **DEC-041** (ملكية الطلب: كل رسم بموارد مستقلة، لا نداء متداخل هنا). لا قرار جديد ولا تعديل لقرار |
| البيئة | Docker فقط: `maatify-rl-php64:local` (PHP 8.4.27، `PHP_INT_SIZE=8`، linux/arm64) و`maatify-rl-php32:local` (PHP 8.4.27، `PHP_INT_SIZE=4`، تحت محاكاة linux/386 فالأزمنة تقريبية — لا تُستنتج منها أرقام زمنية هنا أصلًا). **لا Redis**: الـbackend مخزن اصطناعي تحت سيطرة الاختبار (التكليف: «لو استخدمت Redis»)، فلا إصدار Redis ولا عميل يُربط بالأدلة |

## 2. ما تنص عليه المكتبة (النص)

- `RateLimiterConfig::environmentScope()`: «Non-blank namespace included in derived keys» (`src/Config/RateLimiterConfig.php`)؛ المرجع الحزمي: «the non-empty environment scope used by the default composition». أي أن النطاق **مفهوم كجزء من اشتقاق مفاتيح التخزين**. لا نص في `docs/` ولا README ولا DEC-011/015 يقول إن بيئتين تتشاركان عملية PHP واحدة مدعومان أو غير مدعومين، ولا يذكر الـfallback المحلي نطاق البيئة.
- `docs/FAILURE_SEMANTICS.md` و`LocalFallbackLimiter`: «Fallback counters are namespaced by policy identity (`BlockPolicyInterface::getName()`), not by configuration content»؛ العدّادات «process-local». والكود: `namespace = 'fallback:' . sha256(policy->getName())` — **لا يدخل `environmentScope`**، ولا يصل أصلًا إلى `LocalFallbackLimiter::evaluate()`: `RateLimiterEngine` لا يحمل نطاق البيئة (يحمله `EvaluationPipeline` كحقل خاص). الاستنتاج من النص والكود (لا من القياس): الحالة الساكنة `static` مشتركة بين كل رسم في العملية بحسب اسم الـpolicy فقط.

## 3. الطريقة

[`tests/System/FallbackEnvironmentScopeSharingStudy262Test.php`](../../tests/System/FallbackEnvironmentScopeSharingStudy262Test.php) (13 اختبارًا) ودعمه `tests/Support/Study262/`. كل رسم مبني بـ`RateLimiterBuilder` العام، بمخزن rate-limit ومخزن correlation ومخزن circuit وemitter وساعة (`FixedClock` عند `2025-01-01 12:00:10 UTC`) **مستقلة**. السياسة: `FAIL_OPEN` بقاعدة `IP_PREFIX` حدّها 2 ونافذتها 600s (وقاعدة UA لازمة لـAPI_OVERUSE بحدّ غير قابل للبلوغ)، فالمتوقع من الباب الأول: سماحان ثم `HARD_BLOCK` بـ`Retry-After = 590` (الثواني المتبقية من النافذة 600 المحاذية للـepoch). الاختبارات تكتب `evidence.jsonl` (مدخلات ونتيجة متوقعة حالية ومرصود). الحالة الساكنة تُصفّر قبل كل سيناريو وتبقى بين نداءات السيناريو الواحد. الحصة تُقرأ **باستقلال** بـReflection على عدّادات `LocalFallbackLimiter` ومواضيعها المتتبعة، وحالة الـcircuit من مخزن الـcircuit نفسه (`load()`)، لا من الرد.

## 4. النتائج المرصودة

(`PHP_INT_SIZE` 8 و4 **متطابقتان** بعد استبعاد pid والإصدار؛ [`raw/run-64/`](raw/run-64/) و[`raw/run-32/`](raw/run-32/): `OK (13 tests, 4167 assertions)` على كل منهما.)

| # | السيناريو | الدخل | المرصود | الصنف |
|---|---|---|---|---|
| S01 | رسم واحد | production ×3 | ALLOW, ALLOW, HARD_BLOCK (`fallback_limit_exceeded`, block 2, Retry-After 590)؛ العدّادات `[3,3]` (واحد لكل قاعدة) | ضابط نجاح/أساس |
| S02 | بيئة واحدة، رسمان | A(prod)×2 ثم B(prod) | B: **HARD_BLOCK** — الحصة مشتركة | السلوك الموثّق (DEC-011) |
| **S03** | **بيئتان، رسمان، العملية نفسها** | A(production)×2 ثم B(staging) أول نداء له | B: **HARD_BLOCK**، Retry-After 590؛ عدّادان فقط `[3,3]` (لو كان لكل بيئة namespace لظهرت أربعة) | **المرصود محل #262** |
| S04 | تناوب | A,B,A,B | ALLOW, ALLOW, HARD_BLOCK, HARD_BLOCK — عدّاد واحد بترتيب الوصول | امتداد S03 |
| S05 | الحصة مقابل الـcircuit | A×3 ثم B | B مرفوض بحصة A؛ حالة الـcircuit: A=`OPEN`، B=`null` قبل ندائه ثم `CLOSED` بعده — **الـcircuit لكل رسم والحصة مشتركة** | فصل الحصة عن الـcircuit |
| S06 | ضابط: اسم policy مختلف | A(production, P)×2 ثم B(staging, P_other) | B: ALLOW؛ أربعة عدّادات | عزل مؤكد |
| S07 | ضابط: موضوع (IP) مختلف | A×2 على IP1 ثم B على IP2 | B: ALLOW؛ أربعة عدّادات | عزل مؤكد |
| S08 | ضابط: backend سليم | production ×5 | كلها ALLOW بـ`failureMode = NORMAL`؛ **صفر عدّادات** | الحالة السليمة لا تلمس الحصة |
| S09 | ضابط: خطأ غير تشغيلي | `RuntimeException` ×3 على production ثم staging typed ×3 | الاستثناء يصل للمتصل ثلاث مرات؛ العدّادات **صفر** بعدها؛ ثم staging: ALLOW, ALLOW, HARD_BLOCK (حصة كاملة) | غير التشغيلي لا يستهلك الحصة (DEC-015) |
| S10 | الانتهاء | بعد استنفاد A، ساعة الرسمين إلى 12:10:00 | staging: ALLOW, ALLOW, HARD_BLOCK؛ عدّادات جديدة `[3,3]` — التنظيف والنافذة بالزمن لا بالبيئة | تنظيف الانتهاء |
| S11 | سقف 4096 وoverflow | حدّ 1؛ production يملأ 4096 IP، ثم IP جديد من production، ثم IP جديد من staging، ثم IP جديد من staging/policy آخر | 4096 موضوعًا لكل population (8192 للقاعدتين)؛ أول جديد من production: ALLOW (دخل overflow)؛ **staging الجديد: HARD_BLOCK (Retry-After 590) — يشارك الـoverflow bucket نفسه**؛ policy آخر: ALLOW | السقف والـoverflow مشتركان بين البيئات |
| S12 | المسار المتقدم | `EvaluationPipeline` + `RateLimiterEngine` مباشرة، بيئتان | ALLOW, ALLOW, HARD_BLOCK — نفس المشاركة | الـAdvanced Path |
| S13 | عملية PHP منفصلة | production ×3 في الأب (الثالث مرفوض)، ثم عملية ابن جديدة بـstaging ×3 | الابن (pid مختلف): ALLOW, ALLOW, HARD_BLOCK — **حصة كاملة خاصة به**؛ حالة الأب لم تتغير | معنى process-local |

**ضوابط الدليل.** (أ) تحكم المشغّل: تشغيل بـ`--filter` لا يطابق شيئًا يخرج بالرمز 76 («No tests executed»، `evidence has 0 lines`)، لا بصفر ([`raw/control-no-test-executed.log`](raw/control-no-test-executed.log)). (ب) طفرة (mutation): جعل namespace الـfallback يضم `spl_object_id($policy)` — أي عزلًا مصطنعًا بين الرسوم — يُفشل **سبعة** اختبارات بالضبط (S02,S03,S04,S05,S10,S11,S12) ويُبقي الضوابط (S01,S06–S09,S13) خضراء ([`raw/mutation-namespace-per-policy-object.log`](raw/mutation-namespace-per-policy-object.log))؛ أي أن الاختبارات تكشف العزل لو وُجد. (ج) PHPStan max وPHP-CS-Fixer وPER-CS 3.1 والمسافات: نظيفة على PHP 8.4.27 بالـvendor المحلي. **لم يُتحقق من PHPStan 2.0.0 (الأدنى المدعوم)**: طُلب تنزيل `phpstan.phar` داخل الحاوية فرُفض من بيئة التشغيل، فلم يُنزَّل؛ يبقى على CI (الجدول الطبيعي) إثبات ذلك.

## 5. التصنيف (مدى الوصول والأثر)

**المقيس.** في عملية واحدة، رسمان بنفس اسم الـpolicy ونطاقَي بيئة مختلفين **يتشاركان** حصة الـfallback المحلي وسقف 4096 والـoverflow bucket (S03–S05، S11، S12)؛ ولا يتشاركان إلا بمفتاح الـpolicy والموضوع (S06، S07)؛ وعملية منفصلة لا تتشارك شيئًا (S13).

**ما يستنتجه التقرير (لا قياس عليه).**

- *الوصول:* لا يظهر إلا لمن يستضيف رسمين `RateLimiterBuilder` بنطاقَي بيئة مختلفين وباسم policy واحد في عملية PHP واحدة (كالعمل الطويل: Swoole/RoadRunner/ReactPHP أو اختبارات/أدوات متعددة المستأجرين)، **وأثناء عطل تشغيلي typed** في الـbackend (أو دائرة غير مغلقة) وعلى policy لها إعداد fallback صالح بنمط `FAIL_OPEN`/`DEGRADED_MODE`. في PHP-FPM التقليدي (طلب = عملية بنطاق واحد) لا أثر. الحالة السليمة لا تلمس الحصة (S08).
- *الأثر:* اتجاهه **تقييدي فقط** في كل ما قيس: العدّادات تزيد ولا تنقص، فلا حالة أُعطيت فيها بيئة سماحًا أكبر من حدّها؛ الأثر الفعلي أن الحصة الكلية للبيئتين معًا تساوي حدًّا واحدًا بدل حدّ لكل بيئة، وأن حِمل بيئة (أو فيضان مواضيع جديدة فيها حتى 4096) يدفع مواضيع البيئة الأخرى الجديدة إلى overflow مشترك فيُرفض عندها سماح كان متاحًا لها لو عُزلت. هو **توفر** (denial مبكر لـ`FAIL_OPEN`) وليس تجاوزًا أمنيًا؛ لا أثر على سرية الحالة (العدّادات لا تحمل بيانات خام: مفاتيح مجزأة). (التحقق من «تقييدي فقط» استنتاج من أن العدّاد يزيد فقط، وكل السيناريوهات الـ13 تتفق معه؛ لم يُبحث عن مسار يُنقص عدّادًا لأن الكود لا يملك مثله.)
- *الموثّق مقابل المدعوم:* **غير موثّق** بنص صريح ولا مدعوم صراحةً: لا نص يمنع ولا يجيز، والوثائق تعرّف النطاق كمُدخل مفاتيح تخزين فقط. وما عدا ذلك هو سلوك ينتج عن DEC-011 كما كُتب (الهوية = اسم الـpolicy).

## 6. البدائل والأثر (استنتاج، لا قياس إلا حيث ذُكر)

| | A — توثيق السلوك الحالي | B — إدخال نطاق البيئة في namespace الـfallback | C — رفض البناء المتعارض |
|---|---|---|---|
| ما يتغير | وثائق فقط (`FAILURE_SEMANTICS.md`، المرجع الحزمي، وتنقيح DEC-011/015 بنص واضح: الهوية = اسم policy وحده، وعليه عملية واحدة = نطاق بيئة واحد أو أسماء policy مميّزة) | `RateLimiterEngine` يتلقى النطاق (من `Builder` يتوفر `config->environmentScope()`) و`LocalFallbackLimiter::evaluate()` يُدخله في `namespace()`؛ تعديل DEC-011/015 | سجل ساكن للرسوم؛ `build()` يرفض اسم policy موجودًا بنطاق آخر |
| المشاركة بين instances داخل **النطاق نفسه** | كما هي (S02) | كما هي — نفس النطاق نفس العدّاد | كما هي |
| بين النطاقات | مشتركة (موثّقة) | معزولة | ممنوعة |
| الأبعاد الثلاثة (ACCOUNT / IP_PREFIX / IP_PREFIX+UA) | بلا تغيير | كلها تُعزل معًا لأن المفتاح الأب واحد (`namespace`)؛ لا بعد يبقى مشتركًا | بلا تغيير |
| 4096 والـoverflow والتنظيف | سقف واحد مشترك | سقف **لكل نطاق**: حد الذاكرة يتضاعف بعدد النطاقات النشطة (N × 4096 × الأبعاد × النوافذ)، والـoverflow لكل نطاق؛ التنظيف لا يتغير (مفتاحه الانتهاء) — يلزم تنقيح نص DEC-015 «4096 لكل policy/dimension/window» | بلا تغيير |
| الـBuilder | بلا تغيير | يمرّر النطاق تلقائيًا فيستفيد بلا تعديل مضيف | بلا تغيير ظاهر، لكن يكسر من يبني رسمين متعمدًا |
| المسار المتقدم (`new RateLimiterEngine(...)`) | بلا تغيير | **يحتاج بارامترًا جديدًا في ctor عام**: إلزامي = كسر توافق؛ اختياري بقيمة افتراضية = من لا يمرّره يبقى كما هو اليوم (S12 يبقى مشتركًا) | بلا تغيير |
| `LocalFallbackLimiter::check()`/`evaluate()` | بلا تغيير | `evaluate` موسوم `@internal` (حرية أكبر)، أما `check()` فعامّة الشكل ساكنة: توقيع جديد أو بارامتر اختياري | — |
| المخاطرة | لا شيء | مفاتيح fallback تتغير عند الترقية (حالة process-local فقط: تُفقد عند إعادة التشغيل أصلًا، فلا ترحيل) | تعقيد وحالة ساكنة إضافية ولا حاجة تُثبَت |

**توصية (استنتاجية):** A الآن، لأنه الأصدق مع ما يقيسه الدليل ولا يمس عقدًا ولا يضيف سطح API؛ وB فقط إذا قرر الـOwner أن «بيئتان في عملية واحدة» طوبولوجيا مدعومة، وعندها كخيار اختياري البارامتر (غير كاسر) بقرار مستقل يعدّل DEC-011/015 ويضيف اختبارات تحوّل S03/S04/S11/S12 من «مشتركة» إلى «معزولة». C لا يُوصى به.

## 7. القرار المطلوب من الـOwner

1. هل تشغيل بيئتين (نطاقا `environmentScope` مختلفان) داخل عملية PHP واحدة طوبولوجيا **مدعومة**؟ (لا ← A؛ نعم ← B كمرشح.)
2. إن كان A: قبول تنقيح النص في DEC-011 وDEC-015 و`FAILURE_SEMANTICS.md` والمرجع الحزمي كما في الجدول، في تكليف منفصل.
3. إن كان B: قبول تغيير ctor اختياري البارامتر للـEngine وتنقيح سقف الذاكرة، في تكليف منفصل مع DEC جديد/تعديل.

## 8. حدود الدراسة

- Backend اصطناعي وعطل مُحقَن بمخزن اختبار؛ لم يُقَس Redis ولا مسار فشل حقيقي لعميل (خارج السؤال: النطاق مشاركة الحصة لا تصنيف العطل، وتصنيف DEC-015 مغطى بـS09).
- الساعة ثابتة بقصد؛ لا قياس زمني. `PHP_INT_SIZE=4` تحت محاكاة (لا أثر على المنطق هنا؛ التطابق المرصود).
- لم تُفحص مشاركة `OperationalReader` (لا يستعمل `LocalFallbackLimiter`: الـgrep على `src/` يظهره في `RateLimiterEngine` فقط) ولا مسار `FixedWindowSimpleRateLimiter` (لا fallback محلي فيه).
- #263 و#270 وحدات منفصلة؛ لم تُلمس.

## 9. إعادة التشغيل

```bash
investigations/fallback-env-scope-262/run.sh 64 investigations/fallback-env-scope-262/out/run-64
investigations/fallback-env-scope-262/run.sh 32 investigations/fallback-env-scope-262/out/run-32
```

يخرج بالرمز 76 إن لم يُكتب بالضبط 13 سجلًا، و77 إن تعذّر التحقق من هوية المصدر. الملفات: `child.php` (الضابط في عملية منفصلة)، و`raw/` (الدليل الخام).
