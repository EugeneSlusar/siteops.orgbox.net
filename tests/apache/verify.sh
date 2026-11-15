#!/usr/bin/env bash
# Проверка .htaccess пакета site.ops на настоящем Apache + PHP (CGI) с заглушкой Битрикса.
# Проверяет: адреса без .php и без слэша (POST не должен уходить в редирект), закрытие кода и резервных копий,
# передачу заголовка Authorization в PHP, документы обнаружения OAuth.
# Нужны: apache2 (или httpd), php-cgi, curl, unzip, php. Запуск: bash tests/apache/verify.sh
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${APACHE_TEST_PORT:-8091}"
BASE="http://127.0.0.1:$PORT"
APACHE="$(command -v apache2 || command -v httpd || ([ -x /usr/sbin/apache2 ] && echo /usr/sbin/apache2) || true)"
MODS="${APACHE_MODULES:-/usr/lib/apache2/modules}"
PHPCGI="$(command -v php-cgi || true)"
[ -n "$APACHE" ] && [ -n "$PHPCGI" ] || { echo "Нужны apache2 и php-cgi"; exit 2; }

WORK="$(mktemp -d "${RUNNER_TEMP:-/var/tmp}/siteops-apache.XXXXXX")"
DOCROOT="$WORK/www"; mkdir -p "$DOCROOT" "$WORK/logs" "$WORK/run" "$WORK/cgi" "$WORK/dist"
cleanup() { [ -f "$WORK/run/apache.pid" ] && kill "$(cat "$WORK/run/apache.pid")" 2>/dev/null; sleep 0.5; rm -rf "$WORK"; }
trap cleanup EXIT

# 1. Собранный пакет site.ops + заглушка Битрикса
php "$ROOT/tools/build-release.php" "$WORK/dist" > /dev/null || exit 2
(cd "$DOCROOT" && unzip -q "$WORK"/dist/site.ops-*.zip)
cp -a "$ROOT/tests/apache/stub/bitrix" "$DOCROOT/bitrix"
echo '<?php echo "root";' > "$DOCROOT/index.php"
# Необязательный фрагмент для корневого .well-known (как в docs/INSTALL.md)
cp "$ROOT/standalone/root-well-known.htaccess" "$DOCROOT/.htaccess"
# Имитация рабочей папки обновлений: .htaccess как у установщика и копия точки входа
mkdir -p "$DOCROOT/site.ops/_updates/backup-0.0.1-x/mcp"
cp "$DOCROOT/site.ops/mcp/index.php" "$DOCROOT/site.ops/_updates/backup-0.0.1-x/mcp/index.php"
php -r 'preg_match("/<<<\x27HTACCESS\x27\n(.*?)\nHTACCESS\);/s", file_get_contents($argv[1]), $m); file_put_contents($argv[2], $m[1]."\n");' \
  "$ROOT/modules/orgbox.siteops/lib/OrgBox/SiteOps/Update/ZipInstaller.php" "$DOCROOT/site.ops/_updates/.htaccess"
# Диагностика Authorization (только в этой копии)
echo '<?php header("Content-Type: text/plain"); echo "AUTH=" . ($_SERVER["HTTP_AUTHORIZATION"] ?? "-") . " REDIRECT=" . ($_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "-");' > "$DOCROOT/site.ops/mcp/echo.php"

# Настройки сайта: MCP и OAuth включены, статический токен sop_statictoken
mkdir -p "$DOCROOT/stub-state"
php -r '$h=hash("sha256","sop_statictoken"); file_put_contents($argv[1], json_encode([
  "orgbox.siteops|s2|mcp_enabled"=>"Y","orgbox.siteops|s2|oauth_enabled"=>"Y","orgbox.siteops|s2|token_hash"=>$h,
  "orgbox.siteops|s2|public_url"=>$argv[2],"orgbox.siteops|s2|rate_limit"=>"1000"]));' "$DOCROOT/stub-state/options.json" "$BASE"
chmod -R a+rwX "$WORK"; chmod a+rx "$WORK" 2>/dev/null

ln -s "$PHPCGI" "$WORK/cgi/php"
USERS=""; [ "$(id -u)" = "0" ] && USERS=$'User www-data\nGroup www-data'
cat > "$WORK/apache.conf" <<CONF
ServerRoot "/etc/apache2"
PidFile $WORK/run/apache.pid
ServerName 127.0.0.1
Listen 127.0.0.1:$PORT
$USERS
ErrorLog $WORK/logs/error.log
LogLevel warn
LoadModule mpm_prefork_module $MODS/mod_mpm_prefork.so
LoadModule authz_core_module $MODS/mod_authz_core.so
LoadModule dir_module $MODS/mod_dir.so
LoadModule mime_module $MODS/mod_mime.so
LoadModule rewrite_module $MODS/mod_rewrite.so
LoadModule alias_module $MODS/mod_alias.so
LoadModule actions_module $MODS/mod_actions.so
LoadModule cgi_module $MODS/mod_cgi.so
LoadModule env_module $MODS/mod_env.so
TypesConfig /etc/mime.types
DocumentRoot "$DOCROOT"
DirectoryIndex index.php index.html
<Directory "$DOCROOT">
    AllowOverride All
    Require all granted
    Options +FollowSymLinks +ExecCGI
