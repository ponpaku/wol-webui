#!/usr/bin/env bash
# HTTP CRUD/CSRF smoke test. Requires PHP with pdo_sqlite, curl and bash.
set -euo pipefail
cd "$(dirname "$0")/.."
php_bin="${PHP_BIN:-php}"
tmp="$(mktemp -d)"
port=18764
cleanup() { kill "${server_pid:-0}" 2>/dev/null || true; rm -rf "$tmp"; }
trap cleanup EXIT
cat > "$tmp/config.php" <<PHP
<?php
return ['database' => '$tmp/wol.sqlite', 'networks' => [
 ['cidr' => '192.168.10.0/24', 'label' => 'Test LAN', 'bind_ip' => '']
]];
PHP
WOL_CONFIG="$tmp/config.php" "$php_bin" -S "127.0.0.1:$port" -t public >"$tmp/server.log" 2>&1 &
server_pid=$!
base="http://127.0.0.1:$port"
for i in {1..30}; do
  if curl --noproxy '*' -fsS -o "$tmp/initial.html" "$base/"; then break; fi
  sleep 0.1
done
grep -q 'マシンはまだ登録されていません' "$tmp/initial.html"
code=$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' "$base/config/local.php")
[[ "$code" == 404 ]]
curl --noproxy '*' -fsS -c "$tmp/cookies" -b "$tmp/cookies" "$base/?view=new" >"$tmp/form.html"
csrf=$(grep -oE 'name="csrf" value="[0-9a-f]{64}"' "$tmp/form.html" | head -1 | cut -d '"' -f4)
[[ ${#csrf} == 64 ]]
# Wrong CSRF must never mutate state.
code=$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' -c "$tmp/cookies" -b "$tmp/cookies" -d 'action=save&csrf=wrong' "$base/")
[[ "$code" == 400 ]]
code=$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' -c "$tmp/cookies" -b "$tmp/cookies" \
  --data-urlencode 'action=save' --data-urlencode "csrf=$csrf" \
  --data-urlencode 'name=Sample node' --data-urlencode 'mac=02:AA:BB:CC:DD:EE' \
  --data-urlencode 'ip=192.168.10.24' --data-urlencode 'network_cidr=192.168.10.0/24' \
  --data-urlencode 'mode=both' --data-urlencode 'port=9' "$base/")
[[ "$code" == 303 ]]
curl --noproxy '*' -fsS -b "$tmp/cookies" "$base/" >"$tmp/with-machine.html"
grep -q 'Sample node' "$tmp/with-machine.html"
count=$("$php_bin" -r '$d=new PDO("sqlite:".$argv[1]); echo $d->query("SELECT COUNT(*) FROM machines")->fetchColumn();' "$tmp/wol.sqlite")
[[ "$count" == 1 ]]
# Input outside the configured subnet is refused.
code=$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode 'action=save' --data-urlencode "csrf=$csrf" \
  --data-urlencode 'name=Disallowed' --data-urlencode 'mac=02:AA:BB:CC:DD:FF' \
  --data-urlencode 'ip=8.8.8.8' --data-urlencode 'network_cidr=192.168.10.0/24' \
  --data-urlencode 'mode=both' --data-urlencode 'port=9' "$base/")
[[ "$code" == 400 ]]
count=$("$php_bin" -r '$d=new PDO("sqlite:".$argv[1]); echo $d->query("SELECT COUNT(*) FROM machines")->fetchColumn();' "$tmp/wol.sqlite")
[[ "$count" == 1 ]]
# Exercise a real delete POST; logged history is not involved.
code=$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode 'action=delete' --data-urlencode "csrf=$csrf" --data-urlencode 'id=1' "$base/")
[[ "$code" == 303 ]]
curl --noproxy '*' -fsS -b "$tmp/cookies" "$base/" >"$tmp/empty.html"
grep -q 'マシンはまだ登録されていません' "$tmp/empty.html"
echo 'OK: HTTP smoke (empty state, assets denied, CSRF, add, validation, delete)'
