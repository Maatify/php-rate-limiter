# #263 — تسلسل سياق الطلب والنتيجة (JSON) وإرشاد السجل التشخيصي الآمن

دراسة قياس وتصنيف. **لا تغيير في `src/`** ولا في شكل التسلسل العام. المصدر المسجَّل: `main@428279bb07bf9484595b21862b291b9c63b816fe` (الشجرة `4c48cb3edcd067b92e6711f09268280eaef28c4d`) مع ملفات هذه الدراسة، والأدلة الحالية مقيسة على مصدر التشغيل `5a386e05ece20b6a9bfd80caf08a5ad0291ae442` (الشجرة `1b7bf15e6571b4bbb14dcaefa6625a663946a403`، `worktree_dirty_outside_study_evidence=no`). أدلة المراجعة الأولى (13 سيناريو، المصدر `3378828f61e11a431efb254cfd7b7e2917e9a44a`، الشجرة `a3aa9e86085a9c6f007828f86530db1f5dd445ed`) محفوظة كما هي في `raw/superseded-at-3378828f/`.

## 1. السؤال والتصنيف

**السؤال.** `RateLimitContextDTO` يُسلسَل (JSON) كل ما يمرره الـHost، ومنه الـheaders وبصمة العميل ومدخلات admission الاختيارية. هل ينتقل ذلك إلى سجل؟ ومن المسؤول؟

**التصنيف (حسب المقيس).** **تقوية إرشاد، لا تعرُّض مثبت في سلوك المكتبة.**
- **لم يُرصد** أن المكتبة تكتب في القنوات التي تتيحها PHP نفسها: stdout وملف `error_log` وإشعارات PHP (S07)، ولا يوجد في `src/` عند هذا الـSHA logger ولا `error_log` ولا `var_dump` ولا `__toString`، ولا اعتماد على `psr/log` (`raw/grep-automatic-logging.txt`). سجل الـHost أو أي logger له **غير موصول** بالـruntime في S07، فلا يُستنتج منه شيء. المكتبة تستدعي `FailureSignalEmitterInterface::emit()` الذي يوفره الـHost (إشارات `CB_OPENED` ونحوها)، وما إذا كُتب منه سطر سجل هو كود الـHost (S14).
- القيم الاصطناعية تصل إلى السجل **فقط** عند خطوة الكتابة التي يفعلها الـHost (S09): تسلسل `RateLimitContextDTO` كاملًا ينقل كل علامة اصطناعية، المتداخلة منها والـadmission.
- النتيجة الحقيقية (`RateLimitResultDTO` من runtime فعلي) لا تحمل علامات سياق الطلب (S08)، لكن تسلسلها كاملةً مع metadata ممتلئة ينقل `permit` ومعرّف `postPunishmentReentry` (S10).
- لا وعد بالحجب أو التنقيح في الوثائق المفحوصة: دليل الاستخدام ينسب السجل إلى الـHost (`docs/guides/USAGE_GUIDE.md`: «The host owns the final response, retry handling, logging, and user-facing presentation» وفقرة ملكية الـHost لـ«logging destinations»)، و`SECURITY.md` يخص الإبلاغ عن الثغرات فقط، وبحث `redact|sanitiz|mask` لا يعطي وعدًا بحجب لهذه الأشكال.

## 2. الهوية والطريقة

- Docker فقط، `--network none`، الصورتان `maatify-rl-php64:local` (PHP 8.4.27، `PHP_INT_SIZE=8`، linux/arm64) و`maatify-rl-php32:local` (PHP 8.4.27، `PHP_INT_SIZE=4`، linux/386 بمحاكاة).
- بيانات اصطناعية بعلامات `SYN263-*` (والـIP عنوان توثيق RFC 5737 `203.0.113.63`). لا بيانات اعتماد ولا headers حقيقية.
- الاختبار: `tests/System/DtoSerializationLoggingStudy263Test.php` (14 سيناريو)، ودعمه في `tests/Support/Study263/`: `RecordingLogSink` (يعزل خطوة الكتابة عن التسلسل)، `SyntheticInputs`، `AllowlistDiagnosticRecord` (مثال قائمة السماح)، `EmitterRuntime` و`LoggingFailureSignalEmitter` (مُصدِر بأسلوب الـHost يكتب في السجل، S14). المسار الحقيقي يستعمل بناء `RateLimiterBuilder` من دعم دراسة #262.
- `STUDY_263_EVIDENCE` يكتب سطر JSON لكل سيناريو؛ المشغّل `run.sh` هو نفسه مشغّل #262 المحصَّن (F272-01) بعد تغيير الأسماء، وله `selftest.sh` (28 ضابطًا ناجحًا في `raw/runner-selftest.log`).

