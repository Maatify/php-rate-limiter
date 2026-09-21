#!/usr/bin/env bash
set -euo pipefail

repository_root=$(git rev-parse --show-toplevel)
cd "$repository_root"

discovery_file=$(mktemp "${TMPDIR:-/tmp}/maatify-php-syntax.XXXXXX")
trap 'rm -f -- "$discovery_file"' EXIT

if ! git ls-files -z -- '*.php' >"$discovery_file"; then
    echo 'Unable to discover tracked PHP files.' >&2
    exit 1
fi

php_files=()
while IFS= read -r -d '' file; do
    php_files+=("$file")
done <"$discovery_file"

if [[ "${#php_files[@]}" -eq 0 ]]; then
    echo 'No tracked PHP files found.' >&2
    exit 1
fi

for file in "${php_files[@]}"; do
    php -l "$file"
done
