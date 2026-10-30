# #176 — بند الإغلاق 4: دليل الاستقرار الطبيعي — PLAN (S0)

> **الحالة:** PLAN وحده، ثم **توقف حتى أحكام الـ Lead** على §7. لا workflow ولا تغيير في `ci.yml` ولا dispatch في هذا الـ commit.
> **الأساس:** `main@62599c9`، الفرع `claude/stability-release-176`.
> **المصدر:** وصف #176 وأول تعليق فيه (تكليف S0–S4).
> **القواعد:** Draft بالعربية، والدمج بيد الـ Lead، و`check-whitespace.sh` قبل كل push. أي فشل CI يُبلَّغ ثم أنتظر كلمة الـ Owner قبل أي إعادة. لا رقم بلا أمر قياسه.
>
> **البيانات في `data/` (`SOURCE.txt`، `SHA256SUMS`):**
> - `ci-history.raw.json` ناتج الأمر في `ci-history.command.txt` كما هو؛
> - الملخص من `scripts/summarize_history.php` (يُعيد إنتاجه بايتيًا)؛
> - jobs المحاولة 1 وسجلات الوظائف الفاشلة (`attempt1/`)؛
> - مدد الوظائف لأحدث تشغيل أخضر على `main` (`baseline-40975978888.jobs.json`).

## 1. التاريخ الطبيعي (مقاس، 2026-10-30)

### 1.1 الجدول

**الأمر:** `gh run list --workflow ci.yml --limit 400 --json headSha,conclusion,attempt,createdAt,headBranch`. أعاد **400 صفًّا**، من 2026-09-25T20:49:10Z إلى 2026-10-29T23:52:01Z، فالحدّ 400 لا يقطع شيئًا بعد rc.3.

| نافذة البدء | التشغيلات | success | failure | cancelled | `main` | المصدر |
| --- | --- | --- | --- | --- | --- | --- |
| وقت وسم `v1.0.0-rc.3` (2026-10-07T13:14:02Z) | **306** | 236 (231 a1 + 5 a2) | 25 | 45 | **66**، كلها success | `ci-history.summary.json`؛ وAPI `actions/workflows/ci.yml/runs?created=>=…` يعطي 306 و66 |
| بداية يوم 2026-10-07 (00:00Z) | **314** | 242 (237 a1 + 5 a2) | 26 | 46 | **67** | API بالمرشّح نفسه |

* **أرقام الـ Lead** (314 تشغيلًا، و26 فشلًا) تطابق نافذة **بداية اليوم**.
* **`main` = 72 في وصف الـ issue لم يُعَد إنتاجه:** القياس يعطي 67 لبداية اليوم و66 لوقت الوسم (LT-176-02).

**الرؤوس الخمسة التي فشلت في المحاولة 1 ثم نجحت في المحاولة 2 (attempt = 2) مطابقة لجدول الـ Lead:** `08a6ce9`، `b75e7d6`، `fe8775c`، `b14b30d`، `be283a9`.

### 1.2 التصنيف من سجلات المحاولة 1

المصدر `attempt1/<run>.jobs.json` و`attempt1/job-<job>.log.gz`.

