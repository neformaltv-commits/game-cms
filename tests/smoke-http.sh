#!/bin/bash
# Приёмочный HTTP smoke-тест этапа 1 (ТЗ блок 0, этап 1; проверки из блоков 3–4).
# Требования: запущенный php -S 127.0.0.1:8099 -t public и созданный админ
# (php cli/create-admin.php --username=admin --password=...).
# Запуск: bash tests/smoke-http.sh [base_url]
set -u
BASE="${1:-http://127.0.0.1:8099}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PASS='Adm1n-Str0ng!Pass'
CJ=$(mktemp)
TJ=$(mktemp)
trap 'rm -f "$CJ" "$TJ"' EXIT
fail=0

check() { # desc expected actual
  if [ "$2" = "$3" ]; then echo "PASS: $1 ($3)"; else echo "FAIL: $1 (ожидалось $2, получено $3)"; fail=1; fi
}

token() { # cookie jar -> csrf token from login page
  curl -s -b "$1" -c "$1" "$BASE/admin/login.php" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'
}

cd "$ROOT" || exit 1
rm -f data/throttle.json

echo "== 1. Публичная страница =="
check "GET /index.php -> 200" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/index.php")"
H=$(curl -sI "$BASE/index.php" | grep -ci "content-security-policy")
check "CSP header присутствует" 1 "$H"
H=$(curl -sI "$BASE/index.php" | grep -ci "x-content-type-options: nosniff")
check "X-Content-Type-Options: nosniff" 1 "$H"

echo "== 2. Защита админки без сессии =="
check "GET /admin/genres.php без сессии -> 302" 302 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/genres.php")"

echo "== 3. Троттлинг логина (5 неудач -> блокировка) =="
for i in 1 2 3 4 5; do
  C=$(token "$TJ")
  curl -s -b "$TJ" -c "$TJ" --data-urlencode "username=admin" --data-urlencode "password=wrong" --data-urlencode "_csrf=$C" "$BASE/admin/login.php" > /dev/null
done
C=$(token "$TJ")
R=$(curl -s -b "$TJ" -c "$TJ" --data-urlencode "username=admin" --data-urlencode "password=$PASS" --data-urlencode "_csrf=$C" "$BASE/admin/login.php")
if echo "$R" | grep -qi "попыт\|позже\|ждите\|блокир"; then
  echo "PASS: 6-я попытка заблокирована"
else
  echo "FAIL: троттлинг не сработал"; fail=1
fi
rm -f data/throttle.json

echo "== 4. Успешный вход =="
C=$(token "$CJ")
CODE=$(curl -s -b "$CJ" -c "$CJ" -o /dev/null -w '%{http_code}' --data-urlencode "username=admin" --data-urlencode "password=$PASS" --data-urlencode "_csrf=$C" "$BASE/admin/login.php")
check "POST /admin/login.php -> 302" 302 "$CODE"
CODE=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' "$BASE/admin/genres.php")
check "GET /admin/genres.php authed -> 200" 200 "$CODE"

echo "== 5. CSRF =="
CODE=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -d "action=save&name=x" "$BASE/admin/genres.php")
check "POST без CSRF -> 403" 403 "$CODE"
CODE=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -d "action=save&name=x&_csrf=deadbeef" "$BASE/admin/genres.php")
check "POST с неверным CSRF -> 403" 403 "$CODE"

echo "== 6. CRUD жанров (с валидным CSRF) =="
TOK=$(curl -s -b "$CJ" "$BASE/admin/genres.php" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
BEFORE=$(php -r '$g=json_decode(file_get_contents("data/genres.json"),true); echo count($g);')
CODE=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' --data-urlencode "action=save" --data-urlencode "name=Смоук-жанр" --data-urlencode "engine=static-screen" --data-urlencode "_csrf=$TOK" "$BASE/admin/genres.php")
AFTER=$(php -r '$g=json_decode(file_get_contents("data/genres.json"),true); echo count($g);')
check "POST save -> 302" 302 "$CODE"
check "жанр создан (+1)" $((BEFORE+1)) "$AFTER"
ID=$(php -r '$g=json_decode(file_get_contents("data/genres.json"),true); foreach($g as $x) if(($x["name"]??"")==="Смоук-жанр") echo $x["id"];')
CODE=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' --data-urlencode "action=delete" --data-urlencode "id=$ID" --data-urlencode "_csrf=$TOK" "$BASE/admin/genres.php")
AFTER=$(php -r '$g=json_decode(file_get_contents("data/genres.json"),true); echo count($g);')
check "POST delete -> 302" 302 "$CODE"
check "жанр удалён (-1)" "$BEFORE" "$AFTER"

echo "== 7. Выход =="
CODE=$(curl -s -b "$CJ" -c "$CJ" -o /dev/null -w '%{http_code}' "$BASE/admin/logout.php")
check "GET /admin/logout.php -> 302" 302 "$CODE"
CODE=$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' "$BASE/admin/genres.php")
check "GET /admin/genres.php после logout -> 302" 302 "$CODE"

echo
if [ $fail -eq 0 ]; then echo "=== SMOKE OK ==="; else echo "=== SMOKE FAILED ==="; fi
exit $fail
