# #261 — تنفيذ DEC-042: دليل الأساس مقابل الإصلاح

تنفيذ [تكليف #261](https://github.com/Maatify/php-rate-limiter/issues/261#issuecomment-6999369733) وحدها. القرار: [DEC-042](../../docs/decisions/DEC-042_REQUEST_SAVE_ACL_PREFLIGHT_OF_THE_ACTUAL_WRITE_PLAN.md). لا تغيير في `EVAL` أو الواجهات العامة أو الافتراضيات أو نطاق الإصدارات أو Cluster.

## الهوية

| البند | القيمة |
|---|---|
| الريبو / remote | `Maatify/php-rate-limiter`، `origin = https://github.com/Maatify/php-rate-limiter.git` |
| الأساس | `main@3acf0258087a7bb89057dcf629f9bf8f5b38c246`، الشجرة `a231ec4a90afaf21ae8df6bfc0c1295e931d4c49` (`main` لم يتحرك قبل البدء؛ طابقتُ الشجرة بالتكليف) |
| فرع العمل | `claude/261-commit-acl-preflight` |
| SHA مصدر الاختبارات (`src/` و`tests/`) | `728a1f51929aa5aca8632e55d9dd9dcad8b6420b` (الشجرة `a02bf7eb30bb70058b1e0f436dc3ef7e75a89e5f`)؛ ما بعده أدلة ووثائق فقط، لا فرق تحت `src/` ولا `tests/` |
| الحوكمة | Governance Helper داخل Docker على شجرة العمل: `mechanical_status = VALID`، `errors = []`، selective-adoption (`raw-impl/governance-helper.json`)؛ الـpin ثابت ولم يُحدَّث |

## ما تغيّر في الإنتاج

`RedisRequestOverlay::commitScript()` فقط: بعد المقارنة والتحقق وقبل أول كتابة يُبنى **تسلسل أوامر الكتابة الفعلي** (المفاتيح والوسائط والترتيب والدفعات: `DEL`، ثم `SET` أو `HSET`/`SADD`/`ZADD` على دفعات من 200، ثم `PEXPIREAT` لانتهاء مستقبلي أو `DEL` لانتهاء فات، ولا شيء عند غياب الانتهاء)، فيُفحص كله بـ`redis.acl_check_cmd`، ثم يُنفَّذ **نفس التسلسل**. الرفض: `NOPERM request commit: the account may not run <COMMAND> (plan step <n> of <m>)` قبل أي كتابة، بلا اسم مفتاح ولا قيمة. لقطة الوقت الواحدة (`__nowMs`) كما كانت. (تفصيل القرار في DEC-042.) ونسخة الـprototype ذات الوسائط التمثيلية (`AclPreflightCommitExecutor`) حُذفت ولم تُنقل.

## الاختبارات

`tests/Integration/Redis/RequestSaveAclRedisTest.php` (مجموعتا `redis-acl-request-save-261` و`php32`): حساب مؤقت مقيّد لكل حالة وحساب ضابط نجاح، اتصال `MONITOR` مستقل، ولقطة كل المفاتيح من خارج Lua (`TYPE` و`DUMP` و`PEXPIRETIME` الدقيق، بالقيمة الكاملة بلا هامش) قبل/بعد مع **مفتاح تحكم** خارج حالة الطلب؛ عبر `transactRequest()` ومحرك `limit()` وغلافي Host (عميل RESP وext-redis 6.3.0). الحالات التي تغطي مطلب التكليف:

| المطلوب | الحالات |
|---|---|
| حفظ ناجح بحساب صحيح | `control`، وخطة متعددة المفاتيح (7 خطوات، 7 عائلات مفاتيح بأنواع string/hash/set/zset)، و`testASuccessfulSaveWritesTheDataAndTheLifetimes` (البيانات والانتهاء الفعليان) |
| الرفض عبر أنواع الأوامر | `deny -del -set -hset -sadd -zadd -pexpireat`: **NOPERM، لا تغيير في أي مفتاح** |
| الدفعات التي يشتقها الحفظ | `plan batches` (401 عضوًا ← ثلاث دفعات `SADD`: 200 و200 و1) نجاحًا، و`plan batches + deny -sadd` رفضًا بلا تغيير |
| غياب/مستقبلي/فات | بلا انتهاء: `plan without expiry` (+ `deny -pexpireat` ينجح بلا الإذن)؛ مستقبلي: `control` و`deny -pexpireat` (NOPERM)؛ فات: `plan past expiry` (المفتاح يُحذف، DEL) و`+ deny -pexpireat` (ينجح: لا يحتاج الإذن) و`+ deny -del` (NOPERM بلا تغيير) |
| تغيّر الصلاحيات بين التقييم والحفظ | `revoke -del before commit`، `revoke -hset before commit`: NOPERM بلا تغيير |
| التعارض وإعادة التقييم | `conflict` (تقييمان وحفظ)، و`conflict + deny -smembers` (رفض قراءة أثناء التقييم)، و**`conflict then deny -hset`**: تعارض حقيقي في أول حفظ ثم رفض صلاحية كتابة: أول رد `0`، تقييمان، ثم `NOPERM` بلا أي كتابة من المحاولتين |
| `verify` وطلب بلا حفظ | `revoke -hgetall before verify`، `revoke -hgetall before commit` (رفض قراءة بلا تغيير)، و`plan read-only + deny every write` (لا commit) |
| تصنيف العملاء، fallback، الإشارات | الرفض يصل `RateLimiterException` (RESP) أو `\RedisException` (phpredis) لا `BackendFailureException`؛ عدّاد `LocalFallbackLimiter` لم يتغير ولا إشارة؛ وضابطه الإيجابي `engine outage`؛ وفقد الرد وعطل النقل كما قيسا في الدراسة |
| الصيغة | رسالة الرفض تطابق `NOPERM request commit: … (plan step n of m)` ولا تحوي مفتاحًا |

## الأساس مقابل الإصلاح (الانحدار يميّزهما)

الأساس يُشغَّل الآن من **شجرة مصدر منفصلة** (نسخة من الـclone في مجلد مؤقت لا يُعدَّل فيها إلا `RedisRequestOverlay.php` ليصير blob الأساس `cf44419f…`)، فلا يُمَس ملف الإنتاج في الـclone؛ لذلك `worktree_dirty=yes` في `raw-impl/base-*.meta.txt` و`mut-*.meta.txt` (فرق الشجرة المؤقتة هو ذلك الملف وحده) و`no` في تشغيلات الإصلاح.

| | الأساس `3acf0258` | الإصلاح |
|---|---|---|
| `PHP_INT_SIZE=8` | **فشل: 92 اختبارًا، 1823 assertion، 24 فشلًا** (`raw-impl/base-64.log`) | `OK (92 tests, 2117 assertions)` مرتين (`fix-64-1.log`، `fix-64-2.log`) |
| `PHP_INT_SIZE=4` | **فشل: 92 اختبارًا، 1823 assertion، 24 فشلًا** (`base-32.log`) | `OK (92 tests, 2117 assertions)` مرتين (`fix-32-1.log`، `fix-32-2.log`) |

الفشلات الأربعة والعشرون = 12 حالة × غلافين: الرفض على الأساس إما بنص الخطأ العام من السكربت أو **بتطبيق جزئي**، وهي `deny -del -hset -set -sadd -zadd -pexpireat`، و`engine deny -pexpireat`، و`plan batches + deny -sadd`، و`plan past expiry + deny -del`، و`revoke -del before commit`، و`revoke -hset before commit`، و**`conflict then deny -hset`**. باقي الحالات تنجح على الاثنين.

## ضوابط الملاحظات الثلاث (F271-01…03)

- **F271-01 — الانتهاء المطلق دقيق بلا هامش.** `testASuccessfulSaveWritesTheDataAndTheLifetimes` يلتقط **خطة الحفظ كما رآها الـexecutor** (حمولة أمر `commit`) ويقارن بـ`assertSame` رد `PEXPIRETIME` العشري من خارج Lua مع الانتهاء المخطَّط بالميلي ثانية (للانتهاء المستقبلي ولمجموعة الدفعات)، ويثبت غياب الانتهاء (`-1` ولا `x` في الخطة) والمنقضي (انتهاء الخطة خلف وقت Redis والمفتاح محذوف `-2`)، ويقارن **أعضاء** المجموعة الـ401 كقائمة مرتبة بالقائمة المتوقعة، لا عددها. **الحقن التجريبي**: في شجرة مصدر منفصلة جُعل `PEXPIREAT` يحمل `x + 1` ms، فسقط الاختبار على العرضين: `-'…063'` متوقعًا و`+'…064'` فعليًا (`raw-impl/mut-64.log`، `mut-32.log`: 1 اختبار، 13 assertion، فشل واحد). والادعاء السابق بأن هذا الاختبار هو ما يثبت الانتهاء الدقيق كان مبنيًا على نطاق ±؛ سقط وحلّ محله هذا.
- **F271-03 — التعارض يسبق رفض صلاحية الكتابة.** الحالة `conflict then deny -hset` (الغلافان): حساب بلا `+hset`، وكاتب منافس يغيّر انتهاء مفتاح قرأه الطلب قبل **أول** حفظ مباشرة. المرصود: الحفظ الأول يرد **`0`** (تعارض: لا خطة تُبنى ولا فحص ولا كتابة) (`commit -> ok:0`)، ثم **تقييمان** (إعادة تقييم بسبب التعارض لا الرفض)، ثم الحفظ الثاني **`NOPERM request commit: the account may not run HSET (plan step 2 of 21)`** فوق الحالة الحالية؛ واللقطة الدقيقة لكل المفاتيح بعد الاختبار **تساوي تمامًا** اللقطة المأخوذة فورًا بعد الكاتب المنافس (`writerSnapshotEqualsFinal=true`)، أي لا كتابة من المحاولتين، والمفتاح الوحيد المتغير `score` بفعل الكاتب (`expiry`).
- **F271-02 — مشغّل المقارنة.** لا يستبدل ملفًا في الـclone: ذراع الإصلاح يركّب الـclone كما هو، وذراع الأساس شجرة مؤقتة تُزال على كل مسار خروج؛ ويكتب في السجل مصدر كل ذراع وblobه (`arm base: … blob cf44419f…`، `arm fix : … at <head>, blob d83a835e…`)؛ وفشل الكتابة يُرفض قبل التشغيل (75). **قبل الإصلاح** (المشغّل القديم في clone مؤقتة، فشل جاهزية Redis): الخروج 73 وبقي `RedisRequestOverlay.php` بـblob الأساس `cf44419f` مع `M src/…` (`raw/bench-impl-before-f271-02.log`)؛ **بعده** يضبط `selftest.sh` أن الملف في الـclone بلا تغيير والشجرة المؤقتة محذوفة بعد فشل التهيئة وبعد التشغيل الكامل، وأن السجل غير القابل للكتابة يعطي 75، وأن سجل تشغيل قصير يحمل مصدر الذراعين وملخصيهما (كلها ok، `raw/runner-selftest.log`).

## بقية الأدلة

| السجل (`raw-impl/`) | النتيجة |
|---|---|
| `rel-64.log`، `rel-32.log` | `RequestAtomicityBoundariesRedisTest`: `OK (6 tests, 402 assertions)` على العرضين |
| `broad-integration-redis-64.log`، `broad-integration-redis-32.log` | كل `tests/Integration/Redis` على الإصلاح: 927 اختبارًا `OK` مع **14 skip** (64-bit) و**64 skip** (32-bit) (تخطيات الاختبارات القائمة)، دون فشل. **ما لم ينجح سابقًا**: في جولة واسعة قبل الرأس السابق فشل اختبار واحد من اختباراتي على 32-bit بسبب توقّع هشّ (`cycle:content` مقابل `content+expiry` عند عبور حدّ ثانية)؛ وُحّد في المقارنة وأُعيدت التشغيلات |
| `bench-impl-64.log`، `bench-impl-32.log` | كلفة الحفظ (commit EVAL، خمسة مفاتيح، Redis محلي، تشغيل وحيد بالتناوب base/fix من شجرتين منفصلتين، السجل يسمّي مصدر كل ذراع). **طريقة التجميع**: لكل جولة وذراع p50 على 400 طلب (200 على 32-bit)؛ الملخص هو **الوسيط** و**المتوسط** لقيم p50 الجولات. 64-bit (3 جولات): الأساس 290/294 µs، الإصلاح 315/315 µs (وسيط/متوسط) أي ≈ **+8%**؛ 32-bit المحاكى (جولتان): الأساس 392/392، الإصلاح 412/412 (≈ +5%، تقريبي). تصحيح: الأرقام 281/272 µs في النسخة السابقة من هذا الملف كانت **متوسط p50 الجولات** لتشغيل سابق لا وسيطها؛ والتشغيلات تتفاوت (مرة بلا فرق ومرة +6% و+8%)، فالخلاصة: كلفة صغيرة موجبة (بناء الخطة وفحصها)، ليست صفرًا |
| `governance-helper.json` | VALID، errors [] |
| `mut-64.log`، `mut-32.log` | الحقن +1 ms: فشل الاختبار على العرضين (انظر F271-01) |

## الأوامر والإصدارات

كل شيء داخل Docker، Redis معزول لكل تشغيل: `redis:7.0.15-alpine`؛ PHP 8.4.27 (`maatify-rl-php64:local` `linux/arm64` `PHP_INT_SIZE=8`؛ `maatify-rl-php32:local` `linux/386` تحت محاكاة `PHP_INT_SIZE=4`، فالأرقام الزمنية عليه تقريبية)؛ ext-redis 6.3.0؛ PHPUnit 11.5.57. المشغّلان `run.sh` و`bench-impl.sh` يسجّلان رأس المصدر ورمز الخروج في `*.meta.txt`.

```bash
investigations/redis-acl-request-save-261/run.sh 64 --do-not-cache-result tests/Integration/Redis/RequestSaveAclRedisTest.php
investigations/redis-acl-request-save-261/run.sh 32 --do-not-cache-result tests/Integration/Redis/RequestSaveAclRedisTest.php
investigations/redis-acl-request-save-261/run.sh 64 --do-not-cache-result tests/Integration/Redis        # الواسع
investigations/redis-acl-request-save-261/bench-impl.sh 64 400 3                                      # وحده؛ ويعمل من شجرتين، لا يعدّل الـclone
```

فحوص الجودة محليًا (على الرأس الأخير، بعد كل التعديلات): `phpstan analyse src tests examples --level=max` نظيف على 2.0.0 (الأدنى المدعوم) وعلى الأحدث، وPHP-CS-Fixer ونصّ PER 3.1 delta نظيفان. دليل CI الطبيعي على رأس التسليم على الـPR.

## الحدود

لم يُختبر: Redis غير 7.0.15 أو Valkey، Cluster/Sentinel، RESP3، Predis، `EVALSHA`، مسارا التعافي والـadmission، والسكربتات المباشرة خارج الطلب (#270). الفحص يجيب سؤال الـACL فقط ولا يضمن شيئًا عن أخطاء Redis الأخرى أثناء الكتابة. في مراجعة الرفض (`ACL LOG`) لا يظهر رفض الـpreflight (لا يمرّ بتسجيل ACL)، فمصدره الوحيد نص `NOPERM request commit`.
