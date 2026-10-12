#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
cleanup() { kill "${PROXY_PID:-}" "${UPSTREAM_PID:-}" 2>/dev/null || true; rm -rf "$TMP"; }
trap cleanup EXIT
cp -R "$ROOT" "$TMP/proxy"
export TEST_PROXY_DIR="$TMP/proxy"
export OLLAMA_PROXY_UPSTREAM_BASE_URL="http://127.0.0.1:18283"
export OLLAMA_PROXY_UPSTREAM_KEYS="test-upstream-key"
export OLLAMA_PROXY_PUBLIC_MODEL_DISCOVERY="true"
cat > "$TMP/proxy/config.php" <<'PHP'
<?php
return [
  'db_path' => __DIR__ . '/data/proxy.sqlite',
  'base_path' => '/ollama-proxy',
  'app_key' => base64_encode(str_repeat('k',32)),
  'admin_password' => 'testing',
  'upstream_keys' => ['test-upstream-key'],
  'upstream_base_url' => getenv('OLLAMA_PROXY_UPSTREAM_BASE_URL'),
];
PHP
mkdir -p "$TMP/proxy/data"
php -l "$TMP/proxy/api.php"
php -l "$TMP/proxy/lib.php"
php -l "$TMP/proxy/version.php"
php -l "$TMP/proxy/tests/router.php"
php -l "$TMP/proxy/tests/mock-upstream.php"
php -r 'require getenv("TEST_PROXY_DIR") . "/lib.php"; $db=proxy_db(); $db->prepare("INSERT INTO users(email,password_hash,api_key_hash,api_key_cipher,daily_request_limit,is_active) VALUES(?,?,?,?,?,1)")->execute(["test@proxy.local","unused",proxy_api_key_hash("mp_oll_test_key"),"unused",3]);'
# Prime with an injected future release and check cache reuse without hitting
# GitHub. The fake tag is test-only; production always uses the official API.
php -r '
require getenv("TEST_PROXY_DIR") . "/lib.php";
require getenv("TEST_PROXY_DIR") . "/version.php";
if (proxy_ollama_release_version("v0.40.4") !== "0.40.4") exit(1);
if (proxy_ollama_release_version("v0.40.4-rc0") !== null) exit(1);
$fetches = 0;
$probe = static function () use (&$fetches) { ++$fetches; return "v0.40.4"; };
if (proxy_cached_latest_ollama_version($probe) !== "0.40.4") exit(1);
if (proxy_cached_latest_ollama_version($probe) !== "0.40.4") exit(1);
if ($fetches !== 1) exit(1);
'
php -S 127.0.0.1:18283 "$TMP/proxy/tests/mock-upstream.php" >"$TMP/upstream.log" 2>&1 &
UPSTREAM_PID=$!
php -S 127.0.0.1:18284 "$TMP/proxy/tests/router.php" >"$TMP/proxy.log" 2>&1 &
PROXY_PID=$!
for _ in $(seq 1 40); do
  if curl -fsS "http://127.0.0.1:18284/api/version" > /dev/null; then break; fi
  sleep 0.2
done
BASE="http://127.0.0.1:18284"
code() { curl -sS -o "$TMP/body" -w '%{http_code}' "$@"; }
assert_code() {
  local expected="$1"; shift
  local actual; actual="$(code "$@")"
  if [[ "$actual" != "$expected" ]]; then
     echo "Expected HTTP $expected, got $actual from $*" >&2
     cat "$TMP/body" >&2; echo >&2
     echo "Proxy log:" >&2; tail -50 "$TMP/proxy.log" >&2; exit 1
  fi
}
assert_code 200 "$BASE/api/version"
grep -q '"version":"0.40.4"' "$TMP/body"
assert_code 405 -X POST "$BASE/api/version"
assert_code 404 "$BASE/api/does-not-exist"
assert_code 200 "$BASE/api/tags"
grep -q '"model":"test:cloud"' "$TMP/body"
assert_code 200 "$BASE/api/show" -H 'Content-Type: application/json' -d '{"model":"test:cloud"}'
grep -q '"capabilities"' "$TMP/body"
assert_code 400 "$BASE/api/show" -H 'Content-Type: application/json' -d '{"model":"../../../etc/passwd"}'
assert_code 401 "$BASE/v1/models"
assert_code 401 "$BASE/v1/chat/completions" -H 'Content-Type: application/json' -d '{"model":"test:cloud","messages":[]}'
assert_code 200 "$BASE/v1/models" -H 'Authorization: Bearer mp_oll_test_key'
assert_code 200 "$BASE/v1/chat/completions" -H 'Authorization: Bearer mp_oll_test_key' -H 'Content-Type: application/json' -d '{"model":"test:cloud","messages":[{"role":"user","content":"Hello"}],"stream":true}'
grep -q '^data: ' "$TMP/body"
grep -q 'data: \[DONE\]' "$TMP/body"
# A metadata probe never consumes inference quota; three generations do.
assert_code 200 "$BASE/v1/chat/completions" -H 'Authorization: Bearer mp_oll_test_key' -H 'Content-Type: application/json' -d '{"model":"test:cloud","messages":[],"stream":false}'
assert_code 200 "$BASE/api/chat" -H 'Authorization: Bearer mp_oll_test_key' -H 'Content-Type: application/json' -d '{"model":"test:cloud","messages":[],"stream":false}'
assert_code 429 "$BASE/v1/chat/completions" -H 'Authorization: Bearer mp_oll_test_key' -H 'Content-Type: application/json' -d '{"model":"test:cloud","messages":[]}'
assert_code 200 "$BASE/api/tags" -H 'Authorization: Bearer mp_oll_test_key'
echo "PASS: Copilot routing, discovery, auth, stream, method checks and shared quota"
