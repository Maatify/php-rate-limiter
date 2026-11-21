#!/usr/bin/env bash
# compare-selftest.sh <run-dir-a> <run-dir-b> [<before-dir> <after-dir>]
# a, b: two evidence directories of the SAME contract and width (two runs). The comparison must pass on them, and must FAIL when a stored
# value, a success result, a count, a denial message, a constant that merely looks like an epoch, or a time offset is changed in one of them.
# before, after (optional): the two arms of one width. The per-arm message check must FAIL when a reached denial of the `after` arm keeps the
# old range text, or of the `before` arm carries the NOPERM text, even though nothing else differs.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
a="$1"; b="$2"; before="${3:-}"; after="${4:-}"; work="$(mktemp -d)"; fail=0
python3 "$here/compare.py" "$a" "$b" --same-contract >/dev/null && echo "ok   two runs of one contract are identical" || { echo "FAIL baseline"; fail=1; }
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
  if python3 "$here/compare.py" "$a" "$work/m" --same-contract >/dev/null; then echo "FAIL $1 was not detected"; fail=1; else echo "ok   $1 is detected"; fi
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
# a constant that looks like each run's own epoch: equal "near time" values must not be rewritten into offsets and so be hidden
rm -rf "$work/ca" "$work/cb"; mkdir "$work/ca" "$work/cb"; cp "$a/evidence.jsonl" "$work/ca/"; cp "$b/evidence.jsonl" "$work/cb/"
if python3 - "$work/ca/evidence.jsonl" "$work/cb/evidence.jsonl" <<'PY'
import json, re, sys
pa, pb = sys.argv[1], sys.argv[2]
ra = [json.loads(l) for l in open(pa)]
rb = [json.loads(l) for l in open(pb)]
done = 0
for x, y in zip(ra, rb):
    if x.get("kind") == "control" and "[value] =>" in (x.get("result") or "") and x["now"] != y["now"] and not done:
        x["result"] = re.sub(r"\[value\] => (\d+)", "[value] => %d" % x["now"], x["result"], count=1)
        y["result"] = re.sub(r"\[value\] => (\d+)", "[value] => %d" % y["now"], y["result"], count=1)
        done = 1
if not done:
    sys.exit(3)
open(pa, "w").write("".join(json.dumps(r) + "\n" for r in ra))
open(pb, "w").write("".join(json.dumps(r) + "\n" for r in rb))
PY
then
  if python3 "$here/compare.py" "$work/ca" "$work/cb" --same-contract >/dev/null; then echo "FAIL a constant that looks like each run's epoch was normalised away"; fail=1; else echo "ok   a constant that looks like each run's epoch is compared exactly"; fi
else echo "skip no pair of control rows with different clocks to mutate"; fi
if [[ -n "$before" && -n "$after" ]]; then
  python3 "$here/compare.py" "$before" "$after" >/dev/null && echo "ok   the two arms compare cleanly" || { echo "FAIL arms baseline"; fail=1; }
  arm() { # name which python-code
    rm -rf "$work/arm"; mkdir -p "$work/arm/before" "$work/arm/after"
    cp "$before/evidence.jsonl" "$work/arm/before/"; cp "$after/evidence.jsonl" "$work/arm/after/"
    python3 - "$work/arm/$2/evidence.jsonl" "$3" <<'PY'
import json, sys
path, new = sys.argv[1], sys.argv[2]
rows = [json.loads(l) for l in open(path)]
n = 0
for r in rows:
    if r.get("expected") == "denied":
        r["message"] = new if new != "NOPERM" else "NOPERM this user has no permissions to run the '%s' command" % r["denied"]
        n += 1
assert n
open(path, "w").write("".join(json.dumps(r) + "\n" for r in rows))
PY
    if python3 "$here/compare.py" "$work/arm/before" "$work/arm/after" >/dev/null; then echo "FAIL $1 was not detected"; fail=1; else echo "ok   $1 is detected"; fi
  }
  arm "the after arm keeping the old range text on every reached denial" after "expiry not representable"
  arm "the before arm carrying the NOPERM text on every reached denial" before NOPERM
fi
rm -rf "$work"
exit $fail
