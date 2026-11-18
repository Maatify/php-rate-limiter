#!/usr/bin/env bash
# F266-04 check: the dirty-source control must neither discard nor alter work that predates it. It runs in a DISPOSABLE clone (never the
# working checkout) with synthetic edits to RESULTS.md. Usage (repository root): bash investigations/fiber-request-ownership-260/preservation-check.sh <output-dir>
#   a. pre-existing unstaged edit: the control refuses (exit 3) before mutating; bytes, worktree diff and index are unchanged
#   b. pre-existing staged edit: the control refuses (exit 3); bytes, worktree diff and index are unchanged
#   c. initially clean target: the control runs to its end (runner exit 1) and the file is byte-for-byte what it was
# Exits 1 if any check fails; every step keeps its output.
set -uo pipefail
root=$(pwd)
out=${1:?output directory}
mkdir -p "$out"
: > "$out/result.txt"
failed=0
f=investigations/fiber-request-ownership-260/RESULTS.md
tmp=$(mktemp -d "${TMPDIR:-/tmp}/rl260-preserve.XXXXXX") || exit 1
trap 'rm -rf "$tmp"' EXIT
record() { printf '%s\t%s\n' "$1" "$2" >> "$out/result.txt"; if [ "$2" -ne 0 ]; then failed=1; fi; }
fresh() { # name
    rm -rf "${tmp:?}/$1"
    git clone -q "$root" "$tmp/$1" && git -C "$tmp/$1" checkout -q "$(git rev-parse HEAD)" && cp -R "$root/vendor" "$tmp/$1/vendor"
}
case_refused() { # name, staged?
    local n=$1 staged=$2
    fresh "$n" > "$out/$n-setup.log" 2>&1 || { record "$n-setup" 1; return; }
    (
        cd "$tmp/$n" || exit 1
        printf '\nLEAD-PREEXISTING-CANARY (%s)\n' "$n" >> "$f"
        if [ "$staged" = yes ]; then git add "$f"; fi
        cp "$f" "$tmp/$n.bytes"; git diff > "$tmp/$n.diff.before"; git diff --cached > "$tmp/$n.cached.before"
        bash investigations/fiber-request-ownership-260/provenance-failure-control.sh dirty "$tmp/$n-out" > "$out/$n-control.log" 2>&1
        echo "control exit=$?" >> "$out/$n-control.log"
        cmp -s "$f" "$tmp/$n.bytes" && git diff > "$tmp/$n.diff.after" && git diff --cached > "$tmp/$n.cached.after" \
            && cmp -s "$tmp/$n.diff.before" "$tmp/$n.diff.after" && cmp -s "$tmp/$n.cached.before" "$tmp/$n.cached.after" \
            && grep -q 'control exit=3' "$out/$n-control.log" && grep -q 'refused' "$out/$n-control.log"
    ) > "$out/$n-check.log" 2>&1
    record "$n" $?
}
case_refused unstaged-preexisting-edit no
case_refused staged-preexisting-edit yes
n=initially-clean
fresh $n > "$out/$n-setup.log" 2>&1 || record "$n-setup" 1
(
    cd "$tmp/$n" || exit 1
    cp "$f" "$tmp/$n.bytes"
    bash investigations/fiber-request-ownership-260/provenance-failure-control.sh dirty "$tmp/$n-out" > "$out/$n-control.log" 2>&1
    echo "control exit=$?" >> "$out/$n-control.log"
    cmp -s "$f" "$tmp/$n.bytes" && [ -z "$(git status --porcelain -- "$f")" ] && grep -q 'control exit=1' "$out/$n-control.log"
) > "$out/$n-check.log" 2>&1
record "$n" $?
cat "$out/result.txt"
exit "$failed"
