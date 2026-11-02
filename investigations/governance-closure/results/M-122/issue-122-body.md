## مظلة مقارنة التصاميم المعمارية لـ Shared NAT — ضمن #110

**النوع:** سجل تصاميم ومقارنة فقط. لا Runtime ولا `src/` ولا `tests/` ولا Public API ولا Store Contract ولا DEC.

### البنية

كل تصميم يُحفظ **ملفًا** في المستودع، منقولًا من مصدره كما ورد ومرقّمًا بترتيب وصوله. الملفات هي المرجع الوحيد لنصوص التصاميم، وهذه الـIssue تحمل الفهرس والحالة فقط.

`investigations/nat-architecture-proposals-110/`
- `README.md`: الفهرس والبصمة (SHA-256) لكل تصميم.
- `proposals/NN-<name>.md`: ملف لكل تصميم، حرفيًا كما ورد.
- `COMPARISON.md`: المقارنة الورقية. **لا يُنشأ ملف قبل أن يكون له محتوى.**

### الفهرس

| # | المصدر | الحالة | الملف |
|---|---|---|---|
| 1 | **KIMI 3 — Anchored Fair Limiter (AFL)** | UNREVIEWED / VERBATIM | [`proposals/01-kimi-3.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/01-kimi-3.md) — 289 سطرًا، 20,420 حرفًا، SHA-256 `e08f1342243b7bbaa1af6efc0fc71e9aba0c3333ee4cbcb2c660e63f9a10220d` |
| 2 | **Gemini Argon — SQAEE** | UNREVIEWED / VERBATIM | [`proposals/02-gemini-argon.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/02-gemini-argon.md) — 487 سطرًا، 39,806 حرفًا، SHA-256 `361a773618fe10e427376cdace96bae788417c6d6049586fda360e9289dd6944` |
| 3 | **Gemini 3.1 Pro — Contextual Risk Enclaves & Challenge-Response Routing** | UNREVIEWED / VERBATIM | [`proposals/03-gemini-3-1-pro.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/03-gemini-3-1-pro.md) — 213 سطرًا، 9,811 حرفًا، SHA-256 `77a52e28c4b4208500bd8afbd3f1cc5686519fc1f9f1883ff4f935ff3c9809f6` |
| 4 | **DeepSeek — Hierarchical Fair-Share Admission Control** | UNREVIEWED / VERBATIM | [`proposals/04-deepseek.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/04-deepseek.md) — 371 سطرًا، 16,535 حرفًا، SHA-256 `55c90cb72405f6b8d6bb04f9fa841aa6a0895359bd90b035f914c486a73f87fb` |
| 5 | **Grok — مساران، ميزانية غير قابلة للمضاعفة، عقوبة بأضيق نطاق** | UNREVIEWED / VERBATIM | [`proposals/05-grok.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/05-grok.md) — 246 سطرًا، 24,054 حرفًا، SHA-256 `1b5fb2157b418a95072c8a2e5f2253f1b83a9a718674a71f3b7cc24911829f61` |
| 6 | **GPT-6.1 Sol — قبول محدود مع إثبات محدد الهدف وحصص محفوظة** | UNREVIEWED / VERBATIM | [`proposals/06-gpt-6-1-sol.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/06-gpt-6-1-sol.md) — 922 سطرًا، 35,974 حرفًا، SHA-256 `08b5680b457fc088b21a98cfdac5a28b490ef8993896f2e55d936185f0a5c96d` |
| 7 | **GPT-6 Astra — قبول مبني على إثبات محدود للحساب والعملية (U/P)** | UNREVIEWED / VERBATIM | [`proposals/07-gpt-6-astra.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/07-gpt-6-astra.md) — 621 سطرًا، 33,669 حرفًا، SHA-256 `6699f88ba1c5546426a938ca480b998d77c407ae2284a4a6428ff289d32a8b02` |
| 8 | **GPT-6 Luna — Admission ذري قبل الـVerifier مع تصعيد إلى إثبات إضافي** | UNREVIEWED / VERBATIM | [`proposals/08-gpt-6-luna.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/08-gpt-6-luna.md) — 264 سطرًا، 20,895 حرفًا، SHA-256 `d442a23e05ff3090a6bcec6f27989aaacb7e7f67614f67ac3b7c3df6f9b06bfb` |
| 9 | **GPT-6.5 Terra — Hierarchical Costed Admission Control** | UNREVIEWED / VERBATIM | [`proposals/09-gpt-6-5-terra.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/09-gpt-6-5-terra.md) — 220 سطرًا، 10,328 حرفًا، SHA-256 `9d626820e7fa476269e81073006e98f363963e4fb7989aba9b22f8c0b6f94db8` |
| 10 | **Fable 5.1 — الهوية المسكوكة والقبول على مستوى الزوج** | UNREVIEWED / VERBATIM | [`proposals/10-fable-5-1.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/10-fable-5-1.md) — 404 سطرًا، 36,600 حرفًا، SHA-256 `67cd22828e0dae192e1222337d7d37323a015695d63bb489b153af15a0996bfb` |
| 11 | **Opus 5.5 — Pairwise Admission with Novelty Budgets** | UNREVIEWED / VERBATIM | [`proposals/11-opus-5-5.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/proposals/11-opus-5-5.md) — 398 سطرًا، 25,890 حرفًا، SHA-256 `f69479abd38a6c597096e13d9cd9d84e029cab338a58f3486987769f05c0f605` |

الملفات مدمجة في main عبر [PR #123](https://github.com/Maatify/php-rate-limiter/pull/123). **السجل مكتمل: أحد عشر تصميمًا** (قرار المالك، 2026-10-16).

### الحالة

- **التسجيل مكتمل (11 تصميمًا).**
- **المقارنة الورقية: مسودة للمراجعة الهندسية** — [`COMPARISON.md`](https://github.com/Maatify/php-rate-limiter/blob/main/investigations/nat-architecture-proposals-110/COMPARISON.md)، مثبتة على مصدر `54e119e` بعد مصالحة المصادر مع الأصول. بلا ترتيب ولا فائز ولا اختيار مرشحين ولا دمج تصاميم. لا يُعتمد أي استنتاج قبل المراجعة.


