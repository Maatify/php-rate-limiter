# F269-07 — قبل/بعد (ملف خارجي موجود خلف رابط رمزي)

الإعداد: مجلد خارج الـclone فيه أربعة ملفات (`evidence.jsonl` 24 بايت، `phpunit.log` 21، `run-meta.txt` 22، `bench.log` 19) بمحتوى `PRECIOUS-<name>`، ورابط رمزي `out/link` إليه، ثم `RL261_OUT=out/link run.sh 64 --version` و`bench.sh 64 3`.

| | `run.sh` | `bench.sh` | الملفات الخارجية |
|---|---|---|---|
| قبل (على الرأس `b712f45a`) | خروج **0** | خروج **0** | كلها استُبدل محتواها: `evidence.jsonl` 0 بايت، `phpunit.log` 187، `run-meta.txt` 282، `bench.log` 145 |
| بعد | خروج **74** (قبل أي كتابة) | خروج **74** | لم يُمَس أي ملف ولم يُنشأ شيء (مجموع تحقق ثابت في `selftest.sh`) |

السجلان الكاملان: `runner-selftest-before-f269-07.log` (المشغّل القديم مع الضوابط الجديدة: عشرة فشل) و`runner-selftest.log` (بعد الإصلاح: كلها ok).
