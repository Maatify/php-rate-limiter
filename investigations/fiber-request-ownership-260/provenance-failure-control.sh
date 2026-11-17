#!/usr/bin/env bash
# Control for F266-02: the runner must not finish green when the identification of the tested source fails, even though both suites pass.
# A shim delegates every git command to the real git except the three-argument `rev-parse HEAD 'HEAD^{tree}'`, which fails with 71 and prints nothing.
# Usage (repository root): bash investigations/fiber-request-ownership-260/provenance-failure-control.sh <output-dir>
set -uo pipefail
out=${1:?output directory}
real=$(command -v git)
shim=$(mktemp -d "${TMPDIR:-/tmp}/rl260-shim.XXXXXX")
trap 'rm -rf "$shim"' EXIT
cat > "$shim/git" <<SHIM
#!/usr/bin/env bash
if [ "\${@: -3:1}" = rev-parse ] && [ "\${@: -2:1}" = HEAD ] && [ "\${@: -1}" = 'HEAD^{tree}' ]; then exit 71; fi
exec "$real" "\$@"
SHIM
chmod +x "$shim/git"
FIBER260_GIT="$shim/git" bash investigations/fiber-request-ownership-260/run.sh "$out"
status=$?
echo "runner exit=$status" > "$out/exit-status.txt"
exit "$status"
