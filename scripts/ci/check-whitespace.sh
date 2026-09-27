#!/usr/bin/env bash
set -euo pipefail

trailing_whitespace=''
if git_grep_output="$(git grep -nI -E '[[:blank:]]$' -- . ':(exclude)vendor')"; then
    trailing_whitespace="$git_grep_output"
elif [[ "$?" -ne 1 ]]; then
    echo 'Unable to inspect tracked files for trailing whitespace.' >&2
    exit 1
fi

if [[ -n "$trailing_whitespace" ]]; then
    echo 'Trailing whitespace detected:' >&2
    printf '%s\n' "$trailing_whitespace" >&2
    exit 1
fi

git diff --check
echo 'Whitespace verification passed.'
