# #160 — إصلاح (a3): سلطة واحدة لانتهاء المفتاح legacy — PLAN (S0)

> **الحالة:** PLAN فقط، ثم **توقف حتى أحكام الـ Lead** على §10. لا تغيير في `src/` ولا `tests/` ولا `docs/` ولا workflow في هذا الـ commit.
> **الأساس:** الفرع `claude/legacy-expiry-a3` مقطوع من `main@5202bf3`، وفيه أرشيف المرحلة 3 (#170) مدموجًا.
> **المصدر:** تكليف الـ Lead «إصلاح (a3): سلطة واحدة لانتهاء المفتاح legacy». قرار الـ Owner: المسار (أ) في الـ store. التوجيه: الشكل a3.
> **القواعد المحفوظة:**
> - الـ PR Draft بالعربية. تعليم Ready والدمج بيد الـ Lead على كلمة الـ Owner، وأي finding مؤكد يوقف Ready.
> - الاختبارات الحمراء في commit مستقل يفشل على `main` قبل أي تغيير في `src/`.
> - القياس المعدود على E-CI فقط، ولا dispatch ولا إعادة dispatch بلا كلمة الـ Owner. dispatch واحد في كل مرة، ولا push ولا CI آخر أثناءه.
> - `check-whitespace.sh` قبل كل push، و`actionlint` على أي workflow.
> - لا سطر غير مقاس في ملف أدلة.
> - لا مساس بسياج الـ generation ولا بالواجهات ولا بأي DTO.
> - المفتاح previous لا يُكتب (DEC-007)، و`LIFECYCLE_CLAIM` و`HARD_BLOCK` لا يُمسّان.
>
> **ما يقوم عليه (مقاس، REPORT المرحلة 3، `investigations/flaky-160/phase3-k4-hand/REPORT.md`):**
> - K-NAT: 22 و25 و31 غير مطبَّق لكل 100,000، كلها عند الطور 0.
> - K-FORCED: 0/10,000 عند `X ≡ 500`، و24.29 % عند `X ≡ 1`.
> - K-HELD: 41/200,000 بقراءة متصل واحدة ممسوكة، وغير مميَّز عن K-NAT (p = 0.256).
> - H-NAT: فائز صفر 1/20,000، و0 بفائزين أو أكثر.
> - D-DET: 20/20.

كل ما في §1–§4 **قراءة للكود عند `5202bf3`، لا قياس**. أرقام الأسطر من الملفات عند هذا الرأس.

## 1. ما يفعله الكود اليوم

### 1.1 `LIFECYCLE_READ` (`src/Repository/Redis/RedisFullCapabilityStore.php` L382–L469)

* **legacy هنا** = لا `generation` ولا `expiresAt` (L431).
* **الانتهاء:** يُشتق من `PTTL` المصدر بقراءة المتصل `expiry = ⌊(nowMs + pttl + 999)/1000⌋` (L432–L435)، ويعود في الـ tuple (L468) فيصير `GenerationBoundScoreStateDTO::$expiresAt`.
* **للقراءة فقط:** لا كتابة في أي مفتاح.

### 1.2 `LIFECYCLE_MUTATE` (L214–L380)

* **المصدر:** current إن كان حيًّا، وإلا previous (`PTTL` في L306 وL314).
* **الحقول المرصودة:** `generation` و`updatedAt` و`value` و`expiresAt` المخزَّنة (L328–L335).
  * `generation` بلا `expiresAt` خطأ (L339)، فمرصود بلا `expiresAt` يعني بلا generation أيضًا.
* **اشتقاق الانتهاء:** `effectiveObservedExpiry` = المخزَّن، أو للـ legacy `⌊(nowMs + sourcePttl + 999)/1000⌋` (L354–L356) بقراءة متصل MUTATE و`PTTL` الخاص به.
* **السياج:**
  * المصدر (L357)؛
  * ثم `value` و`updatedAt` و**`effectiveObservedExpiry ~= ARGV[5]`** (L358)، وARGV[5] هو `expectedState->expiresAt` (L1852)؛
  * ثم الـ generation (L359–L361).
* **الكتابة:**
  * `expiry = effectiveObservedExpiry` (L365)؛
  * `{0}` إن كان `expiry·1000 ≤ nowMs` (L367)؛
  * العمر الفيزيائي `expiry·1000 − nowMs`، نسبي بلا سقف (L368–L376)؛
  * ثم مسح الأدلة ومفتاح المطالبة (L377–L378).
* **فحص ARGV[5]:** لا يُفحص بنفسه. المساواة في L358 تكفل اليوم أنه مطابق لقيمة صالحة. في PHP، الـ DTO يفرض `expiresAt > 0` و`expiresAt ≥ updatedAt` (`src/DTO/GenerationBoundScoreStateDTO.php` L48–L52).

### 1.3 أين يقع الرفض المقاس

* لمفتاح legacy حيّ، `nowMs + PTTL` في كل سكربت = `X − n`، و`X` الانتهاء المطلق.
* فـ L358 يرفض **إذا وفقط إذا** وقع حدّ ثانية بين `X − n_M` و`X − n_R` (المرحلة 3 §1.2).
* بقراءة متصل ممسوكة (`EvaluationPipeline` L1733–L1745 عبر `CallerTimeStore`) يصير `n_M − n_R` زمن Redis بين السكربتين. لذلك:
  * يبقى الرفض قائمًا (K-HELD)؛
  * ويصير **حتميًا** حين يتجاوز ذلك الزمن ثانية (§4.2، الصف 2,500 ms).
* لم يُقس في المرحلة 3 فاصل طويل بقراءة ممسوكة؛ هذا استنتاج من الصيغة، **غير مقاس**.

### 1.4 مخزنا المرجع والأمثلة

* **`tests/Support/RateLimiter/InMemoryRateLimitStore.php`:**
  * يخزّن `expiresAt` مطلقًا لكل مفتاح، legacy أو لا (L29، L69، L106)، ولا يشتق من عمر متبقٍّ.
  * READ يعيد المخزَّن (L191).
  * MUTATE يقارن `expiresAt` المخزَّن بـ `expectedState->expiresAt` (L200) ويكتبه كما هو (L205).
  * **لا اشتقاق فيه، فلا انجراف.**
* **`examples/basic-rate-limit.php` (`ExampleRateLimitStore`):** النمط نفسه (المقارنة نحو L223). لا اشتقاق.
* **الفرق بعد a3:** هذه المخازن تبقى تقارن الانتهاء المخزَّن لـ legacy، والـ Redis store لا يقارنه. الحالة الوحيدة التي يختلفان فيها: تغيّر الانتهاء وحده بين READ وMUTATE (§3.2 (i)). LT-a3-04.

## 2. الأشكال الثلاثة وأثرها على العقود (بلا اختيار؛ التوجيه a3)

| | a1: تثبيت الانتهاء عند أول READ | a2: سياج بسماح ±1 ثانية | **a3: الانتهاء المشتق ليس جزءًا من سياج القِدم** |
| --- | --- | --- | --- |
| الآلية | READ يكتب `expiresAt` المشتق في المفتاح legacy عند أول رصد | L358 يقبل `\|effectiveObservedExpiry − ARGV[5]\| ≤ 1` | للـ legacy يقارن السياج المصدرَ و`value` و`updatedAt` وغيابَ الـ generation فقط، ويُكتب ARGV[5] في current عند أول mutation مطبَّقة |
| READ | **يكتب**، فيخالف عقد القراءة فقط (docblock الاختبار L640: «read-only — no refresh, no repair, no mutation of Previous itself») | بلا تغيير | بلا تغيير |
| previous | يُكتب إن كان هو المصدر، فيخالف DEC-007 (previous للقراءة فقط) | لا يُكتب | لا يُكتب |
| التوقيت | لا يعتمد عليه بعد أول READ، لكن السباق بين READين أوّلين يبقى | **يبقى معتمدًا عليه**: بقراءة ممسوكة وفاصل > ~1–2 ث يُرفض snapshot غير قديم (§1.3)، ويُقبل snapshot تغيّر انتهاؤه ≤ 1 ث | لا يعتمد على التوقيت ما دام المفتاح حيًّا و`ARGV[5]·1000 > nowMs` (L367) |
| ما يُكتب | المخزَّن | غير محدد: المشتق أم ARGV[5]؟ (LT لو اختير) | ARGV[5]، أي ما رصده READ بقراءة متصله |
| كشف التغيّر | كامل بعد أول READ | جزئي | لا يُكشف تغيّر **الانتهاء وحده** لمفتاح legacy (§3.2) |

## 3. a3 بالتحديد (ما سيُكتب في S2، للأحكام)

### 3.1 القاعدة

1. **legacy في MUTATE** = المصدر المرصود بلا `expiresAt` مخزَّن، وهذا يقتضي أنه بلا generation (L339).
   * المصدر بـ `expiresAt` مخزَّن وبلا generation ليس legacy بهذا التعريف، ويبقى على المقارنة الحالية بالمخزَّن؛ لا اشتقاق فيه.
2. **سياج القِدم للـ legacy:**
   * `expectedSource == source` (L357، بلا تغيير)؛
   * و`observedValue == ARGV[2]` و`observedUpdated == ARGV[3]`؛
   * و`ARGV[4] == ''` (L359–L360، بلا تغيير).

   **القيمة المشتقة لا تُحسب ولا تُقارن.**
3. **فحص ARGV[5] للـ legacy** (يحل محل الضمان الضمني في L358):
   * عدد صحيح غير سالب قانوني (`validNonNegativeInteger`)، و`≠ '0'`، و`≥ observedUpdated`؛
   * وإلا خطأ عقد صريح، لأن الـ DTO لا يُنتج غير ذلك.
   * يبقى L367: `ARGV[5]·1000 ≤ nowMs` يعيد `{0}`، أي snapshot منتهٍ منطقيًا عند قراءة متصل MUTATE. LT-a3-03.
4. **الكتابة:**
   * `expiry = ARGV[5]` للـ legacy، والمخزَّن لغيره (L365)؛
   * العمر الفيزيائي `ARGV[5]·1000 − nowMs` كما L369؛
   * باقي الكتابة بلا تغيير.

   بعدها المفتاح current ذو generation و`expiresAt`، فليس legacy ولا اشتقاق.
5. **ما لا يتغير:**
   * READ (L382–L469)، فيبقى الاشتقاق في READ وحده، مرة واحدة لكل رصد؛
   * مسار المفتاح ذي generation؛
   * `LIFECYCLE_CLAIM` و`HARD_BLOCK`؛
   * الواجهات والـ DTO؛
   * التوقيعات في PHP.
6. **DocBlock** على `mutateGenerationBoundScore` (L1820–L1837 حاليًا) يذكر القاعدة وملاحظة DEC-016، وفق `PHP_SOURCE_DOCUMENTATION_STANDARD.md`.

### 3.2 أثر a3 على العقود (قراءة، غير مقاس)

* **(i) تغيّر الانتهاء وحده لمفتاح legacy لم يعد قِدمًا.** إن تغيّر TTL المفتاح بين READ وMUTATE وبقي `value` و`updatedAt` كما هما، يُطبَّق MUTATE ويكتب انتهاء READ. الحالتان:
  * `EXPIRE` من كاتب آخر؛
  * حذف وإعادة إنشاء بالقيمة نفسها في الثانية نفسها (ABA).

  اليوم يُكشف ذلك فقط إن غيّر التحويل بثانية على الأقل. كاتب كهذا هو كاتب غير-DEC-007 على المفتاح نفسه، وDEC-007 L59–L61 يمنعه («Pre-DEC-007 writers must not concurrently write the same logical namespace with DEC-007 writers»). LT-a3-02.
* **(ii) انتهاء الـ legacy يأتي من الـ snapshot.** المتجر لا يملك ما يحدّه من أعلى بلا ساعة Redis، و`TIME` ممنوع (DEC-016 rule 1 وملاحظة L61). فـ snapshot مصنوع يدويًا بقيم `value`/`updatedAt` صحيحة يستطيع أن يضع انتهاء أطول أو أقصر للدرجة legacy. اليوم يمنعه L358 (إلا داخل الثانية نفسها). LT-a3-03.
* **(iii) DEC-007 «without resetting its score or remaining TTL».** الانتهاء المنطقي المكتوب هو ما رصده READ، فلا يُعاد ضبطه.
  * العمر الفيزيائي نسبي إلى قراءة متصل MUTATE (LT-P2-02)، كما لمفتاح ذي generation اليوم. بقراءة ممسوكة متأخرة عن Redis يطول العمر الفيزيائي بقدر التأخر، والمنطقي لا يتغير.
  * حين يُطبَّق MUTATE اليوم يكون ARGV[5] = المشتق، فالقيمة المكتوبة **هي نفسها** في كل حالة تُطبَّق اليوم (أساس V-DECISION، §5).
* **(iv) المسار الإنتاجي.** handoff الـ legacy في `EvaluationPipeline` (L1733، حتى ثلاث محاولات) لا يحتاج إعادة محاولة بسبب العبور. أثره على الرمي في L1745 **غير مقاس**.

### 3.3 مراجعة اختبارات الـ legacy القائمة

المطلوب في التكليف: هل قِدمها بـ `value`/`updatedAt` أم بالانتهاء المشتق؟

| الاختبار (`tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php`) | ما يجعله قديمًا أو مطبَّقًا | تحت a3 |
| --- | --- | --- |
| `testLegacyGenerationlessMutationConflictsWhenWriterAddsGeneration` L1284–L1295 (`legacy-cas`) | كاتب يضيف `generation` و`expiresAt`، فالمرصود ليس legacy، ويرفضه الـ generation (L360) والمخزَّن ≠ ARGV[5] | قديم بالـ generation؛ لا يمسّه a3 |
| `testGeneratedStateWithoutExpiresAtFailsReadClaimAndMutationWhileLegacyStateRemainsSupported` L1436–L1467 | READ legacy فقط (L1462–L1467)؛ لا MUTATE legacy | بلا أثر |
| `testLegacyScoreWithCompleteLifecycleEvidenceFailsAcrossReadMutateClaimAndPublication` L1615 | خطأ بنيوي (L350) قبل السياج | بلا أثر |
| D2 في L1662–L1672 (`r5-valid-legacy-previous`) | `HARD_BLOCK`، لا MUTATE | لا يُمسّ |
| `testLegacyHandoffAndSameValueMutationAdvanceGenerationExactlyOnce` L1833–L1861 | تطبيقان متوقعان (L1843، L1857)؛ الثالث (L1861) قديم بالمصدر (current صار ذا generation) | التطبيقان حتميان؛ الثالث قديم بالمصدر |
| T-K4 `testLifecycleMutationPreservesPhysicalAndAuthoritativeExpiryAcrossAllK4Boundaries` L1871–L1887 (L1883) | يفترض التطبيق | حتمي |
| T-HAND `testConcurrentPreviousHandoffHasNoDoubleSeedAndGenerationMutationsHaveNoLostUpdates` L1963–L1995 (L1992) | فائز واحد بالضبط؛ الخاسرون قديمون بالمصدر بعد أول تطبيق | فائز واحد حتمًا: الأول يكتب current فيتغير المصدر |
| `tests/Integration/Example/BasicRateLimitExampleContractTest.php` L49، `tests/System/PostPunishmentReentryLifecycleTest.php` L163 وما بعدها، `tests/Unit/Support/InMemoryPunishmentLifecycleContractTest.php` L37 | مخازن مرجع وأمثلة (§1.4) | لا يمسّها تغيير `src/` |

**النتيجة:**
* لا اختبار legacy قائم يعتمد قِدمه على الانتهاء المشتق.
* لا اختبار يحتاج إعادة تعريف تحت ملاحظة DEC-016.
* لا يوجد اختبار يغيّر TTL مفتاح legacy وحده بين READ وMUTATE ويتوقع `{0}` (بحث `HSET` بلا `generation` في الملف). الحالة (i) في §3.2 غير مغطاة اليوم بأي اختبار.

## 4. التنبؤات المسجَّلة

### 4.1 القاعدة العامة (من التكليف، بنطاقه)

**0 غير مطبَّق عند أي فاصل بين READ وMUTATE**، بشرطين:
* المفتاح المصدر حيّ فيزيائيًا عند MUTATE؛
* و`ARGV[5]·1000 > nowMs` عند MUTATE (L367).

خارجهما `{0}` هو العقد (snapshot منتهٍ)، وليس finding. وفي كل MUTATE legacy مطبَّق: **`expiresAt` المكتوب = `expiresAt` الذي أعاده READ**.

### 4.2 S1 — اختبارات حتمية (PHPUnit، Redis حقيقي، في `RedisFullCapabilityStoreIntegrationTest`)

**الإعداد المشترك:**
* store بساعة fallback محقونة، `SettableClock` بإزاحة، عبر معامل الـ constructor الثالث القائم؛
* `PEXPIREAT` على `X ≡ 500 (mod 1000)` بعمر متبقٍّ كافٍ.

**الصفوف:**

| # | الصف | على `main` | بعد a3 |
| --- | --- | --- | --- |
| K1 | P-K4 (legacy previous → current)، إزاحة +1,000 ms لـ MUTATE | غير مطبَّق → **أحمر** | مطبَّق؛ `expiresAt` المكتوب (DTO و`HGET`) = `expiresAt` من READ |
| K2 | الضابط، بلا إزاحة | مطبَّق (أخضر) | مطبَّق |
| K3 | فاصل 2,500 ms بين READ وMUTATE (الشكل في LT-a3-05) | غير مطبَّق → **أحمر** | مطبَّق؛ المكتوب = ما رصده READ |
| H1 | T-HAND: READ في الأب، ستة عمّال بإزاحة +1,000 ms | 0 فائز → **أحمر** | فائز واحد بالضبط |
| H2 | T-HAND ضابط | فائز واحد (أخضر) | فائز واحد |
| H3 | T-HAND بفاصل 2,500 ms | 0 فائز → **أحمر** | فائز واحد بالضبط |

* الاختبارات القائمة كلها تبقى خضراء.
* commit S1 يفشل على `main` في K1 وK3 وH1 وH3، وفي أي assert للانتهاء المكتوب داخلها.

### 4.3 S3 — القياس على الفرع (E-CI، harness المرحلة 3 منسوخًا)

| الذراع | الحجم | التنبؤ | على `main` (المرحلة 3، للمقارنة) |
| --- | --- | --- | --- |
| K-NAT (P-K4، P-CUR، P-PREV) | 3 × 100,000 | **0 غير مطبَّق** لكل نوع (Wilson الأعلى 0.00384 %)؛ `e_M = e_R` في كل مطبَّق | 22، 25، 31 |
| K-HELD (P-K4، P-PREV) | 2 × 100,000 | **0** لكل نوع (0.00384 %) | 25، 16 |
| K-FORCED ρ = 1 | 10,000 | **0** (0.0384 %) | 2,429 (24.29 %) |
| K-FORCED ρ = 500 | 10,000 | **0** (يبقى) | 0 |
| H-NAT | 20,000 | **0 فائز صفر، و0 بفائزين أو أكثر** (0.0192 %) | 1، 0 |
| D-DET (K وH؛ +1,000 ms، ضابط، 2,500 ms) | 20 لكل صف | المحقون والـ 2,500 **مطبَّق 20/20** / **فائز واحد 20/20** (Wilson الأدنى 83.9 %)؛ الضابط كذلك | المحقون غير مطبَّق 20/20 / 0 فائز 20/20 |
| V-DECISION | LT-a3-06 | نفس النتائج مع التغيير وبدونه، ما عدا صفوف S1 الحمراء | — |
| تشغيلات T-K4/T-HAND بلا tap | N في LT-a3-07 | 0 فشل | U المرحلة 1: 0/3,300 لكل منهما |
| V-CLOCK، overhead ≤ 1.25×، 0 صفوف غير صالحة | كالمرحلة 3 | تتحقق | تحققت |

**أي غير مطبَّق في K-NAT أو K-HELD أو K-FORCED، أو أي فائز صفر في H-NAT، أو `e_M ≠ e_R`، هو finding ضد a3** يُعرض بصفّه كاملًا.

### 4.4 القدرة على التمييز (حساب على أرقام المرحلة 3، لا قياس جديد)

* **K-NAT P-K4:** معدّل `main` المقاس (22/100,000) يعطي 0 في 100,000 باحتمال `e^−22 ≈ 2.8 × 10⁻¹⁰`.
* **K-FORCED ρ = 1:** 24.29 % على `main`، فالصفر حاسم.
* **تشغيلات T-K4/T-HAND بلا tap:** لا تميّز بأحجام معقولة. معدّل `main` لكل تشغيل ≈ 2.2 × 10⁻⁴ + 5 × 10⁻⁵ (P-K4 وH-NAT). عند N = 1,000 يكون `P(0 | main) ≈ 0.76`. لذلك تُسجَّل فحصًا للتطابق لا اختبارًا (LT-a3-07).

## 5. القياس (S3): الأدوات والـ workflow والتكلفة

### 5.1 الـ harness

* **ما يُنسخ:** `investigations/flaky-160/phase3-k4-hand/harness/` عند `5202bf3` إلى `investigations/flaky-160/fix-a3/harness/`.
* **الـ provenance:**
  * لكل نسخة ترويسة provenance (المسار وSHA-256 الأصل)، و`diff -U0` مسجَّل؛
  * `provenance_check.sh` بفحص BC-13: يطبّق الـ diff بـ `patch` ويقارن البايتات، ولا يقارن نص الـ diff.
  * النسخ التي تحمل ترويسة provenance من المرحلة 2 تُعامل أصلها عند `5202bf3` (LT-a3-08).
* **التكييفات (diff مسجَّل لكل منها):**
  * `V_HEAD_REF` = commit S2، أي رأس الفرع بعد التغيير في `src/`؛ و`tests/` عند ذلك الـ commit.
  * التصنيف: أي غير مطبَّق في الأذرع الطبيعية والمُجبَرة finding؛ وصنف جديد `e_M ≠ e_R`.
  * D-DET: صف 2,500 ms، وتنبؤات المحقون الجديدة.
  * التقرير: التنبؤات الجديدة.
  * K-CAL وK-FORCED-M **لا يُشغَّلان**: لا تنبؤ لـ a3 يحتاجهما (LT-a3-08).
* **الكواشف قبل أي عدّ:**
  * D1–D6 كالمرحلة 3 على E-LOCAL، سلامة أداة فقط.
  * ثم على E-CI في dispatch كواشف واحد بأحجام صغيرة، هو قياس التكلفة نفسه.
  * D3 يضيف صفوفًا اصطناعية: غير مطبَّق في K-NAT → finding؛ `e_M ≠ e_R` → finding.
  * D4 يستعمل صفوف D-DET الثلاثة بـ 3 لكل صف.

### 5.2 الـ workflow

* `flaky-160.yml` بالاسم المسجَّل: آخر نسخة في التاريخ هي `32e7f5e:.github/workflows/flaky-160.yml`، وتُنسخ كما هي في commit.
* ثم commit تكييف بـ diff مسجَّل:
  * مسارات `fix-a3/harness/`؛
  * الوحدات `detectors | k | h | det | decision | u | all`؛
  * `contents: read`، بلا secrets، `env.sh` في كل job؛
  * `actionlint` نظيف.
* يُحذف في commit مستقل قبل Ready.

### 5.3 التكلفة

* **تقدير، غير مقاس.** المرحلة 3 قاست 59.47 دقيقة runner للأذرع نفسها تقريبًا: k ‏12.2 د، منها K-CAL 0.5 وK-FORCED-M 0.26 لن يُشغَّلا؛ وh ‏46.7 د؛ وdet ‏0.5 د.
* **يُضاف:**
  * V-DECISION: مجموعتا Integration وSystem على رأسين. Integration Test Suite على CI العادي 8 د 55 ث لكل تشغيل في run 40703199779، والـ System **غير مقاس** منفردًا.
  * تشغيلات T-K4/T-HAND بلا tap: زمن التشغيل الواحد **غير مقاس**.
* **التقدير الإجمالي:** ≈ 60–70 دقيقة للأذرع، + 20–30 لـ V-DECISION، + تشغيلات `--filter` بحسب N.
* الرقم المقاس من dispatch الكواشف يُنشر على الـ PR، **ولا dispatch معدود قبل كلمة الـ Owner**.

## 6. الوثائق (S4)

* **`docs/decisions/DEC-016_REDIS_CLOCK_AUTHORITY_RECONCILIATION.md`:** application note جديدة بعد L72، بلا مبدأ جديد. فيها:
  * التاريخ والـ PR وما قيس؛
  * والقاعدة: «الانتهاء المشتق من `PTTL` لمفتاح legacy قراءة ساعة لا حقيقة حالة؛ لا يدخل في سياج القِدم؛ يُثبَّت من أول رصد READ عند أول mutation مطبَّقة».
* **`docs/decisions/DECISIONS_INDEX.md` L15:** سطر DEC-016 يذكر الملاحظة.
* **`docs/FAILURE_SEMANTICS.md` L147–L148** («Legacy generation-less state may use its backend TTL during compatibility reads and is upgraded on a real mutation»): تُضاف القاعدة وأثر §3.2 (i).
* **`docs/KEY_STRATEGY.md`:** لا يذكر الاشتقاق ولا legacy ولا `PTTL` (بحث). لا تغيير إلا إن حكم الـ Lead بغير ذلك.
* **`RATE_LIMITER_PACKAGE_REFERENCE.md` L426–L427** («upgraded on its first real mutation without resetting score or remaining TTL»): يبقى صحيحًا. سطر توضيحي اختياري (LT-a3-09).
* **`CHANGELOG.md`:** تحت `[Unreleased]` (L8): `### Changed (#160, DEC-016 application note)`.
* **وصف الـ PR:** مسار AGENTS.md والـ Standards (§9).

## 7. ترتيب الـ commits

1. هذه الخطة وحدها → push → Draft PR بالعربية → **توقف**.
2. أحكام الـ Lead في §11 من هذا الملف (بلا تغيير آخر).
3. **S1:** الاختبارات الحمراء (commit مستقل، يفشل على `main`)، مع مخرَج التشغيل على `main` في الـ PR.
4. **S2:** التغيير في `src/` (السكربت `LIFECYCLE_MUTATE` وDocBlock) → S1 أخضر، والـ suite كاملة خضراء على CI العادي.
5. **S3:** الـ harness (نسخ بـ provenance، ثم تكييف)، والكواشف E-LOCAL، والـ workflow (نسخ ثم تكييف)، ثم dispatch الكواشف → التكلفة على الـ PR → **توقف لكلمة الـ Owner** → dispatch معدود واحد → `logs/` بـ `SHA256SUMS` → `REPORT.md`.
6. **S4:** الوثائق، ثم حذف الـ workflow في commit مستقل → **توقف**: مراجعة الـ Lead، قرار الـ Owner، والدمج بيد الـ Lead.

## 8. ما لا يحدث قبل الأحكام

لا harness، ولا workflow، ولا dispatch، ولا اختبار، ولا تغيير في `src/` أو `docs/`.

## 9. مسار AGENTS.md

Normal Engineering Task (Adoption Standard §10.1):
* قُرئ `docs/php-engineering-standards/STANDARDS_MANIFEST.md`.
* الـ Scope `/`، والـ activations `composer-package` 4.0.0 و`repository-governance` 3.0.0.
* الملف المتأثر في هذا الـ commit: `investigations/flaky-160/fix-a3/PLAN.md` وحده.

**المنطبق من Final Resolved Applicable Standards Set على المهمة كلها:**

| Standard | الإصدار | لماذا |
| --- | --- | --- |
| `TESTING_STANDARD.md` | 2.0.0 | S1 وV-DECISION |
| `PHP_SOURCE_DOCUMENTATION_STANDARD.md` | 1.0.0 | DocBlock في S2 |
| `PHP_CODING_STYLE_STANDARD.md` | 1.0.1 | S1 وS2 |
| `CI_WORKFLOW_STANDARD.md` | 5.0.0 | الـ workflow المؤقت |
| `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | 3.1.0 | S4 وCHANGELOG |
| `DECISION_GOVERNANCE_STANDARD_AR.md` | 1.0.0 | application note لا قرار جديد |
| `AI_COLLABORATION_WORKFLOW_AR.md` | 12.0.0 | |
| `GITHUB_PHASE_STACK_WORKFLOW_AR.md` | 4.0.0 | Draft، commit لكل خطوة، الدمج بيد الـ Lead |
| `COMPOSER_PACKAGE_STANDARD.md` | 5.0.0 | لا تغيير في `composer.json` |
| `PACKAGE_BUILDING_STANDARD.md` | 3.0.2 | |
| `LIBRARY_PRESENTATION_STANDARD.md` | 5.0.0 | لا تغيير عام في الواجهة |

## 10. أسئلة للـ Lead قبل البناء (LT-a3-xx)

* **LT-a3-01 — تعريف legacy والحقول.**
  * **المقترح:** legacy في MUTATE = المصدر المرصود **بلا `expiresAt` مخزَّن**، وهذا يقتضي بلا generation (L339). سياجه: المصدر و`value` و`updatedAt` و`ARGV[4] == ''`.
  * المصدر بـ `expiresAt` وبلا generation يبقى على مقارنة المخزَّن (لا اشتقاق فيها).
  * مقبول؟
* **LT-a3-02 — تغيّر الانتهاء وحده لا يُكشف (§3.2 (i)).**
  * تحت a3، `EXPIRE` على مفتاح legacy بين READ وMUTATE، أو ABA بالقيمة والثانية نفسيهما، لا يجعل الـ snapshot قديمًا، ويُكتب انتهاء READ.
  * الحالة لا تنشأ إلا من كاتب غير-DEC-007 متزامن، ويمنعه DEC-007 L59–L61.
  * **المقترح:** قبولها وتوثيقها في ملاحظة DEC-016 وفي `FAILURE_SEMANTICS.md`، بلا اختبار ينفيها. أم يُضاف اختبار يثبتها سلوكًا موثّقًا؟
* **LT-a3-03 — فحص ARGV[5] للـ legacy.**
  * **المقترح:** شكل قانوني، `≠ 0`، `≥ updatedAt` المرصود (وإلا خطأ عقد)، وL367 يبقى (`{0}` إن انتهى منطقيًا عند MUTATE).
  * **لا حدّ أعلى:** أي حدّ يحتاج ساعة Redis (`TIME` ممنوع، DEC-016)، فانتهاء الـ legacy يأتي من الـ snapshot (§3.2 (ii)).
  * مقبول؟
* **LT-a3-04 — مخزن المرجع والأمثلة (§1.4).** `InMemoryRateLimitStore` و`ExampleRateLimitStore` لا يشتقان، فلا انجراف فيهما، لكنهما يبقيان يقارنان الانتهاء المخزَّن لـ legacy.
  * **المقترح:** بلا تغيير، وفق نص التكليف («إن كان يحمل اشتقاقًا مماثلًا»)، مع تسجيل الفرق الوحيد (§3.2 (i)).
  * أم تُوحَّد القاعدة فيهما (لا مقارنة انتهاء لـ legacy، وكتابة `expectedState->expiresAt`)؟
* **LT-a3-05 — شكل صف الـ 2,500 ms.**
  * **(A) إزاحة متصل +2,500 ms لـ MUTATE بلا انتظار:** حتمي ورخيص. يحتاج `X` بعمر ≥ ~5.5 ث حتى يبقى `ARGV[5]·1000 > nowMs`.
  * **(B) قراءة متصل ممسوكة للنداءين (`…At()` بالقيمة نفسها) مع انتظار حقيقي 2,500 ms:** المسار الإنتاجي. على `main` يختلف التحويل بثانيتين أو ثلاث فيُرفض حتمًا، وبعد a3 يُطبَّق. يكلّف 2.5 ث لكل حالة.
  * **المقترح:** الاثنان في S1، حالة واحدة لكل من K وH لكل شكل (≈ 10 ث للأربع)، وفي D-DET الـ harness بـ 20 لكل صف. أم أحدهما؟
* **LT-a3-06 — تعريف V-DECISION.**
  * **المقترح:** مجموعتا Integration وSystem في PHPUnit على E-CI، مرة على `main@5202bf3` (بلا التغيير) ومرة على رأس الفرع.
  * النتيجة لكل اختبار من junit متطابقة، ما عدا اختبارات S1 (فشل على `main`، نجاح على الفرع).
  * تشغيل واحد لكل رأس، بـ Redis جديد لكل تشغيل.
  * أم المقصود «القرارات» على مستوى نتائج الـ runtime (DTO)، بمقارنة مخرجات خاصة؟
* **LT-a3-07 — «تشغيلات T-K4/T-HAND بلا tap كما U» وN.**
  * U المرحلة 1 كان مجموعة البادئة كاملة × 3,300 = **24.7 ساعة runner** (مقاس، `investigations/flaky-160/REPORT.md` L12)، أبعد كثيرًا من تقدير 60–70 دقيقة.
  * **المقترح:** `--filter` على T-K4 وT-HAND والمقارن L1833، بحاوية Redis جديدة لكل تشغيل، وN = 1,000 مسجَّل.
  * التنبؤ 0 فشل، مع الإفصاح: `P(0 | main) ≈ 0.76`، فهذا فحص تطابق لا تمييز (§4.4). التمييز من K-NAT وK-HELD وK-FORCED.
  * زمن التشغيل الواحد غير مقاس، ويُقاس في dispatch الكواشف.
  * أم N آخر، أم مجموعة البادئة؟
* **LT-a3-08 — الـ harness وحدوده.**
  * (أ) نسخ من `phase3-k4-hand/harness/` عند `5202bf3`.
  * (ب) `V_HEAD_REF` = commit S2.
  * (ج) K-CAL وK-FORCED-M لا يُشغَّلان.
  * (د) الأحجام كما التكليف: K-NAT 3 × 100,000، K-HELD 2 × 100,000، K-FORCED 2 × 10,000، H-NAT 20,000، D-DET 20 لكل صف من ستة.
  * (هـ) حدّ 2 % لغير الصالح، و1.25× للـ overhead.
  * تأكيد؟
* **LT-a3-09 — نطاق الوثائق.**
  * `KEY_STRATEGY.md` لا يذكر الاشتقاق، فبلا تغيير؟
  * `RATE_LIMITER_PACKAGE_REFERENCE.md` L426–L427 صحيح كما هو، فسطر توضيحي أم بلا تغيير؟
  * ملاحظة DEC-016 تذكر §3.2 (i) و(ii) صراحة؟
