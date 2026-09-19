#!/usr/bin/env bash
set -euo pipefail

php_files=()
for directory in src tests examples; do
    if [[ -d "$directory" ]]; then
        while IFS= read -r -d '' file; do
            php_files+=("$file")
        done < <(find "$directory" -type f -name '*.php' -print0)
    fi
done

if [[ "${#php_files[@]}" -eq 0 ]]; then
    echo 'No package-owned PHP files found.' >&2
    exit 1
fi

for file in "${php_files[@]}"; do
    php -l "$file"
done
