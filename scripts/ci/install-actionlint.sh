#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" != 'Linux' || "$(uname -m)" != 'x86_64' ]]; then
    echo 'This installer supports the GitHub Actions ubuntu-latest x86_64 runner only.' >&2
    exit 1
fi

version='1.7.12'
archive="actionlint_${version}_linux_amd64.tar.gz"
expected_sha256='8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8'
destination="${1:?usage: install-actionlint.sh DESTINATION_DIRECTORY}"
temporary_directory="$(mktemp -d)"
trap 'rm -rf "$temporary_directory"' EXIT

curl --fail --silent --show-error --location --retry 3 \
    "https://github.com/rhysd/actionlint/releases/download/v${version}/${archive}" \
    --output "${temporary_directory}/${archive}"

printf '%s  %s\n' "$expected_sha256" "$archive" \
    | (cd "$temporary_directory" && sha256sum --check --status)

mkdir -p "$destination"
tar -xzf "${temporary_directory}/${archive}" -C "$temporary_directory"
install -m 0755 "${temporary_directory}/actionlint" "${destination}/actionlint"
"${destination}/actionlint" -version
