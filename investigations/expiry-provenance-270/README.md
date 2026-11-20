# #270: مصدر فشل الانتهاء في استدعاءات الـstore المباشرة

دراسة تشخيصية فقط. لا تغيير في `src/` أو العقود العامة. الأساس: `main@18fa1c01411fa45fa0823cc06f652c3da5b939dd` (الشجرة `123aac06f189a5f1f411e5f87d513443a7f5b2d1`).

## البيئة
Docker فقط، شبكة داخلية بلا منافذ منشورة، Redis `7.0.15-alpine` جديد لكل عرض، بيانات اصطناعية، حساب ACL مؤقت لكل حالة. PHP 8.4.27 بعرضي `PHP_INT_SIZE=8` (`maatify-rl-php64:local`، linux/arm64) و`4` (`maatify-rl-php32:local`، linux/386 بمحاكاة). PHPUnit 11.5.57، ext-redis 6.3.0. عميلان: RESP (`RespRedisCommandExecutor`) وphpredis (`PhpRedisScriptExecutor` مع تصنيف الـHost بـ`isConnected()` كما في #261).

الأمر (من جذر المستودع، الصورة `php64` أو `php32`):

```bash
EVIDENCE_DIR=/path/to/out investigations/expiry-provenance-270/run.sh php64 tests/Integration/Redis/ExpiryProvenanceDirectStudy270Test.php
```

القياس: `tests/Integration/Redis/ExpiryProvenanceDirectStudy270Test.php`. يقرأ حالة المفاتيح من خارج Lua بـ`DUMP` و`PEXPIRETIME` قبل وبعد، ويقرأ `ACL LOG`.

## النتيجة العامة
على كل عرض: 58 اختبارًا و819 تأكيدًا نجحت، و242 سطر دليل في `raw/run-64/evidence.jsonl` و`raw/run-32/evidence.jsonl`. الأسطر متطابقة بين العرضين عدا حقل `int` وقيم `ttl_ms` المطلقة.

## ما قيس
15 مسارًا عامًا مباشرًا (الرفض الملتقط في كل منها جاء من `redis.pcall` لأمر الانتهاء) × أمر محظور (`EXPIRE` أو `PEXPIRE` أو `PEXPIREAT`) × عميل (RESP وphpredis) × حالة المفتاح (جديد، موجود مسبقًا بانتهاء معروف).

| الدالة العامة | السكربت | الأمر الذي وُصل إليه فعلًا | جديد | موجود مسبقًا |
|---|---|---|---|---|
| `incrementAt`, `incrementIfSnapshotAt` | SCORE_INCREMENT / SCORE_SET_IF_SNAPSHOT | `EXPIRE` | رفض | لا يصل إلى الأمر (الكتابة نجحت، `incrementAt` غيّر المحتوى) |
| `replaceScoreAt`, `setAt` | SCORE_REPLACE / SCORE_SET | `EXPIRE` | رفض | رفض، الحالة بلا تغيير |
| `blockAt` | BLOCK_SET | `EXPIRE` | رفض | لا يصل |
| `incrementBudgetAt` | BUDGET_INCREMENT | `EXPIRE` | رفض | لا يصل (المحتوى تغيّر) |
| `addDistinctAt`, `addDistinctBoundedAt`, `addDistinctBoundedWithSnapshotAt`, `addDeviceCapPairAt` | DISTINCT_ADD / BOUNDED_SNAPSHOT_PLAN (خطة اللقطة المحدودة) | `EXPIRE` (مفتاحان في بعضها) | رفض، المفاتيح المنشأة حُذفت | لا يصل |
| `incrementWatchFlagAt`, `incrementWatchFlagAcrossRotationAt` | WATCH_INCREMENT | `EXPIRE` | رفض | لا يصل (المحتوى تغيّر) |
| `addDistinctAcrossRotationAt` | ROTATED_SNAPSHOT | `EXPIRE` | رفض | لا يصل |
| `acquireProbeLease` | LEASE | `EXPIRE` | رفض | لا يصل |
| `blockWithCycleTracking` | HARD_BLOCK | `EXPIRE` (عدة مفاتيح) | رفض | رفض، الحالة بلا تغيير |
| `mutateGenerationBoundScoreAtMilliseconds` | LIFECYCLE_MUTATE | `EXPIRE` | رفض | لا يصل |
| `blockWithPunishmentLifecycleTrackingAt` | HARD_BLOCK (دورة الحياة) | `EXPIRE` | رفض | رفض |
| `addDistinctBoundedAcrossRotationAtMilliseconds` (مع جيل سابق) | ROTATED_SNAPSHOT / جسر الجيل | `EXPIRE` و`PEXPIREAT` | رفض في الاثنين | رفض في الاثنين |

بمفاتيح أُنشئت في الاستدعاء نفسه (متعدد المفاتيح)، الرفض حذف كل ما أُنشئ والحالة المقيسة لم تتغير. في كل رفض وصل إليه المسار، ولكل عميل ولكل عرض: `DUMP` و`PEXPIRETIME` قبل وبعد متطابقان (حالة المفتاح الجديد والموجود مسبقًا والمتعدد المفاتيح).

### الفروق بين الفشل الخمسة (دالة `incrementAt`، سكربت SCORE_INCREMENT)
| الحالة | أين رُفضت | الاستثناء (RESP / phpredis) | سجل ACL | الأثر |
|---|---|---|---|---|
| انتهاء صالح (ضابط) | لا رفض | تعود | لا شيء | مفتاح جديد بانتهاء صحيح |
| `ttl = 0` | تحقق PHP | `RateLimiterException` `Score TTL must be positive.` / نفسه | لا شيء | بلا أثر |
| صلاحية `EXPIRE` ممنوعة | داخل Lua: رد خادم أمسكه `pcall` | `RateLimiterException` / `RedisException`، النص `expiry not representable` | `expire` | بلا أثر |
| تجاوز مدى `EXPIRE` فعلي | داخل Lua: رد خادم أمسكه `pcall` | **النص نفسه** `expiry not representable` | **لا شيء** | بلا أثر |
| تجاوز preflight الـLua | preflight Lua | `score TTL exceeds Redis backend expiry representability` | لا شيء | بلا أثر |
| وقت المتصل غير صالح | Lua | `caller time is not representable` | لا شيء | بلا أثر |
| رفض اتصال (لا أحد يستمع) | طبقة الاتصال | `BackendFailureException` (بالطريقتين) | — | — |
| قطع الاتصال بعد المصادقة | طبقة الاتصال | RESP: `BackendFailureException`؛ phpredis: أعاد ext-redis الاتصال مرة وعادت المكالمة ناجحة | — | phpredis: كتب المفتاح |

نص رفض الصلاحية ونص تجاوز المدى الفعلي **واحد**، والمفرّق الوحيد المقيس هو `ACL LOG`. الفرق بين RESP وphpredis في صنف الاستثناء فقط (`RateLimiterException` مقابل `RedisException`)، والنص والأثر والـACL LOG متطابقة.

حالة تجاوز المدى الفعلي تحتاج `ttl` يتجاوز 2^53 ثانية ولا يُمثَّل بـ`int` أصلي على 32-bit، فشُغّل نص السكربت المشحون نفسه (`SCORE_INCREMENT` بالانعكاس) عبر `EVAL` بوسائط نصية على العرضين. لم أشغّل النداء العام `incrementAt` بتلك القيمة على 64-bit بصورة منفصلة.

## الجرد من المصدر (غير مقيس)
سكربتات `redis.call('EXPIRE'…)` بلا `pcall` في `RedisAdmissionOperations` (الأسطر 329-541) وفي مرحلة الإلحاق بـ`DEVICE_CAP_COMMIT` (`SET … EX` و`EXPIRE`، السطران 1293 و1296) ومسار `PEXPIREAT` في `RedisRequestOverlay` (DEC-042، خارج النطاق). فيها يتحول الرفض إلى خطأ سكربت عادي بنص Redis نفسه، لم يُقس هنا؛ `addDeviceCapPairAt` المقيس أعلاه وصل إلى رفضه من `pcall` خطة اللقطة (السطران 1215 و1217) لا من هذه المرحلة. مسار حفظ الطلب مستبعد صراحةً.

## ما لم يُقَس
- مسار علامة `claimPostPunishmentReentry` (`PEXPIRE` على العلامة): لم يصل إليه الإعداد المستخدم، فلا دليل له.
- `PEXPIRE` في LIFECYCLE_MUTATE: لم يُصل إليه بمنع `pexpire`.
- مسارات `RedisAdmissionOperations` وأمر `SET … EX` غير المحروس.
- نص خطأ `pcall` الأصلي من Redis (سجّلت النص النهائي للمتصل فقط)، فالتفريق المقترح أدناه يحتاج التقاطه أولًا.
- رفض تجاوز المدى على مسارات غير `incrementAt`.

## نطاق إصلاح مستقل مقترح ومعيار قبوله
1. في السكربتات التي تمر بـ`expiryApplied()` وملحقاتها: التقاط نص خطأ `pcall` وتمييز رفض الصلاحية (`NOPERM`/"can't run this command") عن خطأ المدى، وإرجاع رد خطأ مميز لرفض الصلاحية بدل `expiry not representable`.
2. تحويل الرد المميز في PHP إلى استثناء typed ليس `BackendFailureException` (ليس عطل اتصال، DEC-015) ولا يشارك نص تجاوز المدى (DEC-018: المفسّر هو الـHost/adapter).
3. القبول: لكل دالة في الجدول، على 32 و64-bit مع RESP وphpredis، رفض صلاحية ≠ تجاوز مدى بنص/صنف مختلف، و`DUMP`/`PEXPIRETIME` لم يتغيرا، وسجل ACL مطابق للنوع، واختبار سلبي لا يصنف قطع الاتصال كرفض صلاحية. يُحدّث `FAILURE_SEMANTICS.md` (LT-P2-12) بالنص الجديد.
4. ملاحظة مستقلة: مسارات الكتابة على مفتاح موجود لا تصل إلى `EXPIRE`، فحساب بلا `EXPIRE` يكتب بلا خطأ على المفاتيح الموجودة (محتوى `incrementAt` و`incrementBudgetAt` و`incrementWatchFlagAt` تغيّر). يستحق سطرًا في التوثيق، لا إصلاحًا في هذه المرحلة.
