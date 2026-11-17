#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'HELP'
用法：bash deploy.sh [--no-pull]

默认快速前进更新当前 Git 分支，然后安装依赖、构建前端、迁移、发布插件、刷新缓存并重启队列。
--no-pull  部署已检出的代码，适用于 CI 或重试失败的部署。

可选环境变量：DEPLOY_PHP（默认 php）、DEPLOY_COMPOSER（默认 composer 的 PHP/PHAR 文件）。
以站点运行账号执行；现有 .env、APP_KEY、数据库和 OSS 配置需已就绪。
HELP
}

deploy_pull=1
for deploy_argument in "$@"; do
    case "$deploy_argument" in
        --no-pull) deploy_pull=0 ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done

deploy_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$deploy_directory"
deploy_php="${DEPLOY_PHP:-php}"
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

for deploy_command in "$deploy_php" "${DEPLOY_COMPOSER:-composer}" git npm flock; do
    if ! command -v "$deploy_command" >/dev/null 2>&1; then
        printf '缺少命令：%s\n' "$deploy_command" >&2
        exit 1
    fi
done
deploy_composer="$(command -v "${DEPLOY_COMPOSER:-composer}")"
if [[ ! -f .env || ! -f vendor/autoload.php ]]; then
    printf '本脚本用于已有站点更新，请先完成 .env、依赖及 APP_KEY 的首次配置。\n' >&2
    exit 1
fi

mkdir -p storage/framework
exec 9>storage/framework/deploy.lock
if ! flock -n 9; then
    printf '另一个部署正在执行，请等待其完成。\n' >&2
    exit 1
fi
if [[ -n "$(git status --porcelain)" ]]; then
    printf '工作区存在未提交文件，部署已停止；请先处理改动，脚本不会覆盖或清理文件。\n' >&2
    exit 1
fi
deploy_previous_commit="$(git rev-parse HEAD)"
if [[ "$deploy_pull" -eq 1 ]]; then
    git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' >/dev/null
fi

run_step '进入维护模式' "$deploy_php" artisan down --retry=60 --no-interaction
deploy_maintenance=1
run_step '清除旧配置缓存' "$deploy_php" artisan config:clear --no-interaction
if [[ "$deploy_pull" -eq 1 ]]; then
    run_step '更新当前分支代码' git pull --ff-only
fi
run_step '安装生产 PHP 依赖' "$deploy_php" "$deploy_composer" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
run_step '安装前端构建依赖' npm ci --include=dev
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
