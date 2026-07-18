#!/usr/bin/env bash
set -euo pipefail

APPLICATION_ROOT="${1:-/var/www/html}"
DIRECTORIES=(app bootstrap config database public resources routes)
FILES=(artisan composer.json composer.lock)

for directory in "${DIRECTORIES[@]}"; do
    if [ ! -d "$APPLICATION_ROOT/$directory" ]; then
        echo "Fingerprint input directory is missing: $directory" >&2
        exit 1
    fi
done

for file in "${FILES[@]}"; do
    if [ ! -f "$APPLICATION_ROOT/$file" ]; then
        echo "Fingerprint input file is missing: $file" >&2
        exit 1
    fi
done

emit_payload_files() {
    local directory file
    for directory in "${DIRECTORIES[@]}"; do
        if [ "$directory" = 'bootstrap' ]; then
            find "$APPLICATION_ROOT/$directory" \
                -path "$APPLICATION_ROOT/bootstrap/cache" -prune \
                -o -type f -print0
        else
            find "$APPLICATION_ROOT/$directory" -type f -print0
        fi
    done
    for file in "${FILES[@]}"; do
        printf '%s\0' "$APPLICATION_ROOT/$file"
    done
}

payload_digest="$({
    emit_payload_files | LC_ALL=C sort -z | while IFS= read -r -d '' path; do
        relative_path="${path#"$APPLICATION_ROOT"/}"
        content_digest="$(sha256sum -- "$path")"
        content_digest="${content_digest%% *}"
        # NUL-delimited relative path + per-file content digest makes framing deterministic even
        # when file contents contain newlines or binary data.
        printf '%s\0%s\0' "$relative_path" "$content_digest"
    done
} | sha256sum)"
payload_digest="${payload_digest%% *}"

if [[ ! "$payload_digest" =~ ^[0-9a-f]{64}$ ]]; then
    echo 'Unable to calculate the application payload fingerprint.' >&2
    exit 1
fi

printf 'sha256:%s\n' "$payload_digest"
