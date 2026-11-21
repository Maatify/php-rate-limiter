#!/usr/bin/env bash
# compare-selftest.sh <run-dir-a> <run-dir-b>: two evidence directories of the SAME contract and width (two runs). The comparison must pass
# on them, and must FAIL when a stored value, a success result, a count or a denial message is changed in one of them.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
a="$1"; b="$2"; work="$(mktemp -d)"; fail=0
python3 "$here/compare.py" "$a" "$b" >/dev/null && echo "ok   two runs of one contract are identical" || { echo "FAIL baseline"; fail=1; }
mutate() { # name python-expression-on-rows
  rm -rf "$work/m"; mkdir "$work/m"; cp "$b/evidence.jsonl" "$work/m/evidence.jsonl"
  python3 - "$work/m/evidence.jsonl" "$2" <<'PY'
import json, sys
path, code = sys.argv[1], sys.argv[2]
rows = [json.loads(l) for l in open(path)]
ns = {"rows": rows}
exec(code, ns)
assert ns["changed"], "the mutation matched no row"
open(path, "w").write("".join(json.dumps(r) + "\n" for r in rows))
PY
  if python3 "$here/compare.py" "$a" "$work/m" >/dev/null; then echo "FAIL $1 was not detected"; fail=1; else echo "ok   $1 is detected"; fi
}
mutate "a changed stored value" 'changed = 0
for r in rows:
    if r.get("stored_value") == "1" and not changed:
        r["stored_value"] = "2"; changed = 1'
mutate "a changed success result" 'changed = 0
for r in rows:
    if r.get("kind") == "control" and "[value] =>" in (r.get("result") or "") and not changed:
        import re
        r["result"] = re.sub(r"\[value\] => (\d+)", lambda m: "[value] => %d" % (int(m.group(1)) + 1), r["result"], count=1); changed = 1'
mutate "a changed stored updatedAt offset" 'changed = 0
for r in rows:
    if r.get("stored_updated_at") and not changed:
        r["stored_updated_at"] = str(int(r["stored_updated_at"]) + 5); changed = 1'
mutate "a changed denial message" 'changed = 0
for r in rows:
    if r.get("expected") == "denied" and not changed:
        r["message"] = r["message"] + " x"; changed = 1'
mutate "a changed Redis counter" 'changed = 0
for r in rows:
    if r.get("expected") == "denied" and r.get("stats") and not changed:
        k = next(iter(r["stats"])); r["stats"][k] = r["stats"][k].replace("rejected_calls=1", "rejected_calls=0"); changed = 1'
mutate "a changed keyspace diff of a reached denial" 'changed = 0
for r in rows:
    if r.get("expected") == "denied" and not changed:
        r["diff"] = ["a leaked change"]; changed = 1'
rm -rf "$work"
exit $fail
