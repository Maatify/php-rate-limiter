#!/usr/bin/env python3
"""Renders raw/evidence-64.jsonl (and checks it against raw/evidence-32.jsonl) into raw/evidence-table.md. Study tooling only."""
import json, re, sys, os
here = os.path.dirname(os.path.abspath(__file__))
raw = os.path.join(here, 'raw')

def load(name):
    return {(r['case'], r['shape']): r for r in (json.loads(l) for l in open(os.path.join(raw, name)) if l.strip())}

a, b = load('evidence-64.jsonl'), load('evidence-32.jsonl')

def changed(r):
    c = r['changed'] if isinstance(r['changed'], dict) else {}
    return sorted(k.split(' ')[0] + ': ' + v for k, v in c.items())

def short(r):
    """What reached the executor on the last command and what the caller got, compressed."""
    failing = [w for w in r['wire'] if not w.endswith('-> ok')]
    last = failing[0] if failing else (r['wire'][-1] if r['wire'] else '')
    last = re.sub(r' \[keys:.*$', '', last)
    last = re.sub(r'script: [0-9a-f]{40}, on @user_script:\d+\.', 'script: <sha>, on @user_script:<line>.', last)
    last = last.replace('Maatify\\RateLimiter\\Exception\\', '')
    res = r['result'].replace('Maatify\\RateLimiter\\Exception\\', '')
    res = re.sub(r'script: [0-9a-f]{40}, on @user_script:\d+\.', 'script: <sha>, on @user_script:<line>.', res)
    return last[:150], res[:150]

def norm(t):
    return 'unclassified RedisException' if re.match(r'thrown RedisException: (protocol|socket) error', t) else t[:40]

def same(case):
    x, y = a[(case, 'resp')], a[(case, 'phpredis')]
    keys = ('sentCommit', 'evaluations', 'stepsReached')
    return all(x[k] == y[k] for k in keys) and changed(x) == changed(y)

