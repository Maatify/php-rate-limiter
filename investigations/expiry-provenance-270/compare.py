#!/usr/bin/env python3
"""Compare the `before` and `after` evidence of one width row by row (#270, DEC-043).

usage: compare.py <before-dir> <after-dir> [--same-contract]   (each holds evidence.jsonl and manifest.json)

`--same-contract` is for the self-test only: the two directories are two runs of ONE contract, so the per-arm message rule is replaced by
plain equality of the message.

Rows are matched by (kind, label|scenario, denied, shape, state, occurrence). Every field must be IDENTICAL between the two runs except:

* `contract` (it names the run) and `now` (the run's own caller epoch, used only to factor the clock out of the named time fields below);
* `message` of a reached ACL denial, which is checked PER ARM, not by difference: the `before` run must carry exactly
  `expiry not representable` and the `after` run exactly `NOPERM this user has no permissions to run the '<denied command>' command`.
  A reached row whose arm carries the other text fails even when the two runs happen to agree;
* the KNOWN time fields, and nothing else, which differ between two runs by construction:
  - `stored_updated_at`, and inside `result` only the values of the keys `updatedAt`, `expiresAt`, `epochStart` and `validUntil`
    (`[key] => N` of the printed result): each is rewritten as its offset from the run's own `now` (`T+offset`) before comparing, so
    the offset must be equal while stored values, counts, levels and generations (a constant that merely LOOKS like an epoch included)
    are compared exactly;
  - `expires_ms`, `redis_time_before_ms`, `redis_time_after_ms`: absolute Redis clock readings; the test itself asserts the bracket
    (TIME before + 600 s <= expiry <= TIME after + 600 s) in each run;
  - in `diff` of a call that did NOT reach the denial (a control or an unreached branch), an expiry that is only re-applied at Redis's own
    clock (`<key> expiry`, or the `+expiry` suffix) differs by milliseconds: it is dropped from both sides. A reached denial's `diff` is
    compared exactly (it is empty).

Exit status 1 on any other difference, on a wrong per-arm message, or when the two runs do not have the same rows.
"""
import json
import re
import sys

CLOCK_FIELDS = {"expires_ms", "redis_time_before_ms", "redis_time_after_ms"}
TIME_KEYS = re.compile(r"\[(updatedAt|expiresAt|epochStart|validUntil)\] => (\d+)")
OLD_TEXT = "expiry not representable"


def new_text(denied):
    return "NOPERM this user has no permissions to run the '%s' command" % denied


def offset(n, now):
    return "T%+d" % (int(n) - now) if now else n


def normalise(field, value, now):
    """Rewrite ONLY the known time fields as offsets from the run's own caller time; everything else is returned untouched."""
    if field == "stored_updated_at" and isinstance(value, str) and value.isdigit():
        return offset(value, now)
    if field == "result" and isinstance(value, str):
        return TIME_KEYS.sub(lambda m: "[%s] => %s" % (m.group(1), offset(m.group(2), now)), value)
    return value


def timing_free(entries):
    kept = []
    for entry in entries or []:
        e = str(entry)
        if e.endswith(" expiry"):
            continue
        kept.append(e[: -len("+expiry")] if e.endswith("+expiry") else e)
    return kept


def load(directory):
    rows, seen = {}, {}
    for line in open(directory + "/evidence.jsonl"):
        r = json.loads(line)
        key = (r.get("kind", ""), r.get("label") or r.get("scenario", ""), r.get("denied", ""), r.get("shape", ""), r.get("state", ""))
        n = seen.get(key, 0)  # the same key repeats (one control per denied command, one row per account kind)
        seen[key] = n + 1
        rows[key + (n,)] = r
    return rows


def main(before_dir, after_dir, same_contract=False):
    before, after = load(before_dir), load(after_dir)
    if set(before) != set(after):
        print("row sets differ:", len(set(before) ^ set(after)), "rows only on one side")
        return 1
    changed = identical = unexpected = 0
    arms = () if same_contract else (("before", before, lambda r: OLD_TEXT), ("after", after, lambda r: new_text(r.get("denied"))))
    for side, rows, wanted in arms:
        for key, r in rows.items():
            if r.get("expected") == "denied" and r.get("message") != wanted(r):
                unexpected += 1
                print("WRONG MESSAGE in the %s arm" % side, key, repr(r.get("message")))
    for key, b in before.items():
        a = after[key]
        reached = b.get("expected") == "denied"
        diff = set()
        for f in set(a) | set(b):
            if f in ("contract", "now") or f in CLOCK_FIELDS:
                continue
            va, vb = a.get(f), b.get(f)
            if f == "diff" and not reached:
                same = timing_free(va) == timing_free(vb)
            else:
                same = normalise(f, va, a.get("now", 0)) == normalise(f, vb, b.get("now", 0))
            if not same:
                diff.add(f)
        if not diff:
            identical += 1
        elif diff == {"message"} and reached and not same_contract:
            changed += 1  # the per-arm check above already demanded the exact text on each side
        else:
            unexpected += 1
            print("UNEXPECTED difference", key, sorted(diff))
    print("rows: %d; identical: %d; reached ACL denials whose message went from 'expiry not representable' to NOPERM: %d; unexpected: %d" % (len(before), identical, changed, unexpected))
    return 1 if unexpected else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1], sys.argv[2], "--same-contract" in sys.argv[3:]))
