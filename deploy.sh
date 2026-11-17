#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'HELP'
用法：bash deploy.sh [--no-pull] [--check]

默认快速前进更新当前 Git 分支，然后安装依赖、构建前端、迁移、发布插件、刷新缓存并重启队列。
--no-pull  部署已检出的代码，适用于 CI 或重试失败的部署。
--check    仅检查账号、工具、Git 工作区和目录权限。

可选环境变量：DEPLOY_PHP（默认 php）、DEPLOY_COMPOSER（默认 composer 的 PHP/PHAR 文件）。
Node 优先使用本站 storage/app/deploy-tools/node/bin 或 /opt/sscpay-node/bin；DEPLOY_NODE_BIN 可显式覆盖。
root 启动时自动识别站点账号并切换；DEPLOY_USER 可用于特殊环境覆盖。
现有 .env、APP_KEY、数据库和 OSS 配置需已就绪。
HELP
}

add_account_candidate() {
    local candidate_uid="$1" candidate_source="$2" existing_uid
    if [[ ! "$candidate_uid" =~ ^[0-9]+$ || "$candidate_uid" -eq 0 ]]; then
        return 0
    fi
    deploy_candidate_details+=("$candidate_source -> UID $candidate_uid")
    if [[ -n "${deploy_candidate_uids[*]:-}" ]]; then
        for existing_uid in "${deploy_candidate_uids[@]}"; do
            if [[ "$existing_uid" == "$candidate_uid" ]]; then
                return 0
            fi
        done
    fi
    deploy_candidate_uids+=("$candidate_uid")
}

select_account_candidate() {
    local candidate_source="$1"
    if [[ "${#deploy_candidate_uids[@]}" -ne 1 ]]; then
        printf '站点账号识别存在冲突（%s），请核对以下来源的属主：\n' "$candidate_source" >&2
        printf '  %s\n' "${deploy_candidate_details[@]}" >&2
        return 1
    fi
    if ! deploy_user="$(id -nu "${deploy_candidate_uids[0]}" 2>/dev/null)"; then
        printf '属主 UID %s 没有对应的系统账号。\n' "${deploy_candidate_uids[0]}" >&2
        return 1
    fi
    printf '自动识别站点账号：%s（依据：%s）。\n' "$deploy_user" "$candidate_source"
}

collect_directory_owner() {
    local candidate_path="$1" candidate_uid
    if [[ -e "$candidate_path" ]]; then
        if candidate_uid="$(stat -L -c '%u' "$candidate_path" 2>/dev/null || stat -L -f '%u' "$candidate_path" 2>/dev/null)"; then
            add_account_candidate "$candidate_uid" "$candidate_path"
        fi
    fi
}

detect_site_account() {
    local candidate_path worker_uid worker_command workers
    deploy_candidate_uids=()
    deploy_candidate_details=()
    for candidate_path in storage storage/logs storage/framework storage/framework/sessions storage/framework/views bootstrap/cache; do
        collect_directory_owner "$candidate_path"
    done
    if [[ -n "${deploy_candidate_uids[*]:-}" ]]; then
        select_account_candidate '本站运行目录属主'
        return "$?"
    fi
    collect_directory_owner "$deploy_directory"
    collect_directory_owner .env
    if [[ -n "${deploy_candidate_uids[*]:-}" ]]; then
        select_account_candidate '项目目录与 .env 属主'
        return "$?"
    fi
    if workers="$(ps -eo uid=,comm= 2>/dev/null)"; then
        while read -r worker_uid worker_command; do
            worker_command="${worker_command##*/}"
            if [[ "$worker_command" =~ ^php-fpm([0-9.]+)?(:.*)?$ ]]; then
                add_account_candidate "$worker_uid" 'PHP-FPM worker'
            fi
        done <<< "$workers"
    fi
    if [[ -n "${deploy_candidate_uids[*]:-}" ]]; then
        select_account_candidate 'PHP-FPM 进程'
        return "$?"
    fi
    printf '未能识别非 root 站点账号，请核对运行目录属主或 PHP-FPM 运行账号。\n' >&2
    return 1
}

check_directory_access() {
    local requested_path="$1" existing_path="$1"
    while [[ ! -e "$existing_path" && ! -L "$existing_path" ]]; do
        existing_path="$(dirname -- "$existing_path")"
    done
    if [[ ! -d "$existing_path" || ! -r "$existing_path" || ! -w "$existing_path" || ! -x "$existing_path" ]]; then
        printf '目录权限不足（需读取、写入、进入）：%s（目标：%s）\n' "$existing_path" "$requested_path" >&2
        return 1
    fi
}