# case -> (required by the contract / classification, expected from the source before the first run)
META = {
 'control': ('الحفظ كاملًا بتقييم واحد وcommit واحد', 'نجاح؛ تقييم واحد؛ commit واحد'),
 'deny -eval': ('رفض صريح قبل أي تقييم ولا تغيير', 'NOPERM على EVAL نفسه قبل أي script'),
 'deny -time': ('رفض قبل أي كتابة، لا إعادة تقييم', 'خطأ داخل أول script قبل commit'),
 'deny -exists': ('رفض قبل أي كتابة، لا إعادة تقييم', 'خطأ داخل أول script قبل commit'),
 'deny -type': ('رفض قبل أي كتابة، لا إعادة تقييم', 'خطأ داخل أول script قبل commit'),
 'deny -pexpiretime': ('رفض قبل أي كتابة، لا إعادة تقييم', 'خطأ داخل أول script قبل commit'),
 'deny -hgetall': ('رفض قبل أي كتابة، لا إعادة تقييم', 'خطأ عند أول مفتاح hash موجود'),
 'deny -get': ('رفض قبل أي كتابة، لا إعادة تقييم', 'خطأ عند أول مفتاح string موجود'),
 'deny -smembers': ('رفض قبل أي كتابة، لا إعادة تقييم', 'خطأ عند أول مفتاح set موجود'),
 'deny -zrange': ('رفض قبل أي كتابة، لا إعادة تقييم', 'خطأ عند أول مفتاح zset موجود'),
 'deny -del': ('حفظ الكل أو لا شيء', 'أول كتابة في commit هي DEL فلا تغيير'),
 'deny -hset': ('حفظ الكل أو لا شيء', 'تطبيق جزئي: مفتاح يُحذف ولا يُكتب'),
 'deny -set': ('حفظ الكل أو لا شيء', 'تطبيق جزئي: مفتاح يُحذف ولا يُكتب'),
 'deny -sadd': ('حفظ الكل أو لا شيء', 'تطبيق جزئي: مفتاح يُحذف ولا يُكتب'),
 'deny -zadd': ('حفظ الكل أو لا شيء', 'تطبيق جزئي: مفتاح يُحذف ولا يُكتب'),
 'deny -pexpireat': ('حفظ الكل أو لا شيء', 'تطبيق جزئي: مفتاح يُعاد كتابته بلا انتهاء'),
 'keys narrow': ('رفض قبل أي كتابة', 'NOPERM على المفاتيح عند EVAL (قبل script)'),
 'keys read-only': ('رفض قبل أي كتابة', 'NOPERM على المفاتيح عند EVAL'),
 'keys write-only': ('رفض قبل أي كتابة', 'NOPERM على المفاتيح عند EVAL'),
 'conflict': ('إعادة تقييم عند تعارض حقيقي ثم حفظ (DEC-037)', 'تقييمان؛ حفظ في الثاني'),
 'conflict + deny -smembers': ('لا إعادة تقييم بسبب الرفض فوق حالة ثابتة', 'إعادة واحدة لأن الحالة تحركت ثم يعود الخطأ ويُرمى'),
 'fresh keyspace + deny reads': ('—', 'لم يُتوقع قبل التشغيل (اكتُشف أثناء بناء الحالات)'),
 'revoke -hgetall before verify': ('رفض صلاحية قراءة أثناء verify نفسه: لا كتابة ولا تغيير', 'خطأ verify يحلّ محل خطأ الخطوة؛ لا تغيير'),
 'revoke -hgetall before commit': ('رفض قراءة أثناء مقارنة الحفظ: لا كتابة ولا تغيير', 'خطأ داخل commit قبل أول كتابة'),
 'plan without expiry': ('حفظ مفتاح بلا انتهاء', 'نجاح؛ لا PEXPIREAT'),
 'plan without expiry + deny -pexpireat': ('فرع لا يحتاج إذن انتهاء ينجح بلا الإذن', 'نجاح'),
 'variant plan without expiry + deny -pexpireat': ('الـpreflight لا يطلب إذن انتهاء حيث لا انتهاء', 'نجاح'),
 'revoke -del before commit': ('حفظ الكل أو لا شيء', 'مثل -del: لا تغيير'),
 'connection killed before commit': ('BackendFailureException ولا تغيير', 'استثناء نقل؛ لا تغيير'),
 'reply lost after the save': ('نتيجة مجهولة: BackendFailureException مع حفظ كامل لا جزئي', 'استثناء نقل؛ الحالة محفوظة كاملة'),
 'variant control': ('نفس نتيجة الضابط', 'نجاح مطابق للضابط'),
 'variant deny -del': ('رفض بلا تغيير', 'رفض من الـpreflight بلا تغيير'),
 'variant deny -hset': ('رفض بلا تغيير', 'رفض من الـpreflight بلا تغيير'),
 'variant deny -set': ('رفض بلا تغيير', 'رفض من الـpreflight بلا تغيير'),
 'variant deny -sadd': ('رفض بلا تغيير', 'رفض من الـpreflight بلا تغيير'),
 'variant deny -zadd': ('رفض بلا تغيير', 'رفض من الـpreflight بلا تغيير'),
 'variant deny -pexpireat': ('رفض بلا تغيير', 'رفض من الـpreflight بلا تغيير'),
 'variant revoke -del before commit': ('رفض بلا تغيير', 'رفض من الـpreflight بلا تغيير'),
 'engine control': ('نجاح عبر المحرك', 'نجاح بحساب مفاتيحه prefix فقط'),
 'engine control (prefix pattern only)': ('—', 'نجاح (لم يُتوقع الرفض)'),
 'engine deny -pexpiretime': ('—', 'رفض كما في مسار المخزن'),
 'engine deny -pexpireat': ('حفظ الكل أو لا شيء', 'تطبيق جزئي كما في مسار المخزن'),
 'engine connection killed before commit': ('FAIL_CLOSED عند انقطاع النقل ولا تغيير', 'مثل مسار المخزن'),
 'engine reply lost after the save': ('FAIL_CLOSED، الحفظ مجهول النتيجة وكامل', 'مثل مسار المخزن'),
}

