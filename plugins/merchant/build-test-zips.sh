#!/usr/bin/env bash
set -euo pipefail
umask 077

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_root="$project_root/plugins/merchant"
output_dir="$project_root/storage/app/private/plugin-test-builds"

for tool in rsync zip unzip; do
    if ! command -v "$tool" >/dev/null; then
        printf '缺少打包工具：%s\n' "$tool" >&2
        exit 1
    fi
done

mkdir -p "$output_dir"
work_dir="$(mktemp -d "$output_dir/.build.XXXXXXXX")"
trap 'rm -rf -- "$work_dir"' EXIT

build_plugin() {
    local slug="$1"
    shift
    local source="$source_root/$slug" version entry
    local -a inputs=()

    if [[ ! -f "$source/vendor/autoload.php" ]]; then
        printf '缺少 %s 的运行依赖，请在该插件目录先执行 composer install --no-dev。\n' "$slug" >&2
        return 1
    fi

    version="$(awk '/^[[:space:]]*\*[[:space:]]*Version:/ { sub(/\r$/, "", $3); print $3; exit }' "$source/$slug.php")"
    if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        printf '无法读取 %s 的插件版本。\n' "$slug" >&2
        return 1
    fi

    for entry in "$slug.php" vendor "$@"; do
        inputs+=("$source/$entry")
    done

    mkdir "$work_dir/$slug"
    rsync -rt --exclude-from="$source/.distignore" \
        --exclude='.*' --exclude='auth.json' --exclude='wp-config.php' \
        --exclude='*.zip' --exclude='*.log' --exclude='*.key' --exclude='*.pem' --exclude='*.sql' \
        -- "${inputs[@]}" "$work_dir/$slug/"
    (
        cd "$work_dir"
        zip -q -X -r "$slug-$version.zip" "$slug"
        unzip -tq "$slug-$version.zip"
    )
}

build_plugin sscpay-gateway-aggregator src assets uninstall.php

for archive in "$work_dir"/*.zip; do
    mv -f "$archive" "$output_dir/"
    printf '%s/%s\n' "$output_dir" "${archive##*/}"
done