</Directory>
# Как в стандартной конфигурации дистрибутивов: .ht* недоступны
<FilesMatch "^\.ht">
    Require all denied
</FilesMatch>
AddHandler php-script .php
Action php-script /php-bin/php virtual
ScriptAlias /php-bin/ $WORK/cgi/
<Directory "$WORK/cgi">
    Require all granted
</Directory>
CONF
"$APACHE" -f "$WORK/apache.conf" -t > "$WORK/syntax.txt" 2>&1 || { cat "$WORK/syntax.txt"; exit 2; }
"$APACHE" -f "$WORK/apache.conf" || { echo "Apache не запустился"; tail -5 "$WORK/logs/error.log"; exit 2; }
for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "$BASE/" && break; sleep 0.3; done

FAIL=0; PASS=0
check() { # описание, условие (0 — успех)
  if [ "$2" = "0" ]; then PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; else FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; fi
}
req() { # метод путь [curl-аргументы...] → код в $CODE, тело в $BODY, заголовки в $HDRS, редирект в $LOC
  local m=$1 p=$2; shift 2
  HDRS="$(curl -s -D - -o "$WORK/body.txt" -X "$m" "$@" "$BASE$p")"
  CODE="$(printf '%s' "$HDRS" | head -1 | awk '{print $2}')"
  BODY="$(cat "$WORK/body.txt")"
  LOC="$(printf '%s' "$HDRS" | grep -i '^location:' | head -1 | tr -d '\r')"
}
JSON=(-H 'Content-Type: application/json')
LIST='{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

echo "== адреса точек входа: без редиректа и без .php"
req POST /site.ops/mcp "${JSON[@]}" -d "$LIST";       check "POST /site.ops/mcp без токена — 401, не редирект" "$([ "$CODE" = 401 ] && [ -z "$LOC" ]; echo $?)"
check "401 содержит resource_metadata со ссылкой на документ OAuth" "$(printf '%s' "$HDRS" | grep -qi 'resource_metadata="http://127.0.0.1:'$PORT'/site.ops/oauth/.well-known/oauth-protected-resource/"'; echo $?)"
req POST /site.ops/mcp "${JSON[@]}" -H 'Authorization: Bearer sop_statictoken' -d "$LIST"
check "POST /site.ops/mcp с токеном — 200 и список инструментов (Authorization дошёл до PHP)" "$([ "$CODE" = 200 ] && printf '%s' "$BODY" | grep -q iblock_list; echo $?)"
req POST /site.ops/mcp/ "${JSON[@]}" -H 'Authorization: Bearer sop_statictoken' -d "$LIST"
check "POST /site.ops/mcp/ (со слэшем) — 200" "$([ "$CODE" = 200 ] && printf '%s' "$BODY" | grep -q iblock_list; echo $?)"
req POST /site.ops/mcp "${JSON[@]}" -H 'Authorization: Bearer sop_wrong' -d "$LIST"
check "неверный токен — 401" "$([ "$CODE" = 401 ]; echo $?)"
req POST /site.ops/mcp -H 'Content-Type: application/json' -H 'X-SiteOps-Token: sop_statictoken' -d "$LIST"
check "токен в X-SiteOps-Token — 200" "$([ "$CODE" = 200 ]; echo $?)"
req GET /site.ops/mcp -H 'Authorization: Bearer sop_statictoken'
check "GET /site.ops/mcp — 405 (только POST), не редирект" "$([ "$CODE" = 405 ] && [ -z "$LOC" ]; echo $?)"
req POST /site.ops/api "${JSON[@]}" -d '{}'
check "POST /site.ops/api — ответ PHP в JSON (API отключён), не редирект" "$([ -z "$LOC" ] && printf '%s' "$BODY" | grep -q '"ok":false'; echo $?)"
req GET /site.ops/admin;            check "GET /site.ops/admin — дошло до страницы администратора" "$([ "$CODE" = 200 ] && [ "$BODY" = ADMIN-STUB ]; echo $?)"
req GET /site.ops/admin/;           check "GET /site.ops/admin/ — то же" "$([ "$CODE" = 200 ] && [ "$BODY" = ADMIN-STUB ]; echo $?)"
req GET '/site.ops/oauth/authorize?client_id=x'; check "GET /site.ops/oauth/authorize — дошло до страницы согласия" "$([ "$CODE" = 200 ] && [ "$BODY" = ADMIN-STUB ] && [ -z "$LOC" ]; echo $?)"

echo "== OAuth"
for p in oauth-authorization-server openid-configuration; do
  for suffix in "" "/"; do
    req GET "/site.ops/oauth/.well-known/$p$suffix"
    check "GET .well-known/$p$suffix — 200, issuer совпадает, без редиректа" "$([ "$CODE" = 200 ] && [ -z "$LOC" ] && printf '%s' "$BODY" | grep -q '"issuer":"http://127.0.0.1:'$PORT'/site.ops/oauth"'; echo $?)"
  done