## 3. المتوقع والمرصود

| # | السيناريو | المتوقع | المرصود (64 و32 متطابقان) |
|---|---|---|---|
| S01 | سياق بأدنى المدخلات | مفاتيح `ip..correlationId` فقط؛ القيم الفارغة `null`؛ مفتاحا admission غائبان | كما توقعنا. `headers` الفارغة تُسلسَل `[]` وليس `{}` |
| S02 | سياق كامل | كل علامة سياق (12) في الـJSON | 12 علامة، ومنها المتداخلة (`clientFingerprint.nested.deep`، `headers.Cookie[0..1]`) |
| S03 | غائب مقابل `null` مقابل `""` | `null` ≡ غياب لمفتاحي admission؛ `""` قيمة والمفتاح موجود؛ `null` لغير admission مفتاحه موجود بقيمة `null` | كما توقعنا |
| S04 | أشكال النتيجة | `metadata` موجودة بـ`null`؛ `postPunishmentReentry` و`permit` غائبان عند `null` وموجودان عند وجودهما | كما توقعنا |
| S05 | `FailureSignalDTO` وreentry | مفاتيح موثقة فقط | `type, policyName, metadata` و`id, validUntil` |
| S06 | جرد الأنواع | لا خاصية في DTO النتيجة/الإشارة من نوع `RateLimitContextDTO` أو `array` أو `mixed` | قائمة فارغة |
| S07 | القنوات المرصودة | لا شيء في stdout ولا ملف `error_log` ولا إشعارات PHP (سجل الـHost غير موصول) | 6 نداءات حقيقية (سليم + عطل typed) بسياق كامل: صفر في القنوات الثلاث |
| S08 | نتيجة حقيقية مقابل السياق | لا علامة سياق في JSON النتيجة | لا علامة (`ALLOW, ALLOW, HARD_BLOCK`) |
| S09 | الـHost يسجل السياق كاملًا | الإنشاء والتسلسل: 0 سطر؛ الكتابة: 1 سطر يحمل العلامات | 0 / 0 / 1، و12 علامة في السطر |
| S10 | الـHost يسجل النتيجة كاملة | `permit` وreentry id وبقية علامات metadata في السطر | 6 علامات (القيم بُنيت اصطناعيًا، لم تُستخرج من runtime admission) |
| S11 | قائمة السماح | خمسة مفاتيح بلا أي علامة، حتى مع metadata متداخلة تحمل علامات | `{"policy","decision","failureMode","blockLevel","retryAfter"}` بلا علامة؛ قيمة غير معروفة تُكتب `unrecognized` |
| S12 | ضابط: قائمة منع | علامات الحقول غير المحذوفة تبقى | 8 علامات تبقى (ضابط، لا توصية) |
| S14 | مُصدِر الإشارات | المكتبة تستدعي مُصدِر الـHost؛ سطر السجل يوجد فقط حيث يكتب مُصدِر الـHost؛ الإشارة بلا علامة سياق | 12 نداءً لكل runtime بعطل typed: إشارة `CB_OPENED` واحدة لكل مُصدِر (التسجيل فقط، والمُصدِر الكاتب)، سطر واحد في السجل بلا علامة، `metadata` فيه `null` |
| S13 | عرض الصحيح | `retryAfter = PHP_INT_MAX` يعود كما هو | `9223372036854775807` (64) و`2147483647` (32) — الفرق الوحيد بين التشغيلين |

العدّ: **14 اختبارًا و83 تأكيدًا على كل من العرضين**، `final_exit=0` (`raw/run-64/run-meta.txt` و`raw/run-32/run-meta.txt`). `selftest.sh` عُدِّل بعد توليد هذه الأدلة (ضابط عدّ السجلات يستعمل 99 بدل 14) وأُعيد تشغيله: 28 ضابطًا ناجحًا (`raw/runner-selftest.log`).
الضابط التحوّلي: `raw/mutation-allowlist-includes-metadata.log` — إضافة `metadata` إلى قائمة السماح تُفشل اختبار S11 (اختبار واحد من 14).

## 4. مثال قائمة السماح (للمضيف؛ ليس جزءًا من المكتبة)

