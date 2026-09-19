#!/usr/bin/env bash
set -euo pipefail

example_count=0
while IFS= read -r -d '' example; do
    echo "Running $example"
    php "$example"
    ((example_count += 1))
done < <(find examples -type f -name '*.php' -print0)

if [[ "$example_count" -eq 0 ]]; then
    echo 'No standalone PHP examples found.' >&2
    exit 1
fi

echo 'Standalone PHP examples passed smoke execution.'
