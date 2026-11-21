# #270: مصدر فشل الانتهاء في استدعاءات الـstore المباشرة

دراسة تشخيصية فقط، ودليل أولي بحدوده. لا تغيير في `src/` أو العقود العامة. الأساس: `main@18fa1c01411fa45fa0823cc06f652c3da5b939dd` (الشجرة `123aac06f189a5f1f411e5f87d513443a7f5b2d1`). رأس المصدر الذي أُنتج منه كل سجل خام مكتوب في `manifest.json` بجواره (الحقول `source_head` و`source_tree` و`source_dirty`)، لا في هذا الملف.

## البيئة والتشغيل
Docker فقط، شبكة داخلية بلا منافذ منشورة، Redis `7.0.15-alpine` جديد لكل عرض، بيانات اصطناعية، حساب ACL مؤقت لكل حالة. PHP 8.4.27 بعرضي `PHP_INT_SIZE=8` (`maatify-rl-php64:local`، linux/arm64) و`4` (`maatify-rl-php32:local`، linux/386 بمحاكاة). PHPUnit 11.5.57، ext-redis 6.3.0. عميلان: RESP (`RespRedisCommandExecutor`) وphpredis (`PhpRedisScriptExecutor` مع تصنيف الـHost بـ`isConnected()` كما في #261).

```bash
investigations/expiry-provenance-270/run.sh php64 tests/Integration/Redis/ExpiryProvenanceDirectStudy270Test.php
investigations/expiry-provenance-270/run.sh php32 tests/Integration/Redis/ExpiryProvenanceDirectStudy270Test.php
```

المشغّل (`run.sh`) ينشئ مجلد الأدلة بنفسه (لا يقبل مجلدًا موجودًا ولا مجلدًا داخل المستودع)، ويرفض شجرة غير نظيفة (`ALLOW_DIRTY=1` للتطوير فقط ويُسجَّل في `manifest.json`)، وينتظر جاهزية Redis بمهلة، ويتحقق **قبل القياس** أن عرض `int` في الصورة هو المطلوب (`php32` ⇒ 4، `php64` ⇒ 8) ثم أن كل سطر دليل يحمل العرض نفسه، وينظّف الحاوية والشبكة عند أي خروج. يخرج بقيمة غير صفرية عند فشل التهيئة أو التشغيل أو **حفظ اللوج** (رمز `tee` مفحوص بجانب رمز PHPUnit) أو عدم تطابق العرض أو غياب الدليل أو عدم اكتماله (عدد الأسطر المتوقع 552). ويكتب `manifest.json` فيه: رأس المصدر والشجرة وحالة النظافة والأمر والإصدارات وعرض `int` وملخص PHPUnit وعدد الأسطر وSHA-256 للدليل والحالة. `selftest.sh` يختبر هذه الضوابط (منها صورة `php32` بعرض 8 وفشل `tee` برمز 23) بلا Docker فعلي (docker وهمي ومستودع مؤقت يملكهما الاختبار).

القياس: `tests/Integration/Redis/ExpiryProvenanceDirectStudy270Test.php`. يقرأ حالة المفاتيح من خارج Lua بـ`DUMP` و`PEXPIRETIME`، ويقرأ `ACL LOG`، ويقرأ عدّادات Redis للأوامر (`INFO commandstats`: `calls` و`rejected_calls` و`failed_calls` لـ`expire`/`pexpire`/`pexpireat`) بعد `CONFIG RESETSTAT` قبل كل نداء.

## النتيجة العامة (على كل عرض)
69 اختبارًا (7187 تأكيدًا على 64-bit و7173 على 32-bit؛ الفرق 14 تأكيدًا لنداء `incrementAt` العام بقيمة لا تُمثَّل بـ`int` أصلي على 32-bit) ونجحت. 552 سطر دليل متطابقة بين العرضين في الحقول البنيوية (النوع والدالة والفرع والاستثناء والرسالة والفرق والسجل والعدّادات)، عدا سطرَي «`incrementAt` العام بقيمة المدى (64-bit فقط)» اللذين يُسجَّلان `not-run` على 32-bit.

## ما قيس
19 دالة عامة مباشرة × أمر محظور (`EXPIRE` أو `PEXPIRE` أو `PEXPIREAT`) × عميل (RESP وphpredis) × حالة. 18 منها وصل فيها المسار إلى رفض أحد أوامر الانتهاء؛ الدالة 19 (`claimPostPunishmentReentry…`) لم تصل في أي حالة.

**الحالات.** `fresh`: لا مفتاح سابق لهذا النداء. `existing`: نُفّذ النداء نفسه قبلها بحساب كامل ثم ضُبط كل انتهاء على 3000 ثانية. `prerequisite-only`: للمسارات التي تحتاج حالة سابقة (كتلة دورة حياة أو جيل سابق) ولا تُنشئ مفاتيحها إلا بالنداء نفسه، فالإعداد المطلوب هو الحالة الأولى ولا «جديد» مستقل عنها؛ و`claim…` لا حالة `existing` صادقة له.

**التحقق لكل صف.** المتوقع مثبَّت في الاختبار (`REACHED`) لكل (دالة، أمر محظور، حالة). صف متوقع الوصول يفشل الاختبار إن لم يُسجَّل في `ACL LOG` الأمر المحظور وحده، أو إن لم يكن الاستثناء من صنف العميل (RESP: `RateLimiterException`، phpredis: `RedisException`) ورسالته `expiry not representable`، أو إن تغيّر `DUMP`/`PEXPIRETIME`، أو إن لم يعدّ Redis `rejected_calls=1` للأمر. صف غير متوقع الوصول يفشل إن ظهر رفض أو اختلف الناتج أو الفرق في الـkeyspace عن **ضابط حساب كامل** من الحالة نفسها. ضابط الحساب الكامل يتحقق أيضًا أن كل مفتاح له انتهاء مطلق في المستقبل. وفي كل ذراع (الضابط والرفض) **مفتاح تحكم مستقل** (`exp270-sentinel`) بقيمة وانتهاء مطلق معروفين، لا يلمسه أي نداء، تُقارَن بايتات `DUMP` ونوعه و`PEXPIRETIME` له قبل وبعد، فتغيّر غير مقصود في ذراع يفشل الاختبار.

**الحسابات.** المصفوفة الواسعة تستخدم `+@all` ناقص أمر واحد، وهو **ضابط عزل المتغير** (يزيل أمرًا واحدًا فقط). وتُعاد خمسة مسارات ممثلة (`incrementAt` و`blockAt` و`addDistinctAt` و`blockWithCycleTracking` و`addDistinctBoundedAcrossRotationAtMilliseconds`) بحساب محدود بالفئات (`-@all +@scripting +@read +@write +@connection` ناقص الأمر) لـ`EXPIRE` و`PEXPIREAT`، والنتيجة مطابقة للمتوقع نفسه.

**العدّ.** صفوف الرفض الفعلي في المصفوفة الواسعة: 46 لكل عرض (`EXPIRE` 44 و`PEXPIREAT` 2 و`PEXPIRE` صفر)، و14 صفًا إضافيًا تحت الحساب المحدود بالفئات. صفوف الضابط (حساب كامل) 262. صفوف غير الواصل (الأمر المحظور لم يُحتج إليه): 176 في الواسعة و26 في المحدودة.

| الدالة العامة | السكربت | الأمر الذي وُصل إليه فعلًا | حالات وصل فيها الرفض | حالات لا يصل فيها (مطابقة للضابط) |
|---|---|---|---|---|
| `incrementAt`, `incrementIfSnapshotAt` | SCORE_INCREMENT / SCORE_SET_IF_SNAPSHOT | `EXPIRE` | fresh | existing |
| `replaceScoreAt`, `setAt` | SCORE_REPLACE / SCORE_SET | `EXPIRE` | fresh, existing | — |
| `blockAt` | BLOCK_SET | `EXPIRE` | fresh | existing |
| `incrementBudgetAt` | BUDGET_INCREMENT | `EXPIRE` | fresh | existing |
| `addDistinctAt`, `addDistinctBoundedAt`, `addDistinctBoundedWithSnapshotAt`, `addDeviceCapPairAt` | DISTINCT_ADD / خطة اللقطة المحدودة | `EXPIRE` (مفتاحان في بعضها) | fresh | existing |
| `incrementWatchFlagAt`, `incrementWatchFlagAcrossRotationAt` | WATCH_INCREMENT | `EXPIRE` | fresh | existing |
| `addDistinctAcrossRotationAt` | ROTATED_SNAPSHOT | `EXPIRE` | fresh | existing |
| `acquireProbeLease` | LEASE | `EXPIRE` | fresh | existing |
| `blockWithCycleTracking` | HARD_BLOCK | `EXPIRE` (عدة مفاتيح) | fresh, existing | — |
| `mutateGenerationBoundScoreAtMilliseconds` | LIFECYCLE_MUTATE | `EXPIRE` | fresh | existing |
| `blockWithPunishmentLifecycleTrackingAt` | HARD_BLOCK (دورة الحياة) | `EXPIRE` | prerequisite-only, existing | — |
| `addDistinctBoundedAcrossRotationAtMilliseconds` | ROTATED_SNAPSHOT / جسر الجيل | `EXPIRE` و`PEXPIREAT` | prerequisite-only (في الاثنين) | existing |
| `claimPostPunishmentReentryAtMilliseconds` | سكربت المطالبة | لا شيء | لا حالة | prerequisite-only (لم يصل إلى أمر انتهاء ممنوع) |

في كل رفض وصل إليه المسار، ولكل عميل ولكل عرض: `DUMP` و`PEXPIRETIME` قبل وبعد متطابقان، بما فيها المسارات المتعددة المفاتيح (الرفض يحذف ما أُنشئ في النداء نفسه). في حالات «لا يصل» التي تغيّر فيها المحتوى (مثل `incrementAt` على مفتاح موجود) الناتج والفرق مطابقان لضابط الحساب الكامل: الكتابة تتم بلا خطأ لأن المسار لا يستدعي أمر الانتهاء الممنوع.

### الفروق بين الفشل (دالة `incrementAt`، سكربت SCORE_INCREMENT)
| الحالة | أين رُفضت | الاستثناء (RESP / phpredis) | سجل ACL | عدّاد Redis لـ`expire` | الأثر |
|---|---|---|---|---|---|
| انتهاء صالح (ضابط، عبر EVAL وعبر `incrementAt`) | لا رفض | تعود | لا شيء | `calls=1` | مفتاح جديد؛ القيمة 1 و`updatedAt` كما مُرّر و`PEXPIRETIME` محصور بين قراءتَي Redis `TIME` حول النداء مضافًا إليهما 600 ثانية بلا هامش (مقروءة بـ`get()` وPEXPIRETIME) |
| `ttl = 0` | تحقق PHP | `RateLimiterException` `Score TTL must be positive.` / نفسه | لا شيء | لا شيء | بلا أثر |
| صلاحية `EXPIRE` ممنوعة | داخل Lua: رد خادم أمسكه `pcall` | `RateLimiterException` / `RedisException`، النص `expiry not representable` | `expire` | `rejected_calls=1` | بلا أثر |
| تجاوز مدى `EXPIRE` فعلي | داخل Lua: رد خادم أمسكه `pcall` | **النص نفسه** `expiry not representable` | **لا شيء** | `failed_calls=1` (و`rejected_calls=0`) | بلا أثر |
| تجاوز preflight الـLua | preflight Lua | `score TTL exceeds Redis backend expiry representability` | لا شيء | لا شيء (لم يصل إلى `EXPIRE`) | بلا أثر |
| وقت المتصل غير صالح | Lua | `caller time is not representable` | لا شيء | لا شيء | بلا أثر |
| رفض اتصال على منفذ أُثبت إغلاقه على مضيف Redis نفسه (رفض فعلي برمز 111 قبل الاستخدام) | طبقة الاتصال | `BackendFailureException` (بالطريقتين) | — | — | — |
| قطع اتصال الـstore المعيَّن بالاسم (`CLIENT SETNAME`، يُقتل بالمعرّف ويُتحقق من اختفائه) | طبقة الاتصال | RESP: `BackendFailureException`؛ phpredis: **رصد مشروط** بـ`OPT_MAX_RETRIES` (انظر أدناه) | — | — | — |

نص رفض الصلاحية ونص تجاوز المدى الفعلي **واحد** في ما يراه المتصل. المفرّقان المقيسان على جانب Redis: `ACL LOG` والعدّاد (`rejected_calls` مقابل `failed_calls` على `expire`). الفرق بين RESP وphpredis في صنف الاستثناء فقط.

**المدى.** حالة تجاوز المدى بقيمة عشرية (`9223372036854775` ثانية) تُشغَّل على العرضين عبر `EVAL` بنص السكربت المشحون نفسه (بالانعكاس)، لأن القيمة لا تُمثَّل بـ`int` أصلي على 32-bit. ونداء `incrementAt` العام بالقيمة نفسها يُقاس **منفصلًا على 64-bit فقط** ونتيجته مطابقة (الرسالة، لا رفض ACL، `failed_calls=1`، ولقطة الحالة مساوية لمسار EVAL: لا تغيير)؛ على 32-bit يُسجَّل `not-run`. دليل بلوغ Redis لأمر الانتهاء هو العدّاد (`calls=1`، `failed_calls=1`)، لا استنتاج من الرسالة.

**القطع.** phpredis بالإعداد الافتراضي (`OPT_MAX_RETRIES=10`) أعاد الاتصال وعادت المكالمة ناجحة؛ بـ`OPT_MAX_RETRIES=0` رفعت `BackendFailureException` (`Redis transport failure: Connection lost`، والاختبار يثبّت الصنف والنتيجة). فهذا رصد مشروط بالإعداد وليس عقدًا للمكتبة.

**رد `pcall`.** لتثبيت ما يُمكن تثبيته استُعمل سكربت فحص (**ليس سكربتًا مشحونًا**) يعيد نص خطأ `redis.pcall`: رفض الصلاحية يعيد `ERR The user executing the script can't run this command or subcommand` (للأوامر الثلاثة)، وتجاوز المدى يعيد `ERR invalid expire time in 'expire' command`، بالعميلين وعلى العرضين. السكربتات المشحونة تُسقط هذا النص اليوم عند `expiryApplied()`. تحويله إلى تمييز معتمد على نصه سؤال مفتوح: يلزمه قرار منفصل (DEC-015 وDEC-018 يفرّقان بين رد Redis وتصنيف النقل، وأي نوع استثناء عام جديد يحتاج قرارًا).

## الجرد من المصدر (غير مقيس)
سكربتات `redis.call('EXPIRE'…)` بلا `pcall` في `RedisAdmissionOperations` (الأسطر 329-541) وفي مرحلة الإلحاق بـ`DEVICE_CAP_COMMIT` (`SET … EX` و`EXPIRE`، السطران 1293 و1296) ومسار `PEXPIREAT` في `RedisRequestOverlay` (DEC-042، خارج النطاق). فيها يتحول الرفض إلى خطأ سكربت عادي بنص Redis نفسه، لم يُقس هنا؛ `addDeviceCapPairAt` المقيس أعلاه وصل إلى رفضه من `pcall` خطة اللقطة (السطران 1215 و1217) لا من هذه المرحلة. مسار حفظ الطلب مستبعد صراحةً.

## ما لم يُقَس
- مسار علامة `claimPostPunishmentReentry` (`PEXPIRE` على العلامة): لم يصل إليه الإعداد المستخدم في أي حالة، فلا دليل له.
- `PEXPIRE` في LIFECYCLE_MUTATE: لم يُصل إليه بمنع `pexpire` (صفر صف رفض لـ`PEXPIRE`).
- مسارات `RedisAdmissionOperations` وأمر `SET … EX` غير المحروس.
- نص `pcall` داخل السكربتات المشحونة نفسها (قيس نص الأمر المرفوض بسكربت فحص لا بالسكربت المشحون).
- رفض تجاوز المدى على مسارات غير `incrementAt`.
- سلوك إعادة اتصال phpredis خارج `OPT_MAX_RETRIES` 10 و0.

## نطاق إصلاح مستقل مقترح ومعيار قبوله
1. في السكربتات التي تمر بـ`expiryApplied()` وملحقاتها: التقاط نص خطأ `pcall` وتمييز رفض الصلاحية (`can't run this command`) عن خطأ المدى (`invalid expire time`)، وإرجاع رد خطأ مميز لرفض الصلاحية بدل `expiry not representable`. يحتاج قرارًا قبل اعتماد تمييز مبني على النص.
2. تحويل الرد المميز في PHP إلى استثناء typed ليس `BackendFailureException` (ليس عطل اتصال، DEC-015) ولا يشارك نص تجاوز المدى (DEC-018: المفسّر هو الـHost/adapter). أي نوع استثناء عام جديد يحتاج قرارًا منفصلًا.
3. القبول: لكل دالة في الجدول، على 32 و64-bit مع RESP وphpredis، رفض صلاحية ≠ تجاوز مدى بنص/صنف مختلف، و`DUMP`/`PEXPIRETIME` لم يتغيرا، وسجل ACL وعدّادات Redis مطابقة للنوع، واختبار سلبي لا يصنف قطع الاتصال كرفض صلاحية. يُحدّث `FAILURE_SEMANTICS.md` (LT-P2-12) بالنص الجديد.
4. ملاحظة مستقلة: مسارات الكتابة على مفتاح موجود لا تصل غالبًا إلى `EXPIRE`، فحساب بلا `EXPIRE` يكتب بلا خطأ على المفاتيح الموجودة (محتوى `incrementAt` و`incrementBudgetAt` و`incrementWatchFlagAt` يتغير بلا خطأ). يستحق سطرًا في التوثيق، لا إصلاحًا في هذه المرحلة.

---

# الإصلاح والتحقق قبل/بعد (#270، DEC-043)

هذا القسم يخص تنفيذ #270 بعد اعتماد الـOwner لعقد التصنيف (تكليف المنفّذ على #270، الأساس `main@971abb58ef542aa72758f2fea406eff1110085f1`، الشجرة `3dde9fed9e1fed435d1cb7792506d690825a5324`). نتائج الدراسة أعلاه تظل منسوبة للـSHA الذي أنتجها؛ هذا القسم وما تحته (`fix/`) نتاج الإصلاح.

## ما تغيّر

- **السلوك (DEC-043):** عند فشل `EXPIRE` أو `PEXPIRE` أو `PEXPIREAT` في استدعاء مباشر للمخزن، يفحص السكربت **الأمر نفسه والوسائط نفسها** بـ`redis.acl_check_cmd`. أمر لا يحق للحساب تشغيله يُرجع رد خطأ `NOPERM this user has no permissions to run the '<command>' command` (اسم الأمر فقط، بلا مفتاح ولا قيمة). غير ذلك يبقى `expiry not representable` كما كان (تجاوز المدى الحقيقي). غياب `acl_check_cmd` أو فشله يبقي الرد القديم. حذف المفاتيح التي أنشأها النداء (all-or-nothing) وفحص المدى المسبق وترتيب العمليات وأصناف الاستثناء على العميل كما هي.
- **الموضع:** المساعد المشترك `LUA_EXPIRY_JUDGE` في [RedisFullCapabilityStore.php](../../src/Repository/Redis/RedisFullCapabilityStore.php) (يُضمَّن في `LUA_TIME_PRELUDE` وفي `LIFECYCLE_MUTATE` و`LIFECYCLE_CLAIM` و`HARD_BLOCK`). **38 موضع استدعاء** تحوّلت إليه، منها مواضع كانت تبتلع الخطأ بـ`pcall` يدويًا (علامة claim، تمديد الإيقاف، حفظ الحظر) ومنها المرجع `expireBridgeKey`. لا يبقى أي `redis.pcall('EXPIRE'|'PEXPIRE'|'PEXPIREAT')` خارج المساعد.
- **فصل الـoverlay صراحةً:** كل سكربت مخزن يعمل أيضًا داخل `transactRequest()` على overlay لا يُصدر أمر انتهاء حقيقيًا. أضيف `acl_check_cmd = false` في جدول الـoverlay ([RedisRequestOverlay.php](../../src/Repository/Redis/RedisRequestOverlay.php)) فيرى المساعد غيابه ويرد كما قبل، ويبقى حفظ الطلب (DEC-042) القاضي الوحيد للصلاحية. لم يتغير سلوك `transactRequest()`.
- **الاختبارات التي عُدّلت:** `RequestSaveAclRedisTest` (الاستدعاء المباشر يتوقع `NOPERM` بدل نص المدى؛ هذه كانت ملاحظة مستقلة موثقة فيه)، و`RotatedAliasBoundedRedisTest` (حارس نصي على مساعد الجسر يتبع اسم المساعد المشترك؛ الضمان نفسه: `PEXPIREAT` فقط للجسر)، و`ExpiryProvenanceDirectStudy270Test` (انظر أدناه).
- **الوثائق:** [DEC-043](../../docs/decisions/DEC-043_DIRECT_CALL_EXPIRY_REFUSAL_PROVENANCE_NOPERM.md) مفهرس في `DECISIONS_INDEX.md`، وفقرة LT-P2-12 في `docs/FAILURE_SEMANTICS.md` صارت جدولًا لسبب الرفض والرد والحالة.

## التحقق نفسه قبل وبعد

مصفوفة الدراسة نفسها صارت اختبار التحقق: `EXPIRY_270_CONTRACT=before` يؤكد النص القديم على المصدر غير المُصلَح، والافتراضي `after` يؤكد `NOPERM`. كل صف يحمل `contract` والمشغّل يرفض صفًّا من عقد آخر (`contract-mismatch`، خروج 5). المصفوفة وُسّعت بأفرع كانت غير مبلوغة: `PEXPIRE` (تعديل النتيجة المُولَّدة بحالة مثبتة على Current وعلى Previous، وعلامة claim بعد انتهاء الحظر)، وتجديد مفتاح قائم (دورة ميزانية منتهية، بذرة، حظر أقوى، عقد prober منتهٍ، نتيجة منتهية منطقيًا، حظر دورة حياة أطول). كلها بحالات `fresh` و`existing` و`prerequisite-only` وبضابط حساب كامل وحساب محدود بالفئات ومفتاح تحكم مستقل ومقارنة `DUMP` و`PEXPIRETIME` من خارج Lua كما في الدراسة.

| | قبل (`ec57254b…`، شجرة `713ec0b4…`، مصدر `src/` = الأساس) | بعد (`304b1407…`، شجرة `bf9f5ef6…`) |
| --- | --- | --- |
| الأوامر | `EXPIRY_270_CONTRACT=before investigations/expiry-provenance-270/run.sh php64\|php32 tests/Integration/Redis/ExpiryProvenanceDirectStudy270Test.php` | الأمر نفسه بلا المتغير |
| PHP 64-bit (`PHP_INT_SIZE` 8) | 102 اختبار، 10625 تأكيدًا، ناجح | 102 اختبار، 10625 تأكيدًا، ناجح |
| PHP 32-bit (`PHP_INT_SIZE` 4، linux/386) | 102 اختبار، 10611 تأكيدًا، ناجح | 102 اختبار، 10611 تأكيدًا، ناجح |
| الصفوف | 816 في كل عرض | 816 في كل عرض |
| رفض صلاحية بلغ الأمر (لكل عرض) | 90 صفًّا، الرد `expiry not representable` | 90 صفًّا، الرد `NOPERM … '<command>' …` (`expire` 76، `pexpire` 10، `pexpireat` 4) |
| تجاوز المدى الحقيقي | `expiry not representable`، بلا ACL LOG، `failed_calls=1` | **نفسه** |
| عطل الاتصال | `BackendFailureException` (kill مع retries=0؛ ويعيد الاتصال بالافتراضي) | **نفسه** |

`fix/compare-output.txt` من `fix/compare.py`: الصفوف متطابقة بين التشغيلين في العرضين (816 صفًّا): 726 متطابقة بلا فرق، و90 رفضًا مبلوغًا تغيّرت رسالتها فقط من `expiry not representable` إلى `NOPERM`، و0 فرق غير متوقع. المتطابقة تشمل الضوابط ونداء `incrementAt` العام على PHP64 والضابط العشري عبر `EVAL` على PHP32، وصفوف الـ`not-run`. كل رفض مبلوغ: فرق الـkeyspace فارغ (`DUMP`/`PEXPIRETIME`)، `ACL LOG` يسمّي الأمر وحده، `rejected_calls=1`، ومفتاح التحكم المستقل سليم.

المصدر الخام: `fix/raw-before/run-64|32` و`fix/raw-after/run-64|32` (لكلٍّ `evidence.jsonl` و`phpunit.log` و`manifest.json` بالرأس والشجرة والأوامر وإصدارات PHP وRedis وext-redis وPHPUnit وعرض `int` وعدد الاختبارات والتأكيدات وSHA-256). كلاهما من commit نظيف.

## تغطية المواضع

[fix-coverage.md](fix-coverage.md): من 38 موضعًا بلغت المصفوفة **31** برفض صلاحية (بنسخة مُعلَّمة مؤقتة لا تُشحن، لتسمية الموضع في الرد)، و**7 لم تُبلغ**: S21 وS23 وS25 وS27 وS29 وS31 وS38، وكلها **الانتهاء الثاني في زوج مفتاح بيانات ومفتاح بياناته الوصفية**، ويُطبَّق بعد نجاح الأول. رفض الأمر بحسب الاسم يتوقف عند الأول، وصلاحيات المفاتيح لا تفصل `EXPIRE` عن كتابة المفتاح نفسه، فلا يمكن بلوغها بحساب مقيَّد. تصنيفها بالكود نفسه (المساعد المشترك) ولا يُقاس منفصلًا.

## حدود لم تُقَس

- سكربتات القبول التي تستخدم `redis.call` غير المحروس لأمر الانتهاء: لم تتغير ولم تُقَس.
- Redis أقدم من 7.0 (لا `acl_check_cmd`): الرد القديم بحكم الكود، لم يُشغَّل.
- رفض مفتاح بنمط مفاتيح ACL لا بأمر: يُصنَّف `NOPERM` بنفس الآلية (`acl_check_cmd` يفحص المفاتيح أيضًا) لكنه لم يُقَس في المصفوفة.
- الاختبارات الوحدوية `ReleaseVerification` ( تحتاج `git` ومسارات Linux ) فشلت داخل صورة Docker المحلية وعلى macOS لأسباب بيئية (لا `git` في الصورة، و`/var` مقابل `/private/var`)، وليست من هذا التغيير؛ مرجعها CI.
