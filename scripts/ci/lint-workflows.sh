#!/usr/bin/env bash
set -euo pipefail

actionlint_binary="${ACTIONLINT_BIN:-actionlint}"
if [[ "$actionlint_binary" == */* ]]; then
    if [[ ! -x "$actionlint_binary" ]]; then
        echo "actionlint executable not found: $actionlint_binary" >&2
        exit 1
    fi
else
    if ! command -v "$actionlint_binary" >/dev/null 2>&1; then
        echo 'actionlint is required; install v1.7.12 or set ACTIONLINT_BIN.' >&2
        exit 1
    fi
fi

shopt -s nullglob
workflow_files=(.github/workflows/*.yml .github/workflows/*.yaml)
if [[ ! -e "${workflow_files[0]}" ]]; then
    echo 'No GitHub Actions workflow files found.' >&2
    exit 1
fi

"$actionlint_binary" "${workflow_files[@]}"
