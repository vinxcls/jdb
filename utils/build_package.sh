#!/bin/bash
set -euo pipefail

NO_MINIFY=false
POSITIONAL=()

for arg in "$@"; do
    case "$arg" in
        --no-minify)
            NO_MINIFY=true
            ;;
        *)
            POSITIONAL+=("$arg")
            ;;
    esac
done

if [ ${#POSITIONAL[@]} -lt 1 ]; then
    echo "Usage: $0 [--no-minify] <outfile> [src directory]"
    echo "Example: $0 jdb.php"
    echo "         $0 jdb.php src"
    echo "         $0 --no-minify jdb.php src"
    echo ""
    echo "Options:"
    echo "  --no-minify  Skip code minification (default: minify)"
    exit 1
fi

out="${POSITIONAL[0]}"
src="${POSITIONAL[1]:-.}"

if [ ! -d "$src" ]; then
    echo "Error: Source directory '$src' not found." >&2
    exit 1
fi

files=(
    "JdbErrorHandler.php"
    "JdbUtil.php"
    "JdbRealpathCache.php"
    "JdbIndexHeader.php"
    "JdbBinaryIndex.php"
    "JdbSecondaryIndex.php"
    "JdbLock.php"
    "JsonDatabase.php"
    "JdbConfig.php"
    "JdbTransaction.php"
    "JdbManager.php"
    "JdbRelationType.php"
    "JdbRelationMeta.php"
    "JdbRelationValidator.php"
    "JdbAggLock.php"
    "JdbAggregate.php"
)

echo "<?php" > "$out"

for f in "${files[@]}"; do
    file_path="${src}/${f}"
    if [ ! -f "$file_path" ]; then
        echo "Warning: $file_path not found, skipping." >&2
        continue
    fi

    grep -v '^<?php' "$file_path" | \
    grep -vE '^\s*require_once\s+.*(Jdb|JsonDatabase)' >> "$out"
done

if ! php -l "$out"; then
    exit 1
fi

if ! php5.6 -l "$out"; then
    echo "PHP5.6 fails!"
    exit 1
fi
if [ "$NO_MINIFY" = true ]; then
    echo "Minification skipped (--no-minify)."
    exit 0
fi

php -r 'echo php_strip_whitespace($argv[1]);' "$out" > "${out}.stripped"

cat > "$out" << 'HEADER'
<?php
/**
 * AUTO-GENERATED FILE - DO NOT MODIFY.
 * This file is a cumulative build of the JDB source files.
 */
HEADER

sed 's/<?php//' "${out}.stripped" >> "$out"

rm "${out}.stripped"

if ! php -l "$out"; then
    exit 1
fi
exit 0