| الـ run | الرأس | الوظيفة / الخطوة الفاشلة | الرسالة في السجل | التصنيف | المصدر المعروف |
| --- | --- | --- | --- | --- | --- |
| 38691580413 | `08a6ce9` | Coding Style، Test Suite 8.5، PHPStan؛ خطوة `Install Dependencies` | `Failed to download maatify/exceptions from dist … (HTTP/2 404 )` من `codeload.github.com` | **بنية تحتية** | تنزيل dist من GitHub |
| 39044075394 | `b75e7d6` | Consumer Verification Harness | `Custom API fallback did not enforce its own IP+UA cap boundary.` (التشغيلان) | **تذبذب اختبار** | **F127-04، أُصلح في `a46701b` (#128)**. رسالة ذلك الـ commit تسمّي هذا الـ run بالذات («failed CI attempt 1 of run 39044075394 on the 80/81 custom API boundary»). **ليس CVH-2 (#173)** كما خمّن الجدول |
| 39570493869 | `fe8775c` | Test Suite 8.5 | `testMalformedStoredGenerationFailsAcrossReadMutateClaimAndPublication` (L1540/L3736) | **تذبذب اختبار** | #157، وهذا الـ run موضوع `investigations/flaky-157/REPORT.md` L11 |
| 39933593795 | `b14b30d` | Test Suite 8.5 | `KnownDeviceAllowanceConcurrencyIntegrationTest::testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly` L136 «28 is identical to 26» | **تذبذب اختبار** | #160 (T-ALLOW)؛ `investigations/flaky-160/REPORT.md` L86 يسمّي هذه الـ job |
| 40932111864 | `be283a9` | Consumer Verification Harness | `CVH-2: the 25th admission was not a level-1 SOFT_BLOCK.` (التشغيلان) | **تذبذب اختبار** | #173، أُصلح في #175 |
| 38996006413 | `8e6c2e9` (2026-10-17) | Integration Test Suite | الاختبار نفسه L136 «30 is identical to 28» | **تذبذب اختبار** (بلا إعادة؛ الرأس التالي أخضر) | T-ALLOW (#160)، والرسالة نفسها في `investigations/flaky-157/REPORT.md` L46/L49 |
| 37578579103 | `ea7c6ab` (2026-10-07T05:53Z، **قبل** وقت الوسم) | Lowest Supported Dependencies؛ `Run PHPStan and PHPUnit` | PHPStan 2.0.0: `[ERROR] Found 15 errors`، في `tests/Support/ReleaseVerification/GitRepository.php` L137 وغيره | **فشل حتمي تطويري** | الـ commit التالي `304b0ad` «fix(release): type proc_open pipes for the lowest supported PHPStan» يعدّل الملف نفسه (+11 −8) |
| 39240504508 | `979c21c` | Lowest؛ `Run PHPStan and PHPUnit` | PHPStan 2.0.0: `Found 1 error`، في `src/Service/AdmissionCredentialCodec.php` L159 | **فشل حتمي تطويري** | التالي `7196577` «F144-01: قائمة budget groups مُنمَّطة…» يعدّل الملف نفسه |
| 39662548515 | `5dfb3f8` | Lowest؛ `Run PHPStan` | PHPStan 2.0.0: `Found 8 errors`، في `tests/Support/Correlation/StatefulInMemoryCorrelationStore.php` L646 وغيره | **فشل حتمي تطويري** | التالي `38c5ce7` يعدّل الملف نفسه (+18 −6) |

* **لا مصدر غير معروف، فلا finding قبل أي dispatch.**
* **تصحيحان لجدول الـ issue:**
  * `b75e7d6` هو F127-04 (#128) لا CVH-2؛
  * `38996006413` تذبذب T-ALLOW، و«Lowest ×3» فشل حتمي لا تذبذب.
* **PHPStan حتمي:** على شيفرة ثابتة واعتماديات مقفلة بـ `--prefer-lowest` يعطي النتيجة نفسها. والإصلاح في كل حالة commit لاحق يعدّل الملف المُبلَّغ عنه.

### 1.3 المعدّل الطبيعي (تعريف مسجَّل، والحكم في LT-176-02)

* **البسط** = تذبذبات الاختبار بالمحاولة 1 = **5**: `b75e7d6`، و`fe8775c`، و`b14b30d`، و`be283a9`، و`38996006413`. البنية التحتية (`08a6ce9`) خارج البسط، وتُذكر وحدها.
* **المقام** = التشغيلات التي كانت محاولتها 1 خضراء أو تذبذب اختبار، أي success + التذبذب المفرد. الحمراء المقصودة والمُلغاة خارجه.

| النافذة | المقام | المعدّل [Wilson 95 %] | Fisher ثنائي الطرف: 0/200 مقابله | أحادي الطرف |
| --- | --- | --- | --- | --- |
| بداية اليوم | 243 | 5/243 = **2.06 %** [0.88، 4.73] | **p = 0.067** | p = 0.049 |
| وقت الوسم | 237 | 5/237 = 2.11 % [0.90، 4.84] | p = 0.066 | p = 0.046 |
| بداية اليوم + البنية التحتية | 243 | 6/243 | p = 0.035 | p = 0.027 |

* **أثره على N = 200 (حساب، لا قياس):** 0/200 لا يُظهر تحسّنًا ذا دلالة عند 0.05 بـ Fisher ثنائي الطرف مقابل 5/243.
  * عتبته **N ≥ 245**؛ وعند N = 250 (10 × 25): p = 0.029، وWilson الأعلى 1.51 %.
  * أما «N ≥ 215» في الـ issue فاختبار عيّنة واحدة مقابل معدّل ثابت 1.4 % (`0.986^N < 0.05` يعطي N ≥ 213)، لا Fisher مقابل التاريخ.
  * الحسابات بـ `fisherTwoSided` و`wilson` من `investigations/flaky-160/fix-a3/harness/k_lib.php` و`stats.php`. **LT-176-01.**

## 2. ما تحققتُ منه في وثائق GitHub (بالرابط)

| السؤال | النص | المصدر |
| --- | --- | --- |
| reusable workflow من مصفوفة | «Jobs using the matrix strategy can call a reusable workflow.» | [Reuse workflows](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows)، *Using a matrix strategy with a reusable workflow* |
| `strategy` على وظيفة الاستدعاء | `jobs.<job_id>.strategy` ضمن الكلمات المدعومة | [Reusing workflow configurations](https://docs.github.com/en/actions/reference/workflows-and-actions/reusing-workflow-configurations)، *Supported keywords for jobs that call a reusable workflow* |
| حدود الاستدعاء | «You can connect up to ten levels of workflows.»؛ «You can call a maximum of 50 unique reusable workflows from a single workflow file.» | المرجع نفسه |
| حدّ المصفوفة | «A job matrix can generate a maximum of 256 jobs per workflow run.» | [Actions limits](https://docs.github.com/en/actions/reference/limits) |
| **مجموع الوظائف في run** | **لا حدّ منصوص** لمجموع الوظائف حين يتوسّع كل استدعاء من المصفوفة إلى 16 وظيفة (25 × 16 = 400). ولا ذكر إلا «Reusable workflows are viewed as a single entity» في سياق طابور التشغيلات | المرجع نفسه. **غير متحقَّق**، LT-176-05 |
| التزامن | 20 وظيفة متزامنة لـ standard runners على خطة Free | المرجع نفسه |
| مدة الوظيفة | «Each job in a workflow can run for up to 6 hours of execution time.» | المرجع نفسه |
| سياق `github` في المستدعى | «the `github` context is always associated with the caller workflow»؛ «A called workflow uses the name of its caller workflow in ${{ github.workflow }}» | Reusing workflow configurations |
| أي ملف يُشغَّل | `./` في المستودع نفسه: «the called workflow is from the same commit as the caller workflow» | Reuse workflows، *Calling a reusable workflow* |
| **`github.ref` و`github.sha` و`github.event_name` داخله** | الصفحتان **لا تذكرانها بأسمائها**. المستنتج من «always associated with the caller»: قيم الـ dispatch (`workflow_dispatch`، ورأس الفرع المُرسَل). **غير متحقَّق**، ويُتحقق في S2 (V-REF) | — |
| التزامن وطابوره | «any existing `pending` job or workflow in the same concurrency group will be canceled»؛ والسياقات المسموحة: «`github`, `inputs`, `vars`, `needs`, `strategy`, and `matrix`» | [Control workflow concurrency](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency) |
| **concurrency على مستوى الـ workflow المستدعى** | الصفحة **لا تذكر** الـ reusable workflows. والمرجع ينصح فقط بعدم تكرار `jobs.<job_id>.concurrency.group` بين المستدعي والمستدعى مع `cancel-in-progress: true` | Reusing workflow configurations. **غير متحقَّق** هل يُطبَّق المفتاح على مستوى الـ workflow في المستدعى، ويُتحقق في S2 (V-PARALLEL) |
| الصلاحيات | «The GITHUB_TOKEN permissions passed from the caller workflow can be only downgraded» | Reusing workflow configurations |

* **لماذا `run_label` ضروري:** إن طُبِّق مفتاح `ci.yml` الحالي في المستدعى، فهو `${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}`، أي اسم المستدعي ثم `refs/heads/main`، **واحد لكل التكرارات**.
  * فتجري واحدة، وتبقى واحدة pending، وتُلغى البقية.
  * بـ `run_label` = `stability-<run_id>-<repeat>` يصير المفتاح فريدًا لكل تكرار.
* **`cancel-in-progress`** = `${{ github.event_name == 'pull_request' }}`. داخل المستدعى `event_name` هو حدث المستدعي `workflow_dispatch`، فالقيمة `false`. هذا استنتاج من «associated with the caller»، ويُتحقق في S2.

## 3. التصميم المقترح (S1، بلا بناء قبل الأحكام)

### 3.1 `ci.yml`: سطور الـ trigger والـ concurrency فقط

```yaml
on:
  workflow_dispatch:
  workflow_call:
    inputs:
      run_label:
        description: 'Concurrency label of one stability-evidence repeat; empty for every other trigger.'
        type: string
        required: false
        default: ''
  push: …            # unchanged
  pull_request: …    # unchanged

concurrency:
  group: ${{ github.workflow }}-${{ inputs.run_label || github.event.pull_request.number || github.ref }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}
```

* لكل حدث غير `workflow_call` تكون `inputs.run_label` فارغة، فيبقى المفتاح كما هو اليوم.
* `workflow_dispatch` لـ `ci.yml` نفسه بلا مُدخلات، فـ `inputs.run_label` غير معرَّف وفارغ.
* لا تغيير في أي وظيفة.

### 3.2 `.github/workflows/stability-evidence.yml` (جديد ودائم)

* **الـ trigger:** `workflow_dispatch` فقط، بمُدخلين: `repeats` (number، 1–25) و`ref` (string، SHA). لا push ولا pull_request.
* **الصلاحيات:** `contents: read`، بلا secrets.
* **الوظائف:**
  1. **`plan`** (`timeout-minutes: 5`):
     * يرفض `repeats` خارج 1–25؛
     * **V-REF:** يرفض إن كان `github.sha` ≠ `inputs.ref` (40 حرفًا)، فوظائف `ci.yml` تأخذ `github.sha` ولا تستطيع غيره؛
     * يُخرج قائمة `[1..repeats]`.
  2. **`ci`:**
     * `needs: plan`؛
     * `strategy.matrix.repeat: ${{ fromJSON(needs.plan.outputs.repeats) }}`؛
     * `fail-fast`: حسب LT-176-03؛
     * `uses: ./.github/workflows/ci.yml`؛
     * `with.run_label: stability-${{ github.run_id }}-${{ matrix.repeat }}`؛
     * `permissions: contents: read`.
* **actionlint** نظيف (Workflow Lint في CI).

### 3.3 ما يُجمع لكل تكرار (`scripts/collect.php`، لا في `scripts/ci/`)

* **المصدر:** `gh api repos/…/actions/runs/<id>/attempts/1/jobs?per_page=100` بالصفحات، بعد انتهاء الـ run.
* **لكل وظيفة:** التكرار (من اسم الوظيفة `ci (N) / <name>`، والشكل يُتحقق في S2: V-NAMES)، والاسم، و`conclusion`، و`started_at`، و`completed_at`، والمدة.
* **الملفات:**
  * `data/runs/<run_id>.jobs.json` كما هو؛
  * و`data/stability.jsonl`، سطر لكل وظيفة.
* **البيئة** (PHP، PHPUnit، الحزم المحلولة) حسب LT-176-04.

### 3.4 ما يُتحقق منه في S2 (smoke، `repeats=2`، بلا عدّ)

* **V-REF:** `github.sha` = الـ SHA المُرسَل في كل وظيفة (من `head_sha` في API الوظائف).
* **V-PARALLEL:** فترات الوظائف المتناظرة في التكرارين متداخلة، أي لا تسلسل ولا إلغاء بالـ concurrency.
* **V-NAMES:** أسماء الوظائف تحمل رقم التكرار.
* **V-SRC:** `git diff 62599c9..<SHA> -- src tests` فارغ (يُسجَّل).
* **V-COUNT:** 1 + 2 × 16 وظيفة.
* **الكلفة:** مجموع المدد لكل تكرار، والجدار.

### 3.5 الكلفة (من القياس، لا تقدير جديد)

* **أحدث تشغيل أخضر على `main@62599c9`** (run 40975978888): 16 وظيفة، مجموع **56.4 دقيقة runner** لكل تكرار، وجدار 13.7 دقيقة.
* **إسقاط خطي (غير مقاس):**
  * N = 200 ≈ 188 ساعة runner، وN = 250 ≈ 235 ساعة (مستودع عام، غير مفوترة)؛
  * الجدار لكل dispatch × 25 ≈ 25 × 56.4 / 20 ≈ 70 دقيقة عند تزامن 20.

## 4. التنبؤات المسجَّلة قبل أي تشغيل

* **(أ) smoke `repeats=2`:** التكراران متوازيان (V-PARALLEL)، و2 × 16 success، وV-REF وV-NAMES وV-COUNT تتحقق.
* **(ب) المعدود:** **0 فشل** في كل التكرارات.
  * عند N = 200: Wilson 95 % الأعلى **1.885 %**؛ وعند N = 250: 1.513 %.
  * التوقف عند أول فشل، والإبلاغ بلا إعادة.
* **(ج) المدد:** الأقصى لكل وظيفة في كل العينات دون مهلتها في `ci.yml` (#169).
  * **الهامش في الأساس** (run 40975978888، المدة ÷ المهلة):
    * Lowest: 815 ث / 20 د، هامش **32.1 %**؛
    * Integration: 572 / 15 د، **36.4 %**؛
    * Test Suite 8.4: 478 / 15 د، 46.9 %؛
    * Test Suite 8.5: 453 / 15 د، 49.7 %؛
    * Consumer: 326 / 15 د، 63.8 %؛
    * Archive 8.4 و8.5: 324 و271 / 25 د.
  * يُسجَّل الهامش الأدنى المرصود لكل وظيفة.
* **(د) Fisher** مقابل التاريخ المصنَّف في §1.3 (5/243):
  * عند N = 200: ثنائي الطرف **p = 0.067**، أي **غير مميَّز عند 0.05**، وأحادي الطرف 0.049؛
  * عند N = 250: ثنائي الطرف p = 0.029.

  هذا حساب على الأعداد المقاسة، يُكرَّر على النتيجة الفعلية في S4.

## 5. ما لا يثبته الدليل (للـ REPORT، مسجَّل مسبقًا)

* **0/N على رأس واحد** يحدّ معدّل الفشل لكل تشغيل لهذا الرأس بهذه البيئة. لا يثبت غياب تذبذب أندر من حدّ Wilson الأعلى، ولا شيئًا عن رؤوس أخرى.
* **البيئة الطبيعية تختلف:** التاريخ الطبيعي على فروع PR بأحمال مختلفة. أما المعدود فـ 25 تكرارًا متزامنًا، ضغطه على الـ runners وعلى Redis في كل وظيفة غير ضغط تشغيل منفرد. غير مقاس.

## 6. الترتيب

1. S0: هذا الـ PLAN، ثم توقف.
2. الأحكام في §8.
3. S1: PR الملفين (CI 16/16، وV-SRC)، ويُدمج بكلمة الـ Owner لأن الـ dispatch يتطلب الملف على الفرع الافتراضي.
4. S2: smoke، وتقرير التحقق والكلفة، ثم كلمة الـ Owner.
5. S3: الـ dispatches المعدودة متتابعة، بلا push ولا CI آخر. البندان 5 و6 محليًا بلا push.
6. S4: REPORT، وتوثيق الـ workflow.

## 7. أسئلة للـ Lead قبل البناء (LT-176-xx)

* **LT-176-01 — N والإحصاء.** 0/200 مقابل التاريخ المقاس (5/243) يعطي Fisher ثنائي الطرف p = 0.067، فلا يُظهر تحسّنًا عند 0.05. الخيارات:
  * (أ) **N = 250** (10 × 25): p = 0.029، وWilson الأعلى 1.51 %، و≈ 235 ساعة runner، و≈ 12 ساعة جدار؛
  * (ب) N = 200 مع Fisher **أحادي الطرف** (p = 0.049)، يُسجَّل قبل التشغيل؛
  * (ج) N = 200، ويُقدَّم Wilson الأعلى وحده دون ادعاء تحسّن.
  * المقترح (أ).
* **LT-176-02 — نافذة التاريخ وتعريف المعدّل.**
  * وقت الوسم (306) أم بداية اليوم (314، مطابق للـ issue)؟
  * و`main` = 72 لم يُعَد إنتاجه: القياس 67 و66.
  * والبسط تذبذبات الاختبار فقط (5)، بلا البنية التحتية؟
  * المقترح: بداية اليوم، والبسط 5، والبنية التحتية تُذكر وحدها.
* **LT-176-03 — `fail-fast` داخل الـ dispatch.**
  * `false`: تكتمل التكرارات الأخرى بعد فشل، فتُعرف بقية الـ dispatch؛
  * `true`: يُلغي الباقي فورًا.
  * في الحالين يتوقف التسلسل بعد الـ dispatch، ولا يُعاد. المقترح `false`.
* **LT-176-04 — البيئة لكل وظيفة اختبارية.** `ci.yml` يحلّ الاعتماديات بـ `composer update` في كل وظيفة، بلا lock ولا env.sh.
  * (أ) تُقرأ من سجل الوظيفة (سطور `Locking …`، وإصدار PHP من `setup-php`، وإصدار PHPUnit من مخرجه)، بلا تغيير في الوظائف؛
  * (ب) خطوة env.sh في وظائف `ci.yml`، وهذا يخالف «لا تغيير في الوظائف».
  * المقترح (أ)، بالسجلات المضغوطة في `data/`.
* **LT-176-05 — 400 وظيفة في run واحد.** الوثائق لا تنص على حدّ لمجموع الوظائف، و`repeats=2` (33 وظيفة) لا يختبره. فيتحقق منه أول dispatch معدود بـ V-COUNT = 1 + 25 × 16، وإن رفضه GitHub أو قطعه: توقف وإبلاغ.
  * أم smoke ثانٍ بـ `repeats=25` غير معدود (≈ 23.5 ساعة runner)؟
  * المقترح: الأول.
* **LT-176-06 — توثيق الـ workflow.** `CONTRIBUTING.md` هو الموضع الوحيد الذي يصف الـ CI (L142: Archive Replay وFinal Gate). المقترح: فقرة هناك، باسم الـ workflow ومُدخليه ومتى يُشغَّل قبل إصدار. أم docs أخرى؟
* **LT-176-07 — ما يُحفظ.**
  * سجلات الـ workflow runs تبقى على GitHub بمدتها الافتراضية.
  * في `data/`: `runs/<run_id>.jobs.json` لكل dispatch و`stability.jsonl` (≈ 400 سطر لكل dispatch)، والسجلات المضغوطة للوظائف الفاشلة فقط، والبيئة من سجلات الوظائف الاختبارية حسب LT-176-04.
  * مقبول؟

## 8. أحكام الـ Lead (PR #177، 2026-10-30)

**تحقّق الـ Lead بنفسه أولًا:**
* أعاد حساب Fisher ثنائي الطرف 0/N مقابل 5/243 مستقلًا: N = 200 → 0.067، وN = 245 → 0.030، وN = 250 → 0.0285، وN = 300 → 0.0175، مطابقًا §1.3 و§4.
* `main` = 72 في الـ issue **خطؤه**: شمل تشغيلات `main` الخمسة قبل rc.3، ومنذ 2026-10-07 العدد 67 كما قيس هنا.
* `b75e7d6` هو F127-04 (`a46701b`) لا CVH-2، والتصنيف من السجل قائم.
* أُلحق التصحيحان بالـ issue.

**الأحكام:**
* **LT-176-01 — (أ) N = 250** (10 dispatches × 25). الإحصاء مسجَّل مسبقًا بهذا الترتيب:
  * **أساسي:** حدّ Wilson 95 % الأعلى لمعدّل الفشل لكل تشغيل على رأس الإصدار. التنبؤ: 0/250 → **1.51 %**.
  * **ثانوي:** Fisher ثنائي الطرف مقابل التاريخ المصنَّف 5/243. التنبؤ: **p = 0.029**. يُقدَّم على أنه *متسق مع* التحسّن لا برهان عليه، لأن §5 يسجّل اختلاف البيئتين.
  * **أحادي الطرف لا يُستعمل.**
  * **الكلفة مقبولة:** ≈ 235 ساعة runner غير مفوترة، و≈ 12 ساعة جدار بلا push. البندان 5 و6 يُكتبان محليًا أثناءها.
* **LT-176-02:** نافذة بداية اليوم (314 / 243)، والبسط تذبذبات الاختبار الخمسة، والبنية التحتية (`08a6ce9`) في سطر وحدها. الـ REPORT يذكر النافذتين في جملة واحدة، لأنهما لا تغيّران أي نتيجة.
* **LT-176-03 — `fail-fast: false`.**
  * فشل داخل dispatch يترك التكرارات الأخرى تكتمل، فنعرف هل هو معزول.
  * و*تسلسل* الـ dispatches يتوقف بعد ذلك الـ dispatch، ويُبلَّغ الفشل بسجله واختباره، ولا يُعاد شيء بلا كلمة الـ Owner.
* **LT-176-04 — (أ)** من سجلات الوظائف، بلا تغيير في أي وظيفة من `ci.yml`.
  * **فحص مضاف V-DEPS:** مجموعة الحزم المحلولة (سطور `Locking …`) في الوظائف الاختبارية متطابقة عبر كل التكرارات وكل الـ dispatches.
  * أي انجراف أثناء القياس يُسجَّل مُربِكًا (confounder) في الـ REPORT، لا فشلًا.
* **LT-176-05:** أول dispatch معدود يتحقق من **V-COUNT = 1 + 25 × 16**، بلا smoke بـ 25 تكرارًا.
  * إن رفض GitHub الـ run أو قطعه: لا يُعدّ ذلك الـ dispatch، وأتوقف وأبلغ.
  * البديل حينها `repeats` عند الحدّ المرصود مع dispatches أكثر، ويُحكم فيه عندئذ.
* **LT-176-06 — `CONTRIBUTING.md`، فقرة واحدة** في قسم الـ CI (بعد L142):
  * اسم الـ workflow ومُدخلاه، وما يقيسه؛
  * وأنه يُشغَّل قبل الإصدار على الـ SHA المرشَّح، والنتيجة تُسجَّل تحت `investigations/stability-release/`.
  * لا docs أخرى: المستودع بلا ملف قائمة إصدار، والـ skill مؤشر فقط.
* **LT-176-07 — مقبول كما اقتُرح:**
  * `runs/<run_id>.jobs.json` لكل dispatch، و`stability.jsonl`؛
  * والسجلات المضغوطة للوظائف الفاشلة فقط، وسطور البيئة حسب LT-176-04؛
  * و`SHA256SUMS` يُحدَّث في كل commit.

**الترتيب بلا تغيير:**
1. commit §8.
2. S1: الملفان وفقرة CONTRIBUTING؛ actionlint نظيف، وCI 16/16، وV-SRC مسجَّل.
3. مراجعة الـ Lead، ثم كلمة الـ Owner، ثم الـ Lead يدمج.
4. S2: smoke `repeats=2` على SHA `main` الجديد بفحوص §3.4 والكلفة المقاسة، ثم كلمة الـ Owner.
5. S3: عشرة dispatches متتابعة، بلا push ولا CI آخر.
6. S4: REPORT.

## 9. مسار AGENTS.md (Adoption Standard §10.1)

Normal Engineering Task: قُرئ `docs/php-engineering-standards/STANDARDS_MANIFEST.md`. الـ Scope `/`، والـ activations `composer-package` 4.0.0 و`repository-governance` 3.0.0.

| Standard | الإصدار | لماذا |
| --- | --- | --- |
| `CI_WORKFLOW_STANDARD.md` | 5.0.0 | workflow دائم جديد، و`workflow_call` في `ci.yml` |
| `TESTING_STANDARD.md` | 2.0.0 | §4.7 Host CI Enforcement of Required Behavioral Evidence: بوابة CI نفسها هي المقاس |
| `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | 3.1.0 | توثيق الـ workflow (LT-176-06) وREPORT |
| `AI_COLLABORATION_WORKFLOW_AR.md` | 12.0.0 | |
| `GITHUB_PHASE_STACK_WORKFLOW_AR.md` | 4.0.0 | Draft، والدمج بيد الـ Lead، والـ dispatch بكلمة الـ Owner |

## 10. S1 — الفحوص المسجَّلة

* **V-SRC:** `git diff 62599c9..de55ae7d -- src tests` يعطي 0 بايت، أي **فارغ**.
* **actionlint 1.7.12** (الإصدار المثبَّت في `scripts/ci/install-actionlint.sh`): `bash scripts/ci/lint-workflows.sh` نظيف على كل الـ workflows، ومنها `stability-evidence.yml`.
* **فقرة CONTRIBUTING.md** وُضعت بعد قائمة الـ CI مباشرة (بعد L147)، لا داخلها عند L142، لئلا تنقسم القائمة.
* **CI العادي 16/16:** يُنشر على الـ PR بعد الـ push.

## 11. S2 — smoke `repeats=2` (مقاس، 2026-10-30)

### 11.1 التشغيل

* **الـ run:** [41050345964](https://github.com/Maatify/php-rate-limiter/actions/runs/41050345964). `workflow_dispatch` لـ `stability-evidence.yml` على فرع `main`، بـ `repeats=2` و`ref=5563e06b2f1950c4a04f624e58ff928ad8dac402`.
* **قبله:** اكتمل CI الدفع على `main@5563e06` (run 41048606580، success)، ولم يكن أي run آخر يعمل.
* **التوقيت:** 11:45:18Z إلى 12:00:18Z. المحاولة 1، و`success`.
* **الجمع:** `scripts/collect.sh 41050345964`، ثم `scripts/analyze.php --ref=… --repeats=2 --baseline=data/baseline-40975978888.jobs.json 41050345964` إلى `data/s2-smoke.analysis.json`.

### 11.2 الفحوص (§3.4، §8)

| الفحص | المرصود | يتحقق |
| --- | --- | --- |
| **V-REF** | `head_sha` = `5563e06b…` في الوظائف الثلاث والثلاثين؛ ووظيفة `plan` نجحت بمقارنتها `ref` بـ `$GITHUB_SHA` | نعم |
| **V-NAMES** | `Plan (repeats and V-REF)` مرة واحدة، والباقي `ci (1) / …` و`ci (2) / …`، ولكل تكرار أسماء الأساس الستة عشر بالضبط | نعم |
| **V-COUNT** | 33 = 1 + 2 × 16 | نعم |
| **V-PARALLEL، كما سُجِّل** («فترات الوظائف المتناظرة متداخلة») | 13 من 16 متداخلة. **غير متداخلة:** Workflow Lint، وComposer Security Audit، وFinal Gate. ولا وظيفة cancelled أو skipped | **لا. F-176-01** |
| **V-DEPS** | سطور `Locking …` (31 لكل وظيفة) وسطور PHP/PHPUnit متطابقة بين التكرارين في الوظائف الإحدى عشرة التي تحلّ الاعتماديات | نعم |
| **V-ENV** (مضاف، سلامة المدخلات) | كل ملفات البيئة الاثنين والعشرين فيها `Locking` و`Installed PHP x.y.z`، وفي وظائف PHPUnit سطر `PHPUnit x.y.z by` | نعم |
| **V-SRC** | `git diff 62599c9..5563e06 -- src tests` يعطي 0 بايت | نعم |
| **النتيجة** | التكرار 1: 16 success؛ التكرار 2: 16 success | — |

**البيئة المسجَّلة:**
* Test Suite 8.5: `Installed PHP 8.5.12`، و`PHPUnit 11.5.57`؛
* Lowest Supported Dependencies: `Installed PHP 8.4.27`، و`PHPUnit 11.5.50`.

### 11.3 F-176-01 — V-PARALLEL كما سُجِّل لا يتحقق، والسبب سعة الـ runners لا الـ concurrency (يحتاج حكمًا)

* **ما يحميه V-PARALLEL** (§2، §3.4): ألّا تتسلسل التكرارات أو تُلغى بمجموعة concurrency مشتركة. لا شيء من ذلك حدث:
  * **كل الوظائف غير الـ gate في التكرارين وُضعت في الطابور معًا:** آخر `created_at` لها بعد ثانية واحدة من انتهاء `plan` (`queued_after_plan_s = 1`)؛
  * ولا وظيفة cancelled أو skipped.
* **لماذا لم تتداخل الثلاث:**
  * **الذروة 20 وظيفة متزامنة** (`peak_concurrent_jobs`)، أي حدّ خطة Free لـ standard runners (§2). فالوظائف القصيرة انتظرت runner: `ci (1) / Workflow Lint` وُضعت عند +6 ث وبدأت عند +21 ث، و`ci (2) / Workflow Lint` انتهت عند +17 ث.
  * **Final Gate لكل تكرار** يُوضع حين تنتهي وظائف تكراره (`needs`): عند +765 ث و+893 ث، ويدوم 3 ث.
* **الاقتراح، والحكم للـ Lead:** يُستبدل V-PARALLEL بـ:
  * (أ) كل وظيفة غير Final Gate في كل تكرار وُضعت في الطابور خلال **60 ث** من انتهاء `plan`؛
  * (ب) ولا وظيفة cancelled أو skipped.

  الـ smoke يحققه: 1 ث، و0 cancelled. وأُبقي الفحص المسجَّل في `analyze.php` كما هو، وأُضيف الحقلان `queued_after_plan_s` و`peak_concurrent_jobs` للتقرير فقط.

### 11.4 الكلفة المقاسة

| | المرصود | الأساس (run 40975978888، `main@62599c9`) |
| --- | --- | --- |
| مجموع مدد الوظائف، التكرار 1 | **55.1 دقيقة** | 56.4 |
| مجموع مدد الوظائف، التكرار 2 | **52.3 دقيقة** | 56.4 |
| الجدار (أول بدء ← آخر انتهاء) | **14.9 دقيقة** | 13.7 (تكرار واحد) |
| الذروة | 20 وظيفة | — |

**إسقاط للمعدود (حساب على الأرقام أعلاه، غير مقاس):**
* تكرار ≈ 53.7 دقيقة (متوسط التكرارين)؛
* dispatch × 25 ≈ 22.4 ساعة runner، وجدار ≈ 25 × 53.7 / 20 ≈ 67 دقيقة عند الذروة 20؛
* العشرة ≈ 224 ساعة runner، و≈ 11.2 ساعة جدار متتابعة.
* أول dispatch معدود يتحقق من V-COUNT = 401 (LT-176-05).

### 11.5 تعديلات الأدوات بعد الـ smoke (قبل أي عدّ)

* **`collect.sh`:** سطر PHP من setup-php ملوَّن بـ ANSI، فلم يُلتقط في الجمع الأول. صارت الإزالة قبل المطابقة، وأُضيف `Runtime: PHP`، وأُعيد الجمع.
* **`analyze.php`:** أُضيف **V-ENV**، ورُئي يفشل على نسخة scratch حُذف منها سطر PHPUnit من ملف واحد: `incomplete: ["41050345964/2/Integration Test Suite"]`، وV-DEPS = false. وأُضيف الحقلان المذكوران في §11.3.

### 11.6 حكم الـ Lead على F-176-01، وإعادة التحليل (2026-10-30)

* **الحكم** (PR #178، تعليق الـ Lead 15:06Z):
  * تحقّق الـ Lead من API الـ run مستقلًا، فـ §11 دقيق.
  * الفحص المسجَّل كان الأداة الخطأ: التداخل لكل وظيفة يقيس توفّر الـ runners لا التسلسل بالـ concurrency. فالـ finding ضد معياره، لا ضد الـ workflow.
* **V-PARALLEL الجديد،** مسجَّل مسبقًا لكل dispatch معدود، وتتحقق الشروط الأربعة كلها:
  * **(أ)** كل وظيفة غير Final Gate في الطابور خلال 60 ث من `completed_at` لـ `plan`؛
  * **(ب)** لا cancelled ولا skipped؛
  * **(ج)** لكل تكرار وظيفة واحدة على الأقل تتداخل فترتها مع وظيفة من تكرار آخر؛
  * **(د)** جدار الـ dispatch (أول بدء ← آخر انتهاء) < `repeats × 13.7 / 2` دقيقة.
  * `overlap_per_job` و`peak_concurrent_jobs` قياسان في التقرير لا بوابة.
* **قُبل أيضًا:** V-ENV، وإصلاحات الأدوات.
* **أُضيف V-TOOLS:** `collect.sh` و`analyze.php` يُجمَّدان عند الـ SHA المدموج قبل أول dispatch معدود. أي تغيير بعده commit وحده، وإعادة التحليل على **كل** البيانات، وتسجيل قبل/بعد في الـ REPORT.
* **كلمة الـ Owner** (منقولة، 15:08Z): «نعم، الاثنان». يُدمج #178 بعد مراجعة الـ Lead بلا finding وCI 16/16، ويُؤذن بالعشرة.

**إعادة التحليل على الـ smoke** (`analyze.php` بالشروط كما حُكمت حرفيًا، `data/s2-smoke.analysis.json`):

| الشرط | المرصود | يتحقق |
| --- | --- | --- |
| (أ) | آخر وظيفة غير gate في الطابور بعد 1 ث من `plan` | نعم |
| (ب) | 0 cancelled، و0 skipped | نعم |
| (ج) | التكرار 1 يتداخل مع 2، والعكس | نعم |
| (د) | الجدار **14.92 د**، والحدّ `2 × 13.7 / 2` = **13.7 د** | **لا** |

**`v_parallel: false`.** والقياسان: `peak_concurrent_jobs` = 20، و`non_overlapping_job_names` = 3.

**الكاشف يعمل:** نسخة scratch أُزيح فيها التكرار 2 كله إلى ما بعد انتهاء التكرار 1 (+895 ث)، أي تسلسل مصطنع، أعطت (أ) false و(ج) false و(د) false، بجدار 27.72 د.

**تصحيح (د) بحكم الـ Lead على F-176-02** (PR #178): الخطأ في صيغة (د) من الـ Lead، والخيار 1 معتمَد.
* **(د) الجديد:** جدار الـ dispatch < `13.7 × (1 + (repeats − 1) / 2)` دقيقة، أي منتصف المسافة بين التوازي الكامل (13.7) والتسلسل الصارم (`repeats × 13.7`). الحدّ 20.55 د عند 2، و178.1 د عند 25.
* (أ)–(ج) بلا تغيير، والنسخة المتسلسلة تبقى دليل كشف.

**إعادة التحليل بعد التصحيح** (`data/s2-smoke.analysis.json`):

| الشرط | المرصود | يتحقق |
| --- | --- | --- |
| (أ) | 1 ث | نعم |
| (ب) | 0 / 0 | نعم |
| (ج) | 1↔2 | نعم |
| (د) | **14.92 د < 20.55 د** | نعم |

* **`v_parallel: true`.** وV-REF وV-NAMES وV-COUNT وV-DEPS وV-ENV كلها `true`.
* **الكاشف بالحدّ الجديد:** النسخة المتسلسلة المصطنعة (+895 ث) تعطي (أ) false و(ج) false و(د) false، لأن 27.72 د ≥ 20.55 د.

### 11.7 F-176-02 — الشرط (د) كما حُكم أولًا لم يتحقق على الـ smoke (محكوم: الخيار 1، انظر §11.6)

* **التكليف** يطلب أن تعطي إعادة التحليل على الـ smoke `v_parallel: true`. **بالتعريف المحكوم لا تعطيها:**
  * عند `repeats = 2` يساوي الحدّ `2 × 13.7 / 2` جدار تكرار واحد في الأساس (13.7 د)؛
  * والـ smoke 14.92 د، وبلا `plan` وFinal Gate 14.73 د، ومن `created_at` إلى `updated_at` للـ run 15.00 د.
  * فـ (د) تفشل عند تكرارين متى كان جدار الـ dispatch أبطأ من جدار تكرار منفرد بأي قدر، ولو كان التوازي تامًّا. التسلسل التام هنا 27.4 د.
* **عند `repeats = 25`:** الحدّ 171 د، والتنبؤ ≈ 67 د، فلا أثر للمسألة على العشرة المعدودة.
* **خيارات، بلا اختيار:**
  * (1) الحدّ `13.7 × (1 + (repeats − 1) / 2)`، أي جدار تكرار واحد + نصف التسلسل الإضافي: 20.55 د عند 2، و178.1 د عند 25؛
  * (2) (د) كما هي للعدّ (`repeats = 25`)، ويُسجَّل الـ smoke بـ (أ)–(ج)، و(د) «غير منطبق عند repeats = 2» صراحة؛
  * (3) غير ذلك بحكمك.
* **`analyze.php` في هذا الـ commit يطبّق (د) حرفيًا.** لم أغيّر التعريف ليمرّ الـ smoke.

## 12. S3 — الـ dispatches المعدودة (10 × 25)

* **الرأس:** `main@2bac701b1ab1034846d08592439e9f5ce933c4c9`، بعد دمج #178.
* **V-SRC:** `git diff 62599c9..2bac701 -- src tests` يعطي 0 بايت.
* **V-TOOLS:** الأداتان مجمَّدتان كما دُمجتا في `2bac701`، وأي تغيير بعده commit وحده مع إعادة التحليل على كل البيانات:
  * `scripts/collect.sh` sha256 `451a8866…c96bac9b973f`؛
  * `scripts/analyze.php` sha256 `d55e6ae1…e1019541691`.
* **لكل dispatch:**
  1. لا يبدأ وأي run آخر يعمل؛
  2. `gh workflow run stability-evidence.yml --ref main -f repeats=25 -f ref=2bac701b…`؛
  3. بعد الانتهاء: `collect.sh <run>`، ثم `analyze.php --ref=2bac701b… --repeats=25 --baseline=data/baseline-40975978888.jobs.json <كل الـ runs حتى الآن>` إلى `data/s3.analysis.json`؛
  4. commit محلي.
* **push** بعد الخامس وبعد العاشر فقط، وانتظار CI الـ PR قبل الـ dispatch التالي.
* **يتوقف التسلسل** عند:
  * run غير `success`، أو أي وظيفة غير `success`؛
  * أو V-COUNT ≠ 401، أو V-REF أو V-NAMES أو V-PARALLEL أو V-ENV = false؛
  * أو رفض أو قطع من GitHub.
* **V-DEPS** إن انجرف يُسجَّل مُربِكًا ولا يوقف (LT-176-04).

| # | الـ run | الوظائف | النتيجة (التكرارات) | V-REF | V-NAMES | V-COUNT | V-PARALLEL | V-ENV | V-DEPS (تراكمي) | الجدار (د) | دقائق runner |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | [41084219452](https://github.com/Maatify/php-rate-limiter/actions/runs/41084219452) | 401 | 400 success، 0 غير success | نعم | نعم | نعم (401) | نعم | نعم | نعم | 74.9 | 1366.4 |
| 2 | [41097286365](https://github.com/Maatify/php-rate-limiter/actions/runs/41097286365) | 401 | 400 success، 0 غير success | نعم | نعم | نعم (401) | نعم | نعم | نعم | 85.2 | 1496.0 |
| 3 | [41110748185](https://github.com/Maatify/php-rate-limiter/actions/runs/41110748185) | 401 | 400 success، 0 غير success | نعم | نعم | نعم (401) | نعم | نعم | نعم | 77.6 | 1376.2 |
