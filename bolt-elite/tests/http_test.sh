#!/bin/bash
# BOLT ELITE REDIRECT - HTTP end-to-end test against a real server.
set -u
cd "$(dirname "$0")/.."
EXT="-d extension=/tmp/phpext/usr/lib/php/20240924/sqlite3.so -d extension=/tmp/phpext/usr/lib/php/20240924/pdo_sqlite.so"
PORT=${PORT:-8899}
DATA=$(mktemp -d)
TOK=httptok$RANDOM

BE_DATA_DIR="$DATA" ADMIN_TOKEN="$TOK" php $EXT -S 127.0.0.1:$PORT -t . tests/router.php >/tmp/be_http.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$DATA"' EXIT

for i in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$PORT/api/ping" && break; sleep 0.2; done

PASS=0; FAIL=0
ok(){ if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3 ($1)"; else FAIL=$((FAIL+1)); echo "  FAIL $3 (got '$1' want '$2')"; fi; }
has(){ if echo "$1" | grep -qi "$2"; then PASS=$((PASS+1)); echo "  ok   $3"; else FAIL=$((FAIL+1)); echo "  FAIL $3"; fi; }
B="http://127.0.0.1:$PORT"

echo; echo "auth"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/api/ping)" "200" "ping is public"
has "$(curl -s $B/api/ping)" '"authed":false' "ping reports unauthenticated"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/api/tokens)" "401" "tokens requires auth"
ok "$(curl -s -o /dev/null -w '%{http_code}' -H "X-Admin-Token: wrong" $B/api/tokens)" "401" "wrong token rejected"
ok "$(curl -s -o /dev/null -w '%{http_code}' -H "X-Admin-Token: $TOK" $B/api/tokens)" "200" "correct header accepted"

echo; echo "create links"
R=$(curl -s -H "X-Admin-Token: $TOK" -H 'Content-Type: application/json' -X POST $B/api/tokens/save \
  -d '{"name":"Redirect test","slug":"rd1","target":"https://example.com/dest","mode":"redirect","redirect_code":302,"param_block":"fbclid"}')
has "$R" '"ok":true' "redirect link created"
R2=$(curl -s -H "X-Admin-Token: $TOK" -H 'Content-Type: application/json' -X POST $B/api/tokens/save \
  -d '{"name":"Landing test","slug":"lp1","target":"https://example.com/lp","mode":"landing","template":"invoice","delay":0}')
has "$R2" '"ok":true' "landing link created"

echo; echo "redirect behaviour"
H=$(curl -s -D - -o /dev/null "$B/rd1?utm_source=mail&fbclid=xyz&email=a%40b.c")
has "$H" '302' "302 status"
has "$H" 'location: https://example.com/dest?utm_source=mail&email=a%40b.c' "params merged, fbclid dropped"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/r/rd1)" "302" "/r/{slug} also redirects"
has "$(curl -s -D - -o /dev/null $B/rd1)" 'x-robots-tag: noindex' "noindex header on redirect"

echo; echo "landing behaviour"
L=$(curl -s "$B/lp1")
has "$L" '<!doctype html>' "returns html"
has "$L" 'name="viewport"' "responsive viewport meta"
has "$L" 'https://example.com/lp' "cta points at the target"
has "$L" 'Invoice' "invoice template rendered"
has "$L" 'noindex' "noindex robots meta"

echo; echo "unknown paths"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/does-not-exist)" "404" "unknown slug -> 404"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/admin)" "200" "admin shell served"
has "$(curl -s $B/admin)" 'app.js' "admin loads the SPA bundle"

echo; echo "analytics"
S=$(curl -s -H "X-Admin-Token: $TOK" "$B/api/stats")
has "$S" '"hits":' "stats included"
HITS=$(curl -s -H "X-Admin-Token: $TOK" "$B/api/hits?limit=10")
has "$HITS" '"ip":"127.0.0.1"' "hits logged with client ip"
has "$HITS" '"device":"' "device intel captured"
has "$HITS" '"country":"Local"' "geo recorded for local ip"

echo; echo "paused link"
RD1_ID=$(curl -s -H "X-Admin-Token: $TOK" $B/api/tokens | php $EXT -r '$j=json_decode(stream_get_contents(STDIN),true); foreach($j["tokens"] as $t){ if($t["slug"]==="rd1"){echo $t["id"];break;} }')
curl -s -H "X-Admin-Token: $TOK" -H 'Content-Type: application/json' -X POST $B/api/tokens/pause -d '{"id":"'"$RD1_ID"'"}' >/dev/null
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/rd1)" "404" "paused link stops resolving"
curl -s -H "X-Admin-Token: $TOK" -H 'Content-Type: application/json' -X POST $B/api/tokens/resume -d '{"id":"'"$RD1_ID"'"}' >/dev/null
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/rd1)" "302" "resumed link works again"

echo; echo "security"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/data/bolt.sqlite)" "403" "sqlite store blocked"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/lib.php)" "403" "lib.php blocked"
ok "$(curl -s -o /dev/null -w '%{http_code}' $B/include/api.php)" "403" "include dir blocked"

echo; echo "----------------------------------------------"
echo "PASS: $PASS   FAIL: $FAIL"
[ "$FAIL" = "0" ] || { echo "--- server log ---"; tail -20 /tmp/be_http.log; exit 1; }
exit 0