# Cases whose observation differed from what the source reading led to expect before the first run.
REFUTED = {
    ('fresh keyspace + deny reads', None): 'لم يُتوقع: طلب على مفاتيح جديدة لا يحتاج صلاحيات قراءة المفتاح',
    ('engine control (prefix pattern only)', None): 'مرفوض: مفاتيح KEYS فارغة لا يطابقها نمط prefix',
    ('engine control', None): 'احتاج قاعدة المفتاح الفارغ `~` إضافة إلى النمط',
    ('engine deny -pexpiretime', None): 'نجح: القراءة لا تحدث لمفاتيح غير موجودة',
    ('connection killed before commit', 'phpredis'): 'نجح: مكتبة phpredis أعادت الاتصال وأعادت الإرسال',
    ('reply lost after the save', 'phpredis'): 'استثناء phpredis غير مصنّف (بروتوكول/socket) لا BackendFailureException: لا دليل إيجابي على النقل',
    ('engine reply lost after the save', 'phpredis'): 'استثناء phpredis خام يصل المتصل من limit()؛ الحفظ كامل',
    ('engine connection killed before commit', 'phpredis'): 'نجح: مكتبة phpredis أعادت الاتصال وأعادت الإرسال',
}

out = []
out.append('# جدول الأدلة (مولَّد من `evidence-64.jsonl`؛ `evidence-32.jsonl` تُقارَن بها في آخر الملف)\n')
out.append('الأعمدة: المطلوب = ما يقتضيه العقد/التصنيف؛ المتوقع = ما استُنتج من المصدر قبل أول رصد؛ المرصود = ما قيس. '
           '"وصل للحفظ" = أرسل الـexecutor commit؛ "تقييمات" = عدد استدعاءات `$evaluate` (للمخزن)؛ "executor" = آخر رد وصل للمخزن من الـexecutor؛ '
           '"المتصل" = ما وصل لمن استدعى؛ "تغيّر" = المفاتيح التي اختلف فيها DUMP أو PEXPIRETIME قبل/بعد (من خارج Lua)؛ '
           'مفتاح التحكم (خارج حالة الطلب، داخل نطاق الحساب) لم يتغير في كل الحالات.\n')
out.append('| الحالة | الغلاف | المطلوب | المتوقع | وصل للحفظ | تقييمات | executor (أول رد غير ناجح) | المتصل | تغيّر | خصم fallback | طابق المتوقع؟ |')
out.append('|---|---|---|---|---|---|---|---|---|---|---|')
for case in sorted({c for c, _ in a}, key=lambda c: list(META).index(c) if c in META else 999):
    shapes = ['resp'] if same(case) else ['resp', 'phpredis']
    for s in shapes:
        r = a[(case, s)]
        req, exp = META.get(case, ('—', '—'))
        ex, res = short(r)
        ev = '—' if case.startswith('engine') else '%d (خطوات %d)' % (r['evaluations'], r['stepsReached'])
        label = s if len(shapes) > 1 else 'resp = phpredis'
        note = REFUTED.get((case, s)) or REFUTED.get((case, None))
        verdict = ('✘ ' + note) if note else '✔'
        out.append('| %s | %s | %s | %s | %s | %s | `%s` | `%s` | %s | %s | %s |' % (case, label, req, exp, 'نعم' if r['sentCommit'] else 'لا', ev, ex.replace('|', '/'), res.replace('|', '/'), '؛ '.join(changed(r)) or 'لا شيء', 'نعم' if r.get('fallbackCharged') else 'لا', verdict))
out.append('')
diff = [k for k in a if (a[k]['sentCommit'], a[k]['evaluations'], a[k]['stepsReached'], changed(a[k]), norm(a[k]['result']), a[k]['fallbackCharged']) != (b[k]['sentCommit'], b[k]['evaluations'], b[k]['stepsReached'], changed(b[k]), norm(b[k]['result']), b[k]['fallbackCharged'])]
out.append('مقارنة PHP_INT_SIZE 8 مقابل 4: %d حالة، الفروق: %s' % (len(a), diff or 'لا فرق'))
target = os.path.join(raw, 'evidence-table.md')
if os.path.islink(target) or os.path.islink(raw):
    sys.exit('refusing to write through a symbolic link: ' + target)
open(target, 'w').write('\n'.join(out) + '\n')
print('rows', len(a), 'diff', diff)
