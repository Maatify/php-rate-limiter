#!/usr/bin/env bash
# Controls for F266-02: the runner must not finish green, and must not record a tested source, when the proof of that source fails,
# even though both suites pass. Usage (repository root): bash investigations/fiber-request-ownership-260/provenance-failure-control.sh <mode> <output-dir>
#   identity : a git shim fails only `rev-parse HEAD 'HEAD^{tree}'` (exit 71, no output)
#   status   : a git shim fails only commands that contain the argument `status` (exit 71, a message on stderr, no stdout)
#   dirty    : a tracked file is modified (and restored afterwards): the working tree is not the committed source
set -uo pipefail
mode=${1:?mode: identity|status|dirty}
out=${2:?output directory}
real=$(command -v git)
shim=$(mktemp -d "${TMPDIR:-/tmp}/rl260-shim.XXXXXX")
dirty_file=investigations/fiber-request-ownership-260/RESULTS.md
saved=
touched=0
# Only what the control itself introduced is removed: the file's own bytes, saved before the first change, are put back (never a checkout,
# which would also discard edits that predate the control).
cleanup() {
    rm -rf "$shim"
    if [ "$touched" = 1 ] && [ -n "$saved" ]; then cp "$saved" "$dirty_file"; rm -f "$saved"; fi
}
trap cleanup EXIT
case "$mode" in
    identity)
        cat > "$shim/git" <<SHIM
#!/usr/bin/env bash
if [ "\${@: -3:1}" = rev-parse ] && [ "\${@: -2:1}" = HEAD ] && [ "\${@: -1}" = 'HEAD^{tree}' ]; then exit 71; fi
exec "$real" "\$@"
SHIM
        ;;
    status)
        cat > "$shim/git" <<SHIM
#!/usr/bin/env bash
for arg in "\$@"; do
    if [ "\$arg" = status ]; then echo 'control: git status failed.' >&2; exit 71; fi
done
exec "$real" "\$@"
SHIM
        ;;
    dirty)
        # Refuse, before any change and before anything destructive is installed, when the target already differs from the commit
        # (worktree or index) or the status cannot be read: pre-existing work is never touched.
        pending=$("$real" status --porcelain -- "$dirty_file") || { echo "refused: git status failed" >&2; exit 3; }
        if [ -n "$pending" ]; then echo "refused: $dirty_file has uncommitted changes; the control will not touch it" >&2; exit 3; fi
        saved=$(mktemp "${TMPDIR:-/tmp}/rl260-saved.XXXXXX")
        cp "$dirty_file" "$saved"
        touched=1
        printf '\nuncommitted control line\n' >> "$dirty_file"
        printf '#!/usr/bin/env bash\nexec "%s" "$@"\n' "$real" > "$shim/git"
        ;;
    *) echo "unknown mode: $mode" >&2; exit 2 ;;
esac
chmod +x "$shim/git"
FIBER260_GIT="$shim/git" bash investigations/fiber-request-ownership-260/run.sh "$out"
status=$?
echo "runner exit=$status" > "$out/exit-status.txt"
exit "$status"