check_node_runtime() {
    local node_version node_major node_minor
    node_version="$(node --version)" || return "$?"
    if [[ ! "$node_version" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
        printf '无法识别 Node.js 版本：%s（%s）。\n' "$node_version" "$(command -v node)" >&2
        return 1
    fi
    node_major="${BASH_REMATCH[1]}"
    node_minor="${BASH_REMATCH[2]}"
    # 与 package.json engines.node 及锁定的 Vite / Laravel Vite Plugin 要求一致。
    if [[ "$node_major" -gt 22 || ( "$node_major" -eq 22 && "$node_minor" -ge 12 ) || ( "$node_major" -eq 20 && "$node_minor" -ge 19 ) ]]; then
        printf 'Node.js %s（%s）。\n' "$node_version" "$(command -v node)"
        return 0
    fi
    printf 'Node.js %s 不满足构建要求：^20.19.0 || >=22.12.0（当前：%s）。请让部署账号使用可访问的 Node 22.12+；root 私有 nvm 的版本不会自动提供给其他账号。\n' "$node_version" "$(command -v node)" >&2
    return 1
}

select_node_tools() {
    local node_bin="${DEPLOY_NODE_BIN:-}" candidate_bin
    if [[ -z "$node_bin" ]]; then
        for candidate_bin in "$deploy_directory/storage/app/deploy-tools/node/bin" /opt/sscpay-node/bin; do
            if [[ -e "$candidate_bin" || -L "$candidate_bin" ]]; then
                node_bin="$candidate_bin"
                break
            fi
        done
    fi
    if [[ -n "$node_bin" ]]; then
        if [[ ! -d "$node_bin" || ! -x "$node_bin/node" || ! -x "$node_bin/npm" || ! -r "$node_bin/npm" ]]; then
            printf '站点账号无法使用 Node/npm 工具目录：%s。请核对目录访问权限及两个可执行文件。\n' "$node_bin" >&2
            return 1
        fi
        node_bin="$(cd -- "$node_bin" && pwd -P)" || return "$?"
        export PATH="$node_bin:$PATH"
    fi
}

check_tree_access() {
    local tree_path="$1" tree_mode="$2" tree_requirement='读取文件、读写并进入目录'
    if [[ "$tree_mode" == writable || "$tree_mode" == npm ]]; then
        tree_requirement='读写文件、读写并进入目录'
    elif [[ "$tree_mode" == directories ]]; then
        tree_requirement='读写并进入目录'
    fi
    check_directory_access "$tree_path" || return 1
    if [[ ! -d "$tree_path" ]]; then
        return 0
    fi
    find -H "$tree_path" \( -type d -o -type f \) -print0 | (
        local checked_path problems=0
        while IFS= read -r -d '' checked_path; do
            if [[ -d "$checked_path" ]]; then
                if [[ -r "$checked_path" && -w "$checked_path" && -x "$checked_path" ]]; then
                    continue
                fi
            elif [[ "$tree_mode" == directories ]]; then
                continue
            elif [[ -r "$checked_path" ]]; then
                if [[ "$tree_mode" == readable || "${checked_path##*/}" == .gitignore || -w "$checked_path" ]]; then
                    continue
                fi
                if [[ "$tree_mode" == npm && "$checked_path" == "$tree_path"/_cacache/content-v*/* ]]; then
                    continue
                fi
            fi
            printf '权限不足（需%s）：%s\n' "$tree_requirement" "$checked_path" >&2
            problems=$((problems + 1))
            if [[ "$problems" -ge 20 ]]; then
                printf '此目录仅显示前 20 个权限问题。\n' >&2
                break
            fi
        done
        [[ "$problems" -eq 0 ]]
    )
}

check_deployment_permissions() {
    local permission_path problems=0
    check_directory_access "$deploy_directory" || problems=1
    for permission_path in .env vendor/autoload.php deploy.sh; do
        if [[ ! -f "$permission_path" || ! -r "$permission_path" ]]; then
            printf '文件不存在或不可读取：%s/%s\n' "$deploy_directory" "$permission_path" >&2
            problems=1
        fi
    done
    for permission_path in vendor node_modules; do
        check_tree_access "$permission_path" readable || problems=1
    done
    for permission_path in bootstrap/cache storage/framework storage/logs storage/app/plugin-builds storage/app/private/plugin-releases public/build public/css public/js public/fonts; do
        check_tree_access "$permission_path" writable || problems=1
    done
    for permission_path in storage/app storage/app/private public; do
        check_directory_access "$permission_path" || problems=1
    done
    check_tree_access "$deploy_npm_cache" npm || problems=1
    [[ "$problems" -eq 0 ]]
}

check_tracked_directory_permissions() {
    git ls-files -z | (
        local tracked_path parent_path problems=0
        while IFS= read -r -d '' tracked_path; do
            if [[ ! -L "$tracked_path" && ! -r "$tracked_path" ]]; then
                printf '代码文件不可读取：%s/%s\n' "$deploy_directory" "$tracked_path" >&2
                problems=$((problems + 1))
            fi
            parent_path="${tracked_path%/*}"
            if [[ "$parent_path" == "$tracked_path" ]]; then
                parent_path=.
            fi
            while :; do
                if ! check_directory_access "$parent_path"; then
                    problems=$((problems + 1))
                    break
                fi
                if [[ "$parent_path" == . ]]; then
                    break
                fi
                if [[ "$parent_path" == */* ]]; then
                    parent_path="${parent_path%/*}"
                else
                    parent_path=.
                fi
            done
            if [[ "$problems" -ge 20 ]]; then
                printf '代码目录仅显示前 20 个权限问题。\n' >&2
                break
            fi
        done
        [[ "$problems" -eq 0 ]]
    )
}

deploy_pull=1
deploy_check=0
for deploy_argument in "$@"; do
    case "$deploy_argument" in
        --no-pull) deploy_pull=0 ;;
        --check) deploy_check=1 ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done

