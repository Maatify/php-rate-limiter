#!/usr/bin/env bash
# Self-test of run.sh against a fake `docker` and a throw-away git repository, both created under a directory this script owns.
# It checks that each way of failing is a non-zero exit, that cleanup runs, and that the manifest is complete. No Docker is needed.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
work="$(mktemp -d "${TMPDIR:-/tmp}/run270-selftest.XXXXXX")"
trap 'rm -rf "$work"' EXIT
mkdir "$work/bin" "$work/repo"
cat > "$work/bin/docker" <<'F'
#!/usr/bin/env bash
echo "$*" >> "$FAKE_LOG"
case "$1 $2" in
  "network create"|"network rm"|"rm -f") exit 0 ;;
  "run -d") echo fakeid; exit 0 ;;
  "exec "*)
    if [[ "$*" == *"redis-cli ping"* ]]; then [[ "${FAKE_NOT_READY:-0}" == 1 ]] && exit 1; echo PONG; exit 0; fi
    echo "Redis server v=7.0.15"; exit 0 ;;
esac
if [[ "$*" == *"php -r"* ]]; then echo -n "8.4.27|${FAKE_INT:-8}|6.3.0"; exit 0; fi
if [[ "$*" == *"--version"* ]]; then echo "PHPUnit 11.5.57 by Sebastian Bergmann"; exit 0; fi
out=""
prev=""
for a in "$@"; do if [[ "$prev" == -v && "$a" == *:/out ]]; then out="${a%%:/out}"; fi; prev="$a"; done
if [[ -n "$out" && "${FAKE_NO_EVIDENCE:-0}" != 1 ]]; then
  for ((i = 0; i < ${FAKE_ROWS:-3}; i++)); do
    # FAKE_NOINT_FROM=n drops the int field from row n onward (0 = every row)
    if [[ -n "${FAKE_NOINT_FROM:-}" ]] && ((i >= FAKE_NOINT_FROM)); then echo "{\"row\":$i}" >> "$out/evidence.jsonl"
    else echo "{\"row\":$i,\"int\":${FAKE_ROW_INT:-8}}" >> "$out/evidence.jsonl"; fi
  done
fi
echo "OK (1 test, 1 assertion)"
exit "${FAKE_RC:-0}"
F
chmod +x "$work/bin/docker"
cat > "$work/bin/tee" <<'F'
#!/usr/bin/env bash
if [[ -n "${FAKE_TEE_RC:-}" ]]; then cat > "$1"; exit "$FAKE_TEE_RC"; fi
exec /usr/bin/tee "$@"
F
chmod +x "$work/bin/tee"
git -C "$work/repo" init -q
git -C "$work/repo" -c user.email=t@t -c user.name=t commit -q --allow-empty -m init
export PATH="$work/bin:$PATH" FAKE_LOG="$work/log" RUN270_REPO_ROOT="$work/repo" RUN270_EXPECTED_ROWS=3

fail=0
expect() { # name expected-exit command...
  local name="$1" want="$2"; shift 2
  : > "$FAKE_LOG"
  set +e; "$@" >/dev/null 2>&1; local got=$?; set -e
  if [[ "$got" == "$want" ]]; then echo "ok   $name"; else echo "FAIL $name (exit $got, wanted $want)"; fail=1; fi
}
run() { "$here/run.sh" php64 tests/X.php; }

EVIDENCE_DIR="$work/e1" expect "good run exits 0" 0 run
python3 - "$work/e1/manifest.json" <<'PY' || { echo "FAIL manifest"; fail=1; }
import json, sys
m = json.load(open(sys.argv[1]))
need = ["source_head", "source_tree", "source_dirty", "command", "php", "php_int_size", "ext_redis", "redis", "phpunit", "phpunit_summary", "evidence_rows", "evidence_sha256", "status"]
assert all(m[k] != "" for k in need), m
assert m["status"] == "ok" and m["evidence_rows"] == 3 and m["php_int_size"] == "8" and m["source_dirty"] is False
PY
echo "ok   manifest is complete"
grep -q "rm -f" "$FAKE_LOG" || true

