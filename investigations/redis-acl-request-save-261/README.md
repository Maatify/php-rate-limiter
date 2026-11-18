# #261 — عقد حفظ الطلب (DEC-037) مع صلاحيات Redis ACL: دراسة تحقق وتصنيف ومقترح

**دراسة فقط.** لا يتغير شيء تحت `src/`، ولا `EVAL`، ولا أي default، ولا نطاق الإصدارات أو Cluster. أي إصلاح أو تغيير في العقد ينتقل إلى تكليف منفصل بعد المراجعة. #261 تبقى مفتوحة.

## 1. الهوية

| البند | القيمة |
|---|---|
| الريبو / remote | `Maatify/php-rate-limiter` — `origin = https://github.com/Maatify/php-rate-limiter.git` |
| الأساس | `main@cacdb3a6e2571b68cdae6fe2a73639ea4d4f112e` (دمج #266)، الشجرة `cb7d59eca7715744dc475e65490a924989540a82` |
| SHA المصدر الذي شُغّلت عليه الاختبارات | `c4c4072cf45ad6dd0fbe1f12c0d7fabe90d18acc` (الشجرة `62b2a5a94227e2ee99024a409f8c2240df22f4f1`): الأساس + الاختبار والدعم. بعده تُضاف وثائق وأدلة فقط، فلا يختلف عنه أي ملف تحت `src/` أو `tests/` |
| رأس التسليم | رأس الـPR (ملحوظ في وصفها وفي تعليقات #261 و#257 و#185) |
| معايير Maatify | Selective Pinned Adoption على `ebcf55b0591db61d18a1407bc3effef0197b388f`، دون تحديث الـpin. مسار §10.1 (Normal Engineering Task): قرأتُ `AGENTS.md` ثم `STANDARDS_MANIFEST.md`، وحدّدتُ النطاق (مجلد `investigations/` والاختبار)، واستعملتُ المجموعة المحلية المسجلة فقط دون اتصال بـupstream |
| Governance Helper | شُغّل داخل Docker على clone حقيقي عند `cacdb3a6`: `mechanical_status = VALID`، `errors = []`، `explicit_exceptions_overrides = []` (`raw/governance-helper.json`) |
| Decision Preflight | **DEC-037** (العقد: §4 المقارنة والتحقق قبل أول كتابة ثم التطبيق، §5 التعارض يعيد التقييم وخطأ فوق حالة جارية هو جواب الطلب، §6 حدود الإعادة)، **DEC-015/DEC-018** (provenance: `BackendFailureException` لعطل نقل معرَّف فقط، وردود خطأ الخادم تمر كما هي)، **DEC-036** (claim مستقل: خارج الدراسة)، **DEC-041** (الحصرية: لم يُمَس)، DEC-029 (لا استثناء تزامن بعد كتابة مثبتة). لا قرار جديد ولا تعديل لقرار؛ لا شيء استقر هنا حتى يحكم الـOwner |

## 2. مخزون الصلاحيات (الـofficial Redis store في مسار الطلب)

نقطة البدء `RedisRequestOverlay::commitScript()` والـexecutor الذي يراه المخزن (`RedisCommandExecutorInterface::execute()`). كل أمر Redis يصل عبر `RedisFullCapabilityStore::command()` ثم `$redis->execute()`؛ داخل `transactRequest()` هناك ثلاثة أنواع من `EVAL` فقط: سكربت خطوة ملفوف بالـoverlay، وسكربت التحقق (`verify`)، وسكربت الحفظ (`commit`).

الـoverlay يستبدل `redis.call`/`redis.pcall` بتنفيذ في الذاكرة (جدول `__commands`: أكثر من ثلاثين أمرًا، منها `DEL SET HSET SADD ZADD PEXPIRE…`)؛ **هذه لا تصل إلى Redis أبدًا** أثناء التقييم. الأوامر التي تصل فعلًا إلى Redis من الطلب:

| المرحلة | الأمر | متى يلزم | نطاق المفاتيح | المصدر | قياس |
|---|---|---|---|---|---|
| كل خطوة (top-level) | `EVAL` | دائمًا | كل `KEYS` المعلنة يجب أن تُفتح بصلاحية قراءة **وكتابة** (`%R~` وحدها و`%W~` وحدها تفشلان عند `EVAL`) | `RedisFullCapabilityStore::evalInRequest()` ~3201 | قيس |
| قراءة الـoverlay | `TIME` | في رأس كل خطوة | — | `OVERLAY_HEAD` | قيس |
| قراءة الـoverlay | `EXISTS` | لكل مفتاح معلن | كل مفتاح معلن، **ومنها المفتاح الفارغ `''`** (انظر §5.F6) | `__loadLive` | قيس |
| قراءة الـoverlay | `TYPE`، `PEXPIRETIME` | **فقط لمفتاح موجود** | | `__loadLive` | قيس (§5.F7) |
| قراءة الـoverlay | `GET` / `HGETALL` / `SMEMBERS` / `ZRANGE` | فقط لمفتاح موجود من النوع المقابل (string/hash/set/zset) | | `__loadLive` | قيس |
| المقارنة والتحقق (`verify` و`commit`) | `TIME` + نفس قراءات الـoverlay | في `commit` دائمًا قبل أي كتابة؛ في `verify` بعد خطأ فقط | مفاتيح الطلب | `COMMIT`، `requestStateIsCurrent()` | قيس |
| تطبيق الخطة (`commit` فقط) | `DEL` | لكل مفتاح تغيّر (يُحذف قبل إعادة الكتابة) | مفاتيح الخطة | `COMMIT` | قيس |
| تطبيق الخطة | `SET` / `HSET` / `SADD` / `ZADD` | حسب نوع المفتاح المكتوب (string/hash/set/zset) | | `COMMIT` (`__chunked`) | قيس |
| تطبيق الخطة | `PEXPIREAT` | لكل مفتاح له انتهاء | | `COMMIT` | قيس |
| مسارات أخرى | `PING` (`isHealthy()`)، وأوامر السكربتات المباشرة خارج الطلب (مثل `EXPIRE`, `HGET`, `HINCRBY`, `INCR`, `PTTL`…) | خارج `transactRequest()` أو في التعافي | | `isHealthy()`، سكربتات `RedisFullCapabilityStore` | **استُنتج من المصدر** عدا ملاحظة F9؛ لم يُقَس مسار التعافي ولا سكربتات الـadmission (`ZADD … LT`) |

**ما قيس مقابل ما استُنتج.** قيس: مجموعة أوامر `lua` الفعلية في ضابط النجاح من اتصال `MONITOR` مستقل (`DEL EXISTS GET HGETALL HSET PEXPIREAT PEXPIRETIME SADD SET SMEMBERS TIME TYPE ZADD ZRANGE`)، وأثر حذف كل أمر من القائمة على حدة. استُنتج من المصدر ولم يُقَس: مسارات التعافي و`PING` والـadmission وسكربتات المخزن المباشرة (غير الطلب)، ووضع `EVALSHA` (غير الافتراضي)، وRESP3.

## 3. الطريقة

`tests/Integration/Redis/RequestSaveAclRedisTest.php` (80 اختبارًا: 39 حالة × غلافان للـHost = 78، وضابط المراقِب، وملاحظة F9)، من المدخل العام `transactRequest()` ومن المحرك العام `RateLimiterBuilder::…->limit()`، على `redis:7.0.15-alpine` معزول في Docker (digest `sha256:c9d92d84…`):

- **حساب لكل حالة** مؤقت (`ACL SETUSER … reset on` ثم قائمة أوامر ونطاق مفاتيح محددين، يُحذف بعد الحالة)، **وحساب ضابط نجاح** (`seed`/`control`) يملك ما يلزم لنفس العملية. الحساب المقيّد يملك `REQUEST_COMMANDS` (15 أمرًا) ناقص الأمر المختبَر.
- **غلافان للـHost** (قراءة كل ما يصل للمخزن): عميل RESP الخاص بالمشروع (`RespRedisCommandExecutor`: رد الخطأ → `RateLimiterException`) وext-redis 6.3.0 `rawCommand()` (رد خطأ → `\RedisException`) مع تصنيف Host (رمز خطأ Redis معروف يمر كما هو، وأي شيء آخر من phpredis عطل نقل → `BackendFailureException`).
- **طلب اصطناعي** من سبع خطوات على الخمس عائلات التي يحفظها المسار (score hash، block hash، budget hash، distinct set + meta hash، watch string + meta، cycle zset): كل خطوة تغيّر مفتاحًا موجودًا مسبقًا (زُرع بنفس العمليات)، بيانات اصطناعية بالكامل.
- **ملاحظة مستقلة**: اتصال `MONITOR` منفصل (عدّ `EVAL`ات حساب الحالة وأوامر `lua`)، و`ACL LOG` من Redis (السبب والسياق `lua`/`toplevel`)، وحالة المفاتيح من اتصال إداري **خارج Lua**: `TYPE` و`DUMP` و`PEXPIRETIME` الدقيق لكل مفتاح قبل/بعد، مع **مفتاح تحكم** خارج حالة الطلب وداخل نطاق الحساب (لم يتغير في أي حالة).
- **ضابط المراقِب نفسه**: `testTheObserverReportsAKnownChangeAndNothingElse` يثبت أن المراقِب يرى تغيير المحتوى والانتهاء والحذف والإنشاء ولا يرى تغييرًا حيث لا تغيير.
- **عطل النقل الحقيقي وفقدان الرد**: `CLIENT KILL USER` من الاتصال الإداري قبل الحفظ، وبروكسي TCP (`tests/Support/Redis/reply-dropping-proxy.php`) يمرّر طلب الحفظ إلى Redis ثم **يتخلص من الرد ويغلق** جانب العميل: Redis طبّق والعميل لم يستلم، دون تعاون من المكتبة أو المخزن.

## 4. النتائج المرصودة

جدول كامل (المطلوب / المتوقع / المرصود، لكل حالة وغلاف) في [`raw/evidence-table.md`](raw/evidence-table.md)، مولَّد من [`raw/evidence-64.jsonl`](raw/evidence-64.jsonl) ومقارَن بـ[`raw/evidence-32.jsonl`](raw/evidence-32.jsonl): **78 حالة، لا فرق بين `PHP_INT_SIZE` 8 و4**. ملخص:

| الصنف | الحالات | وصل للحفظ | تقييمات | ما وصل للمتصل | الحالة بعد (من خارج Lua) |
|---|---|:-:|:-:|---|---|
| **ضابط النجاح** | `control` | نعم | 1 | الرد (`[2,null,…]`) | الخطة كاملة؛ مفتاح التحكم سليم |
| **رفض على `EVAL` نفسه** | `deny -eval` | لا | 1 | `NOPERM this user has no permissions to run the 'eval' command` | لا تغيير |
| **رفض نطاق المفاتيح** | `keys narrow` / `read-only` / `write-only` | لا | 1 | `NOPERM this user has no permissions to access one of the keys used as arguments` | لا تغيير |
| **رفض قراءة/تحقق** (داخل السكربت) | `-time -exists -type -pexpiretime -hgetall -get -smembers -zrange` | لا | 1 | `ERR The user executing the script can't run this command or subcommand script: <sha>, on @user_script:<line>.` | لا تغيير |
| **رفض كتابة** | `-del` | نعم | 1 | نفس خطأ السكربت | لا تغيير (DEL أول استدعاء) |
| | `-hset` | نعم | 1 | نفس الخطأ | **`score` محذوف** (مفتاح الـhash الأول في الخطة) |
| | `-set` | نعم | 1 | نفس الخطأ | **`watch` محذوف**، و`budget` و`distinct` و`score` أُعيدت كتابتها قبله |
| | `-sadd` | نعم | 1 | نفس الخطأ | **`distinct` محذوف**، و`budget` و`score` كُتبت قبله |
| | `-zadd` | نعم | 1 | نفس الخطأ | **`cycle` محذوف**، وباقي المفاتيح كُتبت (block/budget/distinct×2/score/watch) |
| | `-pexpireat` | نعم | 1 | نفس الخطأ | **`score` أُعيدت كتابته بلا انتهاء** (`PEXPIRETIME = -1`) |
| **سحب صلاحية بين التقييم والحفظ** | `revoke -del` | نعم | 1 | نفس خطأ السكربت | لا تغيير |
| **تعارض حقيقي** | `conflict` | نعم | **2** | الرد | الخطة كاملة بعد الإعادة |
| **تعارض + رفض** | `conflict + deny -smembers` | لا | **2** | خطأ السكربت | تغيّر `score` (انتهاؤه) بفعل الكاتب المتزامن فقط |
| **عطل نقل** | `connection killed` (resp) | نعم (أُرسل) | 1 | `BackendFailureException` | لا تغيير |
| | `connection killed` (phpredis) | نعم | 1 | **نجح**: phpredis أعاد الاتصال وأعاد الإرسال | الخطة كاملة |
| **فقدان الرد بعد الحفظ** | `reply lost after the save` | نعم | 1 | `BackendFailureException` | الخطة **كاملة** (لا جزئية) |

وصل `EVAL` كاملًا للاتصال في كل الحالات حيث عدّه `MONITOR` مساويًا لما أرسله الـexecutor (باستثناء `EVAL` رُفض عند ACL فلا يُغذّي `MONITOR`، وحالتا الاتصال المقطوع). `ACL LOG` طابق التصنيف في كل حالة: `command/lua` لرفض داخل السكربت، `command/toplevel` و`key/toplevel` للرفض عند `EVAL`، ولا قيد لعطل النقل ولا لرفض الـpreflight.

### مسار المحرك العام (`limit()`), 12 مفتاحًا لطلب `login_protection` من جهاز جديد

| الحالة | المتصل | الحالة بعد | إشارات |
|---|---|---|---|
| ضابط (نمط prefix + `~`) | `ALLOW/NORMAL` | 12 مفتاحًا أُنشئت | لا |
| نمط prefix فقط | استثناء `NOPERM … keys` عند أول خطوة تعلن مفتاحًا فارغًا | لا تغيير | لا |
| `-pexpiretime` | `ALLOW/NORMAL` (مفاتيح غير موجودة فلا قراءة) | كالضابط | لا |
| `-pexpireat` | **استثناء خام** من المحرك للمتصل (`ERR The user executing the script…`) | **`distinct-meta` أُنشئ بلا انتهاء** | لا إشارة، لا فشل دائرة، لا خصم fallback |
| اتصال مقطوع (resp) | `HARD_BLOCK/FAIL_CLOSED` | لا تغيير | لا |
| فقدان الرد | `HARD_BLOCK/FAIL_CLOSED` | **12 مفتاحًا محفوظة كاملة** | لا |

(المحرك لا يلتقط إلا `BackendFailureException`، فخطأ ACL يخرج كما هو من `limit()` دون المرور بفشل الدائرة أو عدّاد الـfallback؛ ولم يُنتَج أي مفتاح دائرة في الحالات.)

## 5. التصنيف

- **F1 — provenance.** رفض ACL رد خطأ من Redis، ليس عطل نقل: عبر عميل RESP يصل `RateLimiterException`، وعبر phpredis `\RedisException` بنص الخادم، **ولا يصل `BackendFailureException`** في أي حالة ACL. هذا يطابق DEC-015/018. نص الخطأ داخل السكربت **لا يسمّي الأمر** (يحمل sha السكربت ورقم السطر فقط)؛ اسم الأمر في `ACL LOG` (`object`) فقط. أما الرفض عند `EVAL` فيسمّي الأمر أو يقول «أحد المفاتيح».
- **F2 — ما يراه المستهلك.** الاستثناء يخرج من `transactRequest()` ومن `limit()` كما هو، دون إشارة عطل ولا فشل دائرة ولا خصم fallback، ولا إعادة تقييم بسبب الرفض فوق حالة ثابتة (التقييمات = 1 في كل حالة رفض).
- **F3 — تعارض حقيقي يبقى تحت DEC-037.** `conflict` يعيد التقييم ويحفظ؛ و`conflict + deny` يعيد التقييم **مرة واحدة لأن الحالة تحركت** (الكاتب المتزامن) ثم يُرمى الخطأ فوق حالة ثابتة. الإعادة سببها الحالة لا الرفض.
- **F4 — رفض القراءة/التحقق آمن.** يقع داخل التقييم قبل `commit`؛ لا كتابة، لا تغيير، تقييم واحد. وهو **يقع فقط عند وجود المفتاح من النوع المقابل** (F7).
- **F5 — رفض كتابة داخل `commit` يترك تطبيقًا جزئيًا (ما عدا `DEL`).** مقارنة الـfingerprints والتحقق يسبقان أول كتابة لكن لا يغطيان الـACL. الخطة تُطبّق بترتيب المفاتيح المحتجزة، وكل مفتاح `DEL` ثم الكتابة ثم `PEXPIREAT`؛ عند رفض أمر الكتابة أو `PEXPIREAT` للمفتاح k: **المفاتيح قبل k كُتبت كاملة، وk نفسه حُذف (`-hset -set -sadd -zadd`) أو كُتب بلا انتهاء (`-pexpireat`)، وما بعده لم يُمَس.** أي أن «يُحفظ كاملًا أو لا شيء» (DEC-037 §4) لا يصمد أمام رفض ACL لأمر كتابة، مع الإشارة إلى أن الاستثناء يخرج للمتصل فلا يظن أن الحفظ تم. الأثر المرصود: فقد عدّاد/حالة (`score` أو `watch` أو `distinct` أو `cycle`) أو مفتاح دائم بلا TTL (`PEXPIRETIME = -1`).
- **F6 — نطاق المفاتيح.** يُرفض دائمًا عند `EVAL` (قبل السكربت)، لا في منتصف `commit`، لأن `KEYS` الحفظ مجموعة جزئية من مفاتيح خطوات سبق قبولها. ويُرفض `%R~` وحده و`%W~` وحده لأن `EVAL` يحتاج قراءة وكتابة على المفاتيح المعلنة. **كما أن `HARD_BLOCK` و`LIFECYCLE_READ` يعلنان مفاتيح فارغة `''`** عندما لا يوجد previous key (`RedisFullCapabilityStore.php` ~2705 و~2769)، ولا يطابقها نمط `~maatify:rate-limiter:v1:*`؛ فمسار المحرك العام فشل بـ`NOPERM … keys` عند أول خطوة كهذه، ونجح بإضافة القاعدة `~` (النمط الفارغ). هذا لم يُتوقع قبل التشغيل.
- **F7 — الحاجة لصلاحيات القراءة تتبع وجود الحالة.** على keyspace جديد نجح الطلب بحساب بلا `TYPE/PEXPIRETIME/GET/HGETALL/SMEMBERS/ZRANGE` (`fresh keyspace + deny reads`، وحالة المحرك `-pexpiretime`)؛ ويفشل نفس الحساب بعد أن توجد الحالة. فاختبار تهيئة على Redis فارغ **يُقلّل** ما يلزم في الإنتاج.
- **F8 — عطل النقل وفقدان الرد.** `connection killed`: `BackendFailureException` ولا تغيير مع عميل RESP؛ ومع phpredis أعادت المكتبة الاتصال (وAUTH) وأرسلت الحفظ فنجح (سلوك المكتبة خارج الحزمة، ولا يُبنى عليه عقد). فقدان الرد بعد الحفظ: نتيجة مجهولة بـ`BackendFailureException` والحالة **كاملة لا جزئية**، والمحرك يُجيب `FAIL_CLOSED` — أي دلالة DEC-037 §7 كما هي.
- **F9 — ملاحظة مستقلة (خارج مسار الطلب).** سكربت مباشر (خارج `transactRequest()`) يطبّق الانتهاء بـ`redis.pcall('EXPIRE', …)`؛ ورفض ACL لـ`EXPIRE` يُبتلع بالـpcall ويُبلَّغ باسم مختلف: `expiry not representable` (ثم يزيل السكربت ما كتبه، ولا أثر على الحالة)؛ الـprovenance الحقيقية (ACL) في `ACL LOG` فقط (`testADirectScriptThatMayNotRunExpireReportsAnExpiryProblem`). تُربط بتذكرتها حين يقرّر الـLead.

حدود النتيجة: **Redis 7.0.15 فقط، نسخة واحدة (لا Cluster/Sentinel)، `EVAL` الافتراضي، RESP2، غلافان فقط للـHost، حسابات بقائمة أوامر مصرّح بها صراحةً (لا `@category`)**. لا استنتاج لنسخ Redis الأحدث ولا لـValkey ولا لعملاء Predis.

## 6. الاقتراح (disposition واحد) والبدائل

**الاقتراح: فحص السماح لخطة الحفظ كلها داخل سكربت `commit` نفسه، بعد التحقق وقبل أول كتابة، بـ`redis.acl_check_cmd`، مع إعادة رفض مُرمَّز `NOPERM …` (مثل رد Redis نفسه)، ومعه وثيقة صلاحيات دنيا للمضيف.** يُنفَّذ في تكليف منفصل بعد حكم الـOwner؛ ولا يُدمج هنا.

ما قيس على المتغير الدراسي (`tests/Support/Redis/AclPreflightCommitExecutor.php`، يعدّل نص `commit` في الطريق ولا يلمس `src/`): في الحالات السبع (`-del -hset -set -sadd -zadd -pexpireat` وسحب `-del` قبل الحفظ) **لا تغيير في أي مفتاح** بدل التطبيق الجزئي؛ ضابط النجاح يطابق الأصل؛ تقييم واحد؛ الاستثناء `NOPERM request commit: the account may not run <CMD> on <key>` من الخادم (`RateLimiterException` عبر RESP، `\RedisException` عبر phpredis) ولا يُنتج إشارة ولا إعادة محاولة. القياس الزمني (`raw/bench-64.log`، 400 طلب لكل ذراع، ذراعان بالتناوب، Redis على الشبكة المحلية): متوسط/وسيط زمن `commit` غير متمايز عن الضوضاء (≈ 240–256 µs وسيطًا؛ ذراع الاختبار يعمل ثانيًا)، وعلى 32-bit تحت المحاكاة (`raw/bench-32.log`) تقريبي. **لا ادعاء بأن الكلفة صفر**؛ ما قيس أنها لم تظهر فوق الضوضاء في هذه الحزمة.

| ما يضمنه الفحص | ما لا يضمنه (شروط تهيئة المضيف) |
|---|---|
| إذا مرّ الفحص فلن يُرفض أمر كتابة من أوامر الخطة بسبب ACL داخل نفس السكربت: Redis ينفّذ السكربت ذريًا فلا يتغير الـACL بين الفحص والكتابة | لا يمنع خطأ Redis آخر أثناء الكتابة (حدّ الذاكرة، replica للقراءة فقط…)؛ **غياب خطأ ACL لا يثبت rollback**، ولم تُختبر هذه الأخطاء هنا |
| يغطي مفاتيح الخطة (حجج الأمر تُفحص مقابل نطاق المفاتيح) | رفض `EVAL` نفسه ونطاق المفاتيح المعلنة يقعان قبل السكربت أصلًا (F6)؛ فيبقى على المضيف منح `+eval` وقراءة وكتابة على نطاق المفاتيح، **وقاعدة المفتاح الفارغ `~`** للمحرك |
| لا يحتاج رحلة شبكة إضافية | صلاحيات **القراءة** (F4) لا يحميها هذا الفحص ولا تحتاجه (الرفض قبل أي كتابة) |

بدائل فعلية:

1. **وثيقة صلاحيات دنيا فقط (بلا تغيير كود).** أقل تدخلًا؛ تُبقي التطبيق الجزئي لأي حساب خاطئ التهيئة أو سُحبت منه صلاحية في الإنتاج، وتحتاج اختبار تهيئة على keyspace غير فارغ (F7).
2. **الفحص داخل `commit` (المقترح).** يغلق F5 للـACL بكلفة نص سكربت أطول قليلًا؛ لا يغيّر الواجهة العامة ولا `EVAL` ولا الإعدادات؛ رسالة الخطأ تسمّي الأمر والمفتاح (على خلاف رسالة Redis)؛ **يجب أن يحمل الرد رمز خطأ** (`NOPERM`) كي لا يُصنّفه Host يعتمد الرمز كعطل نقل: وقد رُصد أن نصًا بلا رمز (`request commit: …`) صُنّف عطل نقل في غلاف phpredis، وهو نفس نمط رسائل `request commit: …` القائمة اليوم في `COMMIT` (ملاحظة تستحق مراجعتها ضمن التنفيذ لا هنا).
3. **فحص مبكر أثناء قراءة الـoverlay (قبل التقييم) إضافة إلى الحفظ.** يبكّر الفشل قبل عمل الطلب لكنه لا يغني عن فحص الحفظ (قد يُسحب الإذن بعدها) ويضيف نصًا لكل `EVAL`؛ لا يرجّح.
4. **واجهة جاهزية (`verifyRequestSaveAccess()` ونحوها) يستدعيها المضيف عند الإقلاع.** تكشف سوء التهيئة مبكرًا لكنها تغيّر الواجهة العامة، ولا تحمي من سحب لاحق؛ تحتاج قرارًا مستقلًا.
5. **تصنيف خطأ ACL كـ`BackendFailureException`.** مرفوض: يناقض DEC-015/018 ويحوّل خطأ تهيئة إلى فشل دائرة وfallback وإشارة عطل، ويُخفي الـprovenance.

أثر المقترح (2):

| المحور | الأثر |
|---|---|
| الصلاحيات الدنيا | لا تتغير (نفس الأوامر الخمسة عشر)؛ المتغير أن غياب أمر كتابة يصبح رفضًا نظيفًا بدل ضياع حالة |
| رسائل Redis | رفض الحفظ بنص `NOPERM request commit: the account may not run … on …` بدل نص السكربت العام؛ رفض `EVAL` وقراءات الـoverlay يبقيان بنص Redis |
| توافق عملاء الـHost | رد خطأ بكود معروف يمر كما يمر `NOPERM` اليوم في عميلَي الدراسة؛ لم يُفحص Predis أو عملاء آخرون |
| الإشارات وإعادة المحاولة | خطأ يخرج من `commitRequest()` خارج `try` فلا إعادة تقييم (التقييمات = 1 قيست)؛ لا إشارة ولا فشل دائرة (مثل اليوم) |

## 7. ما لم يُقَس

Redis الأحدث من 7.0.15، Valkey، Cluster/Sentinel، RESP3، Predis، وضع `EVALSHA`، مسار التعافي والـadmission والسكربتات المباشرة، أخطاء Redis غير ACL أثناء `commit` (OOM، READONLY)، والأحمال المتزامنة. النتائج أعلاه محصورة بما فُحص.

## 8. إعادة الإنتاج

كل تشغيل داخل Docker مع Redis معزول لكل استدعاء (`redis:7.0.15-alpine`، شبكة خاصة تُحذف بعد التشغيل). الصور المحلية: `maatify-rl-php64:local` (`linux/arm64`، PHP 8.4.27، `PHP_INT_SIZE=8`) و`maatify-rl-php32:local` (`linux/386` تحت المحاكاة، PHP 8.4.27، `PHP_INT_SIZE=4`)، ext-redis 6.3.0، PHPUnit 11.5.57. الصورتان بلا `git`.

```bash
investigations/redis-acl-request-save-261/run.sh 64 investigations/redis-acl-request-save-261/raw/evidence-64.jsonl --do-not-cache-result tests/Integration/Redis/RequestSaveAclRedisTest.php
investigations/redis-acl-request-save-261/run.sh 32 investigations/redis-acl-request-save-261/raw/evidence-32.jsonl --do-not-cache-result tests/Integration/Redis/RequestSaveAclRedisTest.php
python3 -I investigations/redis-acl-request-save-261/render.py
investigations/redis-acl-request-save-261/bench.sh 64 400   # وحده، لا بالتوازي مع اختبارات
```

| السجل | النتيجة |
|---|---|
| `raw/final-64.log` | `OK (80 tests, 1662 assertions)` — `PHP_INT_SIZE=8` |
| `raw/final-32.log` | `OK (80 tests, 1662 assertions)` — `PHP_INT_SIZE=4` |
| `raw/related-64.log`، `raw/related-32.log` | `RequestAtomicityBoundariesRedisTest`: `OK (6 tests, 402 assertions)` على العرضين (عدم تأثر المسار القائم؛ لا تغيير تحت `src/`) |
| `raw/bench-64.log`، `raw/bench-32.log` | قياس كلفة الـpreflight (§6) |
| `raw/evidence-{64,32}.jsonl`، `raw/evidence-table.md` | الأدلة الخام والجدول |
| `raw/governance-helper.json` | مخرج الـGovernance Helper |

الاستقرار: كُرّر تشغيل ملف الاختبار ثلاث مرات على كل عرض أثناء البناء (نجح كله). فحوصات الجودة المحلية على الملفات المضافة: PHPStan `--level=max` نظيف، PHP-CS-Fixer بلا تغيير، `check-per-cs-31-delta` ناجح. الفحوص الكاملة للمستودع (ReleaseVerification وتاريخ 2999 على 32-bit: #267؛ مهلات NM-7 وBlockNoDowngrade على 32-bit المحاكى: #268) **لم تُشغَّل محليًا هنا**؛ دليلها الطبيعي من CI على رأس التسليم.

## 9. التفاصيل الحساسة

لا تفاصيل حساسة هنا: الحسابات مؤقتة والكلمات اصطناعية والبيانات اصطناعية. السلوك المرصود (ضياع حالة عند ACL يمنع أمر كتابة) ناتج عن تهيئة المشغّل ولا يوفّر ثغرة بمفرده؛ وأي تفصيل استغلالي يُبلَّغ عبر `SECURITY.md` لا عبر هذه الدراسة.