```php
final class AllowlistDiagnosticRecord
{
    private const DECISIONS = [RateLimitResultDTO::DECISION_ALLOW, RateLimitResultDTO::DECISION_SOFT_BLOCK, RateLimitResultDTO::DECISION_HARD_BLOCK];
    private const FAILURE_MODES = ['NORMAL', 'DEGRADED_MODE', 'FAIL_OPEN', 'FAIL_CLOSED'];

    public static function fromResult(string $policyName, RateLimitResultDTO $result): array
    {
        return [
            'policy' => $policyName, // معرفة الـHost، لا من سياق الطلب
            'decision' => in_array($result->decision, self::DECISIONS, true) ? $result->decision : 'unrecognized',
            'failureMode' => in_array($result->failureMode, self::FAILURE_MODES, true) ? $result->failureMode : 'unrecognized',
            'blockLevel' => $result->blockLevel,
            'retryAfter' => $result->retryAfter,
        ];
    }
}
// $logger->info('rate_limit', AllowlistDiagnosticRecord::fromResult('login_protection', $result));
```

- لا يستقبل `RateLimitContextDTO` أصلًا، ولا يسلسل DTO، فلا تصل headers ولا بصمة ولا مدخلات admission ولا `permit` ولا metadata متداخلة.
- القيم المعدّدة تُقارن بالثوابت المعروفة. المقيس: أربع قيم لـ`failureMode` في `src/` (`NORMAL`, `DEGRADED_MODE`, `FAIL_OPEN`, `FAIL_CLOSED`)؛ لو أضافت نسخة قيمة جديدة فستُكتب `unrecognized` حتى يُحدَّث المثال.
- أي حقل من السياق يريده الـHost لأغراض التشخيص (معرّف مُشتق، أو تجزئة) قرار الـHost وسياسة خصوصيته؛ لم تقيسه الدراسة.

## 5. مسؤولية الـHost مقابل سلوك المكتبة

| | المكتبة (مقيس) | الـHost |
|---|---|---|
| متى تصل القيم إلى السجل | لم تكتب في القنوات المرصودة (S07)؛ تستدعي مُصدِر الـHost للإشارات (S14) | عند `json_encode` ثم الكتابة (S09/S10)، وفي مُصدِره إن كتب (S14) |
| ما يُسلسَل في `RateLimitContextDTO` | كل المدخلات، admission منها عند الوجود | اختيار الحقول، وحجب الحساس |
| شكل الـJSON | ثابت، لم يُغيَّر | عدم الاعتماد على غياب الحقل = `null` (S03) |

## 6. حدود الدليل

- S07 لا يغطي سجل الـHost: لا يوجد في الـruntime منفذ لتمرير logger، والـsink المرتبط بمُصدِر الإشارات (S14) هو الموصول الوحيد.
- المقيس: اختبارات في عملية PHP واحدة، backend اصطناعي، ساعة ثابتة. لا Redis ولا عميل Redis (خارج السؤال).
- S10 يبني metadata ممتلئة يدويًا؛ لم يُستخرج `permit` من runtime admission حقيقي، فلا يُستنتج منه متى يظهر في نتيجة فعلية.
- الفحص النصي (`grep`) يدل على غياب مسار كتابة في `src/` عند هذا الـSHA، ولا يثبت غياب كتابة من كود الـHost أو من اعتمادات خارجية؛ رسائل الاستثناءات لم تُفحص قيمةً قيمةً (وجدنا أن استثناء البصمة نصه ثابت).
- المقيس لا يشمل أطر تسجيل بعينها (قد تُسلسل كائنات الـcontext تلقائيًا إن مُرِّرت كاملة؛ هذا سلوك الإطار والـHost).
- تنبيه: لو عُثر على مادة حساسة حقيقية في أدلة هذه الدراسة، يُبلَّغ عنها عبر المسار الخاص في `SECURITY.md` لا عبر issue عام.

## 7. التقديم

الدراسة لا تقترح تغيير عقد التسلسل. المقترح (قرار منفصل للـOwner): إضافة فقرة إرشاد في دليل الاستخدام و`SECURITY.md` تذكر أن `RateLimitContextDTO` و`RateLimitResultDTO` ليسا آمنين للتسجيل الكامل، وتقدم مثال قائمة السماح أعلاه.

## 8. إعادة التشغيل

```bash
bash investigations/dto-logging-263/run.sh 64 investigations/dto-logging-263/out/r64
bash investigations/dto-logging-263/run.sh 32 investigations/dto-logging-263/out/r32
bash investigations/dto-logging-263/selftest.sh
```

أدلة خام في `raw/`: `run-64/` و`run-32/` (`evidence.jsonl`, `phpunit.log`, `run-meta.txt`)، `grep-automatic-logging.txt`، `mutation-allowlist-includes-metadata.log`، `runner-selftest.log`، `governance-helper.json`.