deploy_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$deploy_directory"
deploy_php="${DEPLOY_PHP:-php}"

if [[ "$(id -u)" -eq 0 ]]; then
    deploy_user="${DEPLOY_USER:-}"
    if [[ -z "$deploy_user" ]]; then
        detect_site_account || exit 1
    fi
    if ! deploy_user_id="$(id -u "$deploy_user" 2>/dev/null)" || [[ "$deploy_user_id" -eq 0 ]]; then
        printf '请通过 DEPLOY_USER 指定已存在的非 root 站点账号（当前：%s）。\n' "$deploy_user" >&2
        exit 1
    fi
    printf '当前以 root 启动，切换到站点账号 %s 执行部署。\n' "$deploy_user"
    if command -v runuser >/dev/null 2>&1; then
        exec runuser -u "$deploy_user" -- env PATH="$PATH" DEPLOY_USER="$deploy_user" DEPLOY_NODE_BIN="${DEPLOY_NODE_BIN:-}" DEPLOY_PHP="$deploy_php" DEPLOY_COMPOSER="${DEPLOY_COMPOSER:-composer}" bash "$deploy_directory/deploy.sh" "$@"
    elif command -v sudo >/dev/null 2>&1; then
        exec sudo -n -H -u "$deploy_user" -- env PATH="$PATH" DEPLOY_USER="$deploy_user" DEPLOY_NODE_BIN="${DEPLOY_NODE_BIN:-}" DEPLOY_PHP="$deploy_php" DEPLOY_COMPOSER="${DEPLOY_COMPOSER:-composer}" bash "$deploy_directory/deploy.sh" "$@"
    else
        printf '未找到 runuser 或 sudo，请先切换到站点账号再执行本脚本。\n' >&2
        exit 1
    fi
fi

deploy_step='部署前检查'
deploy_maintenance=0

deployment_failed() {
    local deploy_status="$?"
    printf '\n部署失败：%s（退出码 %s）。\n' "$deploy_step" "$deploy_status" >&2
    if [[ "$deploy_maintenance" -eq 1 ]]; then
        printf '站点保持维护模式。修复上述错误后重新执行 bash deploy.sh；脚本不会自动回滚数据库。\n' >&2
    fi
    exit "$deploy_status"
}
trap deployment_failed ERR

run_step() {
    deploy_step="$1"
    shift
    printf '\n[%s]\n' "$deploy_step"
    "$@"
}

deploy_current_uid="$(id -u)"
deploy_current_user="$(id -un)"
if [[ -n "${DEPLOY_USER:-}" ]]; then
    if ! deploy_requested_uid="$(id -u "$DEPLOY_USER" 2>/dev/null)" || [[ "$deploy_requested_uid" != "$deploy_current_uid" ]]; then
        printf '当前账号 %s 与指定的站点账号 %s 不一致，请以 root 启动以完成账号切换。\n' "$deploy_current_user" "$DEPLOY_USER" >&2
        exit 1
    fi
