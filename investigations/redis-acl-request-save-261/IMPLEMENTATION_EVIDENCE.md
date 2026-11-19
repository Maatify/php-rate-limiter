# #261 — تنفيذ DEC-042: دليل الأساس مقابل الإصلاح

تنفيذ [تكليف #261](https://github.com/Maatify/php-rate-limiter/issues/261#issuecomment-6999369733) وحدها. القرار: [DEC-042](../../docs/decisions/DEC-042_REQUEST_SAVE_ACL_PREFLIGHT_OF_THE_ACTUAL_WRITE_PLAN.md). لا تغيير في `EVAL` أو الواجهات العامة أو الافتراضيات أو نطاق الإصدارات أو Cluster.

## الهوية

| البند | القيمة |
|---|---|
| الريبو / remote | `Maatify/php-rate-limiter`، `origin = https://github.com/Maatify/php-rate-limiter.git` |
| الأساس | `main@3acf0258087a7bb89057dcf629f9bf8f5b38c246`، الشجرة `a231ec4a90afaf21ae8df6bfc0c1295e931d4c49` (`main` لم يتحرك قبل البدء؛ طابقتُ الشجرة بالتكليف) |
| فرع العمل | `claude/261-commit-acl-preflight` |
| SHA مصدر الاختبارات (`src/` و`tests/`) | `9e3a266ba52141dd02473c2f44bab19a0370d380` (الشجرة `d75b71b642eac5e435715d6ea134aa81992650bc`)؛ ما بعده أدلة ووثائق فقط، لا فرق تحت `src/` ولا `tests/` |
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
| التعارض وإعادة التقييم | `conflict` (تقييمان وحفظ)، و`conflict + deny -smembers` (إعادة بسبب الحالة لا الرفض) |
| `verify` وطلب بلا حفظ | `revoke -hgetall before verify`، `revoke -hgetall before commit` (رفض قراءة بلا تغيير)، و`plan read-only + deny every write` (لا commit) |
| تصنيف العملاء، fallback، الإشارات | الرفض يصل `RateLimiterException` (RESP) أو `\RedisException` (phpredis) لا `BackendFailureException`؛ عدّاد `LocalFallbackLimiter` لم يتغير ولا إشارة؛ وضابطه الإيجابي `engine outage`؛ وفقد الرد وعطل النقل كما قيسا في الدراسة |
| الصيغة | رسالة الرفض تطابق `NOPERM request commit: … (plan step n of m)` ولا تحوي مفتاحًا |

## الأساس مقابل الإصلاح (الانحدار يميّزهما)

نفس ملف الاختبار الجديد على `main` الأساس (`src/…/RedisRequestOverlay.php` مستبدل مؤقتًا بنسخة `3acf0258` ثم يُسترجع؛ لذا `worktree_dirty=yes` في `raw-impl/base-*.meta.txt`). ملاحظة: كل ملفات `.meta.txt` سجّلت `worktree_dirty=yes` لأن ملفات الأدلة غير المتتبَّعة (هذا الملف و`raw-impl/`) كانت موجودة وقت التشغيل؛ لا ملف متتبَّع معدّل (`git status` لا يُظهر إلا هذه الأدلة)، وفي حالة الأساس يُضاف استبدال `RedisRequestOverlay.php` المؤقت:

| | الأساس `3acf0258` | الإصلاح |
|---|---|---|
| `PHP_INT_SIZE=8` | **فشل: 90 اختبارًا، 1785 assertion، 22 فشلًا** (`raw-impl/base-64.log`) | `OK (90 tests, 2045 assertions)` مرتين (`fix-64-1.log`، `fix-64-2.log`) |
| `PHP_INT_SIZE=4` | **فشل: 90 اختبارًا، 1785 assertion، 22 فشلًا** (`base-32.log`) | `OK (90 tests, 2045 assertions)` مرتين (`fix-32-1.log`، `fix-32-2.log`) |

الفشلات الاثنان والعشرون = 11 حالة × غلافين، وهي بالضبط حالات رفض كتابة: `deny -del -hset -set -sadd -zadd -pexpireat`، و`engine deny -pexpireat`، و`plan batches + deny -sadd`، و`plan past expiry + deny -del`، و`revoke -del before commit`، و`revoke -hset before commit`. على الأساس إما نص الخطأ من السكربت العام بدل `NOPERM request commit`، أو **تطبيق جزئي** (المفتاح المحذوف/بلا انتهاء، ومفاتيح قبله مكتوبة)؛ وباقي الحالات (قراءة، نطاق مفاتيح، نجاح، تعارض، نقل) تنجح على الأساس والإصلاح معًا، فالضابط لا يغيّر ما لم يُقصد تغييره.

## بقية الأدلة

| السجل (`raw-impl/`) | النتيجة |
|---|---|
| `rel-64.log`، `rel-32.log` | `RequestAtomicityBoundariesRedisTest`: `OK (6 tests, 402 assertions)` على العرضين |
| `broad-integration-redis-64.log`، `broad-integration-redis-32.log` | كل `tests/Integration/Redis` على الإصلاح: 925 اختبارًا، `OK` مع **14 skip** على 64-bit و**64 skip** على 32-bit (تخطيات الاختبارات القائمة)، دون فشل. **ما لم ينجح في جولة سابقة**: في تشغيل واسع سابق على 32-bit فشل اختبار واحد من اختباراتي (`phpredis reply lost after the save`): توقّع `cycle:content` بينما رُصد `cycle:content+expiry`، لأن إعادة كتابة مفتاح تغيّر انتهاءه المطلق أيضًا عندما يعبر الزرع والطلب حدّ ثانية. كان ذلك هشاشة في توقّع الاختبار لا في الإنتاج؛ وُحّد `content` و`content+expiry` في المقارنة (والانتهاء الدقيق يُثبته `testASuccessfulSaveWritesTheDataAndTheLifetimes`)، ثم أُعيدت كل التشغيلات على هذا الرأس ونجحت |
| `bench-impl.log` | كلفة الحفظ (commit EVAL، خمسة مفاتيح، Redis محلي، تشغيل وحيد بالتناوب base/fix): وسيط 64-bit ≈ **281 µs** أساسًا مقابل **272 µs** بعد الإصلاح (ثلاث جولات × 400)، دون فرق متسق؛ وعلى 32-bit تحت المحاكاة ≈ **413** مقابل **461 µs** (≈ +12%، جولتان × 200، تقريبي). في تشغيل سابق على الرأس السابق كان الفرق على 64-bit ≈ +6%. أي أن الكلفة **ليست صفرًا لكنها ضمن ضوضاء القياس على 64-bit وقد تظهر على 32-bit المحاكى**، وهي بناء الخطة وفحصها قبل التنفيذ |
| `governance-helper.json` | VALID، errors [] |

## الأوامر والإصدارات

كل شيء داخل Docker، Redis معزول لكل تشغيل: `redis:7.0.15-alpine`؛ PHP 8.4.27 (`maatify-rl-php64:local` `linux/arm64` `PHP_INT_SIZE=8`؛ `maatify-rl-php32:local` `linux/386` تحت محاكاة `PHP_INT_SIZE=4`، فالأرقام الزمنية عليه تقريبية)؛ ext-redis 6.3.0؛ PHPUnit 11.5.57. المشغّلان `run.sh` و`bench-impl.sh` يسجّلان رأس المصدر ورمز الخروج في `*.meta.txt`.

```bash
investigations/redis-acl-request-save-261/run.sh 64 --do-not-cache-result tests/Integration/Redis/RequestSaveAclRedisTest.php
investigations/redis-acl-request-save-261/run.sh 32 --do-not-cache-result tests/Integration/Redis/RequestSaveAclRedisTest.php
investigations/redis-acl-request-save-261/run.sh 64 --do-not-cache-result tests/Integration/Redis        # الواسع
investigations/redis-acl-request-save-261/bench-impl.sh 64 400 3                                      # وحده
```

فحوص الجودة محليًا: `phpstan analyse src tests examples --level=max` نظيف على 2.0.0 (الأدنى المدعوم) وعلى الأحدث، وPHP-CS-Fixer ونصّ PER 3.1 delta نظيفان. دليل CI الطبيعي على رأس التسليم على الـPR.

## الحدود

لم يُختبر: Redis غير 7.0.15 أو Valkey، Cluster/Sentinel، RESP3، Predis، `EVALSHA`، مسارا التعافي والـadmission، والسكربتات المباشرة خارج الطلب (#270). الفحص يجيب سؤال الـACL فقط ولا يضمن شيئًا عن أخطاء Redis الأخرى أثناء الكتابة. في مراجعة الرفض (`ACL LOG`) لا يظهر رفض الـpreflight (لا يمرّ بتسجيل ACL)، فمصدره الوحيد نص `NOPERM request commit`.
