# #160 — المرحلة 3: آلية T-K4 وT-HAND (تحويل العمر المتبقي للـ legacy) — تسجيل مسبق

> **الحالة:** PRE-REGISTRATION، الخطة **v1**. هذا الـ commit وحده، **قبل** أي harness أو workflow أو نتيجة، ثم **توقف حتى أحكام الـ Lead** على §11. أدلة فقط: لا تغيير في `src/` ولا `tests/` ولا `composer.json` ولا `phpunit.xml` ولا `ci.yml`؛ ملفات المراحل السابقة (`investigations/flaky-160/` الجذر، `phase2/`، `phase2b/`، `stepM/`، `stepS/`) و`investigations/flaky-157/` تُقرأ ولا تُحرَّر.
> **الرأس:** الفرع `claude/flaky-160-phase3-k4-hand` مقطوع من `main@d37f9a3` (PR #168 مدموج، DEC-029 ACTIVE). V-HEAD لكل job: `src/` و`tests/` و`composer.json` و`phpunit.xml` متطابقة بايتيًا مع `d37f9a3` وشجرة نظيفة.
> **ثبات الأسطر المستشهد بها (مُتحقَّق قبل الكتابة):** `git diff --stat c8b8fd7 d37f9a3 -- src/Repository/Redis/RedisFullCapabilityStore.php` فارغ، فأسطر المتجر في PLAN المرحلة 1 §1.4 (L356، L358، L435، L1785، L1840) هي نفسها اليوم. الطرق الثلاث في الاختبار (`testLegacyHandoffAndSameValueMutationAdvanceGenerationExactlyOnce`، T-K4، T-HAND) متطابقة بايتيًا مع `8b79e9b`؛ تغيّرت أرقام أسطرها فقط بإضافات #164: L1797 → **L1833**، L1841 → **L1877**، L1847 → **L1883**، L1932 → **L1968**، L1956 → **L1992**.
> **التكليف:** الـ Lead، #160 «البند المتبقي: T-K4 وT-HAND — آلية مثبتة ثم قرار» (2026-10-28).
> **القواعد المحفوظة:** الخطة قبل الـ harness؛ كل LT يُحكم قبل البناء؛ لا يتغير شرط مسجَّل بعد تشغيله؛ commit لكل خطوة؛ الـ PR يبقى Draft بالعربية؛ مراجعتي «self-review» لا «Lead review»؛ أي finding مؤكد (حتى تعليق خاطئ) يوقف Ready؛ تعليم Ready والدمج بيد الـ Lead على كلمة الـ Owner.

## 0. الأسئلة

1. **K (T-K4).** هل كل MUTATE غير مطبَّق في تسلسل T-K4 (HSET، `PEXPIRE 1200`، READ، MUTATE) هو بالضبط عبور حدّ ثانية بين تحويل READ للعمر المتبقي وتحويل MUTATE له (§1.2)؟ طبيعيًا (K-NAT)، وبإجبار طور الانتهاء (K-FORCED)، وحتميًا (D-DET).
2. **H (T-HAND).** هل «0 is identical to 1» هو الآلية نفسها مع ستة عمّال مفروعين: عند اختلاف التحويل يعيد كل العمّال `{0}`، ولا يظهر فائزان أبدًا؟ (H-NAT، D-DET).
3. **القرار.** لكل اختبار: **عيب في `LIFECYCLE_READ`/`LIFECYCLE_MUTATE`** أم **افتراض اختبار**، مع ما يلزم كل مسار من تغيير وقياس، **بلا اختيار**.

## 1. ما يفعله الكود (قراءة، غير مقاس)

### 1.1 التسلسلان

**T-K4** (`tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php`، الطريقة L1871): `HSET value 4 updatedAt now` على مفتاح previous (L1876)، `PEXPIRE 1200` (L1877)، `HGETALL` (L1878)، `PTTL` (L1879)، READ `readGenerationBoundScoreState('ttl-contract-legacy-current', 'ttl-contract-legacy-previous')` (L1880)، MUTATE `mutateGenerationBoundScore(…, $legacyPreviousState, 86400, 5)` (L1882)، ثم `assertTrue($legacyHandoff->applied)` (**L1883**، موضع الفشل 4/3,300 مع tap في #157 L، 0/3,300 بلا tap في U المرحلة 1).

**T-HAND** (الطريقة L1963): `HSET value 5 updatedAt now` (L1967)، `EXPIRE 600` (L1968)، READ في الأب (L1969)، ثم `runConcurrentWorkers(6, …)` (L1973): كل عامل مفروع يبني `workerStore()` (L3723: `RespRedisCommandExecutor` جديد و`RedisFullCapabilityStore` **بلا ساعة**، أي `SystemClock(UTC)`) ويشغّل MUTATE بالـ DTO الذي قرأه الأب، ويكتب النتيجة `SET handoff-result:<i> 1|0`؛ ثم `assertSame(1, $handoffWinners)` (**L1992**، الفشل 1/3,300 مع tap، «0 is identical to 1»؛ 0/3,300 بلا tap).

**المقارِن** `testLegacyHandoffAndSameValueMutationAdvanceGenerationExactlyOnce` (L1833، 0/3,300 مع tap) فيه زوجان legacy: current legacy (`EXPIRE 600` L1838، `TTL` L1839، READ L1840، MUTATE L1843، `assertTrue` L1844) وprevious legacy (`EXPIRE 600` L1852، `HGETALL` L1853، READ L1854، MUTATE L1857، `assertTrue` L1858).

كل READ وMUTATE هنا عبر الطرق العامة، فكل نداء يأخذ قراءة fallback خاصة به: `fallbackNowMilliseconds()` = `(int) SystemClock->now()->format('Uv')` (L2098؛ من L1785 وL1840).

### 1.2 النموذج (قراءة المرحلة 1 §1.4 بلا تغيير، بتعريفات صريحة)

* لمفتاح legacy (بلا `generation` وبلا `expiresAt`) يحوّل READ العمر المتبقي إلى ثانية المتصل: `expiry = ⌊(nowMs + pttl + 999)/1000⌋` (L432–L435)، ويحوّله MUTATE بالصيغة نفسها من `sourcePttl` (L306/L314، L356)، ثم يعيد `{0}` ما لم `effectiveObservedExpiry == ARGV[5]` (L358)، وARGV[5] هو `expiresAt` الذي أعاده READ.
* تعريفات: `X` = الانتهاء المطلق للمفتاح بالـ ms (`PEXPIRETIME`)؛ لنداء `c`: `h_c` = قراءة المتصل بالـ ms، `r_c` = زمن Redis بالـ ms عند `PTTL` المستعمل في التحويل، `n_c = r_c − h_c ≥ 0` (ساعة نواة واحدة على E-CI). فـ `nowMs + PTTL = X − n_c` والتحويل `m(n) = ⌈(X − n)/1000⌉`.
* `m(n_R) ≠ m(n_M)` **إذا وفقط إذا** وُجد `k` بحيث `X − max(n_R, n_M) ≤ k·1000 < X − min(n_R, n_M)`، أي `((X − 1 − min n) mod 1000) < |n_R − n_M|`. ومنه: كل MUTATE غير مطبَّق بهذه الآلية يحقق `(X − 1) mod 1000 < max(n_R, n_M)`. **عتبة التنبؤ المسجَّل «< 10» تفترض `max n < 10 ms`.**
* T-HAND: بعد أول MUTATE مطبَّق يصير `source = current` فيعيد كل عامل لاحق `{0}` (L358 قبلها: `expectedSource ~= source`)، فلا فائزان بهذه الآلية أبدًا. الفائز صفر **إذا وفقط إذا** اختلف تحويل كل عامل عن تحويل READ.

### 1.3 مسارات أخرى إلى «غير مطبَّق» (تُسجَّل لتمييزها، لا تُفترض)

* **E-EXP (T-K4 فقط):** المفتاح يعيش 1,200 ms؛ إن مضى أكثر من العمر المتبقي قبل MUTATE صار `source = ''` فيعيد `{0}` (L319)، أو أعاد READ `null` قبله (فشل عند L1881 لا L1883). يُميَّز بقراءة `PEXPIRETIME` بعد الجزء الموقوت (`−2` = منتهٍ) وبقراءات المتصل المسجَّلة.
* **السباق (T-HAND فقط):** عامل يخسر لأن غيره كتب أولًا؛ لا يُرى في عدد الفائزين إلا إن كان صفرًا أو ≥ 2.

### 1.4 ملاحظة بالقراءة: المسار الإنتاجي يمسك ساعة واحدة (مقترح LT-160D-06)

`EvaluationPipeline` L1734/L1738 يستدعي READ ثم MUTATE عبر `CallerTimeStore` (L155–L166) بقراءة `$this->clock->now()`، وتحت `RateLimiterBuilder` تكون `RequestScopedClock` الممسوكة (PLAN المرحلة 1 §12.1)، أي **`h_R = h_M`**. حينها `n_M − n_R = r_M − r_R` (زمن Redis المنقضي بين السكربتين)، لا فرق تأخّرين متقاربين كما في مسار الاختبار. لذا نافذة الاختلاف في الإنتاج بعرض الزمن بين النداءين (بالـ ms الصحيحة)، وأي فشل للحلقة كلها يرمي `RateLimitConcurrencyException('K4 generation mutation conflict budget exhausted.')` (L1745 عند `d37f9a3`، وهو «L1741» في ترقيم DEC-029 والخطوة M قبل تغييرات #168). هذا قراءة فقط؛ إن كان القرار «عيب» فهو المسار الذي يمسّ المضيف.

## 2. التنبؤات المسجَّلة (منقولة حرفيًا)

### 2.1 من PLAN المرحلة 1 §6 (`investigations/flaky-160/PLAN.md`، كما هي، بالإنجليزية)

> | **T-K4** | (a) the untapped rate from U (descriptive); (b) a probe, no tap, that runs the test's sequence (HSET, `PEXPIRE 1200`, READ, MUTATE) through the same store class and records, **after** the timed part, the key's `PEXPIRETIME` (`X`) and READ's returned `expiresAt`; same probe for the two legacy pairs of L1797 as comparators; (c) a forced variant that sets `PEXPIREAT` so that `X ≡ 1` or `X ≡ 500 (mod 1000)` | Every not-applied MUTATE has `(X − 1) mod 1000 < 10`; with `X ≡ 500`, 0 not-applied; with `X ≡ 1`, a positive not-applied rate equal to the observed share of `n_R ≠ n_M`; natural rate per pair ≈ `E|n_R − n_M| / 1000`, the same for T-K4's pair and the L1797 pairs. A not-applied MUTATE outside the window is a finding against §1.4 |
> | **T-HAND** | as T-K4 (b), with the parent's READ and six forked workers' MUTATE (`EXPIRE 600`) | When READ's mapped expiry differs from the workers' mappings, all six return `{0}` (winners 0), never 2; otherwise exactly one winner |

(«L1797» هناك = L1833 اليوم؛ المحتوى نفسه بايتيًا.)

### 2.2 من تكليف الـ Lead (كما هو)

* **K-NAT:** مسبار بلا tap يشغّل تسلسل T-K4 (HSET، `PEXPIRE 1200`، READ، MUTATE) عبر `RedisFullCapabilityStore` نفسه، ويسجّل **بعد** الجزء الموقوت `PEXPIRETIME` للمفتاح (`X`) و`expiresAt` الذي أعاده READ؛ ونفس المسبار للزوجين الـ legacy في الاختبار كمقارنة. K = 100,000. التنبؤ: كل MUTATE غير مطبَّق يحقق `(X − 1) mod 1000 < 10`؛ المعدّل الطبيعي لكل زوج ≈ `E|n_R − n_M| / 1000`؛ أي MUTATE غير مطبَّق خارج النافذة finding ضد §1.4.
* **K-FORCED:** يضبط `PEXPIREAT` بحيث `X ≡ 1` أو `X ≡ 500 (mod 1000)`، 10,000 لكل منهما. التنبؤ: عند `X ≡ 500` صفر غير مطبَّق؛ عند `X ≡ 1` معدّل غير مطبَّق موجب يساوي حصة `n_R ≠ n_M` الملاحظة.
* **H-NAT:** كـ K-NAT مع READ في الأب وستة عمّال مفروعين يشغّلون MUTATE (`EXPIRE 600`). K = 20,000 تسلسلًا. التنبؤ: عندما يختلف تحويل READ عن تحويل العمّال يعيد الستة `{0}` (0 فائز)، وإلا فائز واحد بالضبط؛ لا يظهر فائزان أبدًا.
* **D-DET (حتمية، 20 + 20):** store بساعة fallback محقونة تعبر حدّ الثانية بين READ وMUTATE → التنبؤ: MUTATE غير مطبَّق 20/20؛ الضابط 0/20. ونفس الشيء لـ T-HAND (0 فائز 20/20).

§3–§5 تُعرّف كيف يُقاس كل ذلك؛ لا تغيّر تنبؤًا. حيث يحتاج التنبؤ تعريفًا تشغيليًا لم ينص عليه النص أعلاه، أكتبه «تعريف بناء» وأطرحه في §11.

## 3. البيئات

| Id | أين | الدور |
| --- | --- | --- |
| **E-CI** | GitHub Actions `ubuntu-latest`، `actions/checkout` و`shivammathur/setup-php` على pins `ci.yml`، PHP 8.5، `extensions: redis`، `composer update` كما `ci.yml`؛ `redis:7.0.15-alpine` من `docker/redis-integration/compose.yaml` مقروءًا كبيانات، منفذ منشور ≠ 6379. Redis وPHP على ساعة نواة الـ runner نفسها | **كل رقم معدود** |
| **E-LOCAL** | worktree مستقل على الـ Mac، Redis على منفذ ≠ 6379 | سلامة الأداة والكواشف فقط؛ **لا رقم معدود**. Docker Desktop يشغّل Redis في VM بساعة مستقلة، فـ `n_c` هناك يحمل انحراف الساعتين ولا معنى لـ K-NAT/H-NAT عليه. إن تعذّر E-CI يتوقف العمل ويعود إلى الـ Owner |

## 4. الأذرع

### 4.1 مشترك

* سكربت PHP في الـ harness (ليس اختبار PHPUnit؛ لا شيء تحت `tests/` يتغير) يحمّل `vendor/autoload.php` ويستعمل `RedisFullCapabilityStore` و`Tests\Support\Redis\RespRedisCommandExecutor` كما هما. الأوامر الخام بالأوامر والقيم نفسها في الاختبار، والـ keys بصيغة الاختبار `familyPrefix(family) . ':' . sha256(logical)` (L3755).
* **namespace جديد لكل تسلسل** (لا حالة مشتركة؛ BC-2 في المرحلة 1)، و`FLUSHDB` كل 1,000 تسلسل خارج الجزء الموقوت.
* **ساعة المتجر في الأذرع الطبيعية: مسجِّل تمرير** حول `SystemClock(UTC)`: يعيد القراءة دون تغيير ويحفظ بعدها (s، µs) ووسم النداء (READ/MUTATE)؛ كما حكم LT-160-06 وLT-160B-02 «ليس حقنًا». لا يُلفّ الـ executor في الأذرع الطبيعية (كما §12.5 في المرحلة 1).
* **بعد الجزء الموقوت فقط:** `PEXPIRETIME` للمفتاح legacy (`X`؛ `−2` = منتهٍ، §1.3)، وفي T-K4 `HGETALL` للتحقق من أن previous لم يتغير.
* لكل تسلسل يُحفظ: `X`، `X mod 1000`، `h_R` و`h_M` (µs)، `e_R` (`expiresAt` من READ)، `applied`، `e_M` إن طُبّق، و`d_R = ⌈X/1000⌉ − e_R` (هل عبر تحويل READ حدًّا، يكشف `n_R ≥ ((X − 1) mod 1000) + 1`)، والاستثناء إن وُجد.

### 4.2 K-NAT (طبيعي، بلا tap)

* **ثلاثة أنواع أزواج**، K = **100,000** لكل منها (تعريف بناء: المقارنان بالحجم نفسه، LT-160D-02): (P-K4) تسلسل T-K4 L1876–L1883 بأوامره كلها بما فيها `HGETALL` و`PTTL` بين `PEXPIRE` وREAD؛ (P-CUR) زوج current legacy L1837–L1844 (`EXPIRE 600`، `TTL`)؛ (P-PREV) زوج previous legacy L1851–L1858 (`EXPIRE 600`، `HGETALL`). ترتيب الأنواع متناوب داخل الـ job (تسلسل من كل نوع بالتتابع) لئلا يختلف ظرف الـ runner بينها.
* **القاعدة لكل تسلسل (N-K1):** MUTATE غير مطبَّق ⇒ `(X − 1) mod 1000 < 10` (أو E-EXP ظاهر في §1.3). أي غير مطبَّق خارجها **finding ضد §1.2** يُعرض بصفه كاملًا.
* **المعدّل (N-K2):** غير المطبَّق ÷ التسلسلات الصالحة لكل نوع، بـ Wilson 95 %، مع توزيع `X mod 1000` لغير المطبَّق ولكل التسلسلات.
* **«≈ `E|n_R − n_M| / 1000`»:** `n_c` لا يُرى من الأزواج نفسها (لا `TIME` في سكربتات المتجر، DEC-016). تعريف بناء مقترح: ذراع معايرة **K-CAL** (LT-160D-03). بدونه يُكتب هذا البند «غير مقاس» ويُحكم بـ N-K1 وK-FORCED فقط.
* **«نفس المعدّل للأنواع الثلاثة»:** قاعدة مقترحة: يُدحض إن كان Fisher exact ثنائي الطرف بين P-K4 وأي من المقارنَين p < 0.01 (LT-160D-02).

### 4.3 K-FORCED (طور انتهاء مُجبَر، P-K4 فقط)

* بدل `PEXPIRE 1200`: `PEXPIREAT X` بـ `X = ⌊h₀/1000⌋·1000 + 2000 + ρ`، `ρ ∈ {1, 500}`، و`h₀` قراءة المضيف قبل HSET مباشرة (تعريف بناء: عمر متبقٍّ بين 1,002 و2,500 ms، من رتبة 1,200 في الاختبار). باقي التسلسل كما K-NAT. **10,000 لكل ρ**، متناوبة.
* **صلاحية:** `PEXPIRETIME` بعد الجزء الموقوت = `X` المطلوب بالضبط، وإلا التسلسل غير صالح ويُعدّ.
* **ρ = 500:** التنبؤ **0 غير مطبَّق من 10,000** (يتطلب الاختلاف `max n ≥ 500 ms`). Wilson 95 % الأعلى عند 0/10,000 = 0.038 %. أي غير مطبَّق = finding.
* **ρ = 1:** `m(0) = ⌈X/1000⌉` و`m(n) = ⌈X/1000⌉ − 1` لكل `1 ≤ n ≤ 1000`؛ فالاختلاف **إذا وفقط إذا** `[n_R = 0] ≠ [n_M = 0]`، لا `n_R ≠ n_M` حرفيًا (`n_R = 1`، `n_M = 2` يعطيان التحويل نفسه). READ يكشف `[n_R = 0]` من `e_R`؛ `[n_M = 0]` لا يُرى مستقلًا عن `applied`. تعريف «الحصة الملاحظة» هو LT-160D-04 (أقترح متغيرًا ثالثًا K-FORCED-M). التنبؤ في كل الأحوال: معدّل غير مطبَّق **موجب**، وكل غير مطبَّق مصحوب بـ `e_R ∈ {⌈X/1000⌉, ⌈X/1000⌉ − 1}`.

### 4.4 H-NAT (T-HAND، ستة عمّال)

* تسلسل T-HAND L1965–L1992 بأوامره: HSET، `EXPIRE 600`، READ في الأب (مسجِّل تمرير)، `pcntl_fork` ستة عمّال، كل عامل `RedisFullCapabilityStore` جديد بـ executor جديد ومسجِّل تمرير خاص به، MUTATE بالـ DTO نفسه، و`SET` النتيجة كما L1980–L1986؛ ويكتب العامل `h_Mi` إلى ملف صفّه (لا إلى Redis، لئلا يُضاف أمر). **K = 20,000 تسلسلًا.**
* بعد انتهاء العمّال: `PEXPIRETIME` للـ previous (`X`)، وعدد الفائزين من `handoff-result:*`.
* **N-H1:** الفائزون ∈ {0, 1} في كل تسلسل؛ **فائزان أو أكثر = finding**.
* **N-H2:** فائز صفر ⇒ `(X − 1) mod 1000 < 10` (§1.2؛ في T-HAND `X mod 1000` هو طور أمر `EXPIRE`). صفر خارجها = finding.
* **N-H3:** معدّل الفائز صفر بـ Wilson 95 %، وصفيًا مقابل P-PREV.
* **حدّ القياس (معلن):** نتيجة العامل لا تفصل «خسر السباق» عن «اختلف تحويله». التنبؤ المنقول «وإلا فائز واحد بالضبط» يتحقق إن طابق تحويل **عامل واحد على الأقل** تحويل READ؛ الحالة المختلطة (بعض العمّال يطابق) تعطي فائزًا واحدًا كذلك ولا تُرى منفصلة (LT-160D-05).

### 4.5 D-DET (حتمي، 20 + 20 لكل اختبار)

* **الساعة:** ساعة تمرير بإزاحة يضبطها السكربت بين النداءين (`SystemClock` + `offsetMs`)، عبر معامل الـ constructor الثالث القائم (L1381–L1389)؛ لا تغيير في `src/`. **الإزاحة +1,000 ms لـ MUTATE فقط** تنقل ثانية المتصل إلى الثانية التالية.
* **حتمية الضابط (تعريف بناء، LT-160D-01):** بساعة ثابتة قيمتها واحدة للنداءين يبقى التحويلان مختلفين حين يقع حدّ في `r_M − r_R`، فالضابط ليس 0/20 حتميًا. لذا كلا المتغيرين بـ `PEXPIREAT` على `X ≡ 500 (mod 1000)` كما §4.3: حينها المحقون يختلف حتمًا (`m` يزيد واحدًا بالضبط) والضابط يطابق حتمًا ما دام `n < 500 ms`.
* **D-DET-K:** تسلسل P-K4. المحقون (READ إزاحة 0، MUTATE إزاحة +1,000): **غير مطبَّق 20/20** (Wilson الأدنى 83.9 %)؛ الضابط (إزاحة 0 للنداءين): **0/20 غير مطبَّق**.
* **D-DET-H:** تسلسل T-HAND. المحقون (READ في الأب إزاحة 0، العمّال الستة إزاحة +1,000): **0 فائز 20/20**؛ الضابط (الكل إزاحة 0): **فائز واحد 20/20**.
* أي استثناء أو قيمة أخرى finding يُسجَّل بأثره.

## 5. الصلاحية

* **V-HEAD:** قبل كل ذراع، `src/` و`tests/` و`composer.json` و`phpunit.xml` = `d37f9a3` بايتيًا، والشجرة نظيفة.
* **V-PORT:** منفذ كل حاوية مسجَّل، لا 6379 أبدًا. **V-CONFIG:** `redis_version` (المتوقع 7.0.15)، `maxmemory`، `maxmemory-policy` لكل حاوية.
* **C-1 / C-2:** كل job يحفظ `composer.lock` وإصدارَي PHP وPHPUnit؛ `envset.php` (المرحلة 1، بلا تغيير) يذكر هل اشتغلت كل الـ jobs على مجموعة محلولة واحدة.
* **V-CLOCK:** تسلسل P-K4 وتسلسل T-HAND على ساعة ثابتة مع `X ≡ 500`، مرة بالمسجِّل ومرة بدونه (20 لكل منهما): `applied` و`e_R` والحالة المكتوبة متطابقة.
* **overhead المسجِّل (يُقاس مرة):** P-K4 بالتناوب 2,000 بالمسجِّل و2,000 بـ `SystemClock` مباشرة؛ نسبة الوسيط لزمن التسلسل **≤ 1.25×** وإلا لا تبدأ الأذرع الطبيعية ويُبلَّغ.
* **التسلسل غير الصالح** (حاوية فاشلة، `X` غير المطلوب في الأذرع المُجبَرة، صف ناقص) يُعدّ ويُبلَّغ ولا يدخل النسبة؛ إن تجاوز 2 % من ذراع يتوقف الذراع ويُبلَّغ.

## 6. الكواشف (تُرى تعمل قبل أي عدّ)

| الكاشف | المتوقع |
| --- | --- |
| D1 provenance | كل نسخة = الأصل، أو تختلف بالـ diff المسجَّل بالضبط؛ نسخة معبث بها تفشل |
| D2 V-HEAD على ref آخر (مثل `d71c0af`) | مرفوض |
| D3 مصنّف اصطناعي | صفوف مُصنَّعة: غير مطبَّق مع `(X−1) mod 1000 = 3` → «في النافذة»؛ `= 500` → finding؛ مطبَّق → سليم؛ صف H بفائزين → finding؛ صف H بصفر فائز و`= 700` → finding؛ `X ≠` المطلوب في المُجبَر → غير صالح |
| D4 D-DET مصغّر (3 + 3 لكل اختبار) | المحقون غير مطبَّق / 0 فائز، الضابط مطبَّق / فائز واحد |
| D5 قراءة `X` | مفتاح بـ `PEXPIREAT` معروف: `PEXPIRETIME` يعيده بالضبط |
| D6 V-CLOCK وoverhead | كما §5 |

E-LOCAL أولًا لسلامة الأداة، ثم على E-CI ضمن dispatch الكواشف (LT-160D-07).

## 7. الأدوات والـ provenance

* `phase3-k4-hand/harness/`: سكربت المسبار (الأذرع §4)، المصنّف والملخِّص (Wilson، Fisher، توزيع الأطوار)، `run.sh`، الكواشف.
* **منسوخ بترويسة provenance** (المسار المصدر وSHA-256 الأصل عند `d37f9a3`) مع `diff -U0` مسجَّل و`provenance_check.sh` يتحقق من البايتات: `SettableClock`/`RecordingClock` من `phase2/harness/c.php` (مكيَّفة: ms وإزاحة؛ diff مسجَّل)، `stats.php` من `phase2/harness/` (Wilson/Fisher)، `run.sh` من `phase2/harness/` (مكيَّف). `env.sh` و`envset.php` و`redis.sh` تُستعمل من `investigations/flaky-160/harness/` في مكانها بلا نسخ (كما المرحلة 2).
* `PROVENANCE.txt` بصيغة المرحلة 2: `<copy> <original> <sha256> [adapted:<diff>]`.

## 8. الـ workflow المؤقت

* الاسم المسجَّل `.github/workflows/flaky-160.yml`، `workflow_dispatch` فقط (بلا push trigger)، `permissions: contents: read`، بلا secrets، `investigations/flaky-160/harness/env.sh` في كل job، pins `ci.yml`.
* **المصدر:** لا commit حذف على `main` يُعكس (#168 دُمج squash)؛ آخر نسخة هي `fc313fb:.github/workflows/flaky-160.yml` (من `refs/pull/168/head`). تُنسخ في commit مستقل كما هي، ثم commit تكييف (الوحدات: `detectors | k | h | det`، مسارات `phase3-k4-hand/harness/` وحدها)، والـ diff بينهما مسجَّل (LT-160D-07).
* `actionlint` نظيف قبل الـ push؛ يُحذف في commit مستقل قبل Ready.

## 9. التكلفة

* **تقدير قبل القياس:** M في المرحلة 1 كلّف 4.16–5.71 ms لنداء pipeline كامل؛ تسلسل READ+MUTATE ≈ 7–8 أوامر، فالتقدير 1–2 ms. K-NAT 300,000 تسلسل ≈ 5–10 دقائق؛ K-FORCED 20,000 ≈ دقيقة؛ H-NAT 20,000 × (fork ستة عمّال) بتقدير 20–60 ms ≈ 7–20 دقيقة؛ D-DET وV-CLOCK ثوانٍ؛ إعداد ≈ 3.5 دقيقة لكل job (المرحلة 1 §13.4). المجموع ≈ **0.5–1.5 runner-hour** (تقدير التكليف 1–2).
* **المقاس:** زمن dispatch الكواشف (بأحجام صغيرة) يُنشر على الـ PR مع الإسقاط لكل ذراع، و**لا dispatch معدود قبل كلمة الـ Owner**. الحساب مجاني: كل job < ساعتين بهامش، dispatch واحد في كل مرة، لا push ولا CI آخر أثناءه، لا إعادة بلا كلمة الـ Owner، لا رقم معدود من E-LOCAL.

## 10. ما يحويه الـ REPORT

* كل تنبؤ (§2) ونتيجته بـ Wilson 95 %، وكل finding بصفّه الكامل؛ ما لم يُقس يُكتب «غير مقاس».
* لكل من T-K4 وT-HAND **قرار أولي**: (أ) **عيب في `LIFECYCLE_READ`/`LIFECYCLE_MUTATE`** — تحويل العمر المتبقي مرتين من قراءتين مختلفتين (`h` و`PTTL` لكل نداء) لحالة منطقية واحدة، فيعامل fence المساواة في L358 انجراف التحويل كـ snapshot قديم، خلاف مبدأ «قراءة واحدة لكل نداء» في DEC-016 (ملاحظة التطبيق، #158)؛ أو (ب) **افتراض اختبار** — العقد يجيز «غير مطبَّق» لـ snapshot قديم (docblock L1821–L1837، L1825: «never an exception»)، والمستدعي الإنتاجي يعيد المحاولة (L1733)، والاختبار يفترض أن READ→MUTATE واحدًا يُطبَّق دائمًا.
* لكل مسار: ما يلزمه من تغيير (`src/` + DEC، أو `tests/` فقط) وما يلزمه من قياس قبله وبعده (مثلًا: K-NAT = 0 بعد التغيير، أو D-DET للاختبار المعدَّل، وأثر المسار الإنتاجي §1.4 إن قيس). **بلا اختيار**؛ الاختيار للـ Owner.

## 11. أسئلة للـ Lead قبل البناء (LT-160D-xx)

* **LT-160D-01 — حتمية D-DET.** «ساعة محقونة تعبر حدّ الثانية» وحدها لا تجعل الضابط 0/20 حتميًا (§4.5). **أقترح:** إزاحة +1,000 ms لـ MUTATE مع `PEXPIREAT` على `X ≡ 500` في المحقون والضابط. مقبول؟
* **LT-160D-02 — المقارنان في K-NAT.** K = 100,000 لكل من P-CUR وP-PREV كما P-K4 (التكلفة دقائق)، وقاعدة «المعدّل نفسه» تُدحض عند Fisher p < 0.01 بين P-K4 وأي مقارن. **أقترح:** نعم للاثنين. أم المقارنة وصفية فقط؟
* **LT-160D-03 — K-CAL للبند «≈ `E|n_R − n_M| / 1000`».** `n_c` غير مرئي من الأزواج. **أقترح** ذراع معايرة منفصلًا (K = 100,000): قراءة المضيف بالساعة نفسها ثم `EVAL` صغير من الـ harness (ليس سكربت المتجر) يعيد `TIME`، عبر الـ executor نفسه، لتوزيع `n` و`E|Δn|` لأزواج متتالية؛ ثم N-K2: عدد غير المطبَّق داخل نطاق Poisson 95 % حول `K · E|Δn| / 1000`. حدّه: `n` لسكربت صغير تقريب لـ `n` سكربتات المتجر (أطول). بدونه البند «غير مقاس».
* **LT-160D-04 — «حصة `n_R ≠ n_M` الملاحظة» عند `X ≡ 1`.** بالقراءة الاختلاف هناك ⇔ `[n_R = 0] ≠ [n_M = 0]` (§4.3)، و`[n_M = 0]` لا يُرى مستقلًا. **أقترح** متغيرًا ثالثًا **K-FORCED-M** (10,000): MUTATE وحده بـ DTO مبني من حقول READ مع `expiresAt = ⌈X/1000⌉` مفروضًا، فـ `applied ⇔ n_M = 0`؛ ثم التنبؤ: معدّل ρ = 1 = `p_R(1 − p_M) + (1 − p_R)p_M` (استقلال) بـ Wilson متداخل. أم يكفي التنبؤ «موجب»؟
* **LT-160D-05 — حدّ H-NAT.** الحالة المختلطة بين العمّال لا تُرى (§4.4). يُقبل التنبؤ المنقول بصيغته العملية N-H1/N-H2 مع الحدّ معلنًا؟
* **LT-160D-06 — K-HELD (اختياري، المسار الإنتاجي §1.4).** تسلسل P-K4 وP-PREV عبر `readGenerationBoundScoreStateAt`/`mutateGenerationBoundScoreAt` بقراءة ms واحدة ممسوكة للنداءين (كما `CallerTimeStore` تحت `RequestScopedClock`)، K = 100,000 لكل نوع. التنبؤ: غير مطبَّق ⇔ حدّ في `[X − (r_M − h), X − (r_R − h))`، والمعدّل من رتبة K-NAT أو أعلى. **أقترح:** نعم، لأن القرار (أ)/(ب) يحتاج معرفة ما يراه المضيف لا الاختبار فقط. أم خارج النطاق؟
* **LT-160D-07 — الـ workflow وdispatch الكواشف.** (أ) النسخ من `fc313fb` بدل `git revert` (لا commit حذف على `main`)، بالاسم المسجَّل `flaky-160.yml`. (ب) «التكلفة المقاسة لـ run واحد قبل أي dispatch»: أقترح dispatch **كواشف** واحد بأحجام صغيرة (D1–D6 وكل ذراع بـ K = 2,000، تقدير ≈ 15 runner-minute) هو قياس التكلفة نفسه، بكلمة الـ Owner على هذا التقدير؛ ثم dispatch معدود واحد بعد كلمته على الرقم المقاس. مقبول؟
* **LT-160D-08 — أحجام K وحدود الصلاحية.** K-NAT 100,000 لكل نوع، K-FORCED 10,000 لكل ρ، H-NAT 20,000، D-DET 20 + 20، حدّ 2 % للتسلسلات غير الصالحة، و1.25× للـ overhead. تأكيد؟

## 12. ترتيب الـ commits

1. هذه الخطة وحدها → push → Draft PR بالعربية → **توقف**.
2. أحكام الـ Lead في §13 من هذا الملف (بلا تغيير آخر).
3. الـ harness والكواشف (E-LOCAL سلامة أداة)، ثم الـ workflow (نسخ `fc313fb` commit، تكييف commit)، `actionlint` و`check-whitespace.sh`.
4. dispatch الكواشف (بحكم LT-160D-07) → التكلفة المقاسة على الـ PR → **توقف لكلمة الـ Owner**.
5. dispatch معدود واحد → `logs/` بـ `SHA256SUMS` → `REPORT.md`.
6. حذف الـ workflow في commit مستقل قبل Ready.

## 13. أحكام الـ Lead

(تُكتب بعد الحكم.)

## 14. مسار AGENTS.md

Normal Engineering Task (Adoption Standard §10.1): قُرئ `docs/php-engineering-standards/STANDARDS_MANIFEST.md`؛ الـ Scope هو `/` (الـ activations `composer-package` 4.0.0 و`repository-governance` 3.0.0)؛ الملفات المتأثرة في هذا الـ commit `investigations/flaky-160/phase3-k4-hand/PLAN.md` فقط (مستند تحقيق، لا `src/` ولا `tests/`). المنطبق من Final Resolved Applicable Standards Set: `TESTING_STANDARD.md` 2.0.0 (القياس على اختبارات التكامل)، `AI_COLLABORATION_WORKFLOW_AR.md` 12.0.0 و`GITHUB_PHASE_STACK_WORKFLOW_AR.md` 4.0.0 (Draft، خطوة لكل commit، الدمج بيد الـ Lead)، `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` 3.1.0، `DECISION_GOVERNANCE_STANDARD_AR.md` 1.0.0 (لا قرار هنا؛ القرار للـ Owner)، و`CI_WORKFLOW_STANDARD.md` 5.0.0 للـ workflow المؤقت في الخطوة 3.