done
for suffix in "" "/"; do
  req GET "/site.ops/oauth/.well-known/oauth-protected-resource$suffix"
  check "GET .well-known/oauth-protected-resource$suffix — 200, resource = /site.ops/mcp" "$([ "$CODE" = 200 ] && printf '%s' "$BODY" | grep -q '"resource":"http://127.0.0.1:'$PORT'/site.ops/mcp"'; echo $?)"
done
check "документы обнаружения отдаются с CORS" "$(printf '%s' "$HDRS" | grep -qi '^access-control-allow-origin: \*'; echo $?)"
echo "== корневой .well-known (необязательный фрагмент из standalone/root-well-known.htaccess)"
for p in /.well-known/oauth-authorization-server /.well-known/oauth-authorization-server/site.ops/oauth /.well-known/openid-configuration/site.ops/oauth; do
  req GET "$p"; check "GET $p — 200, issuer" "$([ "$CODE" = 200 ] && printf '%s' "$BODY" | grep -q '"issuer":"http://127.0.0.1:'$PORT'/site.ops/oauth"'; echo $?)"
done
for p in /.well-known/oauth-protected-resource /.well-known/oauth-protected-resource/site.ops/mcp; do
  req GET "$p"; check "GET $p — 200, resource" "$([ "$CODE" = 200 ] && printf '%s' "$BODY" | grep -q '"resource":"http://127.0.0.1:'$PORT'/site.ops/mcp"'; echo $?)"
done

echo "== регистрация и токен"
req POST /site.ops/oauth/register "${JSON[@]}" -d '{"client_name":"Test","redirect_uris":["http://localhost:7777/cb"]}'
check "POST /site.ops/oauth/register (без слэша) — 201 и client_id" "$([ "$CODE" = 201 ] && printf '%s' "$BODY" | grep -q '"client_id":"sopc_'; echo $?)"
req POST /site.ops/oauth/register/ "${JSON[@]}" -d '{"client_name":"Test","redirect_uris":["http://localhost:7777/cb"]}'
check "POST /site.ops/oauth/register/ (со слэшем) — 201" "$([ "$CODE" = 201 ]; echo $?)"
req POST /site.ops/oauth/token -d 'grant_type=authorization_code&client_id=sopc_unknown'
check "POST /site.ops/oauth/token (без слэша) — 401 invalid_client в JSON" "$([ "$CODE" = 401 ] && printf '%s' "$BODY" | grep -q invalid_client && [ -z "$LOC" ]; echo $?)"
req POST /site.ops/oauth/token/ -d 'grant_type=authorization_code&client_id=sopc_unknown'
check "POST /site.ops/oauth/token/ — 401 invalid_client" "$([ "$CODE" = 401 ] && printf '%s' "$BODY" | grep -q invalid_client; echo $?)"
req POST /site.ops/oauth/revoke -d 'token=x'
check "POST /site.ops/oauth/revoke — 200" "$([ "$CODE" = 200 ] && [ -z "$LOC" ]; echo $?)"

echo "== закрыто от браузера (403)"
for p in /site.ops/ /site.ops /site.ops/lib/OrgBox/SiteOps/Bootstrap.php /site.ops/lib/OrgBox/SiteOps/Admin/settings.view.php \
         /site.ops/autoload.php /site.ops/version.php /site.ops/.htaccess /site.ops/mcp/.htaccess /site.ops/_updates/ /site.ops/_updates \
         /site.ops/_updates/.htaccess /site.ops/_updates/backup-0.0.1-x/mcp/index.php /site.ops/_updates/backup-0.0.1-x/mcp/ \
         /site.ops/mcp.php /site.ops/admin.php /site.ops/api.php; do
  req GET "$p"; check "GET $p — 403" "$([ "$CODE" = 403 ]; echo $?)"
done

echo "== заголовок Authorization"
BODY="$(curl -s -H 'Authorization: Bearer sop_abc' "$BASE/site.ops/mcp/echo.php")"
# На CGI значение приходит как REDIRECT_HTTP_AUTHORIZATION (после внутреннего редиректа); модуль читает оба варианта.
check "Authorization доходит до PHP (HTTP_AUTHORIZATION или REDIRECT_HTTP_AUTHORIZATION)" "$(printf '%s' "$BODY" | grep -q 'Bearer sop_abc'; echo $?)"
req POST /site.ops/mcp "${JSON[@]}" -H 'Authorization: Bearer sop_abc' -d "$LIST"
check "чужой токен через адрес без слэша: 401 «неверный токен», а не «нужен токен» (заголовок получен)" "$([ "$CODE" = 401 ] && printf '%s' "$BODY" | grep -q 'Неверный'; echo $?)"

echo
echo "Итого: $PASS успешно, $FAIL с ошибками"
[ "$FAIL" = 0 ] || { echo "--- журнал Apache (ошибки):"; grep -v 'AH00529\|notice' "$WORK/logs/error.log" | tail -8 | cut -c1-220; }
[ "$FAIL" = 0 ]
