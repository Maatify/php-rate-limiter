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