fi
printf '部署账号：%s（UID %s）。\n' "$deploy_current_user" "$deploy_current_uid"
deploy_npm_cache="$deploy_directory/storage/app/deploy-cache/npm"
export npm_config_cache="$deploy_npm_cache"
export NPM_CONFIG_CACHE="$deploy_npm_cache"
printf 'npm 缓存：%s。\n' "$deploy_npm_cache"
run_step '选择站点 Node/npm 工具' select_node_tools

for deploy_command in "$deploy_php" "${DEPLOY_COMPOSER:-composer}" git node npm flock find; do
    if ! command -v "$deploy_command" >/dev/null 2>&1; then
        printf '缺少命令：%s\n' "$deploy_command" >&2
        exit 1
    fi
done
run_step '检查 Node.js 构建版本' check_node_runtime
printf 'npm 命令：%s。\n' "$(command -v npm)"
deploy_composer="$(command -v "${DEPLOY_COMPOSER:-composer}")"
if [[ ! -r "$deploy_composer" ]]; then
    printf '部署账号无法读取 Composer 文件：%s\n' "$deploy_composer" >&2
    exit 1
fi
if [[ ! -f .env || ! -f vendor/autoload.php ]]; then
    printf '本脚本用于已有站点更新，请先完成 .env、依赖及 APP_KEY 的首次配置。\n' >&2
    exit 1
fi

if ! run_step '检查部署目录权限' check_deployment_permissions; then
    printf '账号 %s 的目录权限预检失败，请修复上述路径的属主、权限或 ACL 后重试。\n' "$deploy_current_user" >&2
    exit 1
fi
deploy_step='检查 Git 目录权限'
deploy_git_directory="$(git rev-parse --absolute-git-dir)"
deploy_git_common_directory="$(git rev-parse --git-common-dir)"
check_tree_access "$deploy_git_directory" directories
if [[ "$deploy_git_common_directory" != "$deploy_git_directory" && "$deploy_directory/$deploy_git_common_directory" != "$deploy_git_directory" ]]; then
    check_tree_access "$deploy_git_common_directory" directories
fi
check_tracked_directory_permissions
deploy_step='检查 Git 工作区'
deploy_git_status="$(GIT_OPTIONAL_LOCKS=0 git status --porcelain)"
if [[ -n "$deploy_git_status" ]]; then
    printf '工作区存在未提交文件，部署已停止；请先处理改动，脚本不会覆盖或清理文件。\n' >&2
    exit 1
fi
deploy_previous_commit="$(git rev-parse HEAD)"
if [[ "$deploy_pull" -eq 1 ]]; then
    git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' >/dev/null
fi
printf '账号与目录权限预检通过。\n'
if [[ "$deploy_check" -eq 1 ]]; then
    exit 0
fi

mkdir -p storage/framework
exec 9>storage/framework/deploy.lock
if ! flock -n 9; then
    printf '另一个部署正在执行，请等待其完成。\n' >&2
    exit 1
fi
run_step '准备站点 npm 缓存' mkdir -p -m 700 "$deploy_npm_cache"

run_step '进入维护模式' "$deploy_php" artisan down --retry=60 --no-interaction
deploy_maintenance=1
run_step '清除旧配置缓存' "$deploy_php" artisan config:clear --no-interaction
if [[ "$deploy_pull" -eq 1 ]]; then
    run_step '更新当前分支代码' git pull --ff-only
fi
run_step '安装生产 PHP 依赖' "$deploy_php" "$deploy_composer" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
run_step '安装前端构建依赖' npm ci --include=dev --engine-strict
run_step '构建前端资源' npm run build
run_step '更新 Filament 资源' "$deploy_php" artisan filament:upgrade --no-interaction
run_step '缓存当前环境配置' "$deploy_php" artisan config:cache --no-interaction
run_step '检查 API 与插件版本同步' "$deploy_php" artisan plugins:check "--base=$deploy_previous_commit" --no-interaction
run_step '预检全部插件发布' "$deploy_php" artisan plugins:sync --dry-run --no-interaction
run_step '执行增量数据库迁移' "$deploy_php" artisan migrate --force --no-interaction
run_step '缓存路由' "$deploy_php" artisan route:cache --no-interaction
run_step '缓存视图' "$deploy_php" artisan view:cache --no-interaction
run_step '发布全部插件及下载索引' "$deploy_php" artisan plugins:sync --no-interaction
run_step '通知队列进程重启' "$deploy_php" artisan queue:restart --no-interaction
run_step '恢复站点访问' "$deploy_php" artisan up --no-interaction
deploy_maintenance=0
printf '\n部署完成：%s\n' "$(git rev-parse --short HEAD)"
