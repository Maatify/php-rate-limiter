#!/usr/bin/env python3
"""Compare the `before` and `after` evidence of one width row by row (#270, DEC-043).

usage: compare.py <before-dir> <after-dir>   (each holds evidence.jsonl and manifest.json)

Rows are matched by (kind, label|scenario, denied, shape, state, occurrence). Every field must be IDENTICAL between the two runs except:

* `contract` (it names the run) and `now` (the run's own caller epoch, used only to factor the clock out of the other fields);
* `message` of a reached ACL denial: exactly `expiry not representable` before and exactly the NOPERM text of the denied command after;
* what varies between two runs by construction, and nothing else:
  - every integer that is the caller's epoch (`now`, recorded in the row) plus an offset, in seconds or in milliseconds, is rewritten as
    `T+offset` / `M+offset` before comparing, so the stored `updatedAt`, a result's `updatedAt` / `expiresAt` and the like are compared by
    their offset from the run's own clock, while stored values, counts, levels and generations are compared exactly;
  - `expires_ms`, `redis_time_before_ms`, `redis_time_after_ms` are absolute Redis clock readings; the test itself asserts the bracket
    (TIME before + 600 s <= expiry <= TIME after + 600 s) in each run;
  - in `diff` of a call that did NOT reach the denial (a control or an unreached branch), an expiry that is only re-applied at Redis's own
    clock (`<key> expiry`, or the `+expiry` suffix) differs by milliseconds: it is dropped from both sides. A reached denial's `diff` is
    compared exactly (it is empty).

Exit status 1 on any other difference, or when the two runs do not have the same rows.
"""
import json
import re
import sys

CLOCK_FIELDS = {"expires_ms", "redis_time_before_ms", "redis_time_after_ms"}
EPOCH = re.compile(r"(?<![\d.])\d{9,13}(?![\d.])")


def normalise(value, now):
    """Rewrite the integers that are the run's own caller time (seconds or ms) as offsets from it."""
    if isinstance(value, str):
        if not now:
            return value

        def swap(m):
            n = int(m.group(0))
            if abs(n - now) <= 10**8:
                return "T%+d" % (n - now)
            if abs(n - now * 1000) <= 10**11:
                return "M%+d" % (n - now * 1000)
            return m.group(0)

        return EPOCH.sub(swap, value)
    if isinstance(value, list):
        return [normalise(v, now) for v in value]
    if isinstance(value, dict):
        return {k: normalise(v, now) for k, v in value.items()}
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


def main(before_dir, after_dir):
    before, after = load(before_dir), load(after_dir)
    if set(before) != set(after):
        print("row sets differ:", len(set(before) ^ set(after)), "rows only on one side")
        return 1
    changed = identical = unexpected = 0
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
                same = normalise(va, a.get("now", 0)) == normalise(vb, b.get("now", 0))
            if not same:
                diff.add(f)
        if not diff:
            identical += 1
        elif diff == {"message"} and reached and b.get("message") == "expiry not representable" \
                and a.get("message") == "NOPERM this user has no permissions to run the '%s' command" % b.get("denied"):
            changed += 1
        else:
            unexpected += 1
            print("UNEXPECTED difference", key, sorted(diff))
    print("rows: %d; identical: %d; reached ACL denials whose message went from 'expiry not representable' to NOPERM: %d; unexpected: %d" % (len(before), identical, changed, unexpected))
    return 1 if unexpected else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1], sys.argv[2]))
