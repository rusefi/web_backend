#!/usr/bin/env bash
set -euo pipefail

repo=$(cd "$(dirname "$0")/.." && pwd)
command -v httpd >/dev/null || { echo 'httpd is required' >&2; exit 77; }
command -v php-cgi >/dev/null || { echo 'php-cgi is required' >&2; exit 77; }
command -v curl >/dev/null || { echo 'curl is required' >&2; exit 77; }
command -v git >/dev/null || { echo 'git is required' >&2; exit 77; }

tmp=$(mktemp -d /tmp/opencode/ini-rollback-apache.XXXXXX)
docroot="$tmp/www"
git -C "$repo" archive dd618783a71966079d0056f0d3656effb8334cc8 www | tar -x -C "$tmp"

# Apply only the rollback files, as the production tar overlay does. Do not
# delete the stale R2 handler or any other files from the production snapshot.
cp "$repo/www/online/ini/.htaccess" "$docroot/online/ini/.htaccess"
cp "$repo/www/online/ini/index.php" "$docroot/online/ini/index.php"
cp "$repo/www/ini_test/.htaccess" "$docroot/ini_test/.htaccess"
cp "$repo/www/ini_test/index.php" "$docroot/ini_test/index.php"

# The site's unrelated root rule forces HTTPS; this isolated HTTP-only server
# tests the unchanged endpoint .htaccess files without needing a TLS fixture.
cat > "$docroot/.htaccess" <<'HTACCESS'
RewriteEngine On
HTACCESS

mkdir -p "$docroot/online/ini/test-rollback/release/2024/01/02/board" \
	"$docroot/online/ini/test-rollback/release/2026/01/02/board" \
	"$docroot/online/ini/test-rollback/release/2024/01/02/NUCLEO-F7" \
	"$docroot/online/ini/test-rollback/release/2024/01/02/discovery-board" \
	"$docroot/online/ini/test-rollback/release/2024/01/02/F407-Discovery"
printf 'older static bytes\0\n' > "$docroot/online/ini/test-rollback/release/2024/01/02/board/old.ini"
printf 'newer static bytes\n' > "$docroot/online/ini/test-rollback/release/2026/01/02/board/new.ini"
printf 'nucleo static bytes\n' > "$docroot/online/ini/test-rollback/release/2024/01/02/NUCLEO-F7/old.ini"
printf 'discovery static bytes\n' > "$docroot/online/ini/test-rollback/release/2024/01/02/discovery-board/old.ini"
printf 'f407 static bytes\n' > "$docroot/online/ini/test-rollback/release/2024/01/02/F407-Discovery/old.ini"

# If any PHP path loads production R2 configuration, fail this isolated test.
cat > "$docroot/online/config_rusefi.php" <<'PHP'
<?php
throw new RuntimeException('synthetic R2 config was loaded');
PHP

port=$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0", $n, $e); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1);')
uid=$(id -u)
gid=$(id -g)
cat > "$tmp/httpd.conf" <<EOF
ServerRoot /etc/httpd
ServerName localhost
TypesConfig /etc/mime.types
Listen 127.0.0.1:$port
PidFile $tmp/httpd.pid
DefaultRuntimeDir $tmp
LoadModule mpm_event_module modules/mod_mpm_event.so
LoadModule unixd_module modules/mod_unixd.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule actions_module modules/mod_actions.so
LoadModule alias_module modules/mod_alias.so
LoadModule cgid_module modules/mod_cgid.so
User #$uid
Group #$gid
DocumentRoot "$docroot"
<Directory "$docroot">
  Require all granted
  AllowOverride All
  Options ExecCGI FollowSymLinks
</Directory>
ScriptAlias /php-cgi /usr/bin/php-cgi
Action application/x-httpd-php /php-cgi
AddHandler application/x-httpd-php .php
ErrorLog "$tmp/error.log"
LogLevel warn
EOF

