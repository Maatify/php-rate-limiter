# F-185-03 — تعريف واحد لحضور الحساب

**الحكم والتكليف:** F-185-03 على [#185](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6619805009) (= EX-RC4-02)، وإذن الـ Owner في [التعليق التالي](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6619810606). حكم العقد: الحساب حاضر حين `accountId !== null && accountId !== ''`. هذا توضيح لا DEC. الفرع من `main@b3e77e4`.

**البيئة:** macOS، PHP 8.4.4 مع phpredis، Redis 7.0.15 (`redis:7.0.15-alpine`، صورة CI) على `127.0.0.1:6392`.

## 1. إعادة الإنتاج

```bash
php investigations/account-presence-185/harness/repro.php --host=127.0.0.1 --port=6392
```

* **الإعداد:** `RateLimiterBuilder::fromFullCapabilityStore()` فوق `RedisFullCapabilityStore` عبر phpredis (`CallableRedisCommandExecutor`)، وسياسة `login_protection`، وجلسة موثوقة على جهازها. لكل معرّف db جديد و25 جولة `checkOnly` ثم `recordFailure` بعد كل قبول، ثم `buildOperationalReader()->readScorePolicy()`.
* **الخام:** `logs/repro-base-b3e77e4-redis-7.0.15.txt` و`logs/repro-fix-redis-7.0.15.txt`.

| accountId | `b3e77e4` | الإصلاح |
| --- | --- | --- |
| `owner`، `00`، `1` | 4/25 ثم `SOFT_BLOCK` عند 5؛ الـ reader: k4 حاضر، وk5 بـ score 8 | مطابق |
| `"0"` | **`RateLimiterException: Missing current opaque anchor for device_cap_account correlation state.`** في أول `checkOnly` | 4/25 ثم `SOFT_BLOCK` عند 5؛ k4 حاضر، وk5 بـ score 8، أي مسار `"1"` نفسه |
| `""` | **الاستثناء نفسه** | 25/25 بلا رفض؛ k4 وk5 غائبان، مثل `null` |
| `null` | 25/25؛ k4 وk5 غائبان | مطابق |

## 2. المواضع

`src/Service/AccountPresence.php` (`@internal`) فيه:
* `isPresent(?string)`؛
* `of(RateLimitContextDTO)`؛
* `idOf(RateLimitContextDTO): ?string`، لصيغة `correlationId ?? account`.

عليها `@phpstan-assert-if-true non-empty-string`، فيبقى تضييق النوع الذي كانت تعطيه `!== null`.

| الملف | ما كان على `b3e77e4` | العدد |
| --- | --- | --- |
| `EvaluationPipeline.php` | truthiness: L356، L1690، L1847، L1952، L2001، L2016، L2966، L2967 | 8 |
| | `!== null` / `=== null`: L242، L434، L492، L691، L1674، L2481 (المعامل `$accountId`)، L2561 | 7 |
| | `correlationId ?? accountId`: L1072، L1550 | 2 |
| `RateLimitOperationalReader.php` | `=== null`: L239 (الـ budget)، L436 (k4)، L439 (k5) | 3 |
| `RateLimiterEngine.php` | `=== null`: L433 (re-entry) | 1 |
| `LocalFallbackLimiter.php` | `!== null && !== ''`: L94 | 1 |
| `AdmissionRuntime.php` | `$account === null`: L119 (المسار)؛ `correlationId ?? accountId`: L431 | 2 |

**ما زاد على قائمة التكليف، داخل «كل فحص حضور الحساب» (بند 1):**
* `RateLimitOperationalReader` L239: budget الـ reader، وكان `=== null`، فـ `""` كان يقرأ budget حساب.
* `AdmissionRuntime` L119 وL431.
* `EvaluationPipeline` L1072 وL1550: بالصيغة `??` كان `""` يصير subject فارغًا بدل الغياب.

**الأثر:** لا تغيير في اشتقاق المفاتيح لمعرّف حاضر. الـ HMAC والنطاق كما هما، و`owner` و`00` و`1` تعطي النتائج نفسها قبل الإصلاح وبعده (§1).

**التحقق من الاكتمال:**

```bash
git grep -n -E 'accountId\s*(!==|===)|accountId\s*&&|&& \$context->accountId\b|\$context->accountId \?[^?]|\$account === null|empty\(\$context->accountId' -- src
```

فارغ على الإصلاح.

## 3. الحقول الأخرى (بند 2)

* **`sessionDeviceId`:** موضع واحد يقرؤه، `DeviceIdentityResolver` L34 `?? ''`، ثم L35 `$sessionFp !== ''`. لا truthiness، و`"0"` حاضر.
* **`correlationId`:** ثلاثة مواضع، كلها `??`: `AdmissionRuntime` L431، و`EvaluationPipeline` L1072 وL1550. لا truthiness.
* **`fingerprintHash` فيه فحوص مختلطة:**
  * truthiness أو `empty()` في `EvaluationPipeline` L1690، L1840، L1847، L2965 (`$fpHash ?`)، L2967 (`&& $fpHash`)، L3045؛
  * `=== null` في L233، L692، L1078، L1163، L1230، والـ reader L433 وL439؛
  * `=== ''` في L2563.
* **هل يُبلَغ هذا؟** مع الـ resolver الافتراضي لا: `FingerprintHasher::hash()` يعيد `hash_hmac()` hex بطول ثابت، فلا يكون `"0"` ولا `""` أبدًا. لكن `RateLimiterBuilder::withDeviceIdentityResolver()` يقبل resolver من الـ Host، و`DeviceIdentityDTO::$fingerprintHash` من نوع `?string` بلا قيد.
* **الخلاصة:** لم يُعدَّل هنا. التكليف حصر التعديل في حضور الحساب، فأرفعه للـ Lead ملاحظة.

## 4. الاختبارات

| الملف | ما يثبته |
| --- | --- |
| `tests/Integration/Redis/AccountPresenceRedisTest.php` (group `f-185-03`) | ثمانية اختبارات، فصّلتها تحت الجدول |
| `tests/Unit/Service/AccountPresenceTest.php` | null، `""`، `"0"`، `"00"`، `"1"`، `" "`، `owner` على `isPresent` و`of` و`idOf` |

**اختبارات `AccountPresenceRedisTest`:**
* `checkOnly` يعيد `ALLOW` / `NORMAL` بلا استثناء لكل من `"0"`، `""`، `"00"`، `"1"`، null، أي خمسة اختبارات.
* `"0"` كـ `"1"`:
  * سلسلة القرارات متطابقة: 4 `ALLOW` ثم `SOFT_BLOCK`؛
  * عدد مفاتيح Redis متطابق؛
  * الـ reader يُظهر k4 وk5 والـ budget، وscore k5 هو score `"1"` (> 0)، أي أن الـ reader يقرأ مفتاح K5 الذي كتبه الـ enforcement.
* `""` كـ null:
  * القرارات متطابقة؛
  * عدد المفاتيح متطابق؛
  * k4 وk5 والـ budget غائبة، والـ scopes متساوية.
* تحكّم: `"00"` حاضر وnull غائب، والسلوكان مختلفان.

| الكود | النتيجة |
| --- | --- |
| الإصلاح | `--group f-185-03`: `OK (15 tests, 63 assertions)` |
| `b3e77e4` (ملفات `src/Service` الخمسة في مكانها مؤقتًا ثم أُعيد الإصلاح، `cmp` تطابق) | `AccountPresenceRedisTest`: `Tests: 8, Errors: 4`، وهي `"0"` و`""` في checkOnly، و`"0"` كـ `"1"`، و`""` كـ null. والأربعة الأخرى تنجح (`"00"`، `"1"`، null، التحكم). اختبار الـ unit لا يُحمَّل لأن الـ helper غير موجود |

## 5. ملحق (2026-11-04) — F-185-04، commit ثانٍ

**الحكم:** [مراجعة الـ Lead على #189](https://github.com/Maatify/php-rate-limiter/pull/189#issuecomment-6620406949). التعريف الواحد يعمّ كل سلاسل الهوية، أي `accountId` و`correlationId` و`fingerprintHash`: الحاضر هو `!== null && !== ''`. الأقسام 1–4 أعلاه تصف الـ commit الأول `61bd1da` كما كان، وبقيت بلا تعديل.

**الـ helper:**
* `AccountPresence` صار `IdentityPresence` (`git mv`، `@internal`)، وفيه:
  * `isPresent(?string)`؛
  * أغلفة الحساب `account(context)` و`accountId(context)`؛
  * `subject(context)`: `correlationId` إن حضر، وإلا معرّف الحساب إن حضر، وإلا `null`؛
  * `fingerprint(device)`.
* عليها `@phpstan-assert-if-true non-empty-string`.

| الموضع | ما كان على `61bd1da` |
| --- | --- |
| الـ subjects، `EvaluationPipeline` L1072 وL1550 و`AdmissionRuntime` L431 | `correlationId ?? AccountPresence::idOf()`، فكان `""` في `correlationId` subject حاضرًا |
| `fingerprintHash`، truthiness و`empty()`: L1690، L1840، L1847، L2965 (`$fpHash ?`)، L2967 (`&& $fpHash`)، L3045 | 6 |
| `fingerprintHash`، strict: L233، L692، L1078، L1163، L1230، L2562–L2563 | 7 |
| الـ reader، L433 (k3) وL439 (k5) | `=== null` |
| `AdmissionRuntime` L285 (نطاق جهاز الإثبات) | `!== null`. زاد على قائمة الحكم، وهو فحص حضور للحقل نفسه |

* **ما لم يتغيّر:** اشتقاق K3/K5 لـ hash حاضر، أي المدخل والـ HMAC.
* **الاكتمال:** `git grep -n -E 'fingerprintHash\s*(===|!==)|empty\(\$device->fingerprintHash|&& \$device->fingerprintHash\b|\$fpHash \?|&& \$fpHash|correlationId \?\?|accountId\s*(!==|===)' -- src` فارغ.
* **ما بقي بفحص `!== null` / `??`:** `previousFingerprintHash` في `EvaluationPipeline` L1185 وL2854 وL2859، والـ reader L406 وL411، و`deviceIdHash ?? fingerprintHash` في L2682 وreader L393.
  * لم تُعدَّل، لأن الحكم سمّى `fingerprintHash` الحالي.
  * الـ pipeline والـ reader متطابقان فيها، فلا يختلفان على مفتاح: `""` جيلًا سابقًا يصل إلى `buildKeys` الذي يفحصه الآن بالـ helper.

**الاختبارات:**
* `tests/Integration/Redis/IdentityPresenceRedisTest.php` (group `f-185-04`)، عبر Builder وRedis وresolver مخصّص بـ `withDeviceIdentityResolver()`:
  * fingerprint `"0"` مقابل `"1"`، لـ K5 (`login_protection`، جلسة موثوقة) ولـ K3 (`api_heavy_protection`، K3 يُحسب من الـ access): القرارات وعدد المفاتيح متطابقة، والـ reader يقرأ score الـ enforcement نفسه (> 0) في k3 وk5.
  * fingerprint `""` مقابل null في السيناريوهين: القرارات وعدد المفاتيح والـ scopes متطابقة، وk3 وk5 غائبان.
  * `correlationId` `""` بلا حساب: الـ dump الكامل لـ Redis مطابق لعدم وجود subject، والتحكم `subject-a` يختلف.
  * `correlationId` `""` مع حساب `"1"`: مطابق لـ `correlationId` null مع `"1"`، أي أن الـ subject هو `"1"`، والتحكم `subject-b` يختلف.
* `tests/Unit/Service/IdentityPresenceTest.php`: القيم السبع على الدوال الأربع، وثماني حالات لـ `subject`.

**ملاحظة على القياس:** في `login_protection` بجلسة غير موثوقة لا يُكتب K3 ولا K5 لأي fingerprint (K4 فقط، بما فيه `"1"`). لذلك فصلتُ K5 (جلسة موثوقة) عن K3 (`api_heavy_protection`).

| الكود | النتيجة |
| --- | --- |
| الإصلاح | `--group f-185-03 --group f-185-04`: `OK (27 tests, 126 assertions)` |
| `61bd1da` (ملفات `src/Service` الستة بايتًا من `61bd1da` و`IdentityPresence.php` منقول جانبًا، ثم أُعيد الإصلاح، `cmp` تطابق) | `IdentityPresenceRedisTest`: `Tests: 4, Failures: 4`. التفاصيل تحت الجدول |

**الإخفاقات الأربعة على `61bd1da`:**
* k5 لـ `"0"`: القرارات تختلف عن `"1"`.
* `correlationId` `""` بلا حساب يكتب حالة spray.
* `correlationId` `""` مع `"1"` subject مختلف عن `"1"`.
* fingerprint `""`: 17 مفتاحًا مقابل 9 لـ null.