EVIDENCE_DIR="$work/e1" expect "reusing an evidence directory fails" 2 run
mkdir "$work/e2"; : > "$work/e2/stale"
EVIDENCE_DIR="$work/e2" expect "a non-empty or existing directory fails" 2 run
EVIDENCE_DIR="$work/e3" FAKE_NO_EVIDENCE=1 expect "missing evidence fails" 3 run
EVIDENCE_DIR="$work/e4" FAKE_ROWS=2 expect "incomplete evidence fails" 3 run
EVIDENCE_DIR="$work/e5" FAKE_RC=1 expect "a failing phpunit run fails" 1 run
EVIDENCE_DIR="$work/e6" FAKE_NOT_READY=1 RUN270_READY_SECONDS=2 expect "Redis never ready fails" 4 run
EVIDENCE_DIR="$work/e6b" expect "an unknown image fails" 2 "$here/run.sh" php99 tests/X.php
EVIDENCE_DIR="$work/e6c" expect "no phpunit args fails" 2 "$here/run.sh" php64
EVIDENCE_DIR="$work/w1" FAKE_INT=8 expect "php32 on an 8-byte-int image fails" 5 "$here/run.sh" php32 tests/X.php
if grep -q "phpunit" "$FAKE_LOG"; then echo "FAIL a wrong-width image reached phpunit"; fail=1; else echo "ok   a wrong-width image never reached phpunit"; fi
EVIDENCE_DIR="$work/w2" FAKE_INT=4 expect "php64 on a 4-byte-int image fails" 5 "$here/run.sh" php64 tests/X.php
EVIDENCE_DIR="$work/w3" FAKE_INT=4 FAKE_ROW_INT=4 expect "php32 on a 4-byte-int image passes" 0 "$here/run.sh" php32 tests/X.php
EVIDENCE_DIR="$work/w4" FAKE_ROW_INT=4 expect "evidence rows with the wrong int width fail" 5 run
EVIDENCE_DIR="$work/w4a" FAKE_NOINT_FROM=0 expect "rows with no int field at all fail" 5 run
EVIDENCE_DIR="$work/w4b" FAKE_NOINT_FROM=2 expect "one row without the int field fails" 5 run
grep -q '"status": "width-mismatch"' "$work/w4a/manifest.json" && echo "ok   missing field is named width-mismatch" || { echo "FAIL missing-field status"; fail=1; }
EVIDENCE_DIR="$work/w5" FAKE_TEE_RC=23 expect "a failing log save fails even when phpunit passed" 6 run
grep -q '"status": "log-write-failed"' "$work/w5/manifest.json" && echo "ok   log failure is named in the manifest" || { echo "FAIL log status"; fail=1; }
mkdir "$work/repo/in"; : > "$work/repo/in/f"
git -C "$work/repo" add in/f
git -C "$work/repo" -c user.email=t@t -c user.name=t commit -q -m files
EVIDENCE_DIR="$work/e7" expect "an evidence dir inside the repo fails" 2 env EVIDENCE_DIR="$work/repo/in/ev" "$here/run.sh" php64 tests/X.php
rmdir "$work/repo/in/ev" 2>/dev/null || true
: > "$work/repo/dirty-file"
EVIDENCE_DIR="$work/e8" expect "a dirty tree fails" 2 run
if grep -q "run --rm" "$FAKE_LOG"; then echo "FAIL a dirty tree reached docker run"; fail=1; else echo "ok   a dirty tree never reached docker run"; fi
EVIDENCE_DIR="$work/e9" ALLOW_DIRTY=1 expect "ALLOW_DIRTY=1 runs and says so" 0 run
grep -q '"source_dirty": true' "$work/e9/manifest.json" && echo "ok   dirty run is labelled dirty" || { echo "FAIL dirty label"; fail=1; }
rm "$work/repo/dirty-file"
: > "$FAKE_LOG"; EVIDENCE_DIR="$work/e10" FAKE_RC=1 "$here/run.sh" php64 tests/X.php >/dev/null 2>&1 || true
grep -q "network rm" "$FAKE_LOG" && grep -q "rm -f" "$FAKE_LOG" && echo "ok   cleanup runs after a failure" || { echo "FAIL cleanup"; fail=1; }
exit "$fail"
