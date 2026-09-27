#!/usr/bin/env bash
set -euo pipefail

temporary_directory="$(mktemp -d)"
trap 'rm -rf "$temporary_directory"' EXIT

awk -v output_directory="$temporary_directory" '
    /^```php[[:space:]]*$/ {
        in_block = 1
        block += 1
        output = output_directory "/example-" block ".php"
        next
    }
    /^```[[:space:]]*$/ && in_block {
        close(output)
        in_block = 0
        next
    }
    in_block {
        print > output
    }
' README.md

shopt -s nullglob
examples=("$temporary_directory"/*.php)
if [[ "${#examples[@]}" -eq 0 ]]; then
    echo 'No PHP examples found in README.md.'
    exit 0
fi

for example in "${examples[@]}"; do
    checked_example="${example%.php}.checked.php"
    {
        printf '%s\n' '<?php'
        cat "$example"
    } > "$checked_example"
    php -l "$checked_example"
done
