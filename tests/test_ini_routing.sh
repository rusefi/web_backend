#!/usr/bin/env bash
set -euo pipefail

repo=$(cd "$(dirname "$0")/.." && pwd)
command -v httpd >/dev/null || { echo 'httpd is required' >&2; exit 77; }
command -v php-cgi >/dev/null || { echo 'php-cgi is required' >&2; exit 77; }
command -v curl >/dev/null || { echo 'curl is required' >&2; exit 77; }
config_path="$repo/www/online/config_rusefi.php"
if [[ -e "$config_path" || -L "$config_path" ]]; then
	echo 'refusing to replace existing R2 config in Apache test' >&2
	exit 77
fi

tmp=$(mktemp -d /tmp/opencode/ini-apache-test.XXXXXX)
prefix="apache-test-$RANDOM-$$"
base="$repo/www/online/ini/$prefix"
config_created=0
port=$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0", $n, $e); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1);')
cleanup() {
	if [[ -n ${server_pid:-} ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
	if [[ "$config_created" == 1 ]]; then rm -f "$config_path"; fi
	rm -f "$base/release/2026/01/02/NUCLEO-F7/2026.ini" "$base/release/2024/01/02/NUCLEO-F7/old.ini" "$base/release/2024/01/02/discoveryfoo/old.ini" "$base/release/2024/01/02/myNucleoBoard/old.ini" "$base/release/2024/01/02/F407-Discovery/old.ini" "$base/release/2024/01/02/board/old.ini"
	rmdir "$base/release/2026/01/02/NUCLEO-F7" "$base/release/2026/01/02" "$base/release/2026/01" "$base/release/2026" "$base/release/2024/01/02/NUCLEO-F7" "$base/release/2024/01/02/discoveryfoo" "$base/release/2024/01/02/myNucleoBoard" "$base/release/2024/01/02/F407-Discovery" "$base/release/2024/01/02/board" "$base/release/2024/01/02" "$base/release/2024/01" "$base/release/2024" "$base/release" "$base" 2>/dev/null || true
	rm -rf "$tmp"
}
trap cleanup EXIT

mkdir -p "$base/release/2026/01/02/NUCLEO-F7" "$base/release/2024/01/02/NUCLEO-F7" "$base/release/2024/01/02/discoveryfoo" "$base/release/2024/01/02/myNucleoBoard" "$base/release/2024/01/02/F407-Discovery" "$base/release/2024/01/02/board"
printf 'unchanged 2026 static\0bytes\n' > "$base/release/2026/01/02/NUCLEO-F7/2026.ini"
printf 'nucleo static\0bytes\n' > "$base/release/2024/01/02/NUCLEO-F7/old.ini"
printf 'local discovery fallback\n' > "$base/release/2024/01/02/discoveryfoo/old.ini"
printf 'nucleo substring static\n' > "$base/release/2024/01/02/myNucleoBoard/old.ini"
printf 'f407 discovery eligible\n' > "$base/release/2024/01/02/F407-Discovery/old.ini"
printf 'older local fallback\n' > "$base/release/2024/01/02/board/old.ini"

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
DocumentRoot "$repo/www"
<Directory "$repo/www">
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
for _ in $(seq 1 50); do
	if curl -fsS "http://127.0.0.1:$port/" -o /dev/null 2>/dev/null; then break; fi
	sleep 0.1
done
if ! kill -0 "$server_pid" 2>/dev/null; then cat "$tmp/stdout.log" "$tmp/error.log" >&2; exit 1; fi

request() {
	local expected=$1 url=$2
	local code
	code=$(curl -sS -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' "$url")
	if [[ "$code" != "$expected" ]]; then
		echo "expected HTTP $expected, got $code for $url" >&2
		cat "$tmp/headers" "$tmp/body" "$tmp/error.log" >&2
		exit 1
	fi
}

request 200 "http://127.0.0.1:$port/online/ini/$prefix/release/2026/01/02/NUCLEO-F7/2026.ini"
cmp -s "$tmp/body" "$base/release/2026/01/02/NUCLEO-F7/2026.ini"
if grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null; then echo '2026 key unexpectedly used PHP handler' >&2; exit 1; fi
range_code=$(curl -sS -H 'Range: bytes=0-8' -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' "http://127.0.0.1:$port/online/ini/$prefix/release/2026/01/02/NUCLEO-F7/2026.ini")
[[ "$range_code" == 206 ]]
printf 'unchanged' > "$tmp/expected"
cmp -s "$tmp/body" "$tmp/expected"
: > "$tmp/body"
head_code=$(curl -sS -I -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' "http://127.0.0.1:$port/online/ini/$prefix/release/2026/01/02/NUCLEO-F7/2026.ini")
[[ "$head_code" == 200 ]]
static_size=$(wc -c < "$base/release/2026/01/02/NUCLEO-F7/2026.ini" | tr -d ' ')
if ! grep -iF "Content-Length: $static_size" "$tmp/headers" >/dev/null; then cat "$tmp/headers" "$tmp/error.log" >&2; exit 1; fi
if grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null; then echo '2026 HEAD unexpectedly used PHP handler' >&2; exit 1; fi
request 200 "http://127.0.0.1:$port/online/ini/index.php?path=$prefix/release/2026/01/02/NUCLEO-F7/2026.ini"
cmp -s "$tmp/body" "$base/release/2026/01/02/NUCLEO-F7/2026.ini"
grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
: > "$tmp/body"
head_code=$(curl -sS -I -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' "http://127.0.0.1:$port/online/ini/index.php?path=$prefix/release/2026/01/02/NUCLEO-F7/2026.ini")
[[ "$head_code" == 200 ]]
grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
request 200 "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/NUCLEO-F7/old.ini"
cmp -s "$tmp/body" "$base/release/2024/01/02/NUCLEO-F7/old.ini"
grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
range_code=$(curl -sS -H 'Range: bytes=0-5' -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/NUCLEO-F7/old.ini")
[[ "$range_code" == 200 ]]
cmp -s "$tmp/body" "$base/release/2024/01/02/NUCLEO-F7/old.ini"
request 200 "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/discoveryfoo/old.ini"
cmp -s "$tmp/body" "$base/release/2024/01/02/discoveryfoo/old.ini"
grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
request 200 "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/myNucleoBoard/old.ini"
cmp -s "$tmp/body" "$base/release/2024/01/02/myNucleoBoard/old.ini"
grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
request 200 "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/F407-Discovery/old.ini"
cmp -s "$tmp/body" "$base/release/2024/01/02/F407-Discovery/old.ini"
grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
request 200 "http://127.0.0.1:$port/online/ini/index.php?path=$prefix/release/2024/01/02/NUCLEO-F7/old.ini"
cmp -s "$tmp/body" "$base/release/2024/01/02/NUCLEO-F7/old.ini"
grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
request 200 "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/board/old.ini"
cmp -s "$tmp/body" "$base/release/2024/01/02/board/old.ini"
grep -iF 'Content-Type: application/octet-stream' "$tmp/headers" >/dev/null
request 200 "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/board/old.ini?path=$prefix/release/2026/01/02/NUCLEO-F7/2026.ini"
cmp -s "$tmp/body" "$base/release/2024/01/02/board/old.ini"
request 302 "http://127.0.0.1:$port/ini_test/$prefix/release/2024/01/02/board/old.ini"
grep -iF "/online/ini/$prefix/release/2024/01/02/board/old.ini" "$tmp/headers" >/dev/null
request 302 "http://127.0.0.1:$port/ini_test/index.php?path=$prefix/release/2024/01/02/board/old.ini"
grep -iF "/online/ini/$prefix/release/2024/01/02/board/old.ini" "$tmp/headers" >/dev/null

cat > "$config_path" <<'PHP'
<?php
throw new RuntimeException('synthetic R2 config failure');
PHP
config_created=1
request 200 "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/board/old.ini"
cmp -s "$tmp/body" "$base/release/2024/01/02/board/old.ini"
if grep -F 'synthetic R2 config failure' "$tmp/body" >/dev/null; then echo 'config exception leaked into response' >&2; exit 1; fi
request 502 "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/board/missing.ini"
if grep -F 'synthetic R2 config failure' "$tmp/body" >/dev/null; then echo 'config exception leaked into error response' >&2; exit 1; fi
: > "$tmp/body"
head_code=$(curl -sS -I -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' "http://127.0.0.1:$port/online/ini/$prefix/release/2024/01/02/board/old.ini")
[[ "$head_code" == 200 ]]
grep -iF 'X-Powered-By: PHP' "$tmp/headers" >/dev/null
request 200 "http://127.0.0.1:$port/online/ini/index.php?path=$prefix/release/2026/01/02/NUCLEO-F7/2026.ini"
cmp -s "$tmp/body" "$base/release/2026/01/02/NUCLEO-F7/2026.ini"

echo 'isolated Apache INI routing checks passed'
