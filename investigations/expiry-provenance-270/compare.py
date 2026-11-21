#!/usr/bin/env python3
"""Compare the `before` and `after` evidence of one width row by row (#270, DEC-043).

usage: compare.py <before-dir> <after-dir>   (each holds evidence.jsonl and manifest.json)

Rows are matched by (kind, label|scenario, denied, shape, state, occurrence). Exit status 1 when anything other than the message of a reached
ACL denial (and the contract field) differs between the two runs, or when the two do not have the same rows.
"""
import json
import sys


def comparable(entries):
    """An expiry re-applied at Redis's own clock differs by milliseconds between two runs: the same normalisation the test applies."""
    kept = []
    for entry in entries or []:
        if str(entry).endswith(" expiry"):
            continue
        e = str(entry)
        kept.append(e[: -len("+expiry")] if e.endswith("+expiry") else e)
    return kept


def load(directory):
    rows, seen = {}, {}
    for line in open(directory + "/evidence.jsonl"):
        r = json.loads(line)
        key = (r.get("kind", ""), r.get("label") or r.get("scenario", ""), r.get("denied", ""), r.get("shape", ""), r.get("state", ""))
        # the same key repeats (one control per denied command, one row per account kind): match by the n-th occurrence
        n = seen.get(key, 0)
        seen[key] = n + 1
        rows[key + (n,)] = r
    return rows


before, after = load(sys.argv[1]), load(sys.argv[2])
if set(before) != set(after):
    print("row sets differ:", len(set(before) ^ set(after)), "rows only on one side")
    sys.exit(1)
changed_message = unchanged = other = 0
ignored = {"contract"}
for key, b in before.items():
    a = after[key]
    reached = b.get("expected") == "denied"
    diff = set()
    for f in set(a) | set(b):
        if f in ignored:
            continue
        if f == "diff" and not reached:
            same = comparable(a.get(f)) == comparable(b.get(f))
        elif f == "result" and not reached:
            same = True  # the result of a successful call carries the caller time of its own run
        else:
            same = a.get(f) == b.get(f)
        if not same:
            diff.add(f)
    if not diff:
        unchanged += 1
    elif diff == {"message"} and b.get("expected") == "denied" and b.get("message") == "expiry not representable" and str(a.get("message", "")).startswith("NOPERM "):
        changed_message += 1
    elif diff <= {"stored_value", "stored_updated_at", "expires_ms", "redis_time_before_ms", "redis_time_after_ms", "after", "result"} and key[0] not in ("wide", "limited"):
        unchanged += 1  # clocks of two separate runs
    else:
        other += 1
        print("UNEXPECTED difference", key, sorted(diff))
print("rows: %d; identical: %d; reached ACL denials whose message went from 'expiry not representable' to NOPERM: %d; unexpected: %d" % (len(before), unchanged, changed_message, other))
sys.exit(1 if other else 0)