httpd -f "$tmp/httpd.conf" -DFOREGROUND >"$tmp/stdout.log" 2>&1 &
server_pid=$!
cleanup() {
	if [[ -n ${server_pid:-} ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
	rm -rf "$tmp"
}
trap cleanup EXIT
for _ in $(seq 1 50); do
	if curl -sS "http://127.0.0.1:$port/" -o /dev/null 2>/dev/null; then break; fi
	sleep 0.1
done
if ! kill -0 "$server_pid" 2>/dev/null; then cat "$tmp/stdout.log" "$tmp/error.log" >&2; exit 1; fi

request() {
	local expected=$1
	shift
	local url=${@: -1}
	local code
	code=$(curl -sS -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' "${@:1:$#-1}" "$url")
	if [[ "$code" != "$expected" ]]; then
		echo "expected HTTP $expected, got $code for $url" >&2
		cat "$tmp/headers" "$tmp/body" "$tmp/error.log" >&2
		exit 1
	fi
}

base="http://127.0.0.1:$port"
for path in \
	test-rollback/release/2024/01/02/board/old.ini \
	test-rollback/release/2026/01/02/board/new.ini \
	test-rollback/release/2024/01/02/NUCLEO-F7/old.ini \
	test-rollback/release/2024/01/02/discovery-board/old.ini \
	test-rollback/release/2024/01/02/F407-Discovery/old.ini; do
	request 200 "$base/online/ini/$path"
	cmp -s "$tmp/body" "$docroot/online/ini/$path"
	if grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null; then
		echo "native static URL unexpectedly used PHP: $path" >&2
		exit 1
	fi
	head_code=$(curl -sS -I -D "$tmp/headers" -o /dev/null -w '%{http_code}' "$base/online/ini/$path")
	[[ "$head_code" == 200 ]]
	length=$(wc -c < "$docroot/online/ini/$path" | tr -d '[:space:]')
	grep -iFx "Content-Length: $length" <(tr -d '\r' < "$tmp/headers") >/dev/null
	request 206 -H 'Range: bytes=0-3' "$base/online/ini/$path"
	head -c 4 "$docroot/online/ini/$path" > "$tmp/expected-range"
	cmp -s "$tmp/body" "$tmp/expected-range"
	request 200 "$base/online/ini/index.php?path=$path"
	cmp -s "$tmp/body" "$docroot/online/ini/$path"
	grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
	head_code=$(curl -sS -I -D "$tmp/headers" -o /dev/null -w '%{http_code}' "$base/online/ini/index.php?path=$path")
	[[ "$head_code" == 200 ]]
	grep -iF 'Content-Type: application/octet-stream' "$tmp/headers" >/dev/null
	grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
done

request 404 "$base/online/ini/test-rollback/release/2024/01/02/board/missing.ini"
if grep -iF 'Location:' "$tmp/headers" >/dev/null; then echo 'missing static INI redirected' >&2; exit 1; fi
request 404 "$base/online/ini/index.php?path=test-rollback/release/2024/01/02/board/missing.ini"
if grep -iF 'Location:' "$tmp/headers" >/dev/null; then echo 'missing direct INI redirected' >&2; exit 1; fi
request 400 "$base/online/ini/index.php?path=../config_rusefi.php"
request 405 -X POST "$base/online/ini/index.php?path=test-rollback/release/2024/01/02/board/old.ini"

request 302 "$base/ini_test/test-rollback/release/2024/01/02/board/old.ini"
grep -iF '/online/ini/test-rollback/release/2024/01/02/board/old.ini' "$tmp/headers" >/dev/null
request 302 "$base/ini_test/index.php?path=test-rollback/release/2024/01/02/board/old.ini"
grep -iF '/online/ini/test-rollback/release/2024/01/02/board/old.ini' "$tmp/headers" >/dev/null

if grep -F 'synthetic R2 config was loaded' "$tmp/error.log" "$tmp/stdout.log" >/dev/null; then
	echo 'a rollback endpoint loaded the R2 configuration fixture' >&2
	exit 1
fi

echo 'production-overlay rollback INI routing checks passed'
